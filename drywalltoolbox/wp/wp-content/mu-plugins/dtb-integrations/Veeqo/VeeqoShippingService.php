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
	public static function live_domestic_rates( array $destination, float $weight_lb, array $package = [] ): array {
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

		// Carrier quotes require a merchant-verified physical shipping carton.
		// Product/kit weight alone cannot establish the box size or parcel count.
		$parcel = self::verified_single_carton( $package );
		if ( null === $parcel ) {
			return [ 'ok' => false, 'quotes' => [], 'error' => 'Verified single-carton packing data required.' ];
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
			'parcels'                    => [ $parcel ],
			'customer_reference'         => 'DTB-RATE-' . wp_generate_uuid4(),
			'is_amazon_order'            => false,
			'include_unavailable_quotes' => false,
		];

		$result = dtb_veeqo_request( 'POST', '/shipping/api/v1/rates', [], $body );

		if ( ! $result['ok'] ) {
			return [ 'ok' => false, 'quotes' => [], 'error' => $result['error'] ];
		}

		$quotes     = is_array( $result['data']['quotes'] ?? null ) ? $result['data']['quotes'] : [];
		$normalized = self::normalize_quotes( $quotes, $parcel );
		if ( empty( $normalized ) ) {
			return [ 'ok' => false, 'quotes' => [], 'error' => 'No eligible carrier services for verified carton.' ];
		}

		// Cheapest valid quote per tier, never the four globally cheapest quotes.
		usort( $normalized, static fn( array $a, array $b ): int => $a['cost'] <=> $b['cost'] );
		$selected = [];
		foreach ( [ 'standard', 'express', 'overnight' ] as $tier ) {
			foreach ( $normalized as $quote ) {
				if ( $quote['tier'] === $tier ) {
					$selected[] = $quote;
					break;
				}
			}
		}
		return [ 'ok' => true, 'quotes' => $selected, 'error' => '' ];
	}

	/**
	 * Quotes must be for actual eligible services, not document-only postage,
	 * cubic/flat-rate/One Rate packaging, or unspecified carrier products.
	 * Veeqo's rate id is an expiring quote token; shipping method IDs use
	 * stable carrier+service identity, never the short-lived token.
	 */
	private static function normalize_quotes( array $quotes, array $parcel ): array {
		$normalized = [];
		foreach ( $quotes as $quote ) {
			if ( ! is_array( $quote ) || false === ( $quote['available'] ?? true ) ) {
				continue;
			}
			$cost = (float) ( $quote['total_charge'] ?? $quote['totalCharge'] ?? $quote['base_rate'] ?? 0 );
			if ( ! is_finite( $cost ) || $cost <= 0 || $cost > 10000 ) {
				continue;
			}
			$carrier = sanitize_text_field( (string) ( $quote['carrier_nice_name'] ?? $quote['carrier'] ?? '' ) );
			$service = sanitize_text_field( (string) ( $quote['service_name'] ?? $quote['serviceName'] ?? '' ) );
			if ( '' === $carrier || '' === $service || ! self::service_eligible_for_carton( $carrier, $service, $parcel ) ) {
				continue;
			}
			$tier = self::classify_service( $service );
			if ( 'unknown' === $tier ) {
				continue;
			}
			$normalized[] = [
				'id'      => substr( hash( 'sha256', strtolower( $carrier . '|' . $service ) ), 0, 18 ),
				'carrier' => $carrier,
				'service' => $service,
				'tier'    => $tier,
				'cost'    => round( $cost, 2 ),
			];
		}
		return $normalized;
	}

	private static function classify_service( string $service ): string {
		$name = strtolower( $service );
		if ( preg_match( '/\\b(overnight|next[ -]?day|next day air|1[ -]?day|one[ -]?day)\\b/', $name ) ) {
			return 'overnight';
		}
		if ( preg_match( '/\\b(2[ -]?day|two[ -]?day|second[ -]?day|2nd day|3[ -]?day|three[ -]?day|3 day|express|expedited|priority mail|priority|express saver)\\b/', $name ) ) {
			return 'express';
		}
		if ( preg_match( '/\\b(ground|standard|economy|parcel select|home delivery)\\b/', $name ) ) {
			return 'standard';
		}
		return 'unknown';
	}

	private static function service_eligible_for_carton( string $carrier, string $service, array $parcel ): bool {
		$name = strtolower( $carrier . ' ' . $service );
		// These services require document contents or carrier-specific packaging
		// which a merchant-owned generic carton cannot truthfully satisfy.
		if ( preg_match( '/bound printed matter|media mail|first.class|flat.rate|envelope|cubic|one.rate|simple.rate|postcard|letter/', $name ) ) {
			return false;
		}
		$longest = max( (float) $parcel['length'], (float) $parcel['width'], (float) $parcel['height'] );
		$girth = array_sum( [ (float) $parcel['length'], (float) $parcel['width'], (float) $parcel['height'] ] ) - $longest;
		$length_plus_girth = $longest + 2 * $girth;
		if ( str_contains( $name, 'usps' ) && ( (float) $parcel['weight'] > 70 || $length_plus_girth > 130 ) ) {
			return false;
		}
		return $longest <= 108 && $length_plus_girth <= 165 && (float) $parcel['weight'] <= 150;
	}

	/**
	 * One declared/verified physical shipping carton only. Multi-item carts,
	 * multi-carton kits, virtual combinations, and missing measurements are
	 * deliberately not collapsed into a fictitious single parcel.
	 * Units here are explicit lb/in regardless of WooCommerce display units.
	 */
	public static function verified_single_carton( array $package ): ?array {
		$items = is_array( $package['contents'] ?? null ) ? $package['contents'] : [];
		if ( 1 !== count( $items ) ) {
			return null;
		}
		$item = reset( $items );
		if ( ! is_array( $item ) || 1 !== (int) ( $item['quantity'] ?? 0 ) ) {
			return null;
		}
		$product = $item['data'] ?? null;
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_meta' ) ||
			'yes' !== $product->get_meta( '_dtb_shipping_box_verified', true ) ) {
			return null;
		}
		$dimensions = [];
		foreach ( [ 'weight', 'length', 'width', 'height' ] as $field ) {
			$value = $product->get_meta( '_dtb_shipping_box_' . $field . ( 'weight' === $field ? '_lb' : '_in' ), true );
			if ( ! is_numeric( $value ) || (float) $value <= 0 || ! is_finite( (float) $value ) ) {
				return null;
			}
			$dimensions[ $field ] = round( (float) $value, 2 );
		}
		$longest = max( $dimensions['length'], $dimensions['width'], $dimensions['height'] );
		$girth = $dimensions['length'] + $dimensions['width'] + $dimensions['height'] - $longest;
		if ( $dimensions['weight'] > 150 || $longest > 108 || $longest + 2 * $girth > 165 ) {
			return null;
		}
		return $dimensions + [ 'weight_unit' => 'lb', 'dimension_unit' => 'in' ];
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
