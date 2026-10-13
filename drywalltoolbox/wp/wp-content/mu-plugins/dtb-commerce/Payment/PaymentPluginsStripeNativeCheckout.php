<?php
/**
 * Payment Plugins for Stripe WooCommerce checkout integration.
 *
 * WooCommerce owns cart/session state, checkout fields, shipping, tax, totals,
 * order creation, refunds, and authoritative payment status. Payment Plugins for
 * Stripe WooCommerce owns Stripe payment methods, wallet eligibility, Stripe
 * Elements, tokenization, authentication, confirmation, capture, and webhooks.
 * DTB owns provider readiness, order tagging, captured-payment observation, and
 * redacted operational diagnostics only.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_PaymentPluginsStripeNativeCheckout {
	public const CHECKOUT_GATEWAY = 'woo_native_stripe';
	public const CONTRACT_VERSION = 'payment-plugins-stripe-v1';
	public const PROVIDER         = 'payment_plugins_stripe';

	private const UPM_GATEWAY_ID       = 'stripe_upm';
	private const MINIMUM_VERSION      = '4.0.8';
	private const PLUGIN_PATH_FRAGMENT = '/woo-stripe-payment/';

	/** @var array<int,string> */
	private const BNPL_GATEWAY_IDS = [
		'stripe_klarna',
		'stripe_afterpay',
		'stripe_affirm',
	];

	public static function register(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
		add_filter( 'woocommerce_available_payment_gateways', [ __CLASS__, 'enforce_single_stripe_authority' ], 1000 );
		add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'tag_checkout_order' ], 20, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'tag_store_api_order' ], 20 );
		add_action( 'woocommerce_payment_complete', [ __CLASS__, 'mirror_verified_stripe_payment' ], 9 );
		add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'mirror_verified_stripe_payment' ], 9 );
		add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'mirror_verified_stripe_payment' ], 9 );
		add_action( 'admin_notices', [ __CLASS__, 'admin_notices' ] );
	}

	public static function register_rest_routes(): void {
		register_rest_route(
			'dtb/v1',
			'/checkout/capabilities',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'checkout_capabilities' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public static function checkout_capabilities(): WP_REST_Response {
		$gateways = [];
		foreach ( self::payment_gateways() as $gateway ) {
			if ( ! self::is_payment_plugins_gateway_instance( $gateway ) ) {
				continue;
			}

			$id = sanitize_key( (string) ( $gateway->id ?? '' ) );
			$gateways[] = [
				'id'               => $id,
				'title'            => sanitize_text_field( (string) ( $gateway->method_title ?? $gateway->title ?? 'Stripe' ) ),
				'enabled'          => self::gateway_enabled( $gateway ),
				'express_checkout' => false,
				'universal_method' => self::UPM_GATEWAY_ID === $id,
				'provider'         => self::PROVIDER,
				'contract'         => self::CONTRACT_VERSION,
			];
		}

		$shipping_enabled      = function_exists( 'wc_shipping_enabled' ) && wc_shipping_enabled();
		$shipping_method_count = function_exists( 'wc_get_shipping_method_count' )
			? max( 0, (int) wc_get_shipping_method_count( true, true ) )
			: 0;
		$allowed_countries     = [];
		if ( function_exists( 'WC' ) && WC() && WC()->countries ) {
			$allowed_countries = array_keys( (array) WC()->countries->get_shipping_countries() );
		}

		$upm = self::gateway_by_id( self::UPM_GATEWAY_ID );

		return rest_ensure_response(
			[
				'checkout' => 'woo_native_checkout_block',
				'contract' => self::CONTRACT_VERSION,
				'provider' => self::PROVIDER,
				'gateways' => $gateways,
				'readiness' => [
					'stripe_extension_active'            => self::is_extension_active(),
					'stripe_extension_version'           => self::plugin_version(),
					'stripe_gateway_enabled'             => self::gateway_enabled( $upm ),
					'upm_gateway_enabled'                => self::gateway_enabled( $upm ),
					'upm_configuration_ready'            => self::upm_configuration_ready( $upm ),
					'apple_pay_enabled'                  => self::upm_method_enabled( 'stripe_applepay', $upm ),
					'apple_pay_express_checkout'         => false,
					'google_pay_enabled'                 => self::upm_method_enabled( 'stripe_googlepay', $upm ),
					'google_pay_express_checkout'        => false,
					'link_enabled'                       => self::upm_method_enabled( 'stripe_link', $upm ),
					'affirm_enabled'                     => self::upm_method_enabled( 'stripe_affirm', $upm ),
					'klarna_enabled'                     => self::upm_method_enabled( 'stripe_klarna', $upm ),
					'afterpay_enabled'                   => self::upm_method_enabled( 'stripe_afterpay', $upm ),
					'paypal_enabled'                     => self::is_any_gateway_enabled( [ 'ppcp-gateway', 'paypal' ] ),
					'express_checkout_enabled'           => false,
					'express_checkout_checkout_location' => false,
					'bnpl_gateway_count'                 => self::enabled_upm_method_count( self::BNPL_GATEWAY_IDS, $upm ),
					'checkout_block'                     => self::checkout_page_has_supported_content(),
					'https'                              => is_ssl(),
					'competing_official_stripe'          => self::is_official_woocommerce_stripe_active(),
					'competing_woopayments'              => self::is_gateway_enabled_by_id( 'woocommerce_payments' ),
					'shipping_enabled'                   => $shipping_enabled,
					'shipping_method_count'              => $shipping_method_count,
					'allowed_shipping_countries_count'   => count( $allowed_countries ),
					'wallet_shipping_ready'              => ! $shipping_enabled || ( $shipping_method_count > 0 && count( $allowed_countries ) > 0 ),
				],
			]
		);
	}

	/**
	 * Fail closed against competing storefront Stripe card/wallet authorities.
	 * The old plugins may remain installed for rollback or historical order review,
	 * but they are not offered on the primary checkout while the replacement card
	 * gateway is active. Order-pay remains untouched for explicit historical repair.
	 *
	 * @param mixed $gateways Available WooCommerce payment gateways.
	 * @return array<string,object>
	 */
	public static function enforce_single_stripe_authority( $gateways ): array {
		$gateways = is_array( $gateways ) ? $gateways : [];
		if ( ! self::is_storefront_payment_request() ) {
			return $gateways;
		}

		// A WooPayments test-mode checkout has one provider for new transactions.
		// This is deliberately inactive in WooPayments live mode. Never touch
		// historical Stripe orders, tokens, refunds or gateway registrations.
		if ( class_exists( 'WC_Payments' ) && is_callable( [ 'WC_Payments', 'mode' ] ) ) {
			$mode = WC_Payments::mode();
			if ( is_object( $mode ) && is_callable( [ $mode, 'is_test' ] ) && $mode->is_test() ) {
				// Never expose simulated payment methods to ordinary visitors on
				// the shared live WooCommerce backend. Only authorized operators
				// may see WooPayments while its account is in test mode.
				$operator_test = is_user_logged_in() && current_user_can( 'manage_woocommerce' );
				foreach ( $gateways as $id => $gateway ) {
					$woopayments = class_exists( 'DTB_WooPaymentsNativeCheckout' ) && DTB_WooPaymentsNativeCheckout::is_gateway( (string) $id );
					if ( $operator_test ? ! $woopayments : $woopayments ) {
						unset( $gateways[ $id ] );
					}
				}
				if ( $operator_test ) {
					return $gateways;
				}
			}
		}

		if ( ! self::is_upm_gateway_enabled() ) {
			return $gateways;
		}

		foreach ( $gateways as $id => $gateway ) {
			if (
				self::UPM_GATEWAY_ID !== sanitize_key( (string) $id )
				&& self::is_payment_plugins_gateway_instance( $gateway )
			) {
				unset( $gateways[ $id ] );
			}
		}
		unset( $gateways['stripe'], $gateways['woocommerce_payments'] );
		return $gateways;
	}

	public static function tag_checkout_order( WC_Order $order, array $data = [] ): void {
		unset( $data );
		self::tag_order_if_provider_order( $order, 'woocommerce_checkout' );
	}

	public static function tag_store_api_order( $order ): void {
		if ( $order instanceof WC_Order ) {
			self::tag_order_if_provider_order( $order, 'woocommerce_store_api_checkout' );
		}
	}

	public static function mirror_verified_stripe_payment( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order || ! self::is_payment_plugins_order( $order ) || null === $order->get_date_paid() ) {
			return;
		}

		$reference = self::gateway_reference( $order );
		if ( '' === $reference ) {
			return;
		}

		self::tag_order( $order, 'payment_plugins_stripe_lifecycle' );
		$order->update_meta_data( '_dtb_payment_provider', self::PROVIDER );
		$order->update_meta_data( '_dtb_payment_ref', $reference );
		$order->update_meta_data( '_dtb_payment_captured', '1' );
		$order->update_meta_data( '_dtb_payment_lifecycle_source', 'payment_plugins_stripe_lifecycle' );
		$order->save();
	}

	public static function stripe_badge_visible(): bool {
		return self::is_extension_active() && self::is_upm_gateway_enabled();
	}

	public static function is_payment_plugins_gateway_id( string $gateway_id ): bool {
		$gateway_id = sanitize_key( $gateway_id );
		if ( '' === $gateway_id || ! self::is_extension_active() ) {
			return false;
		}
		return self::is_payment_plugins_gateway_instance( self::gateway_by_id( $gateway_id ) );
	}

	public static function admin_notices(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) || ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		if ( ! self::is_extension_active() ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'Drywall Toolbox checkout requires Payment Plugins for Stripe WooCommerce. Install and activate woo-stripe-payment before testing payments.', 'drywall-toolbox' )
				. '</p></div>';
			return;
		}

		$version = self::plugin_version();
		if ( '' !== $version && version_compare( $version, self::MINIMUM_VERSION, '<' ) ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html( sprintf( __( 'Drywall Toolbox requires Payment Plugins for Stripe WooCommerce %1$s or newer. Installed version: %2$s.', 'drywall-toolbox' ), self::MINIMUM_VERSION, $version ) )
				. '</p></div>';
		}
		if ( ! self::is_upm_gateway_enabled() ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'Connect Payment Plugins for Stripe to Stripe and enable Universal Payment Methods (Stripe) before accepting checkout payments.', 'drywall-toolbox' )
				. '</p></div>';
		}
		if ( self::is_official_woocommerce_stripe_active() || self::is_gateway_enabled_by_id( 'woocommerce_payments' ) ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'Drywall Toolbox permits one storefront card/wallet authority. Deactivate the WooCommerce Stripe Gateway and WooPayments before enabling Payment Plugins for Stripe.', 'drywall-toolbox' )
				. '</p></div>';
		}
		$upm = self::gateway_by_id( self::UPM_GATEWAY_ID );
		if ( self::gateway_enabled( $upm ) && ! self::upm_configuration_ready( $upm ) ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'Select a dedicated Stripe Payment Method Configuration for Universal Payment Methods before checkout acceptance testing.', 'drywall-toolbox' )
				. '</p></div>';
		}
		if ( ! is_ssl() ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'Drywall Toolbox checkout requires HTTPS before Stripe Elements or wallets may be enabled.', 'drywall-toolbox' )
				. '</p></div>';
		}
		if ( ! self::checkout_page_has_supported_content() ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'The assigned WooCommerce Checkout page must contain the WooCommerce Checkout Block.', 'drywall-toolbox' )
				. '</p></div>';
		}
	}

	private static function tag_order_if_provider_order( WC_Order $order, string $source ): void {
		$payment_method = sanitize_key( (string) $order->get_payment_method() );
		if ( '' !== $payment_method && ! self::is_payment_plugins_gateway_id( $payment_method ) ) {
			return;
		}
		self::tag_order( $order, $source );
	}

	private static function tag_order( WC_Order $order, string $source ): void {
		$order->update_meta_data( '_dtb_checkout_gateway', self::CHECKOUT_GATEWAY );
		$order->update_meta_data( '_dtb_checkout_contract_version', self::CONTRACT_VERSION );
		if ( '' === (string) $order->get_meta( '_dtb_checkout_source', true ) ) {
			$order->update_meta_data( '_dtb_checkout_source', sanitize_key( $source ) );
		}
		$order->update_meta_data( '_dtb_order_type', 'product' );
	}

	private static function is_payment_plugins_order( WC_Order $order ): bool {
		return self::is_payment_plugins_gateway_id( (string) $order->get_payment_method() );
	}

	private static function gateway_reference( WC_Order $order ): string {
		$transaction_id = trim( (string) $order->get_transaction_id() );
		if ( '' !== $transaction_id ) {
			return sanitize_text_field( $transaction_id );
		}
		foreach ( [ '_stripe_intent_id', '_stripe_charge_id', '_payment_intent_id' ] as $meta_key ) {
			$value = trim( (string) $order->get_meta( $meta_key, true ) );
			if ( '' !== $value ) {
				return sanitize_text_field( $value );
			}
		}
		return '';
	}

	private static function payment_gateways(): array {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->payment_gateways() ) {
			return [];
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		return is_array( $gateways ) ? $gateways : [];
	}

	private static function gateway_by_id( string $gateway_id ) {
		$gateways = self::payment_gateways();
		return $gateways[ sanitize_key( $gateway_id ) ] ?? null;
	}

	private static function gateway_enabled( $gateway ): bool {
		return is_object( $gateway ) && isset( $gateway->enabled ) && 'yes' === (string) $gateway->enabled;
	}

	private static function is_gateway_enabled_by_id( string $gateway_id ): bool {
		return self::gateway_enabled( self::gateway_by_id( $gateway_id ) );
	}

	/** @param array<int,string> $gateway_ids */
	private static function is_any_gateway_enabled( array $gateway_ids ): bool {
		foreach ( $gateway_ids as $gateway_id ) {
			if ( self::is_gateway_enabled_by_id( $gateway_id ) ) {
				return true;
			}
		}
		return false;
	}

	private static function upm_method_enabled( string $gateway_id, $upm = null ): bool {
		$upm = $upm ?? self::gateway_by_id( self::UPM_GATEWAY_ID );
		if ( ! self::gateway_enabled( $upm ) || ! method_exists( $upm, 'is_enabled_payment_method' ) ) {
			return false;
		}
		try {
			return (bool) $upm->is_enabled_payment_method( sanitize_key( $gateway_id ) );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	private static function is_upm_gateway_enabled(): bool {
		$gateway = self::gateway_by_id( self::UPM_GATEWAY_ID );
		return self::is_payment_plugins_gateway_instance( $gateway ) && self::gateway_enabled( $gateway );
	}

	private static function upm_configuration_ready( $upm = null ): bool {
		$upm = $upm ?? self::gateway_by_id( self::UPM_GATEWAY_ID );
		if ( ! self::gateway_enabled( $upm ) || ! method_exists( $upm, 'get_payment_method_configuration' ) ) {
			return false;
		}
		try {
			return '' !== trim( (string) $upm->get_payment_method_configuration() );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/** @param array<int,string> $ids */
	private static function enabled_upm_method_count( array $ids, $upm = null ): int {
		$count = 0;
		foreach ( $ids as $id ) {
			if ( self::upm_method_enabled( $id, $upm ) ) {
				++$count;
			}
		}
		return $count;
	}

	private static function is_extension_active(): bool {
		if ( ! defined( 'WC_STRIPE_PLUGIN_FILE_PATH' ) || ! function_exists( 'wc_stripe_get_container' ) || ! class_exists( 'WC_Payment_Gateway_Stripe' ) ) {
			return false;
		}
		$path = trailingslashit( wp_normalize_path( (string) WC_STRIPE_PLUGIN_FILE_PATH ) );
		return false !== strpos( $path, self::PLUGIN_PATH_FRAGMENT );
	}

	private static function plugin_version(): string {
		if ( ! self::is_extension_active() ) {
			return '';
		}
		try {
			$container = wc_stripe_get_container();
			if ( is_object( $container ) && method_exists( $container, 'get' ) ) {
				return sanitize_text_field( (string) $container->get( 'VERSION' ) );
			}
		} catch ( Throwable $e ) {
			return '';
		}
		return '';
	}

	private static function is_payment_plugins_gateway_instance( $gateway ): bool {
		if ( ! is_object( $gateway ) || ! self::is_extension_active() ) {
			return false;
		}
		$id = sanitize_key( (string) ( $gateway->id ?? '' ) );
		if ( ! str_starts_with( $id, 'stripe_' ) ) {
			return false;
		}
		try {
			$file = wp_normalize_path( (string) ( new ReflectionClass( $gateway ) )->getFileName() );
		} catch ( ReflectionException $e ) {
			return false;
		}
		$plugin_path = trailingslashit( wp_normalize_path( (string) WC_STRIPE_PLUGIN_FILE_PATH ) );
		return '' !== $file && str_starts_with( $file, $plugin_path );
	}

	private static function is_official_woocommerce_stripe_active(): bool {
		return defined( 'WC_STRIPE_VERSION' ) || defined( 'WC_STRIPE_PLUGIN_PATH' );
	}

	private static function checkout_page_has_supported_content(): bool {
		$checkout_page_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'checkout' ) : 0;
		if ( $checkout_page_id <= 0 ) {
			return false;
		}
		return has_block( 'woocommerce/checkout', (string) get_post_field( 'post_content', $checkout_page_id ) );
	}

	private static function is_storefront_payment_request(): bool {
		if ( is_admin() ) {
			return false;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) ) {
			return false;
		}
		return true;
	}
}

// Compatibility alias for third-party code compiled against the retired class name.
if ( ! class_exists( 'DTB_OfficialStripeNativeCheckout', false ) ) {
	class_alias( DTB_PaymentPluginsStripeNativeCheckout::class, 'DTB_OfficialStripeNativeCheckout' );
}

DTB_PaymentPluginsStripeNativeCheckout::register();
