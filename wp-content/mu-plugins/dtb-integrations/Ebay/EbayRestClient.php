<?php
/**
 * eBay — EbayRestClient
 *
 * Low-level HTTP client for eBay REST APIs.
 * Injects ****** authentication and handles 401 retry, 429 back-off, and structured errors.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_EbayRestClient' ) ) {
	final class DTB_EbayRestClient {

		/**
		 * Make an authenticated eBay REST API request.
		 *
		 * @param string $method   HTTP method.
		 * @param string $path     API path, e.g. '/sell/fulfillment/v1/order'.
		 * @param array  $params   Query parameters.
		 * @param array  $body     Request body.
		 * @param bool   $retried  Internal retry flag.
		 * @return array{ok: bool, status: int, data: mixed, error: string, rate_limited: bool}
		 */
		public static function request( string $method, string $path, array $params = [], array $body = [], bool $retried = false ): array {
			if ( ! DTB_EbayConfig::is_configured() ) {
				return self::error( 503, 'eBay API not configured.' );
			}

			$token = DTB_EbayOAuthTokenService::get_access_token();
			if ( '' === $token ) {
				return self::error( 401, 'Could not obtain eBay access token.' );
			}

			$url = DTB_EbayConfig::api_base() . $path;
			if ( ! empty( $params ) ) {
				$url = add_query_arg( $params, $url );
			}

			$args = [
				'method'  => strtoupper( $method ),
				'timeout' => 25,
				'headers' => [
					'Authorization'         => 'Bearer ' . $token,
					'Content-Type'          => 'application/json',
					'Accept'                => 'application/json',
					'X-EBAY-C-MARKETPLACE-ID' => DTB_EbayConfig::get()['marketplace_id'],
				],
			];
			if ( ! empty( $body ) ) {
				$args['body'] = wp_json_encode( $body );
			}

			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				return self::error( 503, 'eBay HTTP error: ' . $response->get_error_message() );
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$raw  = wp_remote_retrieve_body( $response );
			$data = json_decode( $raw, true );

			if ( 429 === $code ) {
				$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' ) ?: 60;
				if ( class_exists( 'DTB_MarketplaceRateLimitState' ) ) {
					DTB_MarketplaceRateLimitState::record_throttle( DTB_CHANNEL_EBAY, 'rest_api', $retry_after );
				}
				DTB_MarketplaceExceptionService::create(
					DTB_MarketplaceExceptionService::CAT_RATE_LIMIT,
					DTB_CHANNEL_EBAY,
					'ebay_rate_limit',
					'eBay rate limit reached. Retry in ' . $retry_after . 's.',
					[ 'is_retryable' => true ]
				);
				return array_merge( self::error( 429, 'eBay rate limit reached.' ), [ 'rate_limited' => true ] );
			}

			if ( 401 === $code && ! $retried ) {
				DTB_EbayOAuthTokenService::invalidate();
				return self::request( $method, $path, $params, $body, true );
			}

			if ( $code < 200 || $code >= 300 ) {
				$err = self::extract_error( $data );
				return self::error( $code, $err );
			}

			DTB_MarketplaceReadModels::upsert_channel( DTB_CHANNEL_EBAY, [
				'last_sync_at' => current_time( 'mysql', true ),
				'health_state' => 'ok',
				'auth_state'   => 'connected',
			] );

			return [ 'ok' => true, 'status' => $code, 'data' => $data, 'error' => '', 'rate_limited' => false ];
		}

		private static function error( int $status, string $message ): array {
			error_log( '[DTB][eBay][RestClient] Error ' . $status . ': ' . $message );
			return [ 'ok' => false, 'status' => $status, 'data' => null, 'error' => $message, 'rate_limited' => false ];
		}

		private static function extract_error( mixed $data ): string {
			if ( is_array( $data ) ) {
				if ( ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
					$first = $data['errors'][0] ?? [];
					return sanitize_text_field( (string) ( $first['message'] ?? $first['errorId'] ?? 'eBay error' ) );
				}
				return sanitize_text_field( (string) ( $data['message'] ?? $data['error'] ?? 'eBay error' ) );
			}
			return 'eBay returned unexpected response.';
		}
	}
}
