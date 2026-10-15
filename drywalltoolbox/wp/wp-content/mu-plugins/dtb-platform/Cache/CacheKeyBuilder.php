<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_CacheKeyBuilder' ) ) {
	return;
}

final class DTB_CacheKeyBuilder {
	public static function proxy_key( string $route, array $params ): string {
		ksort( $params );
		$generation = (int) get_option( 'dtb_proxy_cache_generation', 1 );
		return 'drywall_cache_' . md5( $generation . '|' . $route . '|' . wp_json_encode( $params ) );
	}

	public static function proxy_ttl( string $route ): int {
		if ( false !== strpos( $route, 'categories' ) || false !== strpos( $route, 'attributes' ) ) {
			return 900;
		}

		return 600;
	}

	public static function ops_key( string $module, string $key ): string {
		$global_generation = (int) get_option( 'dtb_ops_cache_generation', 1 );
		$module_key        = sanitize_key( $module );
		$module_generation = (int) get_option( 'dtb_ops_cache_generation_' . $module_key, 1 );
		return 'dtb_ops_' . md5( $global_generation . '|' . $module_key . '|' . $module_generation . '|' . $key );
	}
}
