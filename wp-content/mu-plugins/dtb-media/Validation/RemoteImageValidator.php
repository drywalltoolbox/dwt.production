<?php
defined( 'ABSPATH' ) || exit;

/**
 * Return true when the current user may manage the DTB image sync.
 *
 * Accepts manage_woocommerce (WooCommerce shop managers / admins) OR
 * manage_options (standard WordPress administrator capability). The fallback
 * to manage_options prevents 403 errors when WooCommerce capabilities have not
 * yet been flushed onto the administrator role — e.g. after a fresh WC install,
 * a capability reset, or a WC version upgrade that regenerates role data.
 */
function dtb_image_sync_can_manage(): bool {
	return current_user_can( 'dtb_manage_image_sync' )
		|| current_user_can( 'manage_woocommerce' )
		|| current_user_can( 'manage_options' );
}

/**
 * Accepts either:
 *   A) A WP session with manage_woocommerce OR manage_options capability.
 *      manage_options is the standard WP administrator cap and is checked as a
 *      fallback so that admin access works even when WooCommerce capabilities
 *      have not yet been flushed onto the administrator role (e.g. after a
 *      fresh WC install or a capability reset).
 *   B) A valid DTB JWT cookie/header via dtb_jwt_permission() (dtb-auth.php).
 *
 * WP REST passes the current WP_REST_Request object as the first argument to
 * every permission_callback automatically — we forward it to dtb_jwt_permission().
 */
function dtb_image_sync_permission( WP_REST_Request $request ): bool {
	// Fast path: WP session already authenticated (e.g. wp-admin browser request).
	// Accept manage_woocommerce (shop managers) OR manage_options (administrators).
	if ( is_user_logged_in() && dtb_image_sync_can_manage() ) {
		return true;
	}

	// JWT path: validate the token, then check roles embedded in the payload.
	// We do NOT rely on current_user_can() here because wp_set_current_user()
	// is never called for JWT-only requests — the WP session remains anonymous.
	// dtb_verify_jwt() returns the decoded payload object (with ->roles) on
	// success, so we read the role directly from the token claims.
	if ( function_exists( 'dtb_verify_jwt' ) ) {
		// Re-extract the raw token the same way dtb_jwt_permission does.
		$token = null;

		if ( ! empty( $_COOKIE['dtb_auth'] ) ) {
			$token = sanitize_text_field( wp_unslash( $_COOKIE['dtb_auth'] ) );
		}

		if ( ! $token ) {
			$auth = $request->get_header( 'authorization' );

			if ( ! $auth && ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
				$auth = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
			}
			if ( ! $auth && ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
				$auth = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
			}

			if ( $auth && preg_match( '/^Bearer\s+(\S+)$/i', $auth, $m ) ) {
				$token = $m[1];
			}
		}

		if ( $token ) {
			$payload = dtb_verify_jwt( $token );
			if ( ! is_wp_error( $payload ) ) {
				$roles = isset( $payload->roles ) ? (array) $payload->roles : [];
				// Allowed roles: administrator, shop_manager, or any role with manage_woocommerce.
				$allowed = [ 'administrator', 'shop_manager' ];
				if ( array_intersect( $allowed, $roles ) ) {
					return true;
				}
			}
		}
	}

	return false;
}

