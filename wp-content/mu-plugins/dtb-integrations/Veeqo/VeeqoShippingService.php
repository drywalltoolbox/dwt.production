<?php
/**
 * DTB checkout shipping-policy facade housed in the Veeqo integration module.
 *
 * For a domestic (US) destination, `live_domestic_rates()` requests real
 * carrier quotes from Veeqo's standalone Rate Shopping API
 * (`POST /shipping/api/v1/rates`, `https://developers.veeqo.com/rate-shopping-api/`) —
 * the officially documented endpoint for retrieving shipping rates "without
 * requiring an existing order or allocation in Veeqo", i.e. safe to call live
 * from checkout before any order exists. It never books a shipment or
 * purchases a label; Veeqo remains authoritative for inventory and
 * downstream fulfillment, shipment, label, and tracking workflows — those
 * stay entirely inside Veeqo's own native fulfillment workflow, untouched by
 * checkout-time rate shopping.
 *
 * `DTB_Shipping_Method::calculate_shipping()` (mu-plugins/dtb-commerce/Shipping/DTBShippingMethod.php)
 * is the only caller. It always keeps its own local policy tiers as the
 * fallback (and as the sole source of the $50+ free-shipping subsidy, which
 * is a DTB merchant policy, not a carrier rate Veeqo can quote) whenever live
 * rates are unavailable — not configured, API error, incomplete address, no
 * usable quotes, or a non-US destination (the Rate Shopping API is
 * documented as US-only). Live rate shopping can never block or break
 * checkout: every failure mode here degrades to "no live quotes", which the
 * caller already knows how to handle.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_VeeqoShippingService' ) ) {
	return;
}

final class DTB_VeeqoShippingService {
	/**
	 * Dispatch the current DTB shipping-rate REST calculation handler.
	 */
	public static function rates( WP_REST_Request $request ): ?WP_REST_Response {
		if ( function_exists( 'dtb_veeqo_route_shipping_rates' ) ) {
			return dtb_veeqo_route_shipping_rates( $request );
		}

		return null;
	}

	/**
	 * Request live domestic (US) carrier quotes from Veeqo's Rate Shopping API.
	 *
	 * @param array<string,mixed> $destination WooCommerce package destination
	 *                                          array (`country`, `state`,
	 *                                          `postcode`, `city`, `address`,
	 *                                          `address_2` — the shape
	 *                                          `WC_Cart::get_shipping_packages()`
	 *                                          builds).
	 * @param float                $weight_lb   Total parcel weight in pounds.
	 * @return array{ok:bool,quotes:array<int,array{id:string,carrier:string,service:string,cost:float}>,error:string}
	 */
	public static function live_domestic_rates( array $destination, float $weight_lb ): array {
		if ( ! function_exists( 'dtb_veeqo_enabled' ) || ! dtb_veeqo_enabled() || ! function_exists( 'dtb_veeqo_request' ) ) {
			return [ 'ok' => false, 'quotes' => [], 'error' => 'Veeqo not configured.' ];
		}

		$from_address = self::warehouse_origin_address();
		if ( null === $from_address ) {
			return [ 'ok' => false, 'quotes' => [], 'error' => 'No Veeqo warehouse origin address available.' ];
		}

		$to_line1 = trim( (string) ( $destination['address'] ?? '' ) );
		$to_city  = trim( (string) ( $destination['city'] ?? '' ) );
		$to_state = trim( (string) ( $destination['state'] ?? '' ) );
		$to_zip   = trim( (string) ( $destination['postcode'] ?? '' ) );

		if ( '' === $to_line1 || '' === $to_city || '' === $to_state || '' === $to_zip ) {
			return [ 'ok' => false, 'quotes' => [], 'error' => 'Incomplete destination address.' ];
		}

		if ( $weight_lb <= 0.0 ) {
			$weight_lb = 0.5; // Matches the local-policy fallback's per-item default weight.
		}

		$to_address = [
			'name'         => sanitize_text_field( self::customer_name() ),
			'line1'        => sanitize_text_field( $to_line1 ),
			'town'         => sanitize_text_field( $to_city ),
			'county'       => sanitize_text_field( $to_state ),
			'postcode'     => sanitize_text_field( $to_zip ),
			'country_code' => 'US',
		];

		$to_line2 = trim( (string) ( $destination['address_2'] ?? '' ) );
		if ( '' !== $to_line2 ) {
			$to_address['line2'] = sanitize_text_field( $to_line2 );
		}

		$body = [
			'to_address'                 => $to_address,
			'from_address'               => $from_address,
			'parcels'                    => [
				[
					'weight'      => round( $weight_lb, 2 ),
					'weight_unit' => 'lb',
				],
			],
			'include_unavailable_quotes' => false,
		];

		$result = dtb_veeqo_request( 'POST', '/shipping/api/v1/rates', [], $body );

		if ( ! $result['ok'] ) {
			return [ 'ok' => false, 'quotes' => [], 'error' => $result['error'] ];
		}

		$quotes     = is_array( $result['data']['quotes'] ?? null ) ? $result['data']['quotes'] : [];
		$normalized = self::normalize_quotes( $quotes );

		if ( empty( $normalized ) ) {
			return [ 'ok' => false, 'quotes' => [], 'error' => 'No usable quotes returned.' ];
		}

		usort( $normalized, static fn( array $a, array $b ): int => $a['cost'] <=> $b['cost'] );

		// Cap to a manageable number of checkout options; cheapest first so a
		// hard cap never silently drops the most relevant (lowest) prices.
		return [ 'ok' => true, 'quotes' => array_slice( $normalized, 0, 4 ), 'error' => '' ];
	}

	/**
	 * @param array<int,mixed> $quotes Raw `quotes[]` from the Rate Shopping API response.
	 * @return array<int,array{id:string,carrier:string,service:string,cost:float}>
	 */
	private static function normalize_quotes( array $quotes ): array {
		$normalized = [];

		foreach ( $quotes as $index => $quote ) {
			if ( ! is_array( $quote ) ) {
				continue;
			}

			$cost = (float) ( $quote['total_charge'] ?? $quote['base_rate'] ?? 0 );
			if ( $cost <= 0.0 ) {
				continue;
			}

			$rate_id = (string) ( $quote['rate_id'] ?? ( 'veeqo_quote_' . $index ) );
			$slug    = substr( sanitize_key( $rate_id ), 0, 40 );

			$normalized[] = [
				'id'      => '' !== $slug ? $slug : ( 'veeqo_quote_' . $index ),
				'carrier' => sanitize_text_field( (string) ( $quote['carrier_nice_name'] ?? $quote['carrier'] ?? 'Carrier' ) ),
				'service' => sanitize_text_field( (string) ( $quote['service_name'] ?? 'Shipping' ) ),
				'cost'    => round( $cost, 2 ),
			];
		}

		return $normalized;
	}

	/**
	 * Best-effort customer display name for the Rate Shopping API's required
	 * `to_address.name` field. Package destinations carry no name field, so
	 * this reads the current WooCommerce customer session directly; a live
	 * rate lookup only needs a plausible name for carrier pricing, not a
	 * validated one, so an empty session falls back to a generic label
	 * rather than skipping the live lookup entirely.
	 */
	private static function customer_name(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return 'Customer';
		}

		$first = trim( (string) WC()->customer->get_shipping_first_name() );
		$last  = trim( (string) WC()->customer->get_shipping_last_name() );
		$full  = trim( $first . ' ' . $last );

		return '' !== $full ? $full : 'Customer';
	}

	/**
	 * Resolve and short-lived-cache the configured Veeqo warehouse's address
	 * for the Rate Shopping API's `from_address`. Returns null when no
	 * warehouse is configured or its address is incomplete in Veeqo — callers
	 * must treat that as "live rates unavailable", never as a checkout error.
	 *
	 * Both a hit and a miss are cached for an hour so an unconfigured or
	 * incomplete warehouse address doesn't trigger a Veeqo API call on every
	 * checkout package calculation; `dtb_veeqo_settings_saved` clears it
	 * immediately when an operator changes the configured warehouse.
	 *
	 * @return array<string,string>|null
	 */
	private static function warehouse_origin_address(): ?array {
		$cached = get_transient( 'dtb_veeqo_warehouse_origin_address' );
		if ( is_array( $cached ) ) {
			return ! empty( $cached ) ? $cached : null;
		}

		$cfg          = function_exists( 'dtb_veeqo_config' ) ? dtb_veeqo_config() : [];
		$warehouse_id = (int) ( $cfg['warehouse_id'] ?? 0 );

		if ( $warehouse_id <= 0 ) {
			set_transient( 'dtb_veeqo_warehouse_origin_address', [], HOUR_IN_SECONDS );
			return null;
		}

		$result     = dtb_veeqo_request( 'GET', '/warehouses' );
		$warehouses = ( $result['ok'] && is_array( $result['data'] ) ) ? $result['data'] : [];
		$address    = null;

		foreach ( $warehouses as $warehouse ) {
			if ( ! is_array( $warehouse ) || $warehouse_id !== (int) ( $warehouse['id'] ?? 0 ) ) {
				continue;
			}

			$line1 = trim( (string) ( $warehouse['address_line_1'] ?? '' ) );
			$city  = trim( (string) ( $warehouse['city'] ?? '' ) );
			$state = trim( (string) ( $warehouse['region'] ?? '' ) );
			$zip   = trim( (string) ( $warehouse['post_code'] ?? '' ) );

			if ( '' === $line1 || '' === $city || '' === $state || '' === $zip ) {
				break;
			}

			$address = [
				'name'         => sanitize_text_field( (string) ( $warehouse['name'] ?? 'Drywall Toolbox Warehouse' ) ),
				'line1'        => sanitize_text_field( $line1 ),
				'town'         => sanitize_text_field( $city ),
				'county'       => sanitize_text_field( $state ),
				'postcode'     => sanitize_text_field( $zip ),
				'country_code' => 'US',
			];

			$line2 = trim( (string) ( $warehouse['address_line_2'] ?? '' ) );
			if ( '' !== $line2 ) {
				$address['line2'] = sanitize_text_field( $line2 );
			}

			break;
		}

		set_transient( 'dtb_veeqo_warehouse_origin_address', $address ?? [], HOUR_IN_SECONDS );

		return $address;
	}
}

/**
 * Backward-compatible shipping-rate wrapper.
 */
function dtb_integrations_veeqo_shipping_rates( WP_REST_Request $request ): ?WP_REST_Response {
	return DTB_VeeqoShippingService::rates( $request );
}
