<?php
/**
 * Marketplace REST — EbayInboxController
 *
 * Routes:
 *   POST /dtb/v1/admin/marketplace/ebay/replies
 *        — queue an eBay buyer message reply
 *   GET  /dtb/v1/admin/marketplace/ebay/oauth-url
 *        — return the eBay OAuth authorization URL for initial connect
 *   POST /dtb/v1/admin/marketplace/ebay/oauth-callback
 *        — exchange OAuth code for tokens
 *
 * Capability: dtb_manage_marketplace
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_EbayInboxController' ) ) {
	final class DTB_EbayInboxController extends DTB_AbstractRestController {

		public static function register_routes(): void {
			register_rest_route( 'dtb/v1', '/admin/marketplace/ebay/replies', [
				'methods'             => 'POST',
				'callback'            => [ self::class, 'queue_reply' ],
				'permission_callback' => [ self::class, 'check_permission' ],
			] );
			register_rest_route( 'dtb/v1', '/admin/marketplace/ebay/oauth-url', [
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_oauth_url' ],
				'permission_callback' => [ self::class, 'check_settings_permission' ],
			] );
			register_rest_route( 'dtb/v1', '/admin/marketplace/ebay/oauth-callback', [
				'methods'             => 'POST',
				'callback'            => [ self::class, 'oauth_callback' ],
				'permission_callback' => [ self::class, 'check_settings_permission' ],
			] );
		}

		public static function check_permission(): bool {
			return current_user_can( 'dtb_manage_marketplace' ) && is_user_logged_in();
		}

		public static function check_settings_permission(): bool {
			return current_user_can( 'dtb_manage_marketplace_settings' ) && is_user_logged_in();
		}

		/**
		 * Queue an eBay reply.
		 */
		public static function queue_reply( WP_REST_Request $request ): WP_REST_Response {
			$conversation_id  = (int) $request->get_param( 'conversation_id' );
			$buyer_username   = sanitize_text_field( $request->get_param( 'buyer_username' ) ?? '' );
			$item_id          = sanitize_text_field( $request->get_param( 'item_id' ) ?? '' );
			$order_id         = sanitize_text_field( $request->get_param( 'order_id' ) ?? '' );
			$body             = sanitize_textarea_field( $request->get_param( 'body' ) ?? '' );
			$idempotency_key  = sanitize_text_field( $request->get_param( 'idempotency_key' ) ?? wp_generate_uuid4() );

			if ( ! $conversation_id || '' === $buyer_username ) {
				return new WP_REST_Response( [ 'ok' => false, 'error' => 'conversation_id and buyer_username are required.' ], 400 );
			}

			$result = DTB_EbayMessageService::queue_reply(
				$conversation_id,
				$buyer_username,
				$item_id,
				$order_id,
				$body,
				get_current_user_id(),
				$idempotency_key
			);

			DTB_MarketplaceAuditService::write( 'ebay.reply.queued', 'marketplace_message', $result['message_id'] ?? 0, DTB_CHANNEL_EBAY, [
				'after' => [ 'conversation_id' => $conversation_id, 'result' => $result['ok'] ],
			] );

			return new WP_REST_Response( $result, $result['ok'] ? 200 : 422 );
		}

		/**
		 * Return the eBay OAuth authorization URL (admin redirect the operator's browser).
		 */
		public static function get_oauth_url( WP_REST_Request $request ): WP_REST_Response {
			$cfg   = DTB_EbayConfig::get();
			$state = wp_create_nonce( 'dtb_ebay_oauth' );

			$params = [
				'client_id'     => $cfg['client_id'],
				'redirect_uri'  => $cfg['redirect_uri'],
				'response_type' => 'code',
				'scope'         => 'https://api.ebay.com/oauth/api_scope https://api.ebay.com/oauth/api_scope/sell.fulfillment https://api.ebay.com/oauth/api_scope/sell.messaging',
				'state'         => $state,
			];

			$auth_url = $cfg['sandbox']
				? DTB_EbayConfig::OAUTH_AUTH_URL_SANDBOX
				: DTB_EbayConfig::OAUTH_AUTH_URL_PROD;

			return new WP_REST_Response( [
				'url' => add_query_arg( $params, $auth_url ),
			], 200 );
		}

		/**
		 * Exchange authorization code for tokens (operator completes OAuth flow).
		 */
		public static function oauth_callback( WP_REST_Request $request ): WP_REST_Response {
			$code  = sanitize_text_field( $request->get_param( 'code' ) ?? '' );
			$state = sanitize_text_field( $request->get_param( 'state' ) ?? '' );

			if ( '' === $code ) {
				return new WP_REST_Response( [ 'ok' => false, 'error' => 'Missing code.' ], 400 );
			}

			if ( ! wp_verify_nonce( $state, 'dtb_ebay_oauth' ) ) {
				return new WP_REST_Response( [ 'ok' => false, 'error' => 'Invalid state parameter.' ], 403 );
			}

			$result = DTB_EbayOAuthTokenService::exchange_auth_code( $code );

			DTB_MarketplaceAuditService::write( 'ebay.oauth.connected', 'marketplace_channel', 0, DTB_CHANNEL_EBAY, [
				'after' => [ 'result' => $result['ok'] ],
			] );

			return new WP_REST_Response( $result, $result['ok'] ? 200 : 422 );
		}
	}
}

add_action( 'rest_api_init', [ 'DTB_EbayInboxController', 'register_routes' ] );
