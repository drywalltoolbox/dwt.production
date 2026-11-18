<?php
/**
 * WooCommerce tax policy/readiness diagnostics for storefront checkout.
 *
 * WooCommerce remains the sole tax authority. DTB does not create or mutate tax
 * rates here; operators configure the applicable Minnesota rate in wp-admin.
 * DTB only fixes the sourcing policy to the shop's base address and surfaces
 * configuration gaps to authorized operators.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_CheckoutTaxReadiness {
	public static function register(): void {
		/* Drywall Toolbox's storefront tax policy is origin-based, by deliberate
		 * business decision: every order is taxed at Minnesota's rate regardless
		 * of the customer's shipping destination. The rate itself remains
		 * operator-managed in WooCommerce > Settings > Tax. */
		add_filter( 'option_woocommerce_tax_based_on', [ __CLASS__, 'tax_based_on_shop_base' ], 20 );
		add_filter( 'default_option_woocommerce_tax_based_on', [ __CLASS__, 'tax_based_on_shop_base' ], 20 );
		add_action( 'admin_notices', [ __CLASS__, 'admin_notice' ] );
		add_action( 'woocommerce_checkout_validate_order_before_payment', [ __CLASS__, 'validate_order_before_payment' ], 20, 2 );
	}

	/**
	 * Force WooCommerce to source storefront tax from the shop's base address.
	 *
	 * This does not calculate tax, alter rates, or bypass Woo's tax engine. It only
	 * makes the location authority explicit so every order is matched against the
	 * Minnesota rate configured in wp-admin, regardless of the customer's shipping
	 * address. This is a deliberate business decision, not a nexus determination —
	 * confirm with whoever handles DTB's sales tax filings before changing it.
	 *
	 * @param mixed $value Stored/default WooCommerce option value.
	 */
	public static function tax_based_on_shop_base( $value ): string {
		return 'base';
	}

	/**
	 * Fail closed before payment when a taxable Store API order has no applicable
	 * WooCommerce rate for the configured shop-base tax location.
	 *
	 * WooCommerce remains authoritative for rate calculation. DTB only prevents a
	 * taxable order from being paid when Woo has no rate with which to calculate.
	 *
	 * @param WC_Order $order  Checkout order assembled by WooCommerce.
	 * @param WP_Error $errors Mutable validation error bag.
	 */
	public static function validate_order_before_payment( $order, $errors ): void {
		if ( ! $order instanceof WC_Order || ! $errors instanceof WP_Error ) {
			return;
		}

		$tax_classes = self::taxable_order_classes( $order );
		if ( empty( $tax_classes ) ) {
			return;
		}

		if ( ! function_exists( 'wc_tax_enabled' ) || ! wc_tax_enabled() ) {
			$errors->add(
				'dtb_checkout_tax_unavailable',
				__( 'Tax calculation is temporarily unavailable. Please contact Drywall Toolbox before completing payment.', 'drywall-toolbox' )
			);
			return;
		}

		$missing_classes = [];
		foreach ( $tax_classes as $tax_class ) {
			if ( ! self::has_applicable_rate_for_class( $tax_class ) ) {
				$missing_classes[] = $tax_class;
			}
		}

		if ( empty( $missing_classes ) ) {
			return;
		}

		$errors->add(
			'dtb_checkout_tax_unavailable',
			__( 'Tax calculation is temporarily unavailable. Please contact Drywall Toolbox before completing payment.', 'drywall-toolbox' ),
			[
				'status'              => 503,
				'missing_tax_classes' => array_values( $missing_classes ),
			]
		);
	}

	public static function admin_notice(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) || ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		if ( ! function_exists( 'wc_tax_enabled' ) || ! wc_tax_enabled() ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'Drywall Toolbox checkout tax is not active because WooCommerce tax calculations are disabled. Enable taxes in WooCommerce Settings before accepting taxable Minnesota orders.', 'drywall-toolbox' )
				. '</p></div>';
			return;
		}

		if ( ! self::has_applicable_rate_for_class( '' ) ) {
			$location = self::shop_base_tax_location();
			$settings_url = admin_url( 'admin.php?page=wc-settings&tab=tax&section=standard' );
			echo '<div class="notice notice-error"><p>'
				. esc_html(
					sprintf(
						/* translators: 1: country/state, 2: postcode. */
						__( 'Drywall Toolbox checkout is configured to calculate tax from the shop base, but WooCommerce has no applicable Standard tax rate for %1$s %2$s. Taxable checkout is blocked before payment until a rate is configured.', 'drywall-toolbox' ),
						trim( (string) $location['country'] . ':' . (string) $location['state'], ':' ),
						(string) $location['postcode']
					)
				)
				. ' <a href="' . esc_url( $settings_url ) . '">'
				. esc_html__( 'Configure WooCommerce tax rates', 'drywall-toolbox' )
				. '</a></p></div>';
		}
	}

	/**
	 * Resolve the exact WooCommerce shop-base location used by the DTB tax policy.
	 *
	 * @return array{country:string,state:string,postcode:string,city:string}
	 */
	private static function shop_base_tax_location(): array {
		$base = function_exists( 'wc_get_base_location' ) ? (array) wc_get_base_location() : [];
		return [
			'country'  => strtoupper( sanitize_text_field( (string) ( $base['country'] ?? '' ) ) ),
			'state'    => strtoupper( sanitize_text_field( (string) ( $base['state'] ?? '' ) ) ),
			'postcode' => sanitize_text_field( (string) get_option( 'woocommerce_store_postcode', '' ) ),
			'city'     => sanitize_text_field( (string) get_option( 'woocommerce_store_city', '' ) ),
		];
	}

	/**
	 * Return distinct WooCommerce tax classes used by taxable order products.
	 *
	 * An empty string is the Standard tax class and must be preserved.
	 *
	 * @return string[]
	 */
	private static function taxable_order_classes( WC_Order $order ): array {
		$classes = [];

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product instanceof WC_Product || ! $product->is_taxable() ) {
				continue;
			}

			$classes[] = sanitize_title( (string) $item->get_tax_class() );
		}

		return array_values( array_unique( $classes ) );
	}

	/**
	 * Check whether WooCommerce can match at least one rate for a tax class at the
	 * authoritative shop-base location.
	 */
	private static function has_applicable_rate_for_class( string $tax_class ): bool {
		if ( ! class_exists( 'WC_Tax' ) ) {
			return false;
		}

		$location = self::shop_base_tax_location();

		try {
			$rates = WC_Tax::find_rates(
				[
					'country'   => $location['country'],
					'state'     => $location['state'],
					'postcode'  => $location['postcode'],
					'city'      => $location['city'],
					'tax_class' => sanitize_title( $tax_class ),
				]
			);
		} catch ( Throwable $error ) {
			return false;
		}

		return ! empty( $rates );
	}
}
