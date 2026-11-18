<?php
/**
 * WooPayments is the sole DTB storefront payment integration. WooCommerce owns
 * checkout, cart/session, order state and refunds; WooPayments owns collection,
 * authentication, capture, wallets and provider webhooks. DTB only applies an
 * environment safety policy, publishes non-secret capability state, and reads
 * provider-confirmed payment evidence for its own order lifecycle.
 */
defined( 'ABSPATH' ) || exit;

final class DTB_WooPaymentsNativeCheckout {
	public const CHECKOUT_GATEWAY = 'woo_native_woopayments';
	public const CONTRACT_VERSION = 'woopayments-v1';
	public const PROVIDER = 'woopayments';

	public static function register(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
		add_filter( 'rest_pre_dispatch', [ __CLASS__, 'guard_production_test_checkout_submission' ], -200, 3 );
		add_filter( 'woocommerce_available_payment_gateways', [ __CLASS__, 'filter_storefront_gateways' ], 1000 );
		add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'tag_checkout' ], 20 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'observe_order' ], 10 );
		add_action( 'woocommerce_payment_complete', [ __CLASS__, 'observe_order_id' ], 8 );
		add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'observe_order_id' ], 8 );
		add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'observe_order_id' ], 8 );
	}

	public static function register_rest_routes(): void {
		register_rest_route( 'dtb/v1', '/checkout/capabilities', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'checkout_capabilities' ],
			'permission_callback' => '__return_true',
		] );
	}

	/** Public, non-secret summary used for advisory UI and deployment checks. */
	public static function checkout_capabilities(): WP_REST_Response {
		$gateways = function_exists( 'WC' ) && WC() && WC()->payment_gateways()
			? WC()->payment_gateways()->payment_gateways()
			: [];
		$gateway = is_array( $gateways ) ? ( $gateways['woocommerce_payments'] ?? null ) : null;
		$enabled = is_object( $gateway ) && 'yes' === (string) ( $gateway->enabled ?? '' );
		$test_mode = self::is_test_mode();
		return new WP_REST_Response( [
			'checkout' => 'woo_native_checkout_block',
			'contract' => self::CONTRACT_VERSION,
			'provider' => self::PROVIDER,
			'gateways' => [ [ 'id' => 'woocommerce_payments', 'title' => 'WooPayments', 'enabled' => $enabled, 'provider' => self::PROVIDER ] ],
			'readiness' => [
				'checkout_block' => self::checkout_block_ready(),
				'https' => is_ssl(),
				'gateway_enabled' => $enabled,
				'provider_test_mode' => $test_mode,
				'isolated_test_environment' => self::is_isolated_test_environment(),
				'test_checkout_available' => $test_mode && ( self::is_isolated_test_environment() || self::is_authorized_production_test_window() ),
				'payment_execution_authority' => self::PROVIDER,
			],
		] );
	}

	/**
	 * Test credentials must never be presented to customers on a shared/live
	 * host. Staging guests are enabled only by explicit environment config and
	 * distinct host and database verification; admin login alone does not change this policy.
	 */
	public static function filter_storefront_gateways( $gateways ): array {
		$gateways = is_array( $gateways ) ? $gateways : [];
		if ( ( is_admin() || self::is_order_transaction_endpoint() ) && ! ( self::is_test_mode() && ! self::is_isolated_test_environment() ) ) {
			return $gateways;
		}
		foreach ( array_keys( $gateways ) as $id ) {
			if ( ! self::is_gateway( (string) $id ) ) {
				unset( $gateways[ $id ] );
			}
		}
		if ( self::is_test_mode() && ! self::is_isolated_test_environment() && ! self::is_authorized_production_test_window() ) {
			foreach ( array_keys( $gateways ) as $id ) {
				if ( self::is_gateway( (string) $id ) ) {
					unset( $gateways[ $id ] );
				}
			}
		}
		return $gateways;
	}

	/**
	 * Enforce the storefront test gateway rule at the actual Store API order
	 * submission boundary. This must not rely on hiding payment methods in UI.
	 * Use a normal REST permission response and preserve production/live flow.
	 */
	public static function guard_production_test_checkout_submission( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof WP_REST_Request
			|| 'POST' !== strtoupper( (string) $request->get_method() )
			|| ! preg_match( '#^/wc/store/v[0-9]+/checkout/?$#', (string) $request->get_route() )
			|| ! self::is_test_mode()
			|| self::is_isolated_test_environment()
		) {
			return $result;
		}
		if ( self::is_authorized_production_test_window() ) {
			return $result;
		}
		return new WP_Error(
			'dtb_production_test_checkout_restricted',
			'Checkout is temporarily unavailable while payment testing is configured.',
			[ 'status' => 403 ]
		);
	}

	/**
	 * Production testing is opt-in, administrator-only, and expires absolutely.
	 * A fixed UTC Unix timestamp in private wp-config.php is required; accepting
	 * request parameters, admin cookies alone, or a relative TTL is prohibited.
	 * Never permit a live-mode provider through this exception.
	 */
	private static function is_authorized_production_test_window(): bool {
		if ( ! self::is_test_mode()
			|| ! is_ssl()
			|| ! defined( 'WP_ENVIRONMENT_TYPE' )
			|| 'production' !== WP_ENVIRONMENT_TYPE
			|| ! defined( 'DTB_WOOPAYMENTS_PRODUCTION_TEST_UNTIL' )
			|| ! is_user_logged_in()
			|| ! current_user_can( 'manage_woocommerce' )
		) {
			return false;
		}
		$until = filter_var( constant( 'DTB_WOOPAYMENTS_PRODUCTION_TEST_UNTIL' ), FILTER_VALIDATE_INT );
		$now = time();
		return false !== $until && $until > $now && $until <= $now + HOUR_IN_SECONDS;
	}

	public static function is_isolated_test_environment(): bool {
		if ( ! is_ssl() || ! defined( 'WP_ENVIRONMENT_TYPE' ) || 'staging' !== WP_ENVIRONMENT_TYPE
			|| ! defined( 'DTB_CHECKOUT_ENVIRONMENT' ) || 'staging' !== DTB_CHECKOUT_ENVIRONMENT
			|| ! defined( 'DTB_WOOPAYMENTS_TEST_CHECKOUT_ENABLED' ) || true !== DTB_WOOPAYMENTS_TEST_CHECKOUT_ENABLED
			|| ! defined( 'DTB_PRODUCTION_ORIGIN' ) || ! defined( 'DTB_PRODUCTION_DB_NAME' ) || ! defined( 'DB_NAME' ) ) {
			return false;
		}
		$stage = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$prod_host = strtolower( (string) wp_parse_url( DTB_PRODUCTION_ORIGIN, PHP_URL_HOST ) );
		$stage_db  = strtolower( trim( (string) DB_NAME ) );
		$prod_db   = strtolower( trim( (string) DTB_PRODUCTION_DB_NAME ) );
		return '' !== $stage && '' !== $prod_host && $stage !== $prod_host
			&& '' !== $stage_db && '' !== $prod_db && $stage_db !== $prod_db;
	}

	private static function is_test_mode(): bool {
		if ( class_exists( 'WC_Payments' ) && is_callable( [ 'WC_Payments', 'mode' ] ) ) {
			$mode = WC_Payments::mode();
			return is_object( $mode ) && is_callable( [ $mode, 'is_test' ] ) && (bool) $mode->is_test();
		}
		return false;
	}

	private static function is_order_transaction_endpoint(): bool {
		return function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) );
	}

	private static function checkout_block_ready(): bool {
		$page_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'checkout' ) : 0;
		return $page_id > 0 && has_block( 'woocommerce/checkout', (string) get_post_field( 'post_content', $page_id ) );
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
		if ( self::CHECKOUT_GATEWAY !== (string) $order->get_meta( '_dtb_checkout_gateway', true ) ) {
			return;
		}
		$mode = sanitize_key( (string) $order->get_meta( '_wcpay_mode', true ) );
		$reference = trim( (string) $order->get_transaction_id() );
		$intent_status = sanitize_key( (string) $order->get_meta( '_intention_status', true ) );
		$intent_id = trim( (string) $order->get_meta( '_intent_id', true ) );
		// No assumption that merely authorized or redirected means captured.
		if ( 'succeeded' !== $intent_status
			|| '' === $intent_id
			|| ! in_array( $mode, [ 'test', 'prod' ], true )
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
		// Never relabel an existing payment contract.
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
