<?php
/**
 * Marketplace — EventService
 *
 * Appends structured marketplace lifecycle events to both
 * wp_dtb_marketplace_events (marketplace-specific) and the platform
 * EventLogger / DTB order timeline (when a Woo order is linked).
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_MarketplaceEventService' ) ) {
	final class DTB_MarketplaceEventService {

		// Canonical event type constants.
		const EVT_ORDER_IMPORTED      = 'marketplace.order.imported';
		const EVT_ORDER_LINKED        = 'marketplace.order.linked';
		const EVT_SYNC_STARTED        = 'marketplace.sync.started';
		const EVT_SYNC_SUCCEEDED      = 'marketplace.sync.succeeded';
		const EVT_SYNC_FAILED         = 'marketplace.sync.failed';
		const EVT_MESSAGE_RECEIVED    = 'marketplace.message.received';
		const EVT_REPLY_DRAFTED       = 'marketplace.reply.drafted';
		const EVT_REPLY_QUEUED        = 'marketplace.reply.queued';
		const EVT_REPLY_SENT          = 'marketplace.reply.sent';
		const EVT_REPLY_FAILED        = 'marketplace.reply.failed';
		const EVT_TRACKING_SYNCED     = 'marketplace.tracking.synced';
		const EVT_EXCEPTION_CREATED   = 'marketplace.exception.created';
		const EVT_EXCEPTION_RESOLVED  = 'marketplace.exception.resolved';
		const EVT_TOKEN_EXPIRED       = 'marketplace.token.expired';
		const EVT_TOKEN_REFRESHED     = 'marketplace.token.refreshed';
		const EVT_WEBHOOK_RECEIVED    = 'marketplace.webhook.received';
		const EVT_WEBHOOK_REJECTED    = 'marketplace.webhook.rejected';
		const EVT_MATERIALIZATION_RESERVED  = 'marketplace.order.materialization_reserved';
		const EVT_MATERIALIZATION_COMPLETED = 'marketplace.order.materialization_completed';

		/** Build the stable reservation identity without retaining the provider order ID in the event payload. */
		public static function materialization_identity_hash( string $channel_key, string $external_order_id ): string {
			return hash( 'sha256', sanitize_key( $channel_key ) . "\0" . $external_order_id );
		}

		/** Fail closed unless the physical reservation arbiter is present and unique. */
		private static function materialization_reservation_index_ready(): bool {
			global $wpdb;
			$table = $wpdb->prefix . 'dtb_marketplace_events';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'idempotency_key'", ARRAY_A );
			if ( ! is_array( $indexes ) || 1 !== count( $indexes ) ) {
				return false;
			}
			$index = $indexes[0];
			return 0 === (int) ( $index['Non_unique'] ?? 1 )
				&& 'idempotency_key' === (string) ( $index['Column_name'] ?? '' );
		}

		/**
		 * Atomically claim one marketplace materialization identity.
		 *
		 * The unique event idempotency key is the concurrency arbiter. A caller may
		 * create a Woo order only when this method returns `won` and the exact
		 * reservation token supplied by that caller. Existing reservations are never
		 * reclaimed automatically.
		 *
		 * @return array{status:string,token:string,identity_hash:string,event_id:int}
		 */
		public static function claim_materialization_reservation( string $channel_key, string $external_order_id, int $marketplace_row_id ): array {
			global $wpdb;

			$channel_key  = sanitize_key( $channel_key );
			$identity_hash = self::materialization_identity_hash( $channel_key, $external_order_id );
			$idem_key      = 'marketplace-materialization-reservation:v1:' . $identity_hash;
			$table         = $wpdb->prefix . 'dtb_marketplace_events';
			if ( ! self::materialization_reservation_index_ready() ) {
				return [ 'status' => 'error', 'token' => '', 'identity_hash' => $identity_hash, 'event_id' => 0 ];
			}
			try {
				$token = bin2hex( random_bytes( 16 ) );
			} catch ( Throwable $e ) {
				return [ 'status' => 'error', 'token' => '', 'identity_hash' => $identity_hash, 'event_id' => 0 ];
			}

			$payload_json = wp_json_encode( [
				'marketplace_row_id' => max( 0, $marketplace_row_id ),
				'identity_hash'      => $identity_hash,
				'reservation_token'  => $token,
				'contract_version'   => 1,
			] );
			if ( ! is_string( $payload_json ) ) {
				return [ 'status' => 'error', 'token' => '', 'identity_hash' => $identity_hash, 'event_id' => 0 ];
			}

			// Insert first. A prior SELECT would allow two callers to believe they won.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$inserted = $wpdb->insert(
				$table,
				[
					'channel_key'       => $channel_key,
					'event_type'        => self::EVT_MATERIALIZATION_RESERVED,
					'external_event_id' => $identity_hash,
					'linked_order_id'   => $marketplace_row_id > 0 ? $marketplace_row_id : null,
					'idempotency_key'   => $idem_key,
					'safe_payload_json' => $payload_json,
					'payload_hash'      => hash( 'sha256', $payload_json ),
					'processing_status' => 'processed',
					'processed_at'      => current_time( 'mysql', true ),
					'created_at'        => current_time( 'mysql', true ),
				],
				[ '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
			);
			$won      = 1 === $inserted;

			// Always read back the unique row. This distinguishes a duplicate-key hold
			// from an unrelated insert/storage failure and proves the stored token.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, channel_key, event_type, linked_order_id, safe_payload_json FROM {$table} WHERE idempotency_key = %s LIMIT 1",
				$idem_key
			), ARRAY_A );
			if ( ! is_array( $row )
				|| $channel_key !== sanitize_key( (string) ( $row['channel_key'] ?? '' ) )
				|| self::EVT_MATERIALIZATION_RESERVED !== (string) ( $row['event_type'] ?? '' )
			) {
				return [ 'status' => 'error', 'token' => '', 'identity_hash' => $identity_hash, 'event_id' => 0 ];
			}

			$stored_payload = json_decode( (string) ( $row['safe_payload_json'] ?? '' ), true );
			$stored_token   = is_array( $stored_payload ) ? (string) ( $stored_payload['reservation_token'] ?? '' ) : '';
			$stored_hash    = is_array( $stored_payload ) ? (string) ( $stored_payload['identity_hash'] ?? '' ) : '';
			$stored_row_id = is_array( $stored_payload ) ? absint( $stored_payload['marketplace_row_id'] ?? 0 ) : 0;
			if ( '' === $stored_token
				|| ! hash_equals( $identity_hash, $stored_hash )
				|| $marketplace_row_id !== $stored_row_id
				|| $marketplace_row_id !== absint( $row['linked_order_id'] ?? 0 )
			) {
				return [ 'status' => 'error', 'token' => '', 'identity_hash' => $identity_hash, 'event_id' => 0 ];
			}

			if ( $won && hash_equals( $token, $stored_token ) ) {
				return [ 'status' => 'won', 'token' => $token, 'identity_hash' => $identity_hash, 'event_id' => (int) $row['id'] ];
			}

			return [ 'status' => 'held', 'token' => $stored_token, 'identity_hash' => $identity_hash, 'event_id' => (int) $row['id'] ];
		}

		/** Return whether an exact idempotent marketplace event is durably present. */
		public static function idempotency_key_exists( string $idempotency_key ): bool {
			global $wpdb;
			$table = $wpdb->prefix . 'dtb_marketplace_events';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(1) FROM {$table} WHERE idempotency_key = %s", substr( $idempotency_key, 0, 191 ) ) ) > 0;
		}

		/** Verify that an idempotency key belongs to the exact expected event. */
		public static function idempotent_event_matches( string $idempotency_key, string $event_type, string $channel_key, int $marketplace_row_id, int $woo_order_id ): bool {
			global $wpdb;
			$table = $wpdb->prefix . 'dtb_marketplace_events';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT channel_key, event_type, linked_order_id, safe_payload_json FROM {$table} WHERE idempotency_key = %s LIMIT 1",
				substr( $idempotency_key, 0, 191 )
			), ARRAY_A );
			if ( ! is_array( $row )
				|| sanitize_key( $channel_key ) !== sanitize_key( (string) ( $row['channel_key'] ?? '' ) )
				|| $event_type !== (string) ( $row['event_type'] ?? '' )
				|| $marketplace_row_id !== absint( $row['linked_order_id'] ?? 0 )
			) {
				return false;
			}
			$payload = json_decode( (string) ( $row['safe_payload_json'] ?? '' ), true );
			return is_array( $payload )
				&& $marketplace_row_id === absint( $payload['marketplace_row_id'] ?? 0 )
				&& $woo_order_id === absint( $payload['woo_order_id'] ?? 0 );
		}

		/**
		 * Append a marketplace event.
		 *
		 * @param string $event_type        Event type constant.
		 * @param string $channel_key       Channel key.
		 * @param array  $context {
		 *   @type string $external_event_id
		 *   @type int    $linked_order_id       wp_dtb_marketplace_orders.id
		 *   @type int    $linked_conversation_id
		 *   @type int    $linked_message_id
		 *   @type int    $woo_order_id          WooCommerce order post ID
		 *   @type array  $payload               Safe (non-secret) data to log
		 * }
		 */
		public static function append( string $event_type, string $channel_key, array $context = [] ): void {
			global $wpdb;
			$table = $wpdb->prefix . 'dtb_marketplace_events';

			$ext_id        = sanitize_text_field( $context['external_event_id'] ?? '' );
			$payload       = $context['payload'] ?? [];
			$payload_json  = wp_json_encode( $payload );
			$payload_hash  = hash( 'sha256', $payload_json );
			$idem_key      = $context['idempotency_key'] ?? ( $channel_key . ':' . $event_type . ':' . $ext_id );

			// Idempotency guard.
			if ( '' !== $ext_id || '' !== ( $context['idempotency_key'] ?? '' ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$exists = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$table} WHERE idempotency_key = %s",
					$idem_key
				) );
				if ( $exists ) {
					return;
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$table,
				[
					'channel_key'            => sanitize_key( $channel_key ),
					'event_type'             => sanitize_text_field( $event_type ),
					'external_event_id'      => $ext_id,
					'linked_order_id'        => $context['linked_order_id'] ?? null,
					'linked_conversation_id' => $context['linked_conversation_id'] ?? null,
					'linked_message_id'      => $context['linked_message_id'] ?? null,
					'idempotency_key'        => substr( $idem_key, 0, 191 ),
					'safe_payload_json'      => $payload_json,
					'payload_hash'           => $payload_hash,
					'processing_status'      => 'processed',
					'processed_at'           => current_time( 'mysql', true ),
					'created_at'             => current_time( 'mysql', true ),
				],
				[ '%s','%s','%s','%d','%d','%d','%s','%s','%s','%s','%s','%s' ]
			);

			// Mirror to platform EventLogger when available.
			if ( function_exists( 'dtb_event_log' ) ) {
				dtb_event_log( $event_type, array_merge( [ 'channel' => $channel_key ], $payload ) );
			}

			// Mirror to WooCommerce order timeline when linked.
			$woo_order_id = (int) ( $context['woo_order_id'] ?? 0 );
			if ( $woo_order_id > 0 && function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( $woo_order_id );
				if ( $order instanceof WC_Order ) {
					$order->add_order_note(
						sprintf( '[Marketplace] %s — %s', strtoupper( $channel_key ), sanitize_text_field( $event_type ) ),
						false,
						false
					);
				}
			}
		}
	}
}
