<?php
/**
 * Storefront tax-label presentation policy.
 *
 * WooCommerce remains the sole tax authority and operators continue to manage the
 * actual Minnesota rate/name in wp-admin. This filter only normalizes the customer-
 * facing label so checkout/order totals present a generic "Tax" label rather than
 * exposing an internal jurisdiction-specific rate name such as "MN State Tax".
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_CheckoutTaxPresentation {
	public static function register(): void {
		add_filter( 'woocommerce_rate_label', [ __CLASS__, 'normalize_rate_label' ], 20, 2 );
	}

	/**
	 * @param string $label   WooCommerce configured tax-rate label.
	 * @param mixed  $rate_id Tax-rate identifier.
	 */
	public static function normalize_rate_label( string $label, $rate_id ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $label;
		}

		return __( 'Tax', 'drywall-toolbox' );
	}
}
