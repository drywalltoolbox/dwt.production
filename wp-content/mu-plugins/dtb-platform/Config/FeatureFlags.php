<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_FeatureFlags' ) ) {
	return;
}

final class DTB_FeatureFlags {
	public static function enabled( string $flag, bool $default = true ): bool {
		return dtb_feature_enabled( $flag, $default );
	}

	public static function all(): array {
		return [
			'DTB_ENABLE_REST_CORS'                    => self::enabled( 'DTB_ENABLE_REST_CORS', true ),
			'DTB_RESTRICT_USER_ENDPOINTS'             => self::enabled( 'DTB_RESTRICT_USER_ENDPOINTS', true ),
			'DTB_WC_PUBLIC_READ'                      => self::enabled( 'DTB_WC_PUBLIC_READ', true ),
			'DTB_ENABLE_NONCE_REFRESH'                => self::enabled( 'DTB_ENABLE_NONCE_REFRESH', true ),
			'DTB_SECURITY_LOGGING'                    => self::enabled( 'DTB_SECURITY_LOGGING', true ),
			'DTB_ENABLE_PROXY_RATE_LIMIT'             => self::enabled( 'DTB_ENABLE_PROXY_RATE_LIMIT', true ),
			'DTB_ENABLE_LOGIN_RATE_LIMIT'             => self::enabled( 'DTB_ENABLE_LOGIN_RATE_LIMIT', true ),
			'DTB_ENABLE_WOO_ADMIN_PAYMENTS_ASSET_GUARD' => self::enabled( 'DTB_ENABLE_WOO_ADMIN_PAYMENTS_ASSET_GUARD', false ),
			'DTB_ENABLE_WOO_ADMIN_REST_NONCE_COMPAT'  => self::enabled( 'DTB_ENABLE_WOO_ADMIN_REST_NONCE_COMPAT', true ),
		];
	}
}
