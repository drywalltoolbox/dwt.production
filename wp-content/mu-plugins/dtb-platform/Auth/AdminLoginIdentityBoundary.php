<?php
/**
 * DTB storefront/customer and wp-admin login identity boundary.
 *
 * The storefront JWT remains authoritative for the React customer session. The
 * native WordPress customer cookie created for WooCommerce checkout continuity
 * must not prevent the same browser from reaching wp-login.php and replacing
 * that native identity with an authorized administrator account.
 *
 * This module clears only native WordPress authentication cookies at the admin
 * login boundary. It never clears, reads, logs, or exposes the HttpOnly DTB JWT.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'login_init', 'dtb_auth_release_customer_identity_for_admin_login', 0 );

/**
 * Release a non-privileged native WordPress identity before wp-login processes
 * an administrator login request.
 *
 * The storefront JWT cookie is deliberately preserved. After an administrator
 * signs in, WordPress owns the native wp-admin identity while DTB continues to
 * own the storefront JWT. Existing privileged-session conflict protection still
 * fails closed if a storefront operation attempts to bridge over that admin.
 */
function dtb_auth_release_customer_identity_for_admin_login(): void {
	$action = isset( $_REQUEST['action'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		: 'login';

	if ( ! in_array( $action, [ 'login', 'reauth' ], true ) ) {
		return;
	}

	if ( ! function_exists( 'dtb_auth_valid_native_cookie_user_id' ) ) {
		return;
	}

	$user_id = dtb_auth_valid_native_cookie_user_id();
	if ( $user_id <= 0 ) {
		return;
	}

	$user = get_user_by( 'id', $user_id );
	if ( ! $user instanceof WP_User ) {
		return;
	}

	if ( function_exists( 'dtb_auth_user_is_privileged' ) && dtb_auth_user_is_privileged( $user ) ) {
		return;
	}

	if ( headers_sent() ) {
		if ( function_exists( 'dtb_security_log' ) ) {
			dtb_security_log( 'admin_login_customer_cookie_release_failed_headers_sent', [] );
		}
		return;
	}

	wp_clear_auth_cookie();
	wp_set_current_user( 0 );
	dtb_auth_unset_native_cookie_request_state();

	if ( function_exists( 'dtb_security_log' ) ) {
		dtb_security_log( 'admin_login_customer_cookie_released', [] );
	}
}

/**
 * Remove native WordPress cookie values from the current PHP request after
 * their browser-side expiration headers have been queued.
 */
function dtb_auth_unset_native_cookie_request_state(): void {
	$cookie_constants = [
		'AUTH_COOKIE',
		'SECURE_AUTH_COOKIE',
		'LOGGED_IN_COOKIE',
		'USER_COOKIE',
		'PASS_COOKIE',
	];

	foreach ( $cookie_constants as $constant ) {
		if ( ! defined( $constant ) ) {
			continue;
		}

		$cookie_name = (string) constant( $constant );
		if ( '' !== $cookie_name ) {
			unset( $_COOKIE[ $cookie_name ] );
		}
	}
}
