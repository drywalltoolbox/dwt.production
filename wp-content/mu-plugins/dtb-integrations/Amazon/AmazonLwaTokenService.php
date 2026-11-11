<?php
/**
 * Amazon — AmazonLwaTokenService
 *
 * Manages LWA (Login with Amazon) access token lifecycle:
 *   - Exchanges refresh token for short-lived access token
 *   - Caches access token in transient (token TTL - 60s buffer)
 *   - Detects expiry and triggers exception + token_refresh event
 *   - Never exposes tokens in logs or REST responses
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_AmazonLwaTokenService' ) ) {
	final class DTB_AmazonLwaTokenService {

		private const TRANSIENT_ACCESS  = 'dtb_amazon_lwa_access_token';
		private const TRANSIENT_EXPIRES = 'dtb_amazon_lwa_expires_at';

		/**
		 * Return a valid access token, refreshing when expired or absent.
		 *
		 * @return string Access token, or empty string on failure.
		 */
		public static function get_access_token(): string {
			// Return cached token if still valid (with 60s buffer).
			$cached  = (string) get_transient( self::TRANSIENT_ACCESS );
			$expires = (int) get_transient( self::TRANSIENT_EXPIRES );
			if ( '' !== $cached && $expires > time() + 60 ) {
				return $cached;
			}

			return self::refresh();
		}

		/**
		 * Force a token refresh.
		 *
		 * @return string New access token, or empty string on failure.
		 */
		public static function refresh(): string {
			$cfg = DTB_AmazonConfig::get();

			if ( '' === $cfg['client_id'] || '' === $cfg['client_secret'] || '' === $cfg['refresh_token'] ) {
				self::record_auth_failure( 'LWA credentials not configured.' );
				return '';
			}

			$response = wp_remote_post(
				DTB_AmazonConfig::LWA_TOKEN_URL,
				[
					'timeout' => 15,
					'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
					'body'    => [
						'grant_type'    => 'refresh_token',
						'refresh_token' => $cfg['refresh_token'],
						'client_id'     => $cfg['client_id'],
						'client_secret' => $cfg['client_secret'],
					],
				]
			);

			if ( is_wp_error( $response ) ) {
				self::record_auth_failure( 'LWA HTTP error: ' . $response->get_error_message() );
				return '';
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];

			if ( 200 !== (int) $code ) {
				$err = sanitize_text_field( (string) ( $body['error_description'] ?? $body['error'] ?? 'Unknown error' ) );
				self::record_auth_failure( 'LWA token refresh failed (' . $code . '): ' . $err );
				return '';
			}

			$token   = (string) ( $body['access_token'] ?? '' );
			$ttl     = max( 60, (int) ( $body['expires_in'] ?? 3600 ) );
			$expires = time() + $ttl;

			if ( '' === $token ) {
				self::record_auth_failure( 'LWA response missing access_token.' );
				return '';
			}

			// Store with TTL buffer.
			set_transient( self::TRANSIENT_ACCESS,  $token,   $ttl - 30 );
			set_transient( self::TRANSIENT_EXPIRES, $expires, $ttl - 30 );

			// Update channel auth state.
			if ( class_exists( 'DTB_MarketplaceReadModels' ) ) {
				DTB_MarketplaceReadModels::upsert_channel( DTB_CHANNEL_AMAZON, [
					'auth_state'   => 'connected',
					'health_state' => 'ok',
					'last_error_msg' => '',
				] );
			}

			if ( class_exists( 'DTB_MarketplaceEventService' ) ) {
				DTB_MarketplaceEventService::append(
					DTB_MarketplaceEventService::EVT_TOKEN_REFRESHED,
					DTB_CHANNEL_AMAZON,
					[ 'payload' => [ 'expires_in' => $ttl ] ]
				);
			}

			return $token;
		}

		/**
		 * Invalidate the cached token (e.g. after a 401 from SP-API).
		 */
		public static function invalidate(): void {
			delete_transient( self::TRANSIENT_ACCESS );
			delete_transient( self::TRANSIENT_EXPIRES );
		}

		// ── Private helpers ───────────────────────────────────────────────────

		private static function record_auth_failure( string $message ): void {
			// Never log the token or secret values.
			error_log( '[DTB][Amazon] Auth failure: ' . $message );

			if ( class_exists( 'DTB_MarketplaceReadModels' ) ) {
				DTB_MarketplaceReadModels::upsert_channel( DTB_CHANNEL_AMAZON, [
					'auth_state'    => 'auth_failed',
					'health_state'  => 'error',
					'last_error_at' => current_time( 'mysql', true ),
					'last_error_msg' => substr( $message, 0, 500 ),
				] );
			}

			if ( class_exists( 'DTB_MarketplaceExceptionService' ) ) {
				DTB_MarketplaceExceptionService::create(
					DTB_MarketplaceExceptionService::CAT_AUTH_FAILURE,
					DTB_CHANNEL_AMAZON,
					'lwa_token_refresh_failed',
					$message,
					[ 'is_retryable' => true ]
				);
			}

			if ( class_exists( 'DTB_MarketplaceEventService' ) ) {
				DTB_MarketplaceEventService::append(
					DTB_MarketplaceEventService::EVT_TOKEN_EXPIRED,
					DTB_CHANNEL_AMAZON,
					[ 'payload' => [ 'reason' => $message ] ]
				);
			}
		}
	}
}
