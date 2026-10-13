<?php
/**
 * WooPayments order observation. WooCommerce and WooPayments remain the only
 * payment / order authorities. No gateway capture, API requests or refunds.
 */
defined( 'ABSPATH' ) || exit;

final class DTB_WooPaymentsNativeCheckout {
	public const CHECKOUT_GATEWAY = 'woo_native_woopayments';
	public const CONTRACT_VERSION = 'woopayments-v1';
	public const PROVIDER = 'woopayments';

	public static function register(): void {
		add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'tag_checkout' ], 20 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'observe_order' ], 10 );
		add_action( 'woocommerce_payment_complete', [ __CLASS__, 'observe_order_id' ], 8 );
		add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'observe_order_id' ], 8 );
		add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'observe_order_id' ], 8 );
	}

	public static function is_gateway( string $id ): bool {
		$id = sanitize_key( $id );
		return 'woocommerce_payments' === $id || str_starts_with( $id, 'woocommerce_payments_' );
	}

	public static function tag_checkout( $order ): void {
		if ( $order instanceof WC_Order && self::is_gateway( (string) $order->get_payment_method() ) ) {
			self::tag( $order );
		}
	}

	public static function observe_order_id( $order_id ): void {
		if ( function_exists( 'wc_get_order' ) ) {
			self::observe_order( wc_get_order( (int) $order_id ) );
		}
	}

	public static function observe_order( $order ): void {
		if ( ! $order instanceof WC_Order || ! self::is_gateway( (string) $order->get_payment_method() ) ) {
			return;
		}

		self::tag( $order );
		$mode = sanitize_key( (string) $order->get_meta( '_wcpay_mode', true ) );
		$reference = trim( (string) $order->get_transaction_id() );
		// No assumption that merely authorized or redirected means captured.
		if ( ! in_array( $mode, [ 'test', 'prod' ], true )
			|| null === $order->get_date_paid()
			|| '' === $reference
			|| ! in_array( $order->get_status(), [ 'processing', 'completed' ], true )
		) {
			return;
		}

		$order->update_meta_data( '_dtb_payment_provider', self::PROVIDER );
		$order->update_meta_data( '_dtb_payment_ref', $reference );
		$order->update_meta_data( '_dtb_payment_captured', '1' );
		$order->update_meta_data( '_dtb_payment_lifecycle_source', 'woopayments_woocommerce_paid' );
		$order->save_meta_data();
	}

	private static function tag( WC_Order $order ): void {
		if ( ! self::is_gateway( (string) $order->get_payment_method() ) ) {
			return;
		}
		// Never relabel an existing Stripe/other payment contract.
		$previous = (string) $order->get_meta( '_dtb_checkout_gateway', true );
		if ( '' !== $previous && self::CHECKOUT_GATEWAY !== $previous ) {
			return;
		}
		$order->update_meta_data( '_dtb_checkout_gateway', self::CHECKOUT_GATEWAY );
		$order->update_meta_data( '_dtb_checkout_contract_version', self::CONTRACT_VERSION );
		$order->save_meta_data();
	}
}

DTB_WooPaymentsNativeCheckout::register();
