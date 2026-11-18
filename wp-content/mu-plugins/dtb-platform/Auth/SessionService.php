<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_SessionService' ) ) {
	return;
}

final class DTB_SessionService {
	/**
	 * Rotate the current Woo session to an anonymous cart-only session.
	 *
	 * This method is called only from the explicit DTB storefront logout route. It
	 * deliberately rotates the Woo shopper session even when a native WordPress
	 * administrator cookie also exists in the browser; the native admin cookie is a
	 * separate authority and must not cause Woo to leave behind a customer-bound
	 * shopper session that later becomes invalid when the browser is anonymous.
	 *
	 * Preserve only cart contents. Never preserve contact, address, shipping,
	 * coupon, payment, or checkout state across logout.
	 *
	 * @return bool True when the cart was preserved; false otherwise.
	 */
	public static function rotate_woocommerce_session_to_guest(): bool {
		if ( ! function_exists( 'WC' ) || ! WC() ) {
			self::expire_woocommerce_session_cookie();
			return false;
		}

		try {
			if ( function_exists( 'wc_load_cart' ) && ( ! WC()->session || ! WC()->cart ) ) {
				wc_load_cart();
			}

			$session = WC()->session;
			if (
				! is_object( $session )
				|| ! is_callable( [ $session, 'get' ] )
				|| ! is_callable( [ $session, 'set' ] )
				|| ! is_callable( [ $session, 'destroy_session' ] )
			) {
				self::expire_woocommerce_session_cookie();
				return false;
			}

			$cart = $session->get( 'cart', [] );
			$cart = is_array( $cart ) ? $cart : [];

			/*
			 * WC_Session_Handler chooses the replacement customer ID from the current
			 * WordPress user. Explicit storefront logout must therefore transition to
			 * user 0 before destroying the customer-owned Woo session.
			 */
			wp_set_current_user( 0 );
			$session->destroy_session();

			if ( empty( $cart ) ) {
				return false;
			}

			$session->set( 'cart', $cart );
			if ( is_callable( [ $session, 'set_customer_session_cookie' ] ) ) {
				$session->set_customer_session_cookie( true );
			}
			if ( is_callable( [ $session, 'save_data' ] ) ) {
				$session->save_data();
			}

			return true;
		} catch ( Throwable $error ) {
			self::expire_woocommerce_session_cookie();
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					'WooCommerce session rotation failed during storefront logout; the session cookie was expired as a privacy-safe fallback.',
					[
						'source'      => 'dtb-auth',
						'error_class' => get_class( $error ),
					]
				);
			}
			return false;
		}
	}

	/** Expire the current Woo session cookie without exposing its contents. */
	private static function expire_woocommerce_session_cookie(): void {
		if ( ! defined( 'COOKIEHASH' ) ) {
			return;
		}

		$cookie_name = (string) apply_filters( 'woocommerce_cookie', 'wp_woocommerce_session_' . COOKIEHASH );
		if ( function_exists( 'wc_setcookie' ) ) {
			wc_setcookie( $cookie_name, '', time() - YEAR_IN_SECONDS, is_ssl(), true );
		} else {
			setcookie( $cookie_name, '', [
				'expires'  => time() - YEAR_IN_SECONDS,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			] );
		}
		unset( $_COOKIE[ $cookie_name ] );
	}

	/** Expire Woo's non-authoritative cart marker cookies after identity conflict. */
	private static function expire_cart_marker_cookies(): void {
		foreach ( [ 'woocommerce_cart_hash', 'woocommerce_items_in_cart' ] as $cookie_name ) {
			if ( function_exists( 'wc_setcookie' ) ) {
				wc_setcookie( $cookie_name, '', time() - YEAR_IN_SECONDS );
			} else {
				setcookie( $cookie_name, '', [
					'expires'  => time() - YEAR_IN_SECONDS,
					'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
					'secure'   => is_ssl(),
					'httponly' => false,
					'samesite' => 'Lax',
				] );
			}
			unset( $_COOKIE[ $cookie_name ] );
		}
	}
}
