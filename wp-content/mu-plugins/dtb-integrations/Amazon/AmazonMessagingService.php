<?php
/**
 * Amazon — AmazonMessagingService
 *
 * Implements Amazon Selling Partner Messaging API (order-scoped only).
 *
 * IMPORTANT: Amazon messaging is strictly order-scoped.
 * Before any outbound action the operator MUST call get_allowed_actions()
 * for the specific order and only present/send actions in the returned list.
 * Unsupported actions are blocked by ActionPolicyValidator.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_AmazonMessagingService' ) ) {
	final class DTB_AmazonMessagingService {

		/**
		 * Get allowed messaging actions for an Amazon order.
		 *
		 * Calls SP-API: GET /messaging/v1/orders/{amazonOrderId}
		 *
		 * @param string $amazon_order_id Amazon order ID.
		 * @return array{ok: bool, actions: array[], error: string}
		 */
		public static function get_allowed_actions( string $amazon_order_id ): array {
			$cfg      = DTB_AmazonConfig::get();
			$response = DTB_AmazonSpApiClient::request(
				'GET',
				'/messaging/v1/orders/' . rawurlencode( $amazon_order_id ),
				[ 'marketplaceIds' => $cfg['marketplace_id'] ]
			);

			if ( ! $response['ok'] ) {
				return [ 'ok' => false, 'actions' => [], 'error' => $response['error'] ];
			}

			// Cache per-order allowed actions for this request lifecycle.
			$actions = $response['data']['_links']['actions'] ?? [];
			// Normalize: each action may be a string or array with 'name'.
			$action_names = array_map( static function ( $a ) {
				return is_array( $a ) ? ( $a['name'] ?? (string) $a ) : (string) $a;
			}, $actions );

			return [ 'ok' => true, 'actions' => $action_names, 'error' => '' ];
		}

		/**
		 * Send a buyer message for an Amazon order.
		 * The allowed_actions list must have been freshly fetched for this order.
		 *
		 * @param string $amazon_order_id   Amazon order ID.
		 * @param string $action            Action slug, e.g. 'SendInvoice'.
		 * @param array  $allowed_actions   Actions from get_allowed_actions().
		 * @param array  $payload           Action-specific payload fields.
		 * @param string $idempotency_key   Caller-provided idempotency key.
		 * @param int    $conversation_id   Internal conversation_id.
		 * @param int    $operator_id       WP user ID.
		 * @return array{ok: bool, message_id: int, error: string}
		 */
		public static function send_message(
			string $amazon_order_id,
			string $action,
			array $allowed_actions,
			array $payload,
			string $idempotency_key,
			int $conversation_id,
			int $operator_id
		): array {
			// Validate action against allowed list.
			$policy = DTB_MarketplaceActionPolicyValidator::validate_amazon( $action, $allowed_actions );
			if ( ! $policy['ok'] ) {
				// Record unsupported-action exception.
				DTB_MarketplaceExceptionService::create(
					DTB_MarketplaceExceptionService::CAT_UNSUPPORTED_ACTION,
					DTB_CHANNEL_AMAZON,
					'unsupported_action',
					$policy['reason'],
					[ 'is_retryable' => false, 'context' => [ 'action' => $action, 'order_id' => $amazon_order_id ] ]
				);
				return [ 'ok' => false, 'message_id' => 0, 'error' => $policy['reason'] ];
			}

			// Build outbound message row (queued).
			$body_text = sanitize_textarea_field( $payload['text'] ?? $payload['body'] ?? '' );
			$msg_data  = DTB_MarketplaceMessageNormalizer::build_outbound(
				$conversation_id,
				$body_text,
				$action,
				$operator_id,
				$idempotency_key
			);
			$message_id = DTB_MarketplaceReadModels::insert_message( $msg_data );

			DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_REPLY_QUEUED, DTB_CHANNEL_AMAZON, [
				'linked_conversation_id' => $conversation_id,
				'linked_message_id'      => $message_id,
				'payload'                => [ 'action' => $action, 'order_id' => $amazon_order_id ],
			] );

			// Dispatch to Action Scheduler / WP-Cron.
			self::schedule_send( $message_id, $amazon_order_id, $action, $payload, $idempotency_key );

			return [ 'ok' => true, 'message_id' => $message_id, 'error' => '' ];
		}

		/**
		 * Execute the actual SP-API send call (called by queue job).
		 *
		 * @param int    $message_id       Internal message ID.
		 * @param string $amazon_order_id  Amazon order ID.
		 * @param string $action           Action slug.
		 * @param array  $payload          Action payload.
		 * @param string $idempotency_key  Idempotency key.
		 */
		public static function execute_send( int $message_id, string $amazon_order_id, string $action, array $payload, string $idempotency_key ): void {
			global $wpdb;
			$table = $wpdb->prefix . 'dtb_marketplace_messages';

			$cfg      = DTB_AmazonConfig::get();
			$response = DTB_AmazonSpApiClient::request(
				'POST',
				'/messaging/v1/orders/' . rawurlencode( $amazon_order_id ) . '/' . $action,
				[ 'marketplaceIds' => $cfg['marketplace_id'] ],
				$payload
			);

			$now = current_time( 'mysql', true );

			if ( $response['ok'] ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( $table, [
					'message_status' => 'sent',
					'sent_at'        => $now,
					'updated_at'     => $now,
				], [ 'id' => $message_id ] );

				DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_REPLY_SENT, DTB_CHANNEL_AMAZON, [
					'linked_message_id' => $message_id,
					'idempotency_key'   => $idempotency_key,
					'payload'           => [ 'action' => $action, 'order_id' => $amazon_order_id ],
				] );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( $table, [
					'message_status'     => 'failed',
					'failed_at'          => $now,
					'failure_reason'     => substr( $response['error'], 0, 500 ),
					'updated_at'         => $now,
				], [ 'id' => $message_id ] );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET send_attempt_count = send_attempt_count + 1 WHERE id = %d", $message_id ) );

				DTB_MarketplaceExceptionService::create(
					DTB_MarketplaceExceptionService::CAT_MESSAGE_SEND,
					DTB_CHANNEL_AMAZON,
					'sp_api_send_failed',
					$response['error'],
					[ 'is_retryable' => ! $response['rate_limited'], 'linked_record_type' => 'marketplace_message', 'linked_record_id' => $message_id ]
				);

				DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_REPLY_FAILED, DTB_CHANNEL_AMAZON, [
					'linked_message_id' => $message_id,
					'payload'           => [ 'error' => $response['error'], 'action' => $action ],
				] );
			}
		}

		// ── Private helpers ───────────────────────────────────────────────────

		private static function schedule_send( int $message_id, string $amazon_order_id, string $action, array $payload, string $idempotency_key ): void {
			$hook = 'dtb_amazon_message_send';
			$args = [ $message_id, $amazon_order_id, $action, $payload, $idempotency_key ];

			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + 5, $hook, [ $args ], 'dtb-marketplace' );
			} else {
				wp_schedule_single_event( time() + 5, $hook, $args );
			}
		}
	}
}
