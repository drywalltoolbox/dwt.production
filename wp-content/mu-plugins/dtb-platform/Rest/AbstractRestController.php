<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_AbstractRestController' ) ) {
	return;
}

abstract class DTB_AbstractRestController {
	abstract public static function register_routes(): void;

	protected static function page_from_request( WP_REST_Request $request, int $default = 1 ): int {
		return max( 1, absint( $request->get_param( 'page' ) ?? $default ) );
	}

	protected static function per_page_from_request( WP_REST_Request $request, int $default = 20, int $max = 200 ): int {
		return min( $max, max( 1, absint( $request->get_param( 'per_page' ) ?? $default ) ) );
	}
}
