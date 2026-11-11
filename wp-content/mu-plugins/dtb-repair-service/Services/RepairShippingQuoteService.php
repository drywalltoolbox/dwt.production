<?php
/**
 * Services — RepairShippingQuoteService: deterministic repair return-shipping policy.
 *
 * Repair shipping is independent from the WooCommerce storefront cart/session.
 * Quotes are calculated locally from a server-owned repair shipment profile and
 * never call Veeqo or trust browser-supplied prices/weights.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DTB_REPAIR_SHIPPING_QUOTE_VERSION' ) ) {
	define( 'DTB_REPAIR_SHIPPING_QUOTE_VERSION', 'repair-shipping-v1' );
}

if ( ! defined( 'DTB_REPAIR_SHIPPING_ESTIMATED_WEIGHT_LBS' ) ) {
	// Preserve the launch repair-form shipment profile previously used client-side.
	define( 'DTB_REPAIR_SHIPPING_ESTIMATED_WEIGHT_LBS', 5.0 );
}

/**
 * Normalize and validate the repair return destination.
 *
 * @param array<string,mixed> $raw Raw destination fields.
 * @return array<string,string>|WP_Error
 */
function dtb_repair_normalize_shipping_destination( array $raw ): array|WP_Error {
	$country = strtoupper( sanitize_text_field( (string) ( $raw['country'] ?? 'US' ) ) );
	if ( '' === $country ) {
		$country = 'US';
	}

	$destination = [
		'address'  => sanitize_text_field( (string) ( $raw['address'] ?? $raw['address_1'] ?? '' ) ),
		'city'     => sanitize_text_field( (string) ( $raw['city'] ?? '' ) ),
		'state'    => strtoupper( sanitize_text_field( (string) ( $raw['state'] ?? $raw['province'] ?? '' ) ) ),
		'postcode' => sanitize_text_field( (string) ( $raw['postcode'] ?? $raw['zip'] ?? $raw['postal_code'] ?? '' ) ),
		'country'  => $country,
	];

	foreach ( [ 'address', 'city', 'state', 'postcode', 'country' ] as $field ) {
		if ( '' === trim( (string) $destination[ $field ] ) ) {
			return new WP_Error(
				'dtb_repair_shipping_invalid_address',
				__( 'A complete return shipping address is required.', 'drywall-toolbox' ),
				[ 'status' => 422 ]
			);
		}
	}

	return $destination;
}

/**
 * Build deterministic repair return-shipping rates.
 *
 * This intentionally mirrors the current DTB launch shipping-policy tiers for
 * the server-owned 5 lb repair shipment profile, but is not coupled to a cart.
 * Customers arrange and pay for inbound carrier shipping; free local
 * pickup/drop-off remains a separate intake option.
 * These rates represent return delivery after service. Pickup remains available
 * as a zero-cost option.
 *
 * @param array<string,string> $destination Normalized destination.
 * @return array<int,array{id:string,name:string,price:float,currency:string}>
 */
function dtb_repair_shipping_policy_rates( array $destination ): array {
	$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
	$country  = strtoupper( (string) ( $destination['country'] ?? 'US' ) );
	$weight   = (float) DTB_REPAIR_SHIPPING_ESTIMATED_WEIGHT_LBS;
	$rates    = [];

	if ( 'US' === $country ) {
		$standard = $weight <= 1.0 ? 7.99 : ( $weight <= 5.0 ? 12.99 : ( $weight <= 15.0 ? 19.99 : 29.99 ) );
		$rates[]  = [
			'id'       => 'repair_standard',
			'name'     => __( 'Standard Shipping (5–7 business days)', 'drywall-toolbox' ),
			'price'    => $standard,
			'currency' => $currency,
		];
		$rates[] = [
			'id'       => 'repair_express',
			'name'     => __( 'Express Shipping (2–3 business days)', 'drywall-toolbox' ),
			'price'    => $standard + 10.00,
			'currency' => $currency,
		];
		$rates[] = [
			'id'       => 'repair_overnight',
			'name'     => __( 'Overnight Shipping (next business day)', 'drywall-toolbox' ),
			'price'    => $standard + 30.00,
			'currency' => $currency,
		];
	} else {
		$base    = $weight <= 2.0 ? 29.99 : ( $weight <= 10.0 ? 49.99 : 79.99 );
		$rates[] = [
			'id'       => 'repair_intl_standard',
			'name'     => __( 'International Standard (10–15 business days)', 'drywall-toolbox' ),
			'price'    => $base,
			'currency' => $currency,
		];
		$rates[] = [
			'id'       => 'repair_intl_express',
			'name'     => __( 'International Express (5–7 business days)', 'drywall-toolbox' ),
			'price'    => $base + 30.00,
			'currency' => $currency,
		];
	}

	$rates[] = [
		'id'       => 'repair_pickup',
		'name'     => __( 'Hold for Pickup', 'drywall-toolbox' ),
		'price'    => 0.0,
		'currency' => $currency,
	];

	return $rates;
}

/**
 * Build a public repair shipping quote.
 *
 * @param array<string,mixed> $payload Request/submission payload.
 * @return array<string,mixed>|WP_Error
 */
function dtb_repair_build_shipping_quote( array $payload ): array|WP_Error {
	$destination_raw = is_array( $payload['destination'] ?? null ) ? $payload['destination'] : $payload;
	$destination     = dtb_repair_normalize_shipping_destination( $destination_raw );
	if ( is_wp_error( $destination ) ) {
		return $destination;
	}

	$rates = dtb_repair_shipping_policy_rates( $destination );

	return [
		'quote_version'        => DTB_REPAIR_SHIPPING_QUOTE_VERSION,
		'source'               => 'dtb-repair-policy',
		'currency'             => $rates[0]['currency'] ?? 'USD',
		'estimated_weight_lbs' => (float) DTB_REPAIR_SHIPPING_ESTIMATED_WEIGHT_LBS,
		'rates'                => $rates,
	];
}

/**
 * Recalculate and validate the selected repair shipping rate at submission.
 * Browser-provided rate names/prices are never authoritative.
 *
 * @param array<string,mixed> $payload Repair submission payload.
 * @return array{id:string,name:string,price:float,currency:string}|WP_Error
 */
function dtb_repair_validate_shipping_selection( array $payload ): array|WP_Error {
	$quote = dtb_repair_build_shipping_quote( $payload );
	if ( is_wp_error( $quote ) ) {
		return $quote;
	}

	$selected_id = sanitize_key( (string) ( $payload['shipping_rate_id'] ?? $payload['shippingRateId'] ?? '' ) );
	if ( '' === $selected_id ) {
		return new WP_Error(
			'dtb_repair_shipping_rate_required',
			__( 'Please select a return shipping option.', 'drywall-toolbox' ),
			[ 'status' => 422 ]
		);
	}

	foreach ( (array) $quote['rates'] as $rate ) {
		if ( isset( $rate['id'] ) && hash_equals( (string) $rate['id'], $selected_id ) ) {
			return $rate;
		}
	}

	return new WP_Error(
		'dtb_repair_shipping_rate_invalid',
		__( 'The selected return shipping option is no longer available. Refresh shipping options and try again.', 'drywall-toolbox' ),
		[ 'status' => 409 ]
	);
}
