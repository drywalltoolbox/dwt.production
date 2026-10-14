<?php
/**
 * Rate limit WooCommerce Store API cart/checkout mutations.
 *
 * Provider-specific bypasses are intentionally prohibited. WooPayments owns its
 * wallet/payment request lifecycle and must use its own verified
 * endpoints and nonces. Ordinary WooCommerce Store API writes remain subject to
 * this generous per-fingerprint containment boundary.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'rest_pre_dispatch', 'dtb_store_api_checkout_rate_limit', 5, 3 );

/**
 * @param mixed $result Existing short-circuit result, if any.
 * @return mixed
 */
function dtb_store_api_checkout_rate_limit( $result, WP_REST_Server $server, WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	if ( null !== $result || ! class_exists( 'DTB_RateLimiter' ) ) {
		return $result;
	}

	if ( ! in_array( $request->get_method(), [ 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) ) {
		return $result;
	}

	if ( ! dtb_store_api_checkout_rate_limit_route( (string) $request->get_route() ) ) {
		return $result;
	}

	/**
	 * Trusted integrations may opt out only through an explicitly reviewed filter.
	 * No browser-supplied provider/wallet header is sufficient for a bypass.
	 *
	 * @param bool            $bypass  Whether to skip rate limiting.
	 * @param WP_REST_Request $request Current request.
	 */
	if ( apply_filters( 'dtb_store_api_checkout_rate_limit_bypass', false, $request ) ) {
		return $result;
	}

	/**
	 * Defaults allow normal retries, quantity/coupon changes, address edits, and
	 * payment attempts while containing scripted mutation amplification.
	 *
	 * @param array{bucket:string,limit:int,window:int} $settings
	 * @param WP_REST_Request                            $request
	 */
	$settings = apply_filters(
		'dtb_store_api_checkout_rate_limit_settings',
		[
			'bucket' => 'store_api_checkout_mutation',
			'limit'  => 90,
			'window' => 5 * MINUTE_IN_SECONDS,
		],
		$request
	);

	$limit_check = DTB_RateLimiter::check(
		sanitize_key( (string) ( $settings['bucket'] ?? 'store_api_checkout_mutation' ) ),
		(int) ( $settings['limit'] ?? 90 ),
		(int) ( $settings['window'] ?? 5 * MINUTE_IN_SECONDS )
	);
	if ( true === $limit_check ) {
		return $result;
	}

	if ( function_exists( 'dtb_security_log' ) ) {
		dtb_security_log( 'store_api_checkout_rate_limited', [ 'route' => $request->get_route() ] );
	}

	return $limit_check;
}

/** Whether a Store API route is a mutating cart/checkout endpoint. */
function dtb_store_api_checkout_rate_limit_route( string $route ): bool {
	foreach ( [ '/wc/store/v1/cart', '/wc/store/v1/checkout', '/wc/store/cart', '/wc/store/checkout' ] as $prefix ) {
		if ( 0 === strpos( $route, $prefix ) ) {
			return true;
		}
	}
	return false;
}
