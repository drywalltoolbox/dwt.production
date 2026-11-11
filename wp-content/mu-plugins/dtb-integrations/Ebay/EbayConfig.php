<?php
/**
 * eBay — EbayConfig
 *
 * Configuration facade for eBay OAuth + REST API integration.
 *
 * Required wp-config.php constants (preferred):
 *   DTB_EBAY_CLIENT_ID          — eBay application client ID (App ID)
 *   DTB_EBAY_CLIENT_SECRET      — eBay application client secret (Cert ID)
 *   DTB_EBAY_REDIRECT_URI       — eBay RuName (redirect URI name)
 *   DTB_EBAY_MARKETPLACE_ID     — eBay marketplace ID, e.g. EBAY_US
 *   DTB_EBAY_SANDBOX            — '1' to use sandbox endpoints
 *   DTB_EBAY_DELETION_WEBHOOK_VERIFY_TOKEN — verification token for deletion notifications
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_EbayConfig' ) ) {
	final class DTB_EbayConfig {

		public const API_BASE_PROD    = 'https://api.ebay.com';
		public const API_BASE_SANDBOX = 'https://api.sandbox.ebay.com';

		public const OAUTH_TOKEN_URL_PROD    = 'https://api.ebay.com/identity/v1/oauth2/token';
		public const OAUTH_TOKEN_URL_SANDBOX = 'https://api.sandbox.ebay.com/identity/v1/oauth2/token';

		public const OAUTH_AUTH_URL_PROD    = 'https://auth.ebay.com/oauth2/authorize';
		public const OAUTH_AUTH_URL_SANDBOX = 'https://auth.sandbox.ebay.com/oauth2/authorize';

		/**
		 * Return config array. Request-scoped cached.
		 *
		 * @return array{client_id: string, client_secret: string, redirect_uri: string, marketplace_id: string, sandbox: bool, deletion_verify_token: string}
		 */
		public static function get(): array {
			if ( isset( $GLOBALS['_dtb_ebay_config'] ) ) {
				return $GLOBALS['_dtb_ebay_config'];
			}

			$stored = class_exists( 'DTB_MarketplaceCredentialFacade' )
				? DTB_MarketplaceCredentialFacade::get( DTB_CHANNEL_EBAY )
				: [];

			$GLOBALS['_dtb_ebay_config'] = [
				'client_id'             => self::const_or( 'DTB_EBAY_CLIENT_ID',      $stored['client_id'] ?? '' ),
				'client_secret'         => self::const_or( 'DTB_EBAY_CLIENT_SECRET',  $stored['client_secret'] ?? '' ),
				'redirect_uri'          => self::const_or( 'DTB_EBAY_REDIRECT_URI',   $stored['redirect_uri'] ?? '' ),
				'marketplace_id'        => self::const_or( 'DTB_EBAY_MARKETPLACE_ID', $stored['marketplace_id'] ?? 'EBAY_US' ),
				'sandbox'               => (bool) self::const_or( 'DTB_EBAY_SANDBOX', $stored['sandbox'] ?? '0' ),
				'deletion_verify_token' => self::const_or( 'DTB_EBAY_DELETION_WEBHOOK_VERIFY_TOKEN', $stored['deletion_verify_token'] ?? '' ),
			];

			return $GLOBALS['_dtb_ebay_config'];
		}

		/** Return true when eBay is fully configured. */
		public static function is_configured(): bool {
			$c = self::get();
			return '' !== $c['client_id'] && '' !== $c['client_secret'];
		}

		/** Return the eBay API base URL. */
		public static function api_base(): string {
			return self::get()['sandbox'] ? self::API_BASE_SANDBOX : self::API_BASE_PROD;
		}

		/** Return the OAuth token endpoint URL. */
		public static function token_url(): string {
			return self::get()['sandbox'] ? self::OAUTH_TOKEN_URL_SANDBOX : self::OAUTH_TOKEN_URL_PROD;
		}

		/** Flush request-scoped cache. */
		public static function flush_cache(): void {
			unset( $GLOBALS['_dtb_ebay_config'] );
		}

		private static function const_or( string $name, string $fallback ): string {
			return ( defined( $name ) && '' !== (string) constant( $name ) ) ? (string) constant( $name ) : $fallback;
		}
	}
}
