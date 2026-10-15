<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_CacheInvalidationService' ) ) {
	return;
}

/**
 * Versioned invalidation works with both database transients and persistent object caches.
 * Old transient entries expire naturally at their original bounded TTL.
 */
final class DTB_CacheInvalidationService {
	public static function invalidate_product_cache(): void {
		self::bump_generation( 'dtb_proxy_cache_generation' );
		dtb_log_cache_event( 'cache_invalidated', [] );
	}

	public static function flush_ops_cache( string $module = '' ): void {
		$option = '' !== $module
			? 'dtb_ops_cache_generation_' . sanitize_key( $module )
			: 'dtb_ops_cache_generation';

		self::bump_generation( $option );
		dtb_log_cache_event( 'ops_cache_flushed', [ 'module' => $module ?: 'all' ] );
	}

	private static function bump_generation( string $option ): void {
		// A unique generation prevents a concurrent invalidation from reusing an old
		// key, even if simultaneous writers read the same previous option value.
		update_option( $option, wp_generate_uuid4(), false );
	}
}
