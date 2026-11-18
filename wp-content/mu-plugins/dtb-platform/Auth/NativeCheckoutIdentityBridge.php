<?php
/**
 * Resolve verified DTB customer identity before native WooCommerce checkout.
 * This boundary runs inside determine_current_user and must not initialize WC sessions.
 *
 * @package drywalltoolbox
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'determine_current_user', 'dtb_native_checkout_resolve_current_user', 25 );

function dtb_native_checkout_resolve_current_user( $user_id ) {
	static $resolving = false;

	if ( $resolving || ! dtb_native_checkout_identity_bridge_request() ) {
		return $user_id;
	}

	$resolving = true;
	try {
		return dtb_native_checkout_resolve_current_user_inner( $user_id );
	} catch ( Throwable $error ) {
		dtb_native_checkout_log_security_event( 'native_checkout_identity_bridge_failed', absint( $user_id ), 0, 'unknown', 'failed' );
		return $user_id;
	} finally {
		$resolving = false;
	}
}

function dtb_native_checkout_resolve_current_user_inner( $user_id ) {
	$native_user_id       = ! empty( $user_id ) ? absint( $user_id ) : 0;
	$native_user          = $native_user_id > 0 ? get_user_by( 'id', $native_user_id ) : false;
	$native_is_privileged = $native_user instanceof WP_User && dtb_native_checkout_user_is_privileged( $native_user );
	$woo_customer_kind    = dtb_native_checkout_woo_customer_kind( $native_user_id, $native_is_privileged );

	$token = ! empty( $_COOKIE['dtb_auth'] )
		? sanitize_text_field( wp_unslash( (string) $_COOKIE['dtb_auth'] ) )
		: '';

	if ( '' === $token ) {
		if ( $native_is_privileged ) {
			dtb_native_checkout_log_security_event( 'native_checkout_privileged_native_preserved', $native_user_id, 0, $woo_customer_kind, 'privileged_preserved' );
			return $user_id;
		}

		if ( $native_user_id > 0 ) {
			dtb_native_checkout_log_security_event( 'native_checkout_native_customer_preserved_without_jwt', $native_user_id, 0, $woo_customer_kind, 'native_preserved' );
			return $user_id;
		}

		dtb_native_checkout_log_security_event( 'native_checkout_guest_without_jwt', 0, 0, 'guest', 'guest' );
		return false;
	}

	$verification = DTB_JwtService::verify( $token );
	if ( is_wp_error( $verification ) ) {
		$event = 'token_expired' === $verification->get_error_code()
			? 'native_checkout_expired_jwt_cookie_ignored'
			: 'native_checkout_invalid_jwt_cookie_ignored';

		if ( $native_user_id > 0 ) {
			dtb_native_checkout_log_security_event( $event, $native_user_id, 0, $woo_customer_kind, $native_is_privileged ? 'privileged_preserved' : 'native_preserved' );
			return $user_id;
		}

		dtb_native_checkout_log_security_event( $event, 0, 0, 'guest', 'invalid_jwt' );
		return false;
	}

	$resolved = absint( $verification->sub ?? 0 );
	$user     = $resolved > 0 ? get_user_by( 'id', $resolved ) : false;
	if ( ! $user instanceof WP_User || dtb_native_checkout_user_is_privileged( $user ) ) {
		dtb_native_checkout_log_security_event( 'native_checkout_jwt_user_rejected', $native_user_id, $resolved, $woo_customer_kind, 'jwt_user_rejected' );
		return $user_id;
	}

	if ( $native_user_id > 0 && $native_user_id === $resolved ) {
		dtb_native_checkout_log_security_event( 'native_checkout_identity_aligned', $native_user_id, $resolved, $woo_customer_kind, 'aligned' );
		return $user_id;
	}

	if ( $native_user_id > 0 && $native_user_id !== $resolved ) {
		if ( $native_is_privileged ) {
			dtb_native_checkout_log_security_event( 'native_checkout_privileged_identity_conflict_blocked', $native_user_id, $resolved, $woo_customer_kind, 'privileged_conflict_blocked' );
			return $user_id;
		}

		/* Privacy isolation outranks cart preservation for a true customer conflict. */
		dtb_native_checkout_expire_woocommerce_browser_state();
		if ( ! headers_sent() ) {
			wp_clear_auth_cookie();
			wp_set_auth_cookie( $resolved, false, is_ssl() );
		}
		dtb_native_checkout_log_security_event( 'native_checkout_identity_conflict_contained', $native_user_id, $resolved, $woo_customer_kind, 'conflict_replaced' );
		return $resolved;
	}

	/*
	 * A browser with no native WP cookie at all is a true anonymous visit. Only
	 * silently establish a native login here when there's a real signal that this
	 * request is an active login/checkout action rather than an incidental page
	 * view: either the JWT was issued moments ago (a fresh storefront login just
	 * handed off to /checkout) or this is a POST (an actual checkout submission).
	 * Otherwise a browser that merely still carries a week-old dtb_auth cookie from
	 * an earlier storefront session would get silently logged into native WP on
	 * every plain visit to /checkout (and, via the admin identity boundary, later
	 * to /wp-admin/ too) even though the shopper never took a login action this
	 * visit — surprising the shopper and orphaning any guest cart they built up.
	 */
	if ( ! dtb_native_checkout_bridge_request_is_active_action( $verification ) ) {
		dtb_native_checkout_log_security_event( 'native_checkout_stale_jwt_bridge_skipped', 0, $resolved, 'guest', 'stale_jwt_skipped' );
		return false;
	}

	if ( headers_sent() ) {
		/*
		 * Without the auth cookie the browser never receives the login, so setting
		 * current_user/firing wp_login here would only bridge this single request
		 * and leave the browser back on anonymous on the very next one — a
		 * misleading partial-bridge state. Stay anonymous instead.
		 */
		dtb_native_checkout_log_security_event( 'native_checkout_bridge_skipped_headers_sent', 0, $resolved, 'guest', 'headers_sent' );
		return false;
	}

	wp_set_auth_cookie( $resolved, false, is_ssl() );
	wp_set_current_user( $resolved );
	/*
	 * WooCommerce only merges/migrates a guest cart into the account cart on the
	 * `wp_login` action (see WC_Cart_Session::load_cart()). Without firing it here,
	 * flipping current_user mid-request via determine_current_user orphans the
	 * anonymous session's cart instead of merging it, which empties the cart the
	 * shopper was just checking out with.
	 */
	do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	dtb_native_checkout_log_security_event( 'native_checkout_identity_bridged', 0, $resolved, 'guest', 'bridged' );
	return $resolved;
}

/**
 * Whether this request carries a real signal of an active login/checkout action,
 * as opposed to an incidental page view riding on a stale JWT cookie.
 */
function dtb_native_checkout_bridge_request_is_active_action( object $verification ): bool {
	$fresh_login_window_seconds = 10 * MINUTE_IN_SECONDS;

	// REQUEST_METHOD is a trusted server-set token, not user input; sanitize_key()
	// would needlessly lowercase/strip it before we re-uppercase it here.
	$method = isset( $_SERVER['REQUEST_METHOD'] )
		? strtoupper( (string) $_SERVER['REQUEST_METHOD'] )
		: 'GET';
	if ( 'POST' === $method ) {
		return true;
	}

	$iat = isset( $verification->iat ) ? (int) $verification->iat : 0;
	return $iat > 0 && ( time() - $iat ) <= $fresh_login_window_seconds;
}

function dtb_native_checkout_user_is_privileged( WP_User $user ): bool {
	return user_can( $user, 'manage_options' ) || user_can( $user, 'edit_users' );
}

/**
 * Verify a DTB storefront JWT and return the customer ID it represents.
 *
 * This is the canonical name the storefront identity guards use when they resolve
 * the verified customer before this bridge runs. Verification itself is delegated
 * to DTB_JwtService so signature, expiry, and claim handling keep a single
 * implementation.
 *
 * @param string $token Raw dtb_auth JWT.
 * @return int Verified user ID, or 0 when the token is absent or invalid.
 */
function dtb_native_checkout_verify_user_id( string $token ): int {
	if ( '' === $token || ! class_exists( 'DTB_JwtService' ) ) {
		return 0;
	}

	return DTB_JwtService::user_id( $token );
}

function dtb_native_checkout_clear_stale_customer_cookie( int $native_user_id ): void {
	if ( $native_user_id <= 0 || headers_sent() ) {
		return;
	}

	$native_user = get_user_by( 'id', $native_user_id );
	if ( $native_user instanceof WP_User && dtb_native_checkout_user_is_privileged( $native_user ) ) {
		return;
	}
	wp_clear_auth_cookie();
}

function dtb_native_checkout_expire_woocommerce_browser_state(): void {
	if ( headers_sent() ) {
		return;
	}

	$cookie_names = [ 'woocommerce_cart_hash', 'woocommerce_items_in_cart' ];
	if ( defined( 'COOKIEHASH' ) ) {
		$cookie_names[] = 'wp_woocommerce_session_' . COOKIEHASH;
	}

	foreach ( $cookie_names as $cookie_name ) {
		setcookie(
			$cookie_name,
			'',
			[
				'expires'  => time() - YEAR_IN_SECONDS,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'secure'   => is_ssl(),
				'httponly' => str_starts_with( $cookie_name, 'wp_woocommerce_session_' ),
				'samesite' => 'Lax',
			]
		);
		unset( $_COOKIE[ $cookie_name ] );
	}
}

function dtb_native_checkout_woo_customer_kind( int $native_user_id, bool $native_is_privileged ): string {
	if ( $native_is_privileged ) {
		return 'privileged_native';
	}
	return $native_user_id > 0 ? 'native_customer' : 'guest';
}

function dtb_native_checkout_log_security_event(
	string $event,
	int $native_user_id = 0,
	int $jwt_user_id = 0,
	string $woo_customer_kind = 'unknown',
	string $handoff_status = 'unknown'
): void {
	$context = [
		'native_user_id'    => absint( $native_user_id ),
		'jwt_user_id'       => absint( $jwt_user_id ),
		'woo_customer_kind' => sanitize_key( $woo_customer_kind ),
		'handoff_status'    => sanitize_key( $handoff_status ),
	];

	if ( function_exists( 'dtb_security_log' ) ) {
		dtb_security_log( $event, $context );
		return;
	}

	// Fallback only if the shared audit logger somehow isn't loaded yet.
	error_log( (string) wp_json_encode( array_merge( [ 'event' => sanitize_key( $event ) ], $context ), JSON_UNESCAPED_SLASHES ) );
}

function dtb_native_checkout_identity_bridge_request(): bool {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return false;
	}

	$method = isset( $_SERVER['REQUEST_METHOD'] )
		? strtoupper( sanitize_key( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
		: 'GET';
	if ( ! in_array( $method, [ 'GET', 'POST' ], true ) ) {
		return false;
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] )
		? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		: '';
	$path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );

	if ( preg_match( '#^/checkout(?:/|$)#i', $path ) ) {
		return true;
	}

	if ( '/wp/index.php' === rtrim( $path, '/' ) || '/index.php' === rtrim( $path, '/' ) ) {
		$pagename = isset( $_GET['pagename'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( wp_unslash( (string) $_GET['pagename'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';
		return 'checkout' === $pagename;
	}

	return false;
}
