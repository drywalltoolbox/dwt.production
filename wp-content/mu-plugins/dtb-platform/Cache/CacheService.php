<?php
/**
 * DTB cache compatibility API and diagnostics.
 *
 * This file preserves the stable procedural functions used throughout the
 * platform. Cache policy and invalidation behavior are owned exclusively by
 * DTB_CacheHeaders, DTB_CacheKeyBuilder, and DTB_CacheInvalidationService.
 * No fallback implementation is allowed here; a missing canonical service is
 * an invalid bootstrap state and must fail visibly rather than create a second
 * source of cache behavior.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! dtb_is_admin_or_rest_request() ) {
	return;
}

add_action( 'rest_api_init', 'dtb_register_cache_control_headers', 10 );
add_action( 'rest_api_init', 'dtb_register_cache_routes', 10 );

/** Register the canonical REST cache-header policy. */
function dtb_register_cache_control_headers(): void {
	DTB_CacheHeaders::register();
}

/**
 * Backward-compatible filter callback delegated to the canonical policy.
 *
 * @param array<string, string> $headers Outgoing response headers.
 * @return array<string, string>
 */
function dtb_maybe_add_cache_control_headers( array $headers ): array {
	return DTB_CacheHeaders::filter_headers( $headers );
}

/**
 * Read-through transient cache for DTB proxy data.
 *
 * @param string   $route Route identifier.
 * @param array    $params Cache-key parameters.
 * @param callable $fetcher Fresh-value callback.
 * @return mixed
 */
function dtb_cached_proxy( string $route, array $params, callable $fetcher ) {
	$cache_key = DTB_CacheKeyBuilder::proxy_key( $route, $params );
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		if ( ! headers_sent() ) {
			header( 'X-Cache: HIT' );
		}
		return $cached;
	}

	$result = $fetcher();
	if ( null === $result || is_wp_error( $result ) ) {
		return $result;
	}

	set_transient( $cache_key, $result, DTB_CacheKeyBuilder::proxy_ttl( $route ) );
	if ( ! headers_sent() ) {
		header( 'X-Cache: MISS' );
	}

	return $result;
}

/** Invalidate DTB product/proxy cache through the canonical service. */
function dtb_invalidate_product_cache(): void {
	DTB_CacheInvalidationService::invalidate_product_cache();
}

/**
 * Append a bounded cache audit event.
 *
 * @param string               $event Machine-readable event.
 * @param array<string, mixed> $context Redacted event context.
 */
function dtb_log_cache_event( string $event, array $context ): void {
	$log = dtb_get_cache_log();

	array_unshift(
		$log,
		[
			'event'     => sanitize_key( $event ),
			'context'   => $context,
			'timestamp' => gmdate( 'c' ),
		]
	);

	update_option( 'drywall_cache_log', array_slice( $log, 0, 50 ), false );
}

/** @return array<int, array<string, mixed>> */
function dtb_get_cache_log(): array {
	return (array) get_option( 'drywall_cache_log', [] );
}

/**
 * Read-through cache for an operations module.
 *
 * @param string   $module Module namespace.
 * @param string   $key Cache-key suffix.
 * @param int      $ttl TTL in seconds.
 * @param callable $callback Fresh-value callback.
 * @return mixed
 */
function dtb_ops_cache_get( string $module, string $key, int $ttl, callable $callback ) {
	$cache_key = DTB_CacheKeyBuilder::ops_key( $module, $key );
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		dtb_log_cache_event( 'ops_cache_hit', [ 'module' => sanitize_key( $module ), 'key' => sanitize_key( $key ) ] );
		return $cached;
	}

	$result = $callback();
	if ( null !== $result && ! is_wp_error( $result ) ) {
		set_transient( $cache_key, $result, max( 1, $ttl ) );
		dtb_log_cache_event( 'ops_cache_miss', [ 'module' => sanitize_key( $module ), 'key' => sanitize_key( $key ) ] );
	}

	return $result;
}

/** Flush operations cache through the canonical invalidation service. */
function dtb_ops_cache_flush( string $module = '' ): void {
	DTB_CacheInvalidationService::flush_ops_cache( $module );
}

/** Register the read-only cache diagnostic route. */
function dtb_register_cache_routes(): void {
	register_rest_route(
		'dtb/v1',
		'/cache/status',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dtb_route_cache_status',
			'permission_callback' => 'dtb_cache_status_permission',
		]
	);
}

/**
 * Require an authenticated administrator for cache diagnostics.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return true|WP_Error
 */
function dtb_cache_status_permission( WP_REST_Request $request ) {
	$token = null;

	if ( ! empty( $_COOKIE[ DTB_AUTH_COOKIE ] ) ) {
		$token = sanitize_text_field( wp_unslash( $_COOKIE[ DTB_AUTH_COOKIE ] ) );
	}

	if ( ! $token ) {
		$authorization = $request->get_header( 'authorization' );
		if ( $authorization && preg_match( '/^Bearer\s+(\S+)$/i', $authorization, $matches ) ) {
			$token = $matches[1];
		}
	}

	if ( ! $token ) {
		return new WP_Error( 'missing_token', __( 'Authorization token required.', 'drywall-toolbox' ), [ 'status' => 401 ] );
	}

	$payload = dtb_verify_jwt( $token );
	if ( is_wp_error( $payload ) ) {
		return $payload;
	}

	$roles = isset( $payload->roles ) ? (array) $payload->roles : [];
	if ( ! in_array( 'administrator', $roles, true ) ) {
		return new WP_Error( 'forbidden', __( 'Administrator access required.', 'drywall-toolbox' ), [ 'status' => 403 ] );
	}

	return true;
}

/** Return bounded cache diagnostics. */
function dtb_route_cache_status(): WP_REST_Response {
	$log              = dtb_get_cache_log();
	$last_invalidated = null;

	foreach ( $log as $entry ) {
		if ( in_array( $entry['event'] ?? '', [ 'cache_invalidated', 'cache_operations_run' ], true ) ) {
			$last_invalidated = $entry['timestamp'] ?? null;
			break;
		}
	}

	return rest_ensure_response(
		[
			'log'              => $log,
			'last_invalidated' => $last_invalidated,
		]
	);
}
