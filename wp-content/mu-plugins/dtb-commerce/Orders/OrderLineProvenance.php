<?php
/**
 * Persist authoritative purchase provenance for schematic-origin cart items.
 *
 * The browser may identify the schematic/page/part the shopper interacted with,
 * but DTB re-resolves that identity against the authoritative schematic record
 * and WooCommerce product before storing any provenance on the cart/order item.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_OrderLineProvenance {
	private const CART_KEY = 'dtb_order_line_provenance';

	private const CLIENT_KEYS = [
		'_dtb_source_surface',
		'_dtb_schematic_id',
		'_dtb_schematic_page_id',
		'_dtb_schematic_page_number',
		'_dtb_schematic_part_ref',
		'_dtb_schematic_variant',
	];

	private const ORDER_META_KEYS = [
		'_dtb_source_surface',
		'_dtb_schematic_id',
		'_dtb_schematic_title',
		'_dtb_schematic_brand',
		'_dtb_schematic_category',
		'_dtb_schematic_page_id',
		'_dtb_schematic_page_number',
		'_dtb_schematic_page_label',
		'_dtb_schematic_part_ref',
		'_dtb_schematic_part_sku',
		'_dtb_schematic_part_title',
		'_dtb_schematic_variant',
	];

	public static function register(): void {
		add_filter( 'woocommerce_store_api_add_to_cart_data', [ self::class, 'capture_store_api_context' ], 30, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ self::class, 'persist_order_line_context' ], 30, 4 );
	}

	public static function capture_store_api_context( array $add_to_cart_data, WP_REST_Request $request ): array {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return $add_to_cart_data;
		}

		$incoming = $params['extensions']['dtb']['metadata'] ?? null;
		$client   = self::sanitize_client_metadata( $incoming );
		if ( 'schematic' !== ( $client['_dtb_source_surface'] ?? '' ) ) {
			return $add_to_cart_data;
		}

		$product_id = absint( $params['id'] ?? 0 );
		$resolved   = self::resolve_schematic_context( $client, $product_id );
		if ( [] === $resolved ) {
			return $add_to_cart_data;
		}

		$add_to_cart_data[ self::CART_KEY ] = $resolved;
		return $add_to_cart_data;
	}

	public static function persist_order_line_context(
		WC_Order_Item_Product $item,
		string $_cart_item_key,
		array $values,
		WC_Order $_order
	): void {
		$context = $values[ self::CART_KEY ] ?? null;
		if ( ! is_array( $context ) || [] === $context ) {
			return;
		}

		foreach ( self::ORDER_META_KEYS as $key ) {
			$value = isset( $context[ $key ] ) ? sanitize_text_field( (string) $context[ $key ] ) : '';
			if ( '' === $value ) {
				continue;
			}
			$item->add_meta_data( $key, $value, true );
		}
	}

	/**
	 * @param mixed $incoming Store API extensions.dtb.metadata payload.
	 * @return array<string,string>
	 */
	private static function sanitize_client_metadata( mixed $incoming ): array {
		if ( ! is_array( $incoming ) ) {
			return [];
		}

		$out = [];
		foreach ( $incoming as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$key = sanitize_key( (string) ( $entry['key'] ?? '' ) );
			if ( ! in_array( $key, self::CLIENT_KEYS, true ) ) {
				continue;
			}
			$value = sanitize_text_field( (string) ( $entry['value'] ?? '' ) );
			if ( '' !== $value ) {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Resolve browser-supplied identifiers against authoritative schematic data.
	 *
	 * @param array<string,string> $client
	 * @return array<string,string>
	 */
	private static function resolve_schematic_context( array $client, int $product_id ): array {
		if (
			$product_id <= 0
			|| ! function_exists( 'wc_get_product' )
			|| ! function_exists( 'dtb_schematic_record_repo_find_by_canonical_id' )
		) {
			return [];
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product || ! $product->is_purchasable() ) {
			return [];
		}

		$schematic_id = sanitize_key( (string) ( $client['_dtb_schematic_id'] ?? '' ) );
		$page_id      = sanitize_text_field( (string) ( $client['_dtb_schematic_page_id'] ?? '' ) );
		$part_ref     = sanitize_text_field( (string) ( $client['_dtb_schematic_part_ref'] ?? '' ) );
		if ( '' === $schematic_id || '' === $page_id || '' === $part_ref ) {
			return [];
		}

		$record = dtb_schematic_record_repo_find_by_canonical_id( $schematic_id );
		if ( ! $record instanceof DTB_Schematic_Record_Entity || ! $record->lifecycle->is_published() ) {
			return [];
		}

		$page = null;
		foreach ( (array) $record->pages as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			if ( hash_equals( (string) ( $candidate['page_id'] ?? '' ), $page_id ) ) {
				$page = $candidate;
				break;
			}
		}
		if ( ! is_array( $page ) ) {
			return [];
		}

		$part = null;
		foreach ( (array) $record->parts as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			if ( hash_equals( (string) ( $candidate['part_ref'] ?? '' ), $part_ref ) ) {
				$part = $candidate;
				break;
			}
		}
		if ( ! is_array( $part ) ) {
			return [];
		}

		$resolved_product_id = absint( $part['product_id'] ?? 0 );
		if ( $resolved_product_id <= 0 || $resolved_product_id !== $product_id ) {
			return [];
		}

		$display = function_exists( 'dtb_schematic_resolve_display_metadata' )
			? dtb_schematic_resolve_display_metadata( $record )
			: [];

		$variant = sanitize_text_field( (string) ( $client['_dtb_schematic_variant'] ?? '' ) );
		if ( '' !== $variant && function_exists( 'dtb_schematic_shared_variant_options' ) ) {
			$allowed_variants = [];
			foreach ( (array) dtb_schematic_shared_variant_options( $record->canonical_id ) as $option ) {
				if ( is_array( $option ) ) {
					$key = sanitize_text_field( (string) ( $option['key'] ?? '' ) );
					if ( '' !== $key ) {
						$allowed_variants[] = $key;
					}
				}
			}
			if ( ! empty( $allowed_variants ) && ! in_array( $variant, $allowed_variants, true ) ) {
				$variant = '';
			}
		}

		return array_filter(
			[
				'_dtb_source_surface'       => 'schematic',
				'_dtb_schematic_id'         => $record->canonical_id,
				'_dtb_schematic_title'      => sanitize_text_field( (string) ( $display['title'] ?? $record->canonical_id ) ),
				'_dtb_schematic_brand'      => sanitize_text_field( (string) ( $display['brand_name'] ?? '' ) ),
				'_dtb_schematic_category'   => sanitize_text_field( (string) ( $display['category_name'] ?? '' ) ),
				'_dtb_schematic_page_id'    => sanitize_text_field( (string) ( $page['page_id'] ?? '' ) ),
				'_dtb_schematic_page_number'=> (string) max( 0, absint( $page['page_number'] ?? 0 ) ),
				'_dtb_schematic_page_label' => sanitize_text_field( (string) ( $page['label'] ?? '' ) ),
				'_dtb_schematic_part_ref'   => sanitize_text_field( (string) ( $part['part_ref'] ?? '' ) ),
				'_dtb_schematic_part_sku'   => sanitize_text_field( (string) ( $part['sku'] ?? $product->get_sku() ) ),
				'_dtb_schematic_part_title' => sanitize_text_field( (string) ( $part['title'] ?? $product->get_name() ) ),
				'_dtb_schematic_variant'    => $variant,
			],
			static fn( $value ) => '' !== (string) $value
		);
	}
}
