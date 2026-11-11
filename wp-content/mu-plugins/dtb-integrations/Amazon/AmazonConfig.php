<?php
/**
 * Amazon — AmazonConfig
 *
 * Configuration facade for Amazon Selling Partner API integration.
 * Reads from wp-config.php constants first, then encrypted options.
 *
 * Required wp-config.php constants (preferred):
 *   DTB_AMAZON_CLIENT_ID        — LWA application client ID
 *   DTB_AMAZON_CLIENT_SECRET    — LWA application client secret
 *   DTB_AMAZON_REFRESH_TOKEN    — LWA refresh token (seller-authorized)
 *   DTB_AMAZON_MARKETPLACE_ID   — Amazon marketplace ID, e.g. ATVPDKIKX0DER (US)
 *   DTB_AMAZON_SELLER_ID        — Amazon seller/merchant ID
 *   DTB_AMAZON_SANDBOX          — (optional) '1' to use sandbox endpoints
 *   DTB_AMAZON_NOTIFICATION_ENDPOINT — public HTTPS endpoint for SP-API SNS notifications
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_AmazonConfig' ) ) {
	final class DTB_AmazonConfig {

		public const SP_API_BASE_PROD    = 'https://sellingpartnerapi-na.amazon.com';
		public const SP_API_BASE_SANDBOX = 'https://sandbox.sellingpartnerapi-na.amazon.com';
		public const LWA_TOKEN_URL       = 'https://api.amazon.com/auth/o2/token';
		public const LWA_AUTH_URL        = 'https://sellercentral.amazon.com/apps/authorize/consent';

		/**
		 * Return config array. Result is request-scoped cached.
		 *
		 * @return array{client_id: string, client_secret: string, refresh_token: string, marketplace_id: string, seller_id: string, sandbox: bool, notification_endpoint: string}
		 */
		public static function get(): array {
			if ( isset( $GLOBALS['_dtb_amazon_config'] ) ) {
				return $GLOBALS['_dtb_amazon_config'];
			}

			$stored = class_exists( 'DTB_MarketplaceCredentialFacade' )
				? DTB_MarketplaceCredentialFacade::get( DTB_CHANNEL_AMAZON )
				: [];

			$GLOBALS['_dtb_amazon_config'] = [
				'client_id'             => self::const_or( 'DTB_AMAZON_CLIENT_ID',             $stored['client_id'] ?? '' ),
				'client_secret'         => self::const_or( 'DTB_AMAZON_CLIENT_SECRET',         $stored['client_secret'] ?? '' ),
				'refresh_token'         => self::const_or( 'DTB_AMAZON_REFRESH_TOKEN',         $stored['refresh_token'] ?? '' ),
				'marketplace_id'        => self::const_or( 'DTB_AMAZON_MARKETPLACE_ID',        $stored['marketplace_id'] ?? '' ),
				'seller_id'             => self::const_or( 'DTB_AMAZON_SELLER_ID',             $stored['seller_id'] ?? '' ),
				'sandbox'               => (bool) self::const_or( 'DTB_AMAZON_SANDBOX',        $stored['sandbox'] ?? '0' ),
				'notification_endpoint' => self::const_or( 'DTB_AMAZON_NOTIFICATION_ENDPOINT', $stored['notification_endpoint'] ?? '' ),
			];

			return $GLOBALS['_dtb_amazon_config'];
		}

		/** Return true when Amazon integration is fully configured. */
		public static function is_configured(): bool {
			$c = self::get();
			return '' !== $c['client_id']
				&& '' !== $c['client_secret']
				&& '' !== $c['refresh_token']
				&& '' !== $c['marketplace_id']
				&& '' !== $c['seller_id'];
		}

		/** Return the SP-API base URL for the current mode. */
		public static function api_base(): string {
			return self::get()['sandbox'] ? self::SP_API_BASE_SANDBOX : self::SP_API_BASE_PROD;
		}

		/** Flush request-scoped cache (call after storing new credentials). */
		public static function flush_cache(): void {
			unset( $GLOBALS['_dtb_amazon_config'] );
		}

		private static function const_or( string $name, string $fallback ): string {
			return ( defined( $name ) && '' !== (string) constant( $name ) ) ? (string) constant( $name ) : $fallback;
		}
	}
}
