<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_CacheHeaders' ) ) {
	return;
}

/**
 * DTB API responses default to private caching. Public cache eligibility must be
 * explicitly reviewed per route and must never be inferred from path substrings.
 * WooCommerce Store API owns its own cache policy.
 */
final class DTB_CacheHeaders {
	public static function register(): void {
		add_filter( 'rest_pre_send_headers', [ self::class, 'filter_headers' ] );
	}

	public static function filter_headers( array $headers ): array {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( ! preg_match( '#(?:^|/)wp-json/dtb/v1(?:/|$)#', $path ) ) {
			return $headers;
		}

		// An allowlist can be introduced after route-by-route public data review.
		// Until then, do not let edge/CDN caches retain DTB API responses.
		$headers['Cache-Control'] = 'private, no-store, max-age=0';
		$headers['Pragma']        = 'no-cache';
		unset( $headers['Expires'] );

		return $headers;
	}
}
