<?php
/**
 * Native wp-admin REST nonce compatibility.
 *
 * Narrowly restores authenticated same-site wp-admin GET requests for selected
 * official WooCommerce and SiteGround admin read endpoints when a stale wp_rest
 * nonce causes WordPress REST cookie auth to fail.
 *
 * Enabled by default because Drywall Toolbox runs WordPress in /wp while REST is
 * also exposed through root /wp-json aliases. The compatibility path is limited
 * to read-only requests, requires a valid signed native WordPress auth cookie,
 * requires WooCommerce/admin capability, and never bypasses mutation nonces.
 * Set DTB_ENABLE_WOO_ADMIN_REST_NONCE_COMPAT=false only for incident isolation.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'determine_current_user', 'dtb_native_admin_restore_cookie_user', 24 );
add_filter( 'rest_authentication_errors', 'dtb_woo_admin_rest_nonce_compat_restore_get_request', 116 );

/**
 * Native admin REST namespaces that must never be resolved through DTB storefront JWTs.
 *
 * This security file loads before Auth/AuthRoutes.php. Defining the shared helper
 * here lets AuthRoutes reuse the same boundary via its function_exists() guard.
 */
if ( ! function_exists( 'dtb_jwt_wp_admin_rest_route_prefixes' ) ) {
	function dtb_jwt_wp_admin_rest_route_prefixes(): array {
		return [
			'/wp/v2/',
			'/wc-admin/',
			'/wc-analytics/',
			'/wc/v3/',
			'/siteground-optimizer/',
			'/sg-ai-studio/',
		];
	}
}

/**
 * Whether this compatibility layer is enabled.
 */
function dtb_woo_admin_rest_nonce_compat_enabled(): bool {
	return function_exists( 'dtb_feature_enabled' )
		? dtb_feature_enabled( 'DTB_ENABLE_WOO_ADMIN_REST_NONCE_COMPAT', true )
		: true;
}

/**
 * Extract the current REST route from either pretty /wp-json URLs or the
 * query-string rest_route form used by internal rewrite fallbacks.
 */
function dtb_woo_admin_rest_nonce_compat_current_route(): string {
	if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$route = sanitize_text_field( wp_unslash( (string) $_GET['rest_route'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $route ) {
			return '/' === $route[0] ? $route : '/' . $route;
		}
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] )
		? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		: '';
	$path = '' !== $request_uri ? (string) wp_parse_url( $request_uri, PHP_URL_PATH ) : '';
	if ( '' === $path ) {
		return '';
	}

	$marker = '/wp-json';
	$offset = strpos( $path, $marker );
	if ( false === $offset ) {
		return '';
	}

	$route = substr( $path, $offset + strlen( $marker ) );
	return '' === $route ? '/' : ( '/' === $route[0] ? $route : '/' . $route );
}

/**
 * Whether the current request targets a native admin REST namespace.
 */
function dtb_native_admin_rest_namespace_request(): bool {
	$route = dtb_woo_admin_rest_nonce_compat_current_route();
	if ( '' === $route ) {
		return false;
	}

	foreach ( dtb_jwt_wp_admin_rest_route_prefixes() as $prefix ) {
		if ( 0 === strpos( $route, $prefix ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Restore a valid native WordPress admin cookie identity before the DTB JWT bridge.
 *
 * This does not bypass REST nonce or capability checks. It only ensures that a
 * root-mounted /wp-json request can recover the same authenticated WordPress user
 * represented by the signed wp-admin cookies when the core resolver leaves the
 * request anonymous under the nested /wp/wp-admin + root /wp-json topology.
 *
 * @param int|false $user_id Current resolved user id.
 * @return int|false
 */
function dtb_native_admin_restore_cookie_user( $user_id ) {
	if ( ! dtb_woo_admin_rest_nonce_compat_enabled() || ! empty( $user_id ) || ! dtb_native_admin_rest_namespace_request() ) {
		return $user_id;
	}

	$resolved = dtb_woo_admin_rest_nonce_compat_auth_user_id();
	return $resolved > 0 ? $resolved : $user_id;
}

/**
 * Whether a route is an explicitly approved native-admin read endpoint.
 */
function dtb_woo_admin_rest_nonce_compat_route_allowed( string $route, string $method ): bool {
	if ( 'GET' !== $method ) {
		return false;
	}

	$allowed_get_routes = [
		'/wc-admin/options',
		'/wc-admin/settings/payments/providers',
		'/wc-analytics/admin/notes',
		'/wc/v3/payments/settings',
		'/wc/v3/payments/pm-promotions',
		'/wc/v3/payments/deposits/overview-all',
		'/siteground-optimizer/v1/fetch-options/frontend',
		'/sg-ai-studio/settings-page/connected',
	];

	return in_array( $route, $allowed_get_routes, true );
}

/**
 * Validate same-site wp-admin context.
 */
function dtb_woo_admin_rest_nonce_compat_same_site_admin_context(): bool {
	$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
	if ( ! $site_host ) {
		return false;
	}

	$raw_origin = isset( $_SERVER['HTTP_ORIGIN'] )
		? (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		: '';
	$referrer = wp_get_raw_referer();

	$origin_host = $raw_origin ? wp_parse_url( $raw_origin, PHP_URL_HOST ) : '';
	$ref_host    = $referrer ? wp_parse_url( $referrer, PHP_URL_HOST ) : '';
	$ref_path    = $referrer ? (string) wp_parse_url( $referrer, PHP_URL_PATH ) : '';
	$ref_query   = $referrer ? (string) wp_parse_url( $referrer, PHP_URL_QUERY ) : '';

	$same_site_origin    = $origin_host && strtolower( $site_host ) === strtolower( $origin_host );
	$cross_site_origin   = $origin_host && strtolower( $site_host ) !== strtolower( $origin_host );
	$payments_referrer   = $ref_host
		&& strtolower( $site_host ) === strtolower( $ref_host )
		&& false !== strpos( $ref_path, '/wp-admin/' )
		&& false !== strpos( $ref_query, 'page=wc-settings' )
		&& false !== strpos( $ref_query, 'tab=checkout' );
	$admin_referrer      = $ref_host
		&& strtolower( $site_host ) === strtolower( $ref_host )
		&& false !== strpos( $ref_path, '/wp-admin/' );
	$no_external_headers = '' === $raw_origin && ! $referrer;

	if ( $cross_site_origin ) {
		return false;
	}

	return (bool) ( $same_site_origin || $payments_referrer || $admin_referrer || $no_external_headers );
}

/**
 * Validate the existing auth cookie and return a privileged admin user id.
 */
function dtb_woo_admin_rest_nonce_compat_auth_user_id(): int {
	$schemes = is_ssl() ? [ 'secure_auth', 'auth', 'logged_in' ] : [ 'auth', 'secure_auth', 'logged_in' ];

	foreach ( array_unique( $schemes ) as $scheme ) {
		$user_id = (int) wp_validate_auth_cookie( '', $scheme );
		if ( $user_id > 0 && ( user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'manage_options' ) ) ) {
			return $user_id;
		}
	}

	return 0;
}

/**
 * Restore only authenticated same-site admin read requests after stale nonce failure.
 *
 * Mutating methods intentionally remain protected by normal WordPress nonce and
 * capability validation. This compatibility path only recovers read-only admin UI
 * hydration when the root-mounted /wp-json endpoint and nested /wp/wp-admin session
 * briefly disagree about a wp_rest nonce.
 *
 * @param WP_Error|mixed $result Authentication result from previous handlers.
 * @return WP_Error|mixed|null
 */
function dtb_woo_admin_rest_nonce_compat_restore_get_request( $result ) {
	if ( ! dtb_woo_admin_rest_nonce_compat_enabled() || ! is_wp_error( $result ) || 'rest_cookie_invalid_nonce' !== $result->get_error_code() ) {
		return $result;
	}

	$method = isset( $_SERVER['REQUEST_METHOD'] )
		? strtoupper( sanitize_text_field( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		: '';
	if ( 'GET' !== $method ) {
		return $result;
	}

	$route = dtb_woo_admin_rest_nonce_compat_current_route();
	if ( ! dtb_woo_admin_rest_nonce_compat_route_allowed( $route, $method ) || ! dtb_woo_admin_rest_nonce_compat_same_site_admin_context() ) {
		return $result;
	}

	$user_id = dtb_woo_admin_rest_nonce_compat_auth_user_id();
	if ( $user_id <= 0 ) {
		return $result;
	}

	wp_set_current_user( $user_id );

	if ( function_exists( 'dtb_security_log' ) ) {
		dtb_security_log(
			'native_admin_get_rest_nonce_restored',
			[
				'route'  => $route,
				'method' => $method,
			]
		);
	}

	return null;
}
