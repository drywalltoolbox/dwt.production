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
