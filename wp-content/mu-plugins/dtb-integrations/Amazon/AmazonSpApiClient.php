<?php
/**
 * Amazon — AmazonSpApiClient
 *
 * Low-level HTTP client for Amazon Selling Partner API.
 * Handles authentication header injection, 401 retry with token refresh,
 * rate-limit detection, and structured error returns.
 *
 * Never logs access tokens or secrets.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_AmazonSpApiClient' ) ) {
	final class DTB_AmazonSpApiClient {

		/**
		 * Make an authenticated SP-API request.
		 *
		 * @param string $method  HTTP method: GET, POST, PUT, PATCH, DELETE.
		 * @param string $path    API path, e.g. '/orders/v0/orders'.
		 * @param array  $params  Query parameters.
		 * @param array  $body    Request body (will be JSON-encoded).
		 * @param bool   $retried Internal flag to prevent infinite retry loop.
		 * @return array{ok: bool, status: int, data: mixed, error: string, rate_limited: bool}
		 */
		public static function request( string $method, string $path, array $params = [], array $body = [], bool $retried = false ): array {
			if ( ! DTB_AmazonConfig::is_configured() ) {
				return self::error( 503, 'Amazon SP-API not configured.' );
			}

			$token = DTB_AmazonLwaTokenService::get_access_token();
			if ( '' === $token ) {
				return self::error( 401, 'Could not obtain LWA access token.' );
			}

			$cfg      = DTB_AmazonConfig::get();
			$base_url = DTB_AmazonConfig::api_base();
			$url      = $base_url . $path;
			if ( ! empty( $params ) ) {
				$url = add_query_arg( $params, $url );
			}

			$args = [
				'method'  => strtoupper( $method ),
				'timeout' => 25,
				'headers' => [
					'x-amz-access-token' => $token,
					'Content-Type'       => 'application/json',
					'Accept'             => 'application/json',
				],
			];
			if ( ! empty( $body ) ) {
				$args['body'] = wp_json_encode( $body );
			}

			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				return self::error( 503, 'SP-API HTTP error: ' . $response->get_error_message() );
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$raw  = wp_remote_retrieve_body( $response );
			$data = json_decode( $raw, true );

			// 429 → rate limited.
			if ( 429 === $code ) {
				$retry_after = (int) ( wp_remote_retrieve_header( $response, 'x-amzn-ratelimit-limit' ) ?: 60 );
				if ( class_exists( 'DTB_MarketplaceRateLimitState' ) ) {
					DTB_MarketplaceRateLimitState::record_throttle( DTB_CHANNEL_AMAZON, 'sp_api', $retry_after );
				}
				return array_merge( self::error( 429, 'Amazon SP-API rate limit reached.' ), [ 'rate_limited' => true ] );
			}

			// 401 → invalidate token and retry once.
			if ( 401 === $code && ! $retried ) {
				DTB_AmazonLwaTokenService::invalidate();
				return self::request( $method, $path, $params, $body, true );
			}

			if ( $code < 200 || $code >= 300 ) {
				$err = self::extract_error_message( $data );
				return self::error( $code, $err );
			}

			// Update last_sync on channel.
			if ( class_exists( 'DTB_MarketplaceReadModels' ) ) {
				DTB_MarketplaceReadModels::upsert_channel( DTB_CHANNEL_AMAZON, [
					'last_sync_at' => current_time( 'mysql', true ),
					'health_state' => 'ok',
					'auth_state'   => 'connected',
				] );
			}

			return [ 'ok' => true, 'status' => $code, 'data' => $data, 'error' => '', 'rate_limited' => false ];
		}

		// ── Helpers ───────────────────────────────────────────────────────────

		private static function error( int $status, string $message ): array {
			error_log( '[DTB][Amazon][SpApi] Error ' . $status . ': ' . $message );
			return [ 'ok' => false, 'status' => $status, 'data' => null, 'error' => $message, 'rate_limited' => false ];
		}

		private static function extract_error_message( mixed $data ): string {
			if ( is_array( $data ) ) {
				if ( ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
					$first = $data['errors'][0] ?? [];
					return sanitize_text_field( (string) ( $first['message'] ?? $first['code'] ?? 'SP-API error' ) );
				}
				return sanitize_text_field( (string) ( $data['message'] ?? $data['error'] ?? 'SP-API error' ) );
			}
			return 'SP-API returned unexpected response.';
		}
	}
}
