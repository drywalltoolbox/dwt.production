<?php
/**
 * eBay — EbayMessageService
 *
 * Retrieves buyer messages and sends replies using official eBay messaging APIs.
 *
 * Uses eBay Customer Service Messaging API where available, enforcing:
 *   - Required buyer_username + item_id or order_id context
 *   - Body length limit (2000 chars)
 *   - Local rate-limit guard via DTB_MarketplaceRateLimitState
 *   - Reply idempotency via outbound message records
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_EbayMessageService' ) ) {
	final class DTB_EbayMessageService {

		/**
		 * Sync buyer messages for a conversation.
		 *
		 * Uses eBay Post Order API messaging endpoint.
		 *
		 * @param string $item_id        eBay item ID.
		 * @param string $buyer_username eBay buyer username.
		 * @param int    $conversation_id Internal conversation_id.
		 * @return array{ok: bool, count: int, error: string}
		 */
		public static function sync_messages( string $item_id, string $buyer_username, int $conversation_id ): array {
			// Post Order API: GET /post-order/v2/inquiry?item_id=...
			$response = DTB_EbayRestClient::request(
				'GET',
				'/post-order/v2/inquiry',
				[
					'item_id'        => $item_id,
					'buyer_username' => $buyer_username,
					'status'         => 'OPEN',
				]
			);

			if ( ! $response['ok'] ) {
				return [ 'ok' => false, 'count' => 0, 'error' => $response['error'] ];
			}

			$messages = $response['data']['inquiries'] ?? $response['data']['messages'] ?? [];
			$count    = 0;

			foreach ( $messages as $raw ) {
				global $wpdb;
				$msg_id = sanitize_text_field( $raw['id'] ?? $raw['messageId'] ?? '' );
				if ( '' === $msg_id ) {
					continue;
				}
				$table = $wpdb->prefix . 'dtb_marketplace_messages';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$exists = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$table} WHERE conversation_id = %d AND external_message_id = %s",
					$conversation_id, $msg_id
				) );
				if ( $exists ) {
					continue;
				}

				$normalized = DTB_MarketplaceMessageNormalizer::from_ebay( $raw, $conversation_id );
				DTB_MarketplaceReadModels::insert_message( $normalized );

				DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_MESSAGE_RECEIVED, DTB_CHANNEL_EBAY, [
					'linked_conversation_id' => $conversation_id,
					'payload'                => [ 'external_message_id' => $msg_id ],
				] );

				$count++;
			}

			return [ 'ok' => true, 'count' => $count, 'error' => '' ];
		}

		/**
		 * Queue an outbound reply and schedule the actual send.
		 *
		 * @param int    $conversation_id Internal conversation ID.
		 * @param string $buyer_username  eBay buyer username (required).
		 * @param string $item_id         eBay item ID (required or order_id).
		 * @param string $order_id        eBay order ID (optional).
		 * @param string $body            Reply body (max 2000 chars).
		 * @param int    $operator_id     WP user ID.
		 * @param string $idempotency_key Unique key.
		 * @return array{ok: bool, message_id: int, error: string}
		 */
		public static function queue_reply(
			int $conversation_id,
			string $buyer_username,
			string $item_id,
			string $order_id,
			string $body,
			int $operator_id,
			string $idempotency_key
		): array {
			// Policy validation.
			$policy = DTB_MarketplaceActionPolicyValidator::validate_ebay_reply(
				$buyer_username, $item_id, $order_id, $body, DTB_CHANNEL_EBAY
			);
			if ( ! $policy['ok'] ) {
				return [ 'ok' => false, 'message_id' => 0, 'error' => $policy['reason'] ];
			}

			// Check idempotency.
			global $wpdb;
			$table = $wpdb->prefix . 'dtb_marketplace_messages';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE idempotency_key = %s",
				$idempotency_key
			) );
			if ( $existing ) {
				return [ 'ok' => true, 'message_id' => (int) $existing, 'error' => '' ];
			}

			$msg_data   = DTB_MarketplaceMessageNormalizer::build_outbound(
				$conversation_id, $body, 'ebay_reply', $operator_id, $idempotency_key
			);
			$message_id = DTB_MarketplaceReadModels::insert_message( $msg_data );

			DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_REPLY_QUEUED, DTB_CHANNEL_EBAY, [
				'linked_conversation_id' => $conversation_id,
				'linked_message_id'      => $message_id,
				'payload'                => [ 'buyer_username' => $buyer_username, 'item_id' => $item_id ],
			] );

			// Schedule send job.
			$hook = 'dtb_ebay_message_send';
			$args = [ $message_id, $buyer_username, $item_id, $order_id, $body, $idempotency_key ];
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + 5, $hook, [ $args ], 'dtb-marketplace' );
			} else {
				wp_schedule_single_event( time() + 5, $hook, $args );
			}

			return [ 'ok' => true, 'message_id' => $message_id, 'error' => '' ];
		}

		/**
		 * Execute the actual eBay reply API call (called by queue job).
		 *
		 * @param int    $message_id      Internal message ID.
		 * @param string $buyer_username  eBay buyer username.
		 * @param string $item_id         eBay item ID.
		 * @param string $order_id        eBay order ID (may be empty).
		 * @param string $body            Reply body.
		 * @param string $idempotency_key Idempotency key.
		 */
		public static function execute_reply( int $message_id, string $buyer_username, string $item_id, string $order_id, string $body, string $idempotency_key ): void {
			global $wpdb;
			$table = $wpdb->prefix . 'dtb_marketplace_messages';

			$response = DTB_EbayRestClient::request(
				'POST',
				'/post-order/v2/inquiry/reply',
				[],
				[
					'buyerUsername'   => $buyer_username,
					'itemId'          => $item_id,
					'orderId'         => $order_id ?: null,
					'message'         => $body,
				]
			);

			$now = current_time( 'mysql', true );

			if ( $response['ok'] ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( $table, [
					'message_status' => 'sent',
					'sent_at'        => $now,
					'external_message_id' => sanitize_text_field( (string) ( $response['data']['messageId'] ?? '' ) ),
					'updated_at'     => $now,
				], [ 'id' => $message_id ] );

				// Update conversation last_outbound_at.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$conv_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT conversation_id FROM {$table} WHERE id = %d", $message_id ) );
				if ( $conv_id ) {
					$conv_table = $wpdb->prefix . 'dtb_marketplace_conversations';
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->update( $conv_table, [ 'last_outbound_at' => $now, 'updated_at' => $now ], [ 'id' => $conv_id ] );
				}

				DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_REPLY_SENT, DTB_CHANNEL_EBAY, [
					'linked_message_id' => $message_id,
					'idempotency_key'   => $idempotency_key,
					'payload'           => [ 'buyer_username' => $buyer_username, 'item_id' => $item_id ],
				] );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( $table, [
					'message_status' => 'failed',
					'failed_at'      => $now,
					'failure_reason' => substr( $response['error'], 0, 500 ),
					'updated_at'     => $now,
				], [ 'id' => $message_id ] );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET send_attempt_count = send_attempt_count + 1 WHERE id = %d", $message_id ) );

				DTB_MarketplaceExceptionService::create(
					DTB_MarketplaceExceptionService::CAT_MESSAGE_SEND,
					DTB_CHANNEL_EBAY,
					'ebay_reply_failed',
					$response['error'],
					[ 'is_retryable' => ! $response['rate_limited'], 'linked_record_type' => 'marketplace_message', 'linked_record_id' => $message_id ]
				);

				DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_REPLY_FAILED, DTB_CHANNEL_EBAY, [
					'linked_message_id' => $message_id,
					'payload'           => [ 'error' => $response['error'] ],
				] );
			}
		}
	}
}
