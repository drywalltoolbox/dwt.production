<?php
/**
 * Checkout cart/session continuity guard.
 *
 * WooCommerce owns cart/session validation and migration. DTB authentication must
 * never expire Woo browser session/cart cookies merely because the DTB JWT and a
 * stale non-privileged WordPress cookie temporarily resolve to different customer
 * IDs during convergence.
 *
 * This guard resolves the verified DTB customer before the legacy native checkout
 * bridge's conflict branch and removes only the stale request-scoped WordPress
 * logged-in cookie marker before REST auth reconciliation. It does not initialize,
 * destroy, copy, or mutate WC()->session and does not touch Woo cart/session cookies.
 *
 * @package drywalltoolbox
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'determine_current_user', 'dtb_checkout_preserve_woo_session_during_identity_convergence', 24 );
add_filter( 'rest_post_dispatch', 'dtb_auth_preserve_woo_session_during_native_cookie_rotation', 19, 3 );

/**
 * Resolve the verified DTB storefront customer immediately before the native
 * checkout bridge so a stale non-privileged WP cookie cannot trigger destructive
 * Woo browser-state expiry.
 *
 * @param int|false $user_id Current resolved user ID.
 * @return int|false
 */
function dtb_checkout_preserve_woo_session_during_identity_convergence( $user_id ) {
	if ( ! function_exists( 'dtb_native_checkout_identity_bridge_request' ) || ! dtb_native_checkout_identity_bridge_request() ) {
		return $user_id;
	}

	$native_user_id = ! empty( $user_id ) ? absint( $user_id ) : 0;
	if ( $native_user_id > 0 ) {
		$native_user = get_user_by( 'id', $native_user_id );
		if ( $native_user instanceof WP_User && function_exists( 'dtb_native_checkout_user_is_privileged' ) && dtb_native_checkout_user_is_privileged( $native_user ) ) {
			return $user_id;
		}
	}

	$token = ! empty( $_COOKIE['dtb_auth'] )
		? sanitize_text_field( wp_unslash( (string) $_COOKIE['dtb_auth'] ) )
		: '';
	if ( '' === $token || ! class_exists( 'DTB_JwtService' ) ) {
		return $user_id;
	}

	$resolved = DTB_JwtService::user_id( $token );
	if ( $resolved <= 0 ) {
		return $user_id;
	}

	$user = get_user_by( 'id', $resolved );
	if ( ! $user instanceof WP_User ) {
		return $user_id;
	}
	if ( function_exists( 'dtb_native_checkout_user_is_privileged' ) && dtb_native_checkout_user_is_privileged( $user ) ) {
		return $user_id;
	}

	return $resolved;
}

/**
 * During same-origin auth convergence, hide a stale non-privileged native WP
 * cookie from the later compatibility reconciler so it rotates native auth without
 * expiring Woo cart/session browser state. The Set-Cookie response emitted by the
 * reconciler remains authoritative for the browser.
 *
 * @param mixed           $response REST response.
 * @param WP_REST_Server  $server   REST server.
 * @param WP_REST_Request $request  REST request.
 * @return mixed
 */
function dtb_auth_preserve_woo_session_during_native_cookie_rotation( $response, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	if ( is_wp_error( $response ) || ! $request instanceof WP_REST_Request ) {
		return $response;
	}

	$route = (string) $request->get_route();
	if ( ! in_array( $route, [ '/dtb/v1/auth/login', '/dtb/v1/auth/register', '/dtb/v1/auth/validate' ], true ) ) {
		return $response;
	}
	if ( function_exists( 'dtb_is_cross_origin_request' ) && dtb_is_cross_origin_request() ) {
		return $response;
	}
	if ( ! defined( 'LOGGED_IN_COOKIE' ) || empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) || ! function_exists( 'wp_validate_auth_cookie' ) ) {
		return $response;
	}

	$data = $response instanceof WP_REST_Response || $response instanceof WP_HTTP_Response ? $response->get_data() : null;
	$target_user_id = is_array( $data ) && ! empty( $data['user']['id'] ) ? absint( $data['user']['id'] ) : 0;
	if ( $target_user_id <= 0 ) {
		return $response;
	}

	$cookie = wp_unslash( (string) $_COOKIE[ LOGGED_IN_COOKIE ] );
	$native_user_id = absint( wp_validate_auth_cookie( $cookie, 'logged_in' ) );
	if ( $native_user_id <= 0 || $native_user_id === $target_user_id ) {
		return $response;
	}

	$native_user = get_user_by( 'id', $native_user_id );
	if ( $native_user instanceof WP_User && function_exists( 'dtb_auth_user_is_privileged' ) && dtb_auth_user_is_privileged( $native_user ) ) {
		return $response;
	}

	/* Request-scoped only. Do not expire Woo cookies or mutate WC()->session. */
	unset( $_COOKIE[ LOGGED_IN_COOKIE ] );

	return $response;
}
