<?php
/**
 * eBay — EbayMessageService
 *
 * Retrieves buyer messages and sends replies through eBay communication APIs.
 *
 * Uses the Message API for existing conversation replies, enforcing:
 *   - Server-resolved existing conversation identity
 *   - Body length limit (2000 chars)
 *   - Local rate-limit guard via DTB_MarketplaceRateLimitState
 *   - Encrypted message-body storage and duplicate queue containment
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_EbayMessageService' ) ) {
	final class DTB_EbayMessageService {

		/**
		 * Sync buyer messages for a conversation.
		 *
		 * Deprecated legacy entry point. Message sync now uses signed NEW_MESSAGE
		 * notifications; never call the retired Post-Order inquiry endpoint.
		 *
		 * @param string $item_id        eBay item ID.
		 * @param string $buyer_username eBay buyer username.
		 * @param int    $conversation_id Internal conversation_id.
		 * @return array{ok: bool, count: int, error: string}
		 */
		public static function sync_messages( string $item_id, string $buyer_username, int $conversation_id ): array {
			return [ 'ok' => false, 'count' => 0, 'error' => 'Legacy eBay inquiry polling is retired; use signed NEW_MESSAGE notifications.' ];
		}

		/**
		 * Persist an authenticated eBay NEW_MESSAGE notification.
		 *
		 * The notification is emitted when the authorized account receives a
		 * message. Raw party identifiers and provider payloads are never stored.
		 *
		 * @param array  $data      Verified notification data.
		 * @param string $event_id  Stable eBay notification ID.
		 * @return array{ok: bool, conversation_id: int, message_id: int, error: string}
		 */
		public static function receive_notification( array $data, string $event_id ): array {
			$conversation_id = sanitize_text_field( (string) ( $data['conversationId'] ?? '' ) );
			$external_msg_id = sanitize_text_field( (string) ( $data['messageId'] ?? '' ) );
			$type            = strtoupper( sanitize_text_field( (string) ( $data['conversationType'] ?? '' ) ) );
			$body            = (string) ( $data['messageBody'] ?? '' );
			$media = $data['messageMedia'] ?? [];
			if ( '' === $conversation_id || strlen( $conversation_id ) > 191
				|| '' === $external_msg_id || strlen( $external_msg_id ) > 191
				|| ! in_array( $type, [ 'FROM_MEMBERS', 'FROM_EBAY' ], true )
				|| strlen( $body ) > 10000
				|| ( '' === $body && ( ! is_array( $media ) || empty( $media ) ) )
			) {
				return [ 'ok' => false, 'conversation_id' => 0, 'message_id' => 0, 'error' => 'The signed eBay message notification is incomplete or outside supported bounds.' ];
			}

			$buyer_hash = '';
			if ( 'FROM_MEMBERS' === $type ) {
				$sender = sanitize_text_field( (string) ( $data['senderUserName'] ?? '' ) );
				if ( '' !== $sender ) {
					$buyer_hash = hash_hmac( 'sha256', strtolower( trim( $sender ) ), (string) ( defined( 'AUTH_SALT' ) ? AUTH_SALT : 'dtb' ) );
				}
			}

			$conversation_data = [
				'conversation_type' => $type,
				'last_inbound_at'  => current_time( 'mysql', true ),
				'status'           => 'open',
			];
			$listing_id = sanitize_text_field( (string) ( $data['listingId'] ?? '' ) );
			if ( '' !== $listing_id ) {
				$conversation_data['external_item_id'] = $listing_id;
			}
			if ( '' !== $buyer_hash ) {
				$conversation_data['buyer_ref_hash'] = $buyer_hash;
			}
			$conv_id = DTB_MarketplaceReadModels::upsert_conversation( DTB_CHANNEL_EBAY, $conversation_id, $conversation_data );
			if ( $conv_id < 1 ) {
				return [ 'ok' => false, 'conversation_id' => 0, 'message_id' => 0, 'error' => 'The eBay conversation could not be stored.' ];
			}

			$message = DTB_MarketplaceMessageNormalizer::from_ebay_notification( $data, $conv_id );
			if ( '' !== $body && empty( $message['body_encrypted'] ) ) {
				return [ 'ok' => false, 'conversation_id' => $conv_id, 'message_id' => 0, 'error' => 'The eBay message body could not be encrypted for storage.' ];
			}
			$message_id = DTB_MarketplaceReadModels::insert_message_once( $message );
			if ( $message_id < 1 ) {
				return [ 'ok' => false, 'conversation_id' => $conv_id, 'message_id' => 0, 'error' => 'The eBay message could not be stored idempotently.' ];
			}

			DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_MESSAGE_RECEIVED, DTB_CHANNEL_EBAY, [
				'external_event_id'      => $external_msg_id,
				'linked_conversation_id' => $conv_id,
				'linked_message_id'      => $message_id,
				'payload'                => [ 'source' => 'signed_new_message_notification', 'conversation_type' => $type ],
			] );
			DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_WEBHOOK_RECEIVED, DTB_CHANNEL_EBAY, [
				'external_event_id'      => $event_id,
				'linked_conversation_id' => $conv_id,
				'linked_message_id'      => $message_id,
				'payload'                => [ 'type' => 'new_message', 'status' => 'persisted' ],
			] );

			return [ 'ok' => true, 'conversation_id' => $conv_id, 'message_id' => $message_id, 'error' => '' ];
		}

		/**
		 * Queue an outbound reply and schedule the actual send.
		 *
		 * @param int    $conversation_id Internal conversation ID.
		 * @param string $body            Reply body (max 2000 chars).
		 * @param int    $operator_id     WP user ID.
		 * @param string $idempotency_key Unique key.
		 * @return array{ok: bool, message_id: int, error: string}
		 */
		public static function queue_reply(
			int $conversation_id,
			mixed $body,
			mixed $operator_id,
			mixed $idempotency_key,
			mixed ...$legacy_args
		): array {
			if ( 3 === count( $legacy_args ) ) {
				// Preserve already-open admin requests while files are deployed in
				// sequence; their buyer/item/order values are discarded immediately.
				$body            = (string) $legacy_args[0];
				$operator_id     = (int) $legacy_args[1];
				$idempotency_key = (string) $legacy_args[2];
			} else {
				$body            = (string) $body;
				$operator_id     = (int) $operator_id;
				$idempotency_key = (string) $idempotency_key;
			}

			global $wpdb;
			$table      = $wpdb->prefix . 'dtb_marketplace_messages';
			$conv_table = $wpdb->prefix . 'dtb_marketplace_conversations';

			// Resolve the provider conversation from server-owned state; never trust
			// caller-supplied buyer, listing, or order identifiers for a reply.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$conversation = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, external_conversation_id, status
				 FROM {$conv_table} WHERE id = %d AND channel_key = %s LIMIT 1",
				$conversation_id,
				DTB_CHANNEL_EBAY
			), ARRAY_A );
			if ( ! is_array( $conversation )
				|| '' === (string) $conversation['external_conversation_id']
				|| ! in_array( (string) $conversation['status'], [ 'open', 'needs_reply', 'replied' ], true )
			) {
				return [ 'ok' => false, 'message_id' => 0, 'error' => 'The eBay conversation is unavailable or has no provider identity.' ];
			}

			$policy = DTB_MarketplaceActionPolicyValidator::validate_ebay_reply( $conversation_id, $body, DTB_CHANNEL_EBAY );
			if ( ! $policy['ok'] ) {
				return [ 'ok' => false, 'message_id' => 0, 'error' => $policy['reason'] ];
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$existing = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, message_status FROM {$table} WHERE idempotency_key = %s LIMIT 1",
				$idempotency_key
			), ARRAY_A );
			if ( is_array( $existing ) ) {
				$existing_status = (string) $existing['message_status'];
				$already_accepted = in_array( $existing_status, [ 'queued', 'sending', 'sent' ], true );
				return [
					'ok'         => $already_accepted,
					'message_id' => (int) $existing['id'],
					'error'      => $already_accepted ? '' : 'A prior attempt exists and requires reconciliation before retry.',
				];
			}

			$msg_data   = DTB_MarketplaceMessageNormalizer::build_outbound(
				$conversation_id, $body, 'ebay_reply', $operator_id, $idempotency_key
			);
			if ( empty( $msg_data['body_encrypted'] ) ) {
				return [ 'ok' => false, 'message_id' => 0, 'error' => 'The reply could not be encrypted for storage.' ];
			}
			$message_id = DTB_MarketplaceReadModels::insert_message( $msg_data );
			if ( $message_id < 1 ) {
				return [ 'ok' => false, 'message_id' => 0, 'error' => 'The reply could not be queued.' ];
			}

			// Action Scheduler receives only the internal message identity. The
			// provider conversation and encrypted body are reloaded by the worker.
			$hook = 'dtb_ebay_message_send';
			$args = [ $message_id ];
			if ( ! function_exists( 'as_schedule_single_action' ) ) {
				$wpdb->update( $table, [ 'message_status' => 'failed', 'failure_reason' => 'Action Scheduler is unavailable.', 'failed_at' => current_time( 'mysql', true ) ], [ 'id' => $message_id ] );
				return [ 'ok' => false, 'message_id' => $message_id, 'error' => 'The marketplace queue is unavailable.' ];
			}

			$queue_args        = [ $args ];
			$already_scheduled = function_exists( 'as_has_scheduled_action' )
				&& as_has_scheduled_action( $hook, $queue_args, 'dtb-marketplace' );
			$action_id = $already_scheduled ? 1 : as_schedule_single_action( time() + 5, $hook, $queue_args, 'dtb-marketplace', true );
			if ( (int) $action_id < 1 && ! ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $queue_args, 'dtb-marketplace' ) ) ) {
				$wpdb->update( $table, [ 'message_status' => 'failed', 'failure_reason' => 'Action Scheduler rejected the reply job.', 'failed_at' => current_time( 'mysql', true ) ], [ 'id' => $message_id ] );
				return [ 'ok' => false, 'message_id' => $message_id, 'error' => 'The reply job could not be scheduled.' ];
			}

			DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_REPLY_QUEUED, DTB_CHANNEL_EBAY, [
				'linked_conversation_id' => $conversation_id,
				'linked_message_id'      => $message_id,
			] );

			return [ 'ok' => true, 'message_id' => $message_id, 'error' => '' ];
		}

		/**
		 * Execute the actual eBay reply API call (called by queue job).
		 *
		 * @param int $message_id Internal message ID.
		 */
		public static function execute_reply( int $message_id ): void {
			global $wpdb;
			$table      = $wpdb->prefix . 'dtb_marketplace_messages';
			$conv_table = $wpdb->prefix . 'dtb_marketplace_conversations';

			// Load the encrypted draft and external conversation ID from the
			// authoritative local records. Only queued eBay messages can send.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$record = $wpdb->get_row( $wpdb->prepare(
				"SELECT m.id, m.conversation_id, m.body_encrypted, m.idempotency_key,
				        m.message_status, c.external_conversation_id
				 FROM {$table} m
			 JOIN {$conv_table} c ON c.id = m.conversation_id
			 WHERE m.id = %d AND c.channel_key = %s LIMIT 1",
				$message_id,
				DTB_CHANNEL_EBAY
			), ARRAY_A );
			if ( ! is_array( $record ) || 'queued' !== (string) $record['message_status'] ) {
				return;
			}

			$external_conversation_id = sanitize_text_field( (string) $record['external_conversation_id'] );
			$body = class_exists( 'DTB_MarketplaceCredentialFacade' )
				? DTB_MarketplaceCredentialFacade::decrypt( (string) $record['body_encrypted'] )
				: false;
			if ( '' === $external_conversation_id || false === $body || '' === $body ) {
				$wpdb->update( $table, [ 'message_status' => 'failed', 'failure_reason' => 'Stored reply or conversation identity is unavailable.', 'failed_at' => current_time( 'mysql', true ) ], [ 'id' => $message_id, 'message_status' => 'queued' ] );
				return;
			}

			$now = current_time( 'mysql', true );
			// Compare-and-set prevents duplicate queue deliveries from issuing a
			// second provider send. Ambiguous provider timeouts require reconciliation.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$claimed = $wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET message_status = 'sending', send_attempt_count = send_attempt_count + 1, updated_at = %s
				 WHERE id = %d AND message_status = 'queued'",
				$now,
				$message_id
			) );
			if ( 1 !== (int) $claimed ) {
				return;
			}

			$response = DTB_EbayRestClient::request(
				'POST',
				'/commerce/message/v1/send_message',
				[],
				[
					'conversationId' => $external_conversation_id,
					'messageText'    => $body,
				]
			);

			$now = current_time( 'mysql', true );

			if ( $response['ok'] ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( $table, [
					'message_status' => 'sent',
					'sent_at'        => $now,
					'updated_at'     => $now,
				], [ 'id' => $message_id, 'message_status' => 'sending' ] );

				$wpdb->update( $conv_table, [ 'last_outbound_at' => $now, 'updated_at' => $now ], [ 'id' => (int) $record['conversation_id'] ] );

				DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_REPLY_SENT, DTB_CHANNEL_EBAY, [
					'linked_message_id' => $message_id,
					'idempotency_key'   => (string) $record['idempotency_key'],
				] );
			} else {
				$send_status = (int) $response['status'] >= 500 ? 'unknown' : 'failed';
				$failure_reason = 'unknown' === $send_status
					? 'eBay response is ambiguous; reconcile the conversation before sending again.'
					: sprintf( 'eBay declined the message request with HTTP status %d.', (int) $response['status'] );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( $table, [
					'message_status' => $send_status,
					'failed_at'      => $now,
					'failure_reason' => $failure_reason,
					'updated_at'     => $now,
				], [ 'id' => $message_id, 'message_status' => 'sending' ] );

				DTB_MarketplaceExceptionService::create(
					DTB_MarketplaceExceptionService::CAT_MESSAGE_SEND,
					DTB_CHANNEL_EBAY,
					'ebay_reply_failed',
					$failure_reason,
					[ 'is_retryable' => false, 'linked_record_type' => 'marketplace_message', 'linked_record_id' => $message_id ]
				);

				DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_REPLY_FAILED, DTB_CHANNEL_EBAY, [
					'linked_message_id' => $message_id,
					'payload'           => [ 'status' => (int) $response['status'], 'result' => $send_status ],
				] );
			}
		}
	}
}
