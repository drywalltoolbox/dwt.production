<?php
/**
 * eBay — EbayOAuthTokenService
 *
 * Manages eBay OAuth 2.0 User Token lifecycle:
 *   - Exchanges authorization code for access/refresh tokens (initial OAuth flow)
 *   - Refreshes access token using stored refresh token
 *   - Stores tokens encrypted via CredentialFacade
 *   - Emits events and exceptions on failure
 *
 * Never exposes tokens in logs or REST responses.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_EbayOAuthTokenService' ) ) {
	final class DTB_EbayOAuthTokenService {

		private const OPTION_ACCESS_ENCRYPTED  = 'dtb_ebay_access_token_enc';
		private const OPTION_EXPIRES_AT        = 'dtb_ebay_access_token_expires';
		private const OPTION_REFRESH_ENCRYPTED = 'dtb_ebay_refresh_token_enc';

		/**
		 * Return a valid access token, refreshing when expired.
		 *
		 * @return string Access token, or empty string on failure.
		 */
		public static function get_access_token(): string {
			$expires = (int) get_option( self::OPTION_EXPIRES_AT, 0 );
			$enc     = (string) get_option( self::OPTION_ACCESS_ENCRYPTED, '' );

			if ( '' !== $enc && $expires > time() + 60 ) {
				$token = DTB_MarketplaceCredentialFacade::decrypt( $enc );
				if ( false !== $token && '' !== $token ) {
					return $token;
				}
			}

			return self::refresh_access_token();
		}

		/**
		 * Refresh the access token using the stored refresh token.
		 *
		 * @return string New access token, or empty string on failure.
		 */
		public static function refresh_access_token(): string {
			$refresh_enc = (string) get_option( self::OPTION_REFRESH_ENCRYPTED, '' );
			if ( '' === $refresh_enc ) {
				self::record_auth_failure( 'eBay refresh token not stored. Complete OAuth flow first.' );
				return '';
			}

			$refresh_token = DTB_MarketplaceCredentialFacade::decrypt( $refresh_enc );
			if ( false === $refresh_token || '' === $refresh_token ) {
				self::record_auth_failure( 'eBay refresh token could not be decrypted.' );
				return '';
			}

			$cfg = DTB_EbayConfig::get();
			$basic = base64_encode( $cfg['client_id'] . ':' . $cfg['client_secret'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

			$response = wp_remote_post(
				DTB_EbayConfig::token_url(),
				[
					'timeout' => 15,
					'headers' => [
						'Authorization' => 'Basic ' . $basic,
						'Content-Type'  => 'application/x-www-form-urlencoded',
					],
					'body' => [
						'grant_type'    => 'refresh_token',
						'refresh_token' => $refresh_token,
						'scope'         => 'https://api.ebay.com/oauth/api_scope https://api.ebay.com/oauth/api_scope/sell.fulfillment https://api.ebay.com/oauth/api_scope/sell.messaging',
					],
				]
			);

			if ( is_wp_error( $response ) ) {
				self::record_auth_failure( 'eBay HTTP error: ' . $response->get_error_message() );
				return '';
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];

			if ( 200 !== $code ) {
				$err = sanitize_text_field( (string) ( $body['error_description'] ?? $body['error'] ?? 'Unknown error' ) );
				self::record_auth_failure( 'eBay token refresh failed (' . $code . '): ' . $err );
				return '';
			}

			$access_token  = (string) ( $body['access_token']  ?? '' );
			$refresh_new   = (string) ( $body['refresh_token'] ?? '' );
			$ttl           = max( 60, (int) ( $body['expires_in'] ?? 7200 ) );

			if ( '' === $access_token ) {
				self::record_auth_failure( 'eBay token response missing access_token.' );
				return '';
			}

			// Encrypt and persist.
			$enc_access = DTB_MarketplaceCredentialFacade::encrypt( $access_token );
			if ( false !== $enc_access ) {
				update_option( self::OPTION_ACCESS_ENCRYPTED, $enc_access, false );
				update_option( self::OPTION_EXPIRES_AT, time() + $ttl, false );
			}

			if ( '' !== $refresh_new ) {
				$enc_refresh = DTB_MarketplaceCredentialFacade::encrypt( $refresh_new );
				if ( false !== $enc_refresh ) {
					update_option( self::OPTION_REFRESH_ENCRYPTED, $enc_refresh, false );
				}
			}

			// Update channel state.
			if ( class_exists( 'DTB_MarketplaceReadModels' ) ) {
				DTB_MarketplaceReadModels::upsert_channel( DTB_CHANNEL_EBAY, [
					'auth_state'     => 'connected',
					'health_state'   => 'ok',
					'last_error_msg' => '',
				] );
			}

			DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_TOKEN_REFRESHED, DTB_CHANNEL_EBAY, [
				'payload' => [ 'expires_in' => $ttl ],
			] );

			return $access_token;
		}

		/**
		 * Exchange an authorization code for tokens (initial OAuth flow).
		 *
		 * @param string $code Authorization code from eBay redirect.
		 * @return array{ok: bool, error: string}
		 */
		public static function exchange_auth_code( string $code ): array {
			$cfg   = DTB_EbayConfig::get();
			$basic = base64_encode( $cfg['client_id'] . ':' . $cfg['client_secret'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

			$response = wp_remote_post(
				DTB_EbayConfig::token_url(),
				[
					'timeout' => 15,
					'headers' => [
						'Authorization' => 'Basic ' . $basic,
						'Content-Type'  => 'application/x-www-form-urlencoded',
					],
					'body' => [
						'grant_type'   => 'authorization_code',
						'code'         => $code,
						'redirect_uri' => $cfg['redirect_uri'],
					],
				]
			);

			if ( is_wp_error( $response ) ) {
				return [ 'ok' => false, 'error' => $response->get_error_message() ];
			}

			$code_status = (int) wp_remote_retrieve_response_code( $response );
			$body        = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];

			if ( 200 !== $code_status ) {
				$err = sanitize_text_field( (string) ( $body['error_description'] ?? $body['error'] ?? 'Exchange failed' ) );
				return [ 'ok' => false, 'error' => $err ];
			}

			$access_token  = (string) ( $body['access_token']  ?? '' );
			$refresh_token = (string) ( $body['refresh_token'] ?? '' );
			$ttl           = max( 60, (int) ( $body['expires_in'] ?? 7200 ) );

			if ( '' !== $access_token ) {
				$enc = DTB_MarketplaceCredentialFacade::encrypt( $access_token );
				if ( false !== $enc ) {
					update_option( self::OPTION_ACCESS_ENCRYPTED, $enc, false );
					update_option( self::OPTION_EXPIRES_AT, time() + $ttl, false );
				}
			}
			if ( '' !== $refresh_token ) {
				$enc = DTB_MarketplaceCredentialFacade::encrypt( $refresh_token );
				if ( false !== $enc ) {
					update_option( self::OPTION_REFRESH_ENCRYPTED, $enc, false );
				}
			}

			DTB_MarketplaceReadModels::upsert_channel( DTB_CHANNEL_EBAY, [
				'auth_state'   => 'connected',
				'health_state' => 'ok',
			] );

			return [ 'ok' => true, 'error' => '' ];
		}

		/**
		 * Invalidate cached access token (e.g. after 401).
		 */
		public static function invalidate(): void {
			delete_option( self::OPTION_ACCESS_ENCRYPTED );
			delete_option( self::OPTION_EXPIRES_AT );
		}

		/**
		 * Manually store a refresh token obtained outside this codebase (eBay
		 * has no in-app OAuth authorize/callback flow yet — see System Manager
		 * -> Integration Settings, where an operator pastes a token obtained
		 * via eBay's own OAuth consent flow). Clears any cached access token
		 * so the next request re-derives one from the new refresh token.
		 */
		public static function store_refresh_token( string $refresh_token ): bool {
			$refresh_token = trim( $refresh_token );
			if ( '' === $refresh_token || ! class_exists( 'DTB_MarketplaceCredentialFacade' ) ) {
				return false;
			}

			$enc = DTB_MarketplaceCredentialFacade::encrypt( $refresh_token );
			if ( false === $enc ) {
				return false;
			}

			update_option( self::OPTION_REFRESH_ENCRYPTED, $enc, false );
			self::invalidate();
			return true;
		}

		/**
		 * Whether a refresh token is currently stored (never returns the value).
		 */
		public static function has_refresh_token(): bool {
			return '' !== (string) get_option( self::OPTION_REFRESH_ENCRYPTED, '' );
		}

		// ── Private helpers ───────────────────────────────────────────────────

		private static function record_auth_failure( string $message ): void {
			error_log( '[DTB][eBay] Auth failure: ' . $message );

			if ( class_exists( 'DTB_MarketplaceReadModels' ) ) {
				DTB_MarketplaceReadModels::upsert_channel( DTB_CHANNEL_EBAY, [
					'auth_state'     => 'auth_failed',
					'health_state'   => 'error',
					'last_error_at'  => current_time( 'mysql', true ),
					'last_error_msg' => substr( $message, 0, 500 ),
				] );
			}

			if ( class_exists( 'DTB_MarketplaceExceptionService' ) ) {
				DTB_MarketplaceExceptionService::create(
					DTB_MarketplaceExceptionService::CAT_TOKEN_REFRESH,
					DTB_CHANNEL_EBAY,
					'ebay_token_refresh_failed',
					$message,
					[ 'is_retryable' => true ]
				);
			}

			DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_TOKEN_EXPIRED, DTB_CHANNEL_EBAY, [
				'payload' => [ 'reason' => $message ],
			] );
		}
	}
}
