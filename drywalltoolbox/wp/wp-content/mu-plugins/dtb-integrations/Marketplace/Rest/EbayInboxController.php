<?php
/**
 * Marketplace REST — EbayInboxController
 *
 * Routes:
 *   POST /dtb/v1/admin/marketplace/ebay/replies
 *        — queue an eBay buyer message reply
 *   GET  /dtb/v1/admin/marketplace/ebay/oauth-url
 *        — return the eBay OAuth authorization URL for initial connect
 *   GET  /dtb/v1/admin/marketplace/ebay/oauth-callback
 *        — exchange OAuth code for tokens
 *
 * Capability: dtb_manage_marketplace
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_EbayInboxController' ) ) {
	final class DTB_EbayInboxController extends DTB_AbstractRestController {
		private const OAUTH_STATE_TTL = 600;

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
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'oauth_callback' ],
					'permission_callback' => '__return_true',
				],
				[
					'methods'             => 'POST',
					'callback'            => [ self::class, 'oauth_callback' ],
					'permission_callback' => [ self::class, 'check_settings_permission' ],
				],
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
			$body             = sanitize_textarea_field( $request->get_param( 'body' ) ?? '' );
			$idempotency_key  = sanitize_text_field( $request->get_param( 'idempotency_key' ) ?? wp_generate_uuid4() );

			if ( ! $conversation_id || '' === $body ) {
				return new WP_REST_Response( [ 'ok' => false, 'error' => 'conversation_id and body are required.' ], 400 );
			}

			$result = DTB_EbayMessageService::queue_reply(
				$conversation_id,
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
			try {
				$state = bin2hex( random_bytes( 32 ) );
			} catch ( Throwable $error ) {
				return new WP_REST_Response( [ 'ok' => false, 'error' => 'Could not start a secure eBay authorization session.' ], 503 );
			}
			$session_token = wp_get_session_token();
			if ( '' === $session_token ) {
				return new WP_REST_Response( [ 'ok' => false, 'error' => 'The signed-in WordPress session is unavailable.' ], 403 );
			}

			$transaction = [
				'user_id'          => get_current_user_id(),
				'session_hash'     => hash( 'sha256', $session_token ),
				'environment'      => $cfg['sandbox'] ? 'sandbox' : 'production',
				'redirect_uri'     => $cfg['redirect_uri'],
				'created_at'       => time(),
			];
			if ( ! set_transient( self::oauth_state_key( $state ), $transaction, self::OAUTH_STATE_TTL ) ) {
				return new WP_REST_Response( [ 'ok' => false, 'error' => 'Could not store the eBay authorization state.' ], 503 );
			}

			$params = [
				'client_id'     => $cfg['client_id'],
				'redirect_uri'  => $cfg['redirect_uri'],
				'response_type' => 'code',
				'scope'         => 'https://api.ebay.com/oauth/api_scope https://api.ebay.com/oauth/api_scope/sell.fulfillment https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly https://api.ebay.com/oauth/api_scope/commerce.message https://api.ebay.com/oauth/api_scope/commerce.notification.subscription',
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
			$code       = sanitize_text_field( $request->get_param( 'code' ) ?? '' );
			$state      = sanitize_text_field( $request->get_param( 'state' ) ?? '' );
			if ( ! preg_match( '/\A[a-f0-9]{64}\z/i', $state ) ) {
				return new WP_REST_Response( [ 'ok' => false, 'error' => 'Invalid state parameter.' ], 403 );
			}

			$state_key = self::oauth_state_key( $state );
			$lock_key  = self::oauth_state_lock_key( $state );
			if ( ! add_option( $lock_key, time(), '', false ) ) {
				return new WP_REST_Response( [ 'ok' => false, 'error' => 'This eBay authorization response is already being processed.' ], 409 );
			}

			try {
				$session     = wp_get_session_token();
				$transaction = get_transient( $state_key );
				$config      = DTB_EbayConfig::get();

				if ( ! is_array( $transaction )
					|| time() - (int) ( $transaction['created_at'] ?? 0 ) > self::OAUTH_STATE_TTL
					|| '' === $session
					|| (int) ( $transaction['user_id'] ?? 0 ) !== get_current_user_id()
					|| ! hash_equals( (string) ( $transaction['session_hash'] ?? '' ), hash( 'sha256', $session ) )
					|| ! current_user_can( 'dtb_manage_marketplace_settings' )
					|| (string) ( $transaction['environment'] ?? '' ) !== ( $config['sandbox'] ? 'sandbox' : 'production' )
					|| (string) ( $transaction['redirect_uri'] ?? '' ) !== $config['redirect_uri']
				) {
					return new WP_REST_Response( [ 'ok' => false, 'error' => 'Invalid state parameter.' ], 403 );
				}
				delete_transient( $state_key );

				$provider_error = sanitize_key( (string) ( $request->get_param( 'error' ) ?? '' ) );
				if ( '' !== $provider_error ) {
					return new WP_REST_Response( [ 'ok' => false, 'error' => 'eBay authorization was declined or failed.' ], 400 );
				}
				if ( '' === $code ) {
					return new WP_REST_Response( [ 'ok' => false, 'error' => 'Missing authorization code.' ], 400 );
				}

				$result = DTB_EbayOAuthTokenService::exchange_auth_code( $code );

				DTB_MarketplaceAuditService::write( 'ebay.oauth.connected', 'marketplace_channel', 0, DTB_CHANNEL_EBAY, [
					'after' => [ 'result' => $result['ok'] ],
				] );

				return new WP_REST_Response( $result, $result['ok'] ? 200 : 422 );
			} finally {
				delete_option( $lock_key );
			}
		}

		/** Hash OAuth state before using it as a transient key. */
		private static function oauth_state_key( string $state ): string {
			return 'dtb_ebay_oauth_state_' . hash( 'sha256', $state );
		}

		/** Serialize callback exchange attempts for a single state transaction. */
		private static function oauth_state_lock_key( string $state ): string {
			return 'dtb_ebay_oauth_lock_' . hash( 'sha256', $state );
		}
	}
}

add_action( 'rest_api_init', [ 'DTB_EbayInboxController', 'register_routes' ] );
