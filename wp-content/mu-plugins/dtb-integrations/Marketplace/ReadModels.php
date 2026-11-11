<?php
/**
 * Marketplace — ReadModels
 *
 * Query helpers for all marketplace read-model tables.
 * Used by REST controllers and admin pages.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_MarketplaceReadModels' ) ) {
	final class DTB_MarketplaceReadModels {

		// ── Channels ──────────────────────────────────────────────────────────

		/**
		 * Return all channel rows.
		 *
		 * @return array[]
		 */
		public static function channels(): array {
			global $wpdb;
			$table = $wpdb->prefix . 'dtb_marketplace_channels';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY channel_key ASC", ARRAY_A );
		}

		/**
		 * Upsert a channel row by channel_key.
		 *
		 * @param string $channel_key Channel key.
		 * @param array  $data        Columns to set.
		 */
		public static function upsert_channel( string $channel_key, array $data ): void {
			global $wpdb;
			$table = $wpdb->prefix . 'dtb_marketplace_channels';
			$now   = current_time( 'mysql', true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE channel_key = %s", $channel_key ) );
			if ( $existing ) {
				$data['updated_at'] = $now;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( $table, $data, [ 'channel_key' => $channel_key ] );
			} else {
				$data['channel_key'] = $channel_key;
				$data['created_at']  = $now;
				$data['updated_at']  = $now;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->insert( $table, $data );
			}
		}

		// ── Orders ────────────────────────────────────────────────────────────

		/**
		 * Paginated order list.
		 *
		 * @param array $filters Associative filter keys: channel_key, fulfillment_state, payment_state, search.
		 * @param int   $page    1-based page.
		 * @param int   $per     Per-page count.
		 * @return array{items: array[], total: int}
		 */
		public static function orders( array $filters = [], int $page = 1, int $per = 25 ): array {
			global $wpdb;
			$table  = $wpdb->prefix . 'dtb_marketplace_orders';
			$where  = [ '1=1' ];
			$params = [];

			if ( ! empty( $filters['channel_key'] ) ) {
				$where[]  = 'channel_key = %s';
				$params[] = sanitize_key( $filters['channel_key'] );
			}
			if ( ! empty( $filters['fulfillment_state'] ) ) {
				$where[]  = 'fulfillment_state = %s';
				$params[] = sanitize_key( $filters['fulfillment_state'] );
			}
			if ( ! empty( $filters['payment_state'] ) ) {
				$where[]  = 'payment_state = %s';
				$params[] = sanitize_key( $filters['payment_state'] );
			}

			$where_sql = implode( ' AND ', $where );
			$offset    = ( max( 1, $page ) - 1 ) * $per;

			if ( $params ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
				$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", ...$params ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
				$items = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY order_placed_at DESC LIMIT %d OFFSET %d", ...[...$params, $per, $offset] ), ARRAY_A );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$items = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY order_placed_at DESC LIMIT %d OFFSET %d", $per, $offset ), ARRAY_A );
			}

			return [ 'items' => $items, 'total' => $total ];
		}

		/**
		 * Find a marketplace order by channel+marketplace_order_id.
		 *
		 * @param string $channel_key        Channel key.
		 * @param string $marketplace_order_id External order ID.
		 * @return array|null
		 */
		public static function find_order( string $channel_key, string $marketplace_order_id ): ?array {
			global $wpdb;
			$table = $wpdb->prefix . 'dtb_marketplace_orders';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE channel_key = %s AND marketplace_order_id = %s",
				$channel_key, $marketplace_order_id
			), ARRAY_A );
			return $row ?: null;
		}

		/**
		 * Upsert a marketplace order row.
		 *
		 * @param array $data Normalized order data (must include channel_key + marketplace_order_id).
		 * @return int Row ID.
		 */
		public static function upsert_order( array $data ): int {
			global $wpdb;
			$table   = $wpdb->prefix . 'dtb_marketplace_orders';
			$now     = current_time( 'mysql', true );
			$channel = sanitize_key( $data['channel_key'] );
			$ext_id  = sanitize_text_field( $data['marketplace_order_id'] );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$existing = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, raw_payload_hash FROM {$table} WHERE channel_key = %s AND marketplace_order_id = %s",
				$channel, $ext_id
			), ARRAY_A );

			$safe = self::sanitize_order_data( $data );

			if ( $existing ) {
				// Skip update when payload hash unchanged.
				if ( isset( $safe['raw_payload_hash'] ) && $existing['raw_payload_hash'] === $safe['raw_payload_hash'] ) {
					return (int) $existing['id'];
				}
				$safe['last_synced_at'] = $now;
				$safe['updated_at']     = $now;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( $table, $safe, [ 'channel_key' => $channel, 'marketplace_order_id' => $ext_id ] );
				return (int) $existing['id'];
			}

			$safe['first_synced_at'] = $now;
			$safe['last_synced_at']  = $now;
			$safe['created_at']      = $now;
			$safe['updated_at']      = $now;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( $table, $safe );
			return (int) $wpdb->insert_id;
		}

		// ── Conversations ─────────────────────────────────────────────────────

		/**
		 * Return paginated conversations.
		 *
		 * @param array $filters channel_key, status, needs_reply (bool), has_sla_breach (bool).
		 * @param int   $page    Page number.
		 * @param int   $per     Per-page.
		 * @return array{items: array[], total: int}
		 */
		public static function conversations( array $filters = [], int $page = 1, int $per = 25 ): array {
			global $wpdb;
			$table  = $wpdb->prefix . 'dtb_marketplace_conversations';
			$where  = [ '1=1' ];
			$params = [];

			if ( ! empty( $filters['channel_key'] ) ) {
				$where[]  = 'channel_key = %s';
				$params[] = sanitize_key( $filters['channel_key'] );
			}
			if ( ! empty( $filters['status'] ) ) {
				$where[]  = 'status = %s';
				$params[] = sanitize_key( $filters['status'] );
			}
			if ( ! empty( $filters['needs_reply'] ) ) {
				$where[] = "(status = 'open' AND (last_inbound_at > last_outbound_at OR last_outbound_at IS NULL))";
			}
			if ( ! empty( $filters['sla_breach'] ) ) {
				$where[]  = 'sla_due_at < %s';
				$params[] = current_time( 'mysql', true );
			}

			$where_sql = implode( ' AND ', $where );
			$offset    = ( max( 1, $page ) - 1 ) * $per;

			if ( $params ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
				$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", ...$params ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
				$items = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY last_inbound_at DESC LIMIT %d OFFSET %d", ...[...$params, $per, $offset] ), ARRAY_A );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$items = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY last_inbound_at DESC LIMIT %d OFFSET %d", $per, $offset ), ARRAY_A );
			}
			return [ 'items' => $items, 'total' => $total ];
		}

		/**
		 * Find or create a conversation record.
		 *
		 * @param string $channel_key            Channel key.
		 * @param string $external_conversation_id External conversation ID.
		 * @param array  $data                   Additional columns.
		 * @return int Conversation row ID.
		 */
		public static function upsert_conversation( string $channel_key, string $external_conversation_id, array $data = [] ): int {
			global $wpdb;
			$table = $wpdb->prefix . 'dtb_marketplace_conversations';
			$now   = current_time( 'mysql', true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE channel_key = %s AND external_conversation_id = %s",
				$channel_key, $external_conversation_id
			) );
			if ( $existing ) {
				$data['updated_at'] = $now;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( $table, $data, [ 'channel_key' => $channel_key, 'external_conversation_id' => $external_conversation_id ] );
				return (int) $existing;
			}
			$data = array_merge( [
				'channel_key'              => sanitize_key( $channel_key ),
				'external_conversation_id' => sanitize_text_field( $external_conversation_id ),
				'status'                   => 'open',
				'created_at'               => $now,
				'updated_at'               => $now,
			], $data );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( $table, $data );
			return (int) $wpdb->insert_id;
		}

		/**
		 * Return messages for a conversation, ordered oldest-first.
		 *
		 * @param int $conversation_id Conversation row ID.
		 * @return array[]
		 */
		public static function messages_for_conversation( int $conversation_id ): array {
			global $wpdb;
			$table = $wpdb->prefix . 'dtb_marketplace_messages';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT id, conversation_id, external_message_id, direction, sender_type, body_preview,
				        attachment_meta_json, message_status, operator_id, platform_action, sent_at,
				        failed_at, failure_reason, created_at
				 FROM {$table} WHERE conversation_id = %d ORDER BY created_at ASC",
				$conversation_id
			), ARRAY_A );
		}

		/**
		 * Insert a message row.
		 *
		 * @param array $data Normalized message data.
		 * @return int Inserted message ID.
		 */
		public static function insert_message( array $data ): int {
			global $wpdb;
			$table = $wpdb->prefix . 'dtb_marketplace_messages';
			$now   = current_time( 'mysql', true );
			if ( ! isset( $data['created_at'] ) ) {
				$data['created_at'] = $now;
			}
			if ( ! isset( $data['updated_at'] ) ) {
				$data['updated_at'] = $now;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( $table, $data );
			return (int) $wpdb->insert_id;
		}

		/**
		 * Return pending outbound messages due for sending.
		 *
		 * @param string $channel_key Channel key.
		 * @param int    $limit       Max rows.
		 * @return array[]
		 */
		public static function queued_outbound( string $channel_key, int $limit = 10 ): array {
			global $wpdb;
			$msg_table  = $wpdb->prefix . 'dtb_marketplace_messages';
			$conv_table = $wpdb->prefix . 'dtb_marketplace_conversations';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT m.*, c.channel_key, c.external_conversation_id, c.external_order_id,
				        c.external_item_id, c.buyer_ref_hash
				 FROM {$msg_table} m
				 JOIN {$conv_table} c ON c.id = m.conversation_id
				 WHERE c.channel_key = %s AND m.direction = 'outbound' AND m.message_status = 'queued'
				 ORDER BY m.created_at ASC LIMIT %d",
				$channel_key, $limit
			), ARRAY_A );
		}

		// ── Overview aggregates ───────────────────────────────────────────────

		/**
		 * Return overview aggregate counts for all channels.
		 *
		 * @return array
		 */
		public static function overview(): array {
			global $wpdb;
			$o_table = $wpdb->prefix . 'dtb_marketplace_orders';
			$c_table = $wpdb->prefix . 'dtb_marketplace_conversations';
			$e_table = $wpdb->prefix . 'dtb_marketplace_exceptions';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$order_counts = $wpdb->get_results(
				"SELECT channel_key,
				        COUNT(*) as total,
				        SUM(CASE WHEN fulfillment_state = 'unshipped' THEN 1 ELSE 0 END) as unshipped,
				        SUM(CASE WHEN exception_count > 0 THEN 1 ELSE 0 END) as with_exceptions,
				        SUM(CASE WHEN woo_order_id IS NULL THEN 1 ELSE 0 END) as unlinked
				 FROM {$o_table} GROUP BY channel_key",
				ARRAY_A
			) ?? [];

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$conv_counts = $wpdb->get_results(
				"SELECT channel_key,
				        COUNT(*) as total,
				        SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_count,
				        SUM(CASE WHEN sla_state = 'breached' THEN 1 ELSE 0 END) as sla_breached
				 FROM {$c_table} GROUP BY channel_key",
				ARRAY_A
			) ?? [];

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$exc_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$e_table} WHERE resolution_state = 'open'" );

			$by_channel = [];
			foreach ( $order_counts as $row ) {
				$k = $row['channel_key'];
				$by_channel[ $k ]['orders'] = $row;
			}
			foreach ( $conv_counts as $row ) {
				$k = $row['channel_key'];
				$by_channel[ $k ]['conversations'] = $row;
			}

			return [
				'by_channel'       => $by_channel,
				'total_exceptions' => $exc_count,
			];
		}

		// ── Private helpers ───────────────────────────────────────────────────

		private static function sanitize_order_data( array $data ): array {
			$allowed = [
				'channel_key', 'marketplace_order_id', 'woo_order_id', 'veeqo_order_id',
				'buyer_ref_hash', 'payment_state', 'fulfillment_state', 'tracking_state',
				'message_state', 'sla_due_at', 'exception_count', 'raw_payload_hash',
				'order_placed_at',
			];
			$out = [];
			foreach ( $allowed as $k ) {
				if ( array_key_exists( $k, $data ) ) {
					$out[ $k ] = is_null( $data[ $k ] ) ? null : sanitize_text_field( (string) $data[ $k ] );
				}
			}
			if ( isset( $out['woo_order_id'] ) ) {
				$out['woo_order_id'] = (int) $out['woo_order_id'] ?: null;
			}
			if ( isset( $out['exception_count'] ) ) {
				$out['exception_count'] = (int) $out['exception_count'];
			}
			return $out;
		}
	}
}
