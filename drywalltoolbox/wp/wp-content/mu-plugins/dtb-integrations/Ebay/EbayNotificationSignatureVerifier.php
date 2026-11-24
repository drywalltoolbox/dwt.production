<?php
/**
 * eBay Event Notification signature verification.
 *
 * Implements the current eBay PHP SDK verification contract while using the
 * WordPress HTTP API and transient cache already available to this MU plugin.
 * The signature shape and ECDSA/SHA-1 verification follow eBay's published
 * Event Notification PHP SDK.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_EbayNotificationSignatureVerifier' ) ) {
	final class DTB_EbayNotificationSignatureVerifier {
		private const KEY_CACHE_TTL = HOUR_IN_SECONDS;
		private const APPLICATION_TOKEN_CACHE_TTL_MAX = 3600;
		private const MAX_BODY_BYTES = 65536;
		private const MAX_SIGNATURE_HEADER_BYTES = 4096;

		/**
		 * Validate a notification signature and return the decoded payload.
		 *
		 * @return array|WP_Error Decoded payload on success, error otherwise.
		 */
		public static function verify( string $body, string $signature_header ) {
			if ( '' === $body || strlen( $body ) > self::MAX_BODY_BYTES || '' === $signature_header || strlen( $signature_header ) > self::MAX_SIGNATURE_HEADER_BYTES ) {
				return new WP_Error( 'ebay_signature_input_invalid', 'Notification signature input is invalid.' );
			}

			$signature_json = base64_decode( $signature_header, true );
			if ( false === $signature_json ) {
				return new WP_Error( 'ebay_signature_invalid', 'Notification signature is invalid.' );
			}

			$signature = json_decode( $signature_json, true );
			if ( ! is_array( $signature )
				|| 'ecdsa' !== strtolower( (string) ( $signature['alg'] ?? '' ) )
				|| 'SHA1' !== strtoupper( (string) ( $signature['digest'] ?? '' ) )
				|| ! preg_match( '/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/i', (string) ( $signature['kid'] ?? '' ) )
				|| ! is_string( $signature['signature'] ?? null )
			) {
				return new WP_Error( 'ebay_signature_invalid', 'Notification signature is invalid.' );
			}

			$payload = json_decode( $body, true );
			if ( ! is_array( $payload ) ) {
				return new WP_Error( 'ebay_signature_invalid', 'Notification payload is invalid.' );
			}

			$signature_bytes = base64_decode( $signature['signature'], true );
			if ( false === $signature_bytes || '' === $signature_bytes ) {
				return new WP_Error( 'ebay_signature_invalid', 'Notification signature is invalid.' );
			}

			if ( ! function_exists( 'openssl_pkey_get_public' ) || ! function_exists( 'openssl_verify' ) ) {
				return new WP_Error( 'ebay_notification_crypto_unavailable', 'eBay notification verification is temporarily unavailable.', [ 'status' => 503 ] );
			}

			$public_key = self::get_public_key( strtolower( $signature['kid'] ) );
			if ( is_wp_error( $public_key ) ) {
				return $public_key;
			}

			// The eBay Event Notification PHP SDK verifies the JSON-decoded payload
			// serialized with PHP's default json_encode behavior.
			$signed_payload = json_encode( $payload );
			if ( ! is_string( $signed_payload ) ) {
				return new WP_Error( 'ebay_signature_payload_invalid', 'Notification payload could not be verified.' );
			}

			$key_resource = openssl_pkey_get_public( $public_key );
			if ( false === $key_resource ) {
				return new WP_Error( 'ebay_signature_key_invalid', 'Notification public key is invalid.' );
			}

			$key_details = openssl_pkey_get_details( $key_resource );
			if ( ! is_array( $key_details ) || OPENSSL_KEYTYPE_EC !== ( $key_details['type'] ?? null ) ) {
				return new WP_Error( 'ebay_signature_key_invalid', 'Notification public key is invalid.' );
			}

			$verified = openssl_verify( $signed_payload, $signature_bytes, $key_resource, OPENSSL_ALGO_SHA1 );
			if ( 1 !== $verified ) {
				return new WP_Error( 'ebay_signature_invalid', 'Notification signature is invalid.' );
			}

			return $payload;
		}

		/** Retrieve a cached public key or fetch it from eBay's fixed API host. */
		private static function get_public_key( string $key_id ) {
			$cache_key = 'dtb_ebay_notification_key_' . md5( $key_id );
			$cached    = get_transient( $cache_key );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}

			if ( ! DTB_EbayConfig::is_configured() ) {
				return new WP_Error( 'ebay_notification_unconfigured', 'eBay notification verification is unavailable.', [ 'status' => 503 ] );
			}

			if ( class_exists( 'DTB_MarketplaceRateLimitState' ) && ! DTB_MarketplaceRateLimitState::allow( DTB_CHANNEL_EBAY, 'notification_key_lookup', 20, 60 ) ) {
				return new WP_Error( 'ebay_notification_rate_limited', 'eBay notification verification is temporarily rate limited.', [ 'status' => 503 ] );
			}

			$token = self::get_application_token();
			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$url      = DTB_EbayConfig::api_base() . '/commerce/notification/v1/public_key/' . rawurlencode( $key_id );
			$response = wp_safe_remote_get(
				$url,
				[
					'timeout'             => 8,
					'redirection'         => 0,
					'reject_unsafe_urls'  => true,
					'limit_response_size' => 8192,
					'headers'             => [
						'Authorization' => 'Bearer ' . $token,
						'Accept'        => 'application/json',
					],
				]
			);

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return new WP_Error( 'ebay_notification_key_fetch_failed', 'eBay notification verification is temporarily unavailable.', [ 'status' => 503 ] );
			}

			$key_data  = json_decode( wp_remote_retrieve_body( $response ), true );
			$key_value = is_array( $key_data ) ? self::normalize_public_key( (string) ( $key_data['key'] ?? '' ) ) : '';
			if ( '' === $key_value ) {
				return new WP_Error( 'ebay_notification_key_invalid', 'eBay returned an invalid notification public key.', [ 'status' => 503 ] );
			}

			set_transient( $cache_key, $key_value, self::KEY_CACHE_TTL );
			return $key_value;
		}

		/** Obtain an application token with only the base eBay API scope. */
		private static function get_application_token() {
			$config    = DTB_EbayConfig::get();
			$cache_key = 'dtb_ebay_notification_token_' . md5( ( $config['sandbox'] ? 'sandbox:' : 'production:' ) . $config['client_id'] );
			$cached    = get_transient( $cache_key );
			if ( is_string( $cached ) && '' !== $cached && class_exists( 'DTB_MarketplaceCredentialFacade' ) ) {
				$token = DTB_MarketplaceCredentialFacade::decrypt( $cached );
				if ( is_string( $token ) && '' !== $token ) {
					return $token;
				}
			}

			$response = wp_remote_post(
				DTB_EbayConfig::token_url(),
				[
					'timeout'             => 8,
					'redirection'         => 0,
					'limit_response_size' => 8192,
					'headers'             => [
						'Authorization' => 'Basic ' . base64_encode( $config['client_id'] . ':' . $config['client_secret'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
						'Accept'        => 'application/json',
					],
					'body'                => [
						'grant_type' => 'client_credentials',
						'scope'      => 'https://api.ebay.com/oauth/api_scope',
					],
				]
			);

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return new WP_Error( 'ebay_notification_token_failed', 'eBay notification verification is temporarily unavailable.', [ 'status' => 503 ] );
			}

			$token_data = json_decode( wp_remote_retrieve_body( $response ), true );
			$token      = is_array( $token_data ) ? (string) ( $token_data['access_token'] ?? '' ) : '';
			if ( '' === $token ) {
				return new WP_Error( 'ebay_notification_token_invalid', 'eBay notification verification is temporarily unavailable.', [ 'status' => 503 ] );
			}

			$expires_in = is_array( $token_data ) ? (int) ( $token_data['expires_in'] ?? 0 ) : 0;
			if ( $expires_in > 60 && class_exists( 'DTB_MarketplaceCredentialFacade' ) ) {
				$encrypted = DTB_MarketplaceCredentialFacade::encrypt( $token );
				if ( is_string( $encrypted ) && '' !== $encrypted ) {
					set_transient( $cache_key, $encrypted, min( self::APPLICATION_TOKEN_CACHE_TTL_MAX, $expires_in - 60 ) );
				}
			}

			return $token;
		}

		/** Normalize eBay's compact PEM representation into a parseable PEM. */
		private static function normalize_public_key( string $key ): string {
			$key = trim( $key );
			if ( ! preg_match( '/\A-----BEGIN PUBLIC KEY-----(.+)-----END PUBLIC KEY-----\z/s', $key, $matches ) ) {
				return '';
			}

			$encoded = preg_replace( '/\s+/', '', $matches[1] );
			$der     = is_string( $encoded ) ? base64_decode( $encoded, true ) : false;
			if ( false === $der || '' === $der ) {
				return '';
			}

			return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
		}
	}
}
