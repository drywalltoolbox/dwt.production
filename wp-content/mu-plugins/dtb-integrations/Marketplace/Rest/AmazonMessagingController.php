<?php
/**
 * Marketplace REST — AmazonMessagingController
 *
 * Routes:
 *   GET  /dtb/v1/admin/marketplace/amazon/messaging-actions/{order_id}
 *        — fetch allowed actions for an Amazon order (must be called before sending)
 *   POST /dtb/v1/admin/marketplace/amazon/messages
 *        — queue/send an outbound Amazon message (action must be in allowed list)
 *
 * Capability: dtb_manage_marketplace
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_AmazonMessagingController' ) ) {
	final class DTB_AmazonMessagingController extends DTB_AbstractRestController {

		public static function register_routes(): void {
			register_rest_route( 'dtb/v1', '/admin/marketplace/amazon/messaging-actions/(?P<order_id>[A-Z0-9\-]+)', [
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_actions' ],
				'permission_callback' => [ self::class, 'check_permission' ],
				'args'                => [
					'order_id' => [ 'sanitize_callback' => 'sanitize_text_field' ],
				],
			] );
			register_rest_route( 'dtb/v1', '/admin/marketplace/amazon/messages', [
				'methods'             => 'POST',
				'callback'            => [ self::class, 'send_message' ],
				'permission_callback' => [ self::class, 'check_permission' ],
			] );
		}

		public static function check_permission(): bool {
			return current_user_can( 'dtb_manage_marketplace' ) && is_user_logged_in();
		}

		/**
		 * Get allowed messaging actions for an Amazon order.
		 */
		public static function get_actions( WP_REST_Request $request ): WP_REST_Response {
			$amazon_order_id = sanitize_text_field( $request->get_param( 'order_id' ) );
			if ( '' === $amazon_order_id ) {
				return new WP_REST_Response( [ 'ok' => false, 'error' => 'Missing order_id.' ], 400 );
			}

			$result = DTB_AmazonMessagingService::get_allowed_actions( $amazon_order_id );
			return new WP_REST_Response( $result, $result['ok'] ? 200 : 422 );
		}

		/**
		 * Queue/send an Amazon outbound message.
		 *
		 * Required body params:
		 *   amazon_order_id   — Amazon order ID
		 *   action            — Action slug from allowed_actions list
		 *   allowed_actions   — Array of actions fetched in the same session
		 *   conversation_id   — Internal conversation_id
		 *   payload           — Action-specific payload
		 *   idempotency_key   — Unique key from caller
		 */
		public static function send_message( WP_REST_Request $request ): WP_REST_Response {
			$amazon_order_id  = sanitize_text_field( $request->get_param( 'amazon_order_id' ) ?? '' );
			$action           = sanitize_text_field( $request->get_param( 'action' ) ?? '' );
			$allowed_actions  = (array) $request->get_param( 'allowed_actions' );
			$conversation_id  = (int) $request->get_param( 'conversation_id' );
			$payload          = (array) $request->get_param( 'payload' );
			$idempotency_key  = sanitize_text_field( $request->get_param( 'idempotency_key' ) ?? '' );

			if ( '' === $amazon_order_id || '' === $action || empty( $allowed_actions ) || ! $conversation_id ) {
				return new WP_REST_Response( [ 'ok' => false, 'error' => 'Missing required parameters.' ], 400 );
			}

			if ( '' === $idempotency_key ) {
				$idempotency_key = wp_generate_uuid4();
			}

			$result = DTB_AmazonMessagingService::send_message(
				$amazon_order_id,
				$action,
				$allowed_actions,
				$payload,
				$idempotency_key,
				$conversation_id,
				get_current_user_id()
			);

			DTB_MarketplaceAuditService::write( 'amazon.message.send', 'marketplace_message', $result['message_id'] ?? 0, DTB_CHANNEL_AMAZON, [
				'after' => [ 'action' => $action, 'order_id' => $amazon_order_id, 'result' => $result['ok'] ],
			] );

			return new WP_REST_Response( $result, $result['ok'] ? 200 : 422 );
		}
	}
}

add_action( 'rest_api_init', [ 'DTB_AmazonMessagingController', 'register_routes' ] );
