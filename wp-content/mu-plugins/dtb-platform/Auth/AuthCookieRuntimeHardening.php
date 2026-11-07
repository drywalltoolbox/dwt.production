<?php
/**
 * DTB auth cookie and native-checkout session hardening.
 *
 * AuthRoutes.php remains the single owner of the HttpOnly `dtb_auth` JWT cookie.
 * This compatibility layer owns cache headers and convergence of verified,
 * same-origin storefront customer sessions into WordPress's native HttpOnly auth
 * cookie so WooCommerce can execute its supported customer/session lifecycle.
 *
 * Native wp-admin requests are authenticated only by signed WordPress cookies.
 * A storefront customer JWT must never become the wp-admin current user or trigger
 * WooCommerce's customer-to-my-account admin redirect.
 *
 * @package drywalltoolbox
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'determine_current_user', 'dtb_auth_enforce_native_admin_identity_boundary', 100 );
add_filter( 'rest_pre_dispatch', 'dtb_auth_block_privileged_native_storefront_auth', 15, 3 );
add_filter( 'rest_post_dispatch', 'dtb_auth_harden_rest_response', 20, 3 );

/**
 * Keep wp-admin and admin-ajax on native WordPress cookie authentication only.
 *
 * AuthRoutes loads during wp-admin and its REST JWT bridge executes late in the
 * determine_current_user chain. Without this final boundary, a browser that has a
 * DTB customer JWT but no valid native admin cookie can be resolved as that customer;
 * WooCommerce then redirects the wp-admin request to My Account. Re-evaluate only
 * signed native cookies here and otherwise leave the request anonymous so WordPress
 * sends the normal wp-login.php challenge.
 *
 * @param int|false $user_id User resolved by earlier authentication filters.
 * @return int|false
 */
function dtb_auth_enforce_native_admin_identity_boundary( $user_id ) {
	if ( ! is_admin() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return $user_id;
	}

	$native_user_id = dtb_auth_valid_native_cookie_user_id();
	if ( $native_user_id > 0 ) {
		return $native_user_id;
	}

	return false;
}

function dtb_auth_block_privileged_native_storefront_auth( $result, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	if ( null !== $result || ! $request instanceof WP_REST_Request ) {
		return $result;
	}

	$route = (string) $request->get_route();
	if ( ! in_array( $route, [ '/dtb/v1/auth/login', '/dtb/v1/auth/register' ], true ) ) {
		return $result;
	}

	if ( function_exists( 'dtb_is_cross_origin_request' ) && dtb_is_cross_origin_request() ) {
		return $result;
	}

	$current_user = wp_get_current_user();
	if ( ! $current_user instanceof WP_User || ! $current_user->exists() || ! dtb_auth_user_is_privileged( $current_user ) ) {
		return $result;
	}

	if ( function_exists( 'dtb_security_log' ) ) {
		dtb_security_log( 'storefront_auth_blocked_by_privileged_native_session', [ 'route' => $route ] );
	}

	return new WP_Error(
		'dtb_auth_native_session_conflict',
		'A conflicting WordPress administrator session is active in this browser. Use a separate/private browser session for customer storefront sign-in.',
		[ 'status' => 409 ]
	);
}

function dtb_auth_harden_rest_response( $response, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	if ( is_wp_error( $response ) || ! $request instanceof WP_REST_Request ) {
		return $response;
	}

	$route = (string) $request->get_route();
	if ( 0 !== strpos( $route, '/dtb/v1/auth/' ) ) {
		return $response;
	}

	if ( $response instanceof WP_REST_Response || $response instanceof WP_HTTP_Response ) {
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', '0' );
		$response->header( 'X-Accel-Expires', '0' );
		$response->header( 'Vary', 'Cookie, Authorization, Origin' );
	}

	if ( in_array( $route, [ '/dtb/v1/auth/login', '/dtb/v1/auth/register', '/dtb/v1/auth/validate' ], true ) ) {
		$state = dtb_auth_reconcile_native_customer_session_from_response( $response, $route );
		dtb_auth_fail_closed_on_privileged_native_conflict( $response, $state, $route );
		dtb_auth_attach_native_session_state( $response, $state );
	}

	if ( '/dtb/v1/auth/logout' === $route ) {
		dtb_auth_clear_native_customer_cookie();
		dtb_auth_signal_session_mutation();
	}

	return $response;
}

/**
 * Reconcile a verified storefront customer into native WordPress auth.
 *
 * This layer never initializes, destroys, or copies WooCommerce session state. It
 * only establishes native WordPress identity for the next request and expires stale
 * browser-side Woo session markers on a cross-customer identity conflict. WooCommerce
 * remains the sole owner of session creation/migration after current-user resolution.
 *
 * @param WP_REST_Response|WP_HTTP_Response $response REST response.
 * @param string                            $route    Auth route.
 * @return array<string,mixed> Redacted handoff state.
 */
function dtb_auth_reconcile_native_customer_session_from_response( $response, string $route ): array {
	$state = [
		'status'                      => 'not_applicable',
		'native_checkout_ready'       => false,
		'native_cookie_queued'        => false,
		'native_cookie_already_valid' => false,
		'identity_conflict_contained' => false,
	];

	if ( ! ( $response instanceof WP_REST_Response || $response instanceof WP_HTTP_Response ) ) {
		return $state;
	}

	if ( function_exists( 'dtb_is_cross_origin_request' ) && dtb_is_cross_origin_request() ) {
		$state['status'] = 'skipped_cross_origin';
		return $state;
	}

	$data = $response->get_data();
	if ( ! is_array( $data ) ) {
		$state['status'] = 'invalid_response';
		return $state;
	}

	if ( '/dtb/v1/auth/validate' === $route && isset( $data['authenticated'] ) && false === $data['authenticated'] ) {
		dtb_auth_clear_native_customer_cookie();
		$state['status'] = 'native_customer_cookie_cleared';
		return $state;
	}

	if ( empty( $data['user']['id'] ) ) {
		$state['status'] = 'no_authenticated_user';
		return $state;
	}

	$authenticated = ! empty( $data['success'] ) || ! empty( $data['authenticated'] );
	if ( ! $authenticated ) {
		$state['status'] = 'not_authenticated';
		return $state;
	}

	$user = get_user_by( 'id', absint( $data['user']['id'] ) );
	if ( ! $user instanceof WP_User ) {
		$state['status'] = 'user_missing';
		return $state;
	}

	if ( dtb_auth_user_is_privileged( $user ) ) {
		$state['status'] = 'skipped_privileged_user';
		return $state;
	}

	$native_user_id = dtb_auth_valid_native_cookie_user_id();
	if ( $native_user_id === (int) $user->ID ) {
		$state['status']                      = 'aligned';
		$state['native_checkout_ready']       = true;
		$state['native_cookie_already_valid'] = true;
		return $state;
	}

	if ( $native_user_id > 0 ) {
		$native_user = get_user_by( 'id', $native_user_id );
		if ( $native_user instanceof WP_User && dtb_auth_user_is_privileged( $native_user ) ) {
			$state['status'] = 'blocked_native_privileged_conflict';
			if ( function_exists( 'dtb_security_log' ) ) {
				dtb_security_log( 'native_privileged_identity_conflict_blocked', [ 'route' => $route ] );
			}
			return $state;
		}
	}

	if ( headers_sent() ) {
		$state['status'] = 'failed_headers_sent';
		if ( function_exists( 'dtb_security_log' ) ) {
			dtb_security_log( 'native_customer_cookie_headers_sent', [ 'route' => $route ] );
		}
		return $state;
	}

	if ( $native_user_id > 0 && $native_user_id !== (int) $user->ID ) {
		if ( function_exists( 'dtb_native_checkout_expire_woocommerce_browser_state' ) ) {
			dtb_native_checkout_expire_woocommerce_browser_state();
		}
		wp_clear_auth_cookie();
		$state['identity_conflict_contained'] = true;
		if ( function_exists( 'dtb_security_log' ) ) {
			dtb_security_log( 'native_customer_identity_conflict_contained', [ 'route' => $route ] );
		}
	}

	wp_set_current_user( (int) $user->ID );
	wp_set_auth_cookie( (int) $user->ID, false, is_ssl() );
	dtb_auth_signal_session_mutation();

	$state['status']                = $state['identity_conflict_contained'] ? 'conflict_replaced' : 'bridged';
	$state['native_checkout_ready'] = true;
	$state['native_cookie_queued']  = true;
	return $state;
}

function dtb_auth_fail_closed_on_privileged_native_conflict( $response, array $state, string $route ): void {
	if ( 'blocked_native_privileged_conflict' !== ( $state['status'] ?? '' ) ) {
		return;
	}
	if ( function_exists( 'dtb_clear_auth_cookie' ) ) {
		dtb_clear_auth_cookie();
	}
	if ( ! ( $response instanceof WP_REST_Response || $response instanceof WP_HTTP_Response ) ) {
		return;
	}
	$data = $response->get_data();
	if ( ! is_array( $data ) ) {
		return;
	}
	$data['success']       = false;
	$data['authenticated'] = false;
	$data['user']          = null;
	$data['message']       = 'A WordPress administrator session is active in this browser. Open the storefront in a private window or sign out of WordPress before signing in as a customer.';
	$response->set_data( $data );
	$response->set_status( 409 );
	dtb_auth_signal_session_mutation();
}

function dtb_auth_user_is_privileged( WP_User $user ): bool {
	return user_can( $user, 'manage_options' ) || user_can( $user, 'edit_users' );
}

function dtb_auth_valid_native_cookie_user_id(): int {
	if ( ! function_exists( 'wp_validate_auth_cookie' ) ) {
		return 0;
	}
	$cookie_candidates = [];
	if ( defined( 'LOGGED_IN_COOKIE' ) && ! empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
		$cookie_candidates['logged_in'] = wp_unslash( (string) $_COOKIE[ LOGGED_IN_COOKIE ] );
	}
	if ( defined( 'SECURE_AUTH_COOKIE' ) && ! empty( $_COOKIE[ SECURE_AUTH_COOKIE ] ) ) {
		$cookie_candidates['secure_auth'] = wp_unslash( (string) $_COOKIE[ SECURE_AUTH_COOKIE ] );
	}
	if ( defined( 'AUTH_COOKIE' ) && ! empty( $_COOKIE[ AUTH_COOKIE ] ) ) {
		$cookie_candidates['auth'] = wp_unslash( (string) $_COOKIE[ AUTH_COOKIE ] );
	}
	foreach ( $cookie_candidates as $scheme => $cookie ) {
		$user_id = wp_validate_auth_cookie( $cookie, $scheme );
		if ( $user_id ) {
			return absint( $user_id );
		}
	}
	return 0;
}

function dtb_auth_attach_native_session_state( $response, array $state ): void {
	if ( ! ( $response instanceof WP_REST_Response || $response instanceof WP_HTTP_Response ) ) {
		return;
	}
	$data = $response->get_data();
	if ( ! is_array( $data ) ) {
		return;
	}
	$session = isset( $data['session'] ) && is_array( $data['session'] ) ? $data['session'] : [];
	$session['native_checkout'] = [
		'status'                      => sanitize_key( (string) ( $state['status'] ?? 'unknown' ) ),
		'ready'                       => (bool) ( $state['native_checkout_ready'] ?? false ),
		'cookie_queued'               => (bool) ( $state['native_cookie_queued'] ?? false ),
		'cookie_already_valid'        => (bool) ( $state['native_cookie_already_valid'] ?? false ),
		'identity_conflict_contained' => (bool) ( $state['identity_conflict_contained'] ?? false ),
	];
	$data['session'] = $session;
	$response->set_data( $data );
}

function dtb_auth_clear_native_customer_cookie(): void {
	if ( headers_sent() ) {
		return;
	}
	$native_user_id = dtb_auth_valid_native_cookie_user_id();
	if ( $native_user_id <= 0 ) {
		return;
	}
	$native_user = get_user_by( 'id', $native_user_id );
	if ( ! $native_user instanceof WP_User || dtb_auth_user_is_privileged( $native_user ) ) {
		return;
	}
	wp_clear_auth_cookie();
}

function dtb_auth_signal_session_mutation(): void {
	if ( ! headers_sent() ) {
		header( 'X-DTB-Session-Mutated: 1', true );
	}
}
