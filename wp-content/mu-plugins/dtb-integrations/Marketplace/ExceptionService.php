<?php
/**
 * Marketplace — ExceptionService
 *
 * Creates, queries, and resolves marketplace exceptions in
 * wp_dtb_marketplace_exceptions.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_MarketplaceExceptionService' ) ) {
	final class DTB_MarketplaceExceptionService {

		// Exception category constants.
		const CAT_AUTH_FAILURE        = 'auth_failure';
		const CAT_TOKEN_REFRESH       = 'token_refresh';
		const CAT_ORDER_IMPORT        = 'order_import';
		const CAT_DUPLICATE_ORDER     = 'duplicate_order';
		const CAT_SKU_MAPPING         = 'sku_mapping';
		const CAT_ORDER_LINKING       = 'order_linking';
		const CAT_VEEQO_MISMATCH      = 'veeqo_mismatch';
		const CAT_MESSAGE_SEND        = 'message_send';
		const CAT_UNSUPPORTED_ACTION  = 'unsupported_action';
		const CAT_RATE_LIMIT          = 'rate_limit';
		const CAT_WEBHOOK_VERIFY      = 'webhook_verify';
		const CAT_DATA_DELETION       = 'data_deletion';
		const CAT_TRACKING_SYNC       = 'tracking_sync';

		/**
		 * Create a new exception record. Idempotent: duplicate (category+channel+linked_id)
		 * within the last 15 minutes returns the existing ID.
		 *
		 * @param string $category         Exception category constant.
		 * @param string $channel_key      Channel key.
		 * @param string $error_code       Short error code.
		 * @param string $error_message    Human-readable message.
		 * @param array  $options {
		 *   @type string $severity           'error'|'warning'|'critical' (default 'error').
		 *   @type string $linked_record_type Linked record type, e.g. 'marketplace_order'.
		 *   @type int    $linked_record_id   Linked record ID.
		 *   @type bool   $is_retryable       Default true.
		 *   @type array  $context            Additional context data.
		 * }
		 * @return int Inserted or existing exception ID.
		 */
		public static function create( string $category, string $channel_key, string $error_code, string $error_message, array $options = [] ): int {
			global $wpdb;
			$table = $wpdb->prefix . 'dtb_marketplace_exceptions';

			$severity   = sanitize_key( $options['severity'] ?? 'error' );
			$rec_type   = sanitize_text_field( $options['linked_record_type'] ?? '' );
			$rec_id     = (int) ( $options['linked_record_id'] ?? 0 );
			$retryable  = isset( $options['is_retryable'] ) ? (int) (bool) $options['is_retryable'] : 1;
			$context    = wp_json_encode( $options['context'] ?? [] );
			$now        = current_time( 'mysql', true );

			// Idempotency: find open exception of same category+channel+record within 15 min.
			if ( $rec_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$existing = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$table}
					 WHERE category = %s AND channel_key = %s
					   AND linked_record_type = %s AND linked_record_id = %d
					   AND resolution_state = 'open'
					   AND created_at >= %s",
					$category,
					$channel_key,
					$rec_type,
					$rec_id,
					gmdate( 'Y-m-d H:i:s', time() - 900 )
				) );
				if ( $existing ) {
					return (int) $existing;
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$table,
				[
					'category'            => sanitize_key( $category ),
					'severity'            => in_array( $severity, [ 'error', 'warning', 'critical' ], true ) ? $severity : 'error',
					'channel_key'         => sanitize_key( $channel_key ),
					'linked_record_type'  => $rec_type,
					'linked_record_id'    => $rec_id ?: null,
					'error_code'          => sanitize_text_field( $error_code ),
					'error_message'       => substr( sanitize_text_field( $error_message ), 0, 1000 ),
					'is_retryable'        => $retryable,
					'resolution_state'    => 'open',
					'retry_count'         => 0,
					'context_json'        => $context,
					'created_at'          => $now,
					'updated_at'          => $now,
				],
				[ '%s','%s','%s','%s','%d','%s','%s','%d','%s','%d','%s','%s','%s' ]
			);

			$id = (int) $wpdb->insert_id;

			// Bubble to platform exception queue.
			if ( function_exists( 'dtb_admin_append_exception' ) ) {
				dtb_admin_append_exception( 'marketplace', $category, $channel_key . ': ' . $error_message );
			}

			return $id;
		}

		/**
		 * Mark an exception as resolved.
		 *
		 * @param int $exception_id Exception ID.
		 * @param int $resolved_by  WP user ID.
		 */
		public static function resolve( int $exception_id, int $resolved_by ): void {
			global $wpdb;
			$table = $wpdb->prefix . 'dtb_marketplace_exceptions';
			$now   = current_time( 'mysql', true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$table,
				[ 'resolution_state' => 'resolved', 'resolved_at' => $now, 'resolved_by' => $resolved_by, 'updated_at' => $now ],
				[ 'id' => $exception_id ],
				[ '%s','%s','%d','%s' ],
				[ '%d' ]
			);
		}

		/**
		 * Increment retry_count and set next_retry_at.
		 *
		 * @param int $exception_id  Exception ID.
		 * @param int $retry_delay   Seconds until next retry.
		 */
		public static function mark_retry_scheduled( int $exception_id, int $retry_delay = 300 ): void {
			global $wpdb;
			$table = $wpdb->prefix . 'dtb_marketplace_exceptions';
			$now   = current_time( 'mysql', true );
			$next  = gmdate( 'Y-m-d H:i:s', time() + $retry_delay );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET retry_count = retry_count + 1, next_retry_at = %s, updated_at = %s WHERE id = %d",
				$next, $now, $exception_id
			) );
		}

		/**
		 * Retrieve open exceptions for a channel.
		 *
		 * @param string $channel_key Channel key, or empty for all channels.
		 * @param int    $limit       Max results.
		 * @return array[]
		 */
		public static function get_open( string $channel_key = '', int $limit = 100 ): array {
			global $wpdb;
			$table = $wpdb->prefix . 'dtb_marketplace_exceptions';

			if ( '' !== $channel_key ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return (array) $wpdb->get_results( $wpdb->prepare(
					"SELECT * FROM {$table} WHERE resolution_state = 'open' AND channel_key = %s ORDER BY created_at DESC LIMIT %d",
					$channel_key, $limit
				), ARRAY_A );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE resolution_state = 'open' ORDER BY created_at DESC LIMIT %d",
				$limit
			), ARRAY_A );
		}

		/**
		 * Count open exceptions, optionally by channel.
		 *
		 * @param string $channel_key Channel key or empty for all.
		 * @return int
		 */
		public static function count_open( string $channel_key = '' ): int {
			global $wpdb;
			$table = $wpdb->prefix . 'dtb_marketplace_exceptions';
			if ( '' !== $channel_key ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE resolution_state = 'open' AND channel_key = %s",
					$channel_key
				) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE resolution_state = 'open'" );
		}
	}
}
