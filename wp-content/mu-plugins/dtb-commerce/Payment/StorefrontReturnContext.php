<?php
/**
 * Route native WooCommerce checkout returns to the root-mounted React storefront.
 *
 * WordPress `home_url()` is the sole public-domain authority. No request,
 * session, or order metadata can select a different storefront host or mount.
 *
 * @package drywalltoolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_StorefrontReturnContext {
	public static function register(): void {
		add_filter( 'woocommerce_get_return_url', [ __CLASS__, 'filter_success_return_url' ], 1000, 2 );
		add_filter( 'woocommerce_return_to_shop_redirect', [ __CLASS__, 'filter_catalog_return_url' ], 1000 );
		add_filter( 'woocommerce_continue_shopping_redirect', [ __CLASS__, 'filter_catalog_return_url' ], 1000 );
	}

	public static function filter_success_return_url( string $return_url, $order = null ): string {
		if ( ! $order instanceof WC_Order || ! self::is_dtb_checkout_order( $order ) ) {
			return $return_url;
		}
		if ( ! self::is_confirmable_order( $order ) ) {
			self::log_unverified_success_redirect( $order );
			return $return_url;
		}

		return add_query_arg(
			[
				'order_key'        => $order->get_order_key(),
				'checkout_complete' => '1',
			],
			home_url( '/order-tracking/' . absint( $order->get_id() ) )
		);
	}

	public static function filter_catalog_return_url( string $url ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return home_url( '/products' );
	}

	private static function is_confirmable_order( WC_Order $order ): bool {
		if ( function_exists( 'dtb_checkout_handoff_has_captured_payment' ) && dtb_checkout_handoff_has_captured_payment( $order ) ) {
			return true;
		}
		$status = sanitize_key( (string) $order->get_status() );
		return (float) $order->get_total() <= 0
			&& ! in_array( $status, [ 'checkout-draft', 'draft', 'auto-draft', 'pending', 'failed', 'cancelled', 'refunded', 'trash' ], true );
	}

	private static function log_unverified_success_redirect( WC_Order $order ): void {
		$context = [
			'source'   => 'dtb-checkout',
			'event'    => 'checkout_success_redirect_blocked_unverified_order',
			'order_id' => (int) $order->get_id(),
			'status'   => sanitize_key( (string) $order->get_status() ),
		];
		if ( function_exists( 'dtb_security_log' ) ) {
			dtb_security_log( 'checkout_success_redirect_blocked_unverified_order', $context );
			return;
		}
		error_log( (string) wp_json_encode( $context, JSON_UNESCAPED_SLASHES ) );
	}

	private static function is_dtb_checkout_order( WC_Order $order ): bool {
		$contract = (string) $order->get_meta( '_dtb_checkout_contract_version', true );
		return function_exists( 'dtb_checkout_handoff_is_order' ) && dtb_checkout_handoff_is_order( $order );
	}
}

DTB_StorefrontReturnContext::register();
