<?php
/**
 * Customer-facing label normalization.
 *
 * Prevents backend implementation identifiers such as dtb_veeqo_rates:standard,
 * retired gateway IDs, or woo_native from appearing in customer emails, order
 * totals, and public order views.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'dtb_public_shipping_method_label' ) ) {
	/**
	 * Convert DTB/Veeqo shipping identifiers into customer-safe method labels.
	 */
	function dtb_public_shipping_method_label( string $value ): string {
		$raw = trim( wp_strip_all_tags( $value ) );
		if ( '' === $raw ) {
			return 'Shipping';
		}

		$normalized = strtolower( trim( $raw ) );
		$normalized = preg_replace( '/\s+/', ' ', $normalized );

		$known = [
			'dtb_veeqo_rates:standard'       => 'Standard Shipping',
			'dtb_veeqo_rates_standard'       => 'Standard Shipping',
			'dtb veeqo rates standard'       => 'Standard Shipping',
			'standard'                       => 'Standard Shipping',
			'dtb_veeqo_rates:express'        => 'Express Shipping',
			'dtb_veeqo_rates_express'        => 'Express Shipping',
			'dtb veeqo rates express'        => 'Express Shipping',
			'express'                        => 'Express Shipping',
			'dtb_veeqo_rates:overnight'      => 'Overnight Shipping',
			'dtb_veeqo_rates_overnight'      => 'Overnight Shipping',
			'dtb veeqo rates overnight'      => 'Overnight Shipping',
			'overnight'                      => 'Overnight Shipping',
			'dtb_veeqo_rates:intl_standard'  => 'International Standard Shipping',
			'dtb_veeqo_rates_intl_standard'  => 'International Standard Shipping',
			'intl_standard'                  => 'International Standard Shipping',
			'dtb_veeqo_rates:intl_express'   => 'International Express Shipping',
			'dtb_veeqo_rates_intl_express'   => 'International Express Shipping',
			'intl_express'                   => 'International Express Shipping',
			'dtb_veeqo_rates:repair_standard'=> 'Repair Service Shipping',
			'dtb_veeqo_rates_repair_standard'=> 'Repair Service Shipping',
			'repair_standard'                => 'Repair Service Shipping',
		];

		if ( isset( $known[ $normalized ] ) ) {
			return $known[ $normalized ];
		}

		if ( preg_match( '/^dtb_veeqo_rates:(?<code>[a-z0-9_-]+)$/i', $raw, $matches ) ) {
			$code = strtolower( (string) $matches['code'] );
			if ( isset( $known[ $code ] ) ) {
				return $known[ $code ];
			}
			return ucwords( str_replace( [ '_', '-' ], ' ', $code ) ) . ' Shipping';
		}

		if ( preg_match( '/^dtb_[a-z0-9_:-]+$/i', $raw ) ) {
			return 'Shipping';
		}

		return $raw;
	}
}

if ( ! function_exists( 'dtb_public_payment_method_label' ) ) {
	/**
	 * Convert current and historical gateway identifiers into customer-safe labels.
	 */
	function dtb_public_payment_method_label( string $value ): string {
		$raw = trim( wp_strip_all_tags( $value ) );
		if ( '' === $raw ) {
			return 'Secure Card Payment';
		}

		$normalized = strtolower( trim( $raw ) );
		$known      = [
			'woocommerce_payments' => 'Secure Card Payment',
			'woo_native_woopayments' => 'Secure Card Payment',
			'woo_native'           => 'Secure Card Payment',
			'stripe'               => 'Secure Card Payment',
			'stripe_cc'            => 'Secure Card Payment',
			'stripe_upm'           => 'Secure Card Payment',
			'stripe_applepay'      => 'Apple Pay',
			'stripe_googlepay'     => 'Google Pay',
			'stripe_link_checkout' => 'Link',
		];
		if ( str_starts_with( $normalized, 'woocommerce_payments_' ) ) {
			return 'Secure Card Payment';
		}

		return $known[ $normalized ] ?? $raw;
	}
}

if ( ! function_exists( 'dtb_public_replace_internal_tokens' ) ) {
	/**
	 * Replace internal token strings inside generated WooCommerce HTML fragments.
	 */
	function dtb_public_replace_internal_tokens( string $value ): string {
		$tokens = [
			'dtb_veeqo_rates:standard',
			'dtb_veeqo_rates_standard',
			'dtb_veeqo_rates:express',
			'dtb_veeqo_rates_express',
			'dtb_veeqo_rates:overnight',
			'dtb_veeqo_rates_overnight',
			'dtb_veeqo_rates:intl_standard',
			'dtb_veeqo_rates_intl_standard',
			'dtb_veeqo_rates:intl_express',
			'dtb_veeqo_rates_intl_express',
			'dtb_veeqo_rates:repair_standard',
			'dtb_veeqo_rates_repair_standard',
			'woo_native',
			'woo_native_woopayments',
			'woocommerce_payments',
			'stripe',
			'stripe_cc',
			'stripe_upm',
			'stripe_applepay',
			'stripe_googlepay',
			'stripe_link_checkout',
		];

		$clean = $value;
		foreach ( $tokens as $token ) {
			$replacement = str_starts_with( $token, 'dtb_veeqo_rates' )
				? dtb_public_shipping_method_label( $token )
				: dtb_public_payment_method_label( $token );
			$clean = str_ireplace( $token, $replacement, $clean );
		}

		return $clean;
	}
}

add_filter(
	'woocommerce_order_item_get_method_title',
	static function ( $method_title, $item ) {
		if ( $item instanceof WC_Order_Item_Shipping ) {
			return dtb_public_shipping_method_label( (string) ( $method_title ?: $item->get_method_id() ) );
		}

		return $method_title;
	},
	10,
	2
);

add_filter(
	'woocommerce_order_shipping_to_display',
	static function ( $shipping ) {
		return dtb_public_replace_internal_tokens( (string) $shipping );
	},
	10,
	1
);

add_filter(
	'woocommerce_get_order_item_totals',
	static function ( $total_rows, $order ) {
		if ( ! is_array( $total_rows ) ) {
			return $total_rows;
		}

		if ( isset( $total_rows['shipping'] ) ) {
			$value = (string) ( $total_rows['shipping']['value'] ?? '' );
			$total_rows['shipping']['value'] = dtb_public_replace_internal_tokens( $value );
		}

		if ( isset( $total_rows['payment_method'] ) ) {
			$value = (string) ( $total_rows['payment_method']['value'] ?? '' );
			$total_rows['payment_method']['value'] = dtb_public_payment_method_label( $value );
		}

		return $total_rows;
	},
	10,
	2
);

add_filter(
	'woocommerce_order_item_display_meta_key',
	static function ( $display_key ) {
		$key = strtolower( trim( wp_strip_all_tags( (string) $display_key ) ) );
		if ( str_starts_with( $key, '_dtb_' ) || str_starts_with( $key, 'dtb_' ) ) {
			return 'Store Reference';
		}

		return $display_key;
	},
	10,
	1
);

add_filter(
	'woocommerce_order_item_display_meta_value',
	static function ( $display_value ) {
		return dtb_public_replace_internal_tokens( (string) $display_value );
	},
	10,
	1
);
