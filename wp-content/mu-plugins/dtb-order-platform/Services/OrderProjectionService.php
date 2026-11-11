<?php
/**
 * DTB Order Projection Service — status and tracking projection builders.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve the fulfillment-facing product for an order item.
 *
 * Variation products retain their own SKU/image, while product-kind and kit
 * composition metadata may fall back to the parent product.
 */
function dtb_order_resolve_line_item_product_context( WC_Order_Item_Product $item ): array {
	static $cache = [];

	$cache_key = (string) $item->get_id() . ':' . md5( (string) $item->get_name() );
	if ( isset( $cache[ $cache_key ] ) ) {
		return $cache[ $cache_key ];
	}

	$product = $item->get_product();
	if ( $product instanceof WC_Product ) {
		return $cache[ $cache_key ] = [
			'product' => $product,
			'source'  => 'order_reference',
		];
	}

	$ordered_sku = trim( (string) $item->get_meta( '_dtb_ordered_sku', true ) );
	if ( '' !== $ordered_sku && function_exists( 'wc_get_product_id_by_sku' ) ) {
		$product_id = absint( wc_get_product_id_by_sku( $ordered_sku ) );
		$product    = $product_id > 0 ? wc_get_product( $product_id ) : null;
		if ( $product instanceof WC_Product ) {
			return $cache[ $cache_key ] = [
				'product' => $product,
				'source'  => 'ordered_sku',
			];
		}
	}

	$name = trim( wp_strip_all_tags( (string) $item->get_name() ) );
	if ( '' !== $name && class_exists( 'WP_Query' ) ) {
		$query = new WP_Query(
			[
				'post_type'              => 'product',
				'post_status'            => [ 'publish', 'private' ],
				'title'                  => $name,
				'posts_per_page'         => 2,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);
		$ids = array_values( array_filter( array_map( 'absint', (array) $query->posts ) ) );
		if ( 1 === count( $ids ) ) {
			$product = wc_get_product( $ids[0] );
			if ( $product instanceof WC_Product ) {
				return $cache[ $cache_key ] = [
					'product' => $product,
					'source'  => 'legacy_name_match',
				];
			}
		}
	}

	return $cache[ $cache_key ] = [
		'product' => null,
		'source'  => 'unresolved',
	];
}

function dtb_order_get_line_item_product( WC_Order_Item_Product $item ): ?WC_Product {
	$context = dtb_order_resolve_line_item_product_context( $item );
	return $context['product'] instanceof WC_Product ? $context['product'] : null;
}

/**
 * Resolve the canonical DTB product kind for a WooCommerce product.
 */
function dtb_order_get_product_kind( ?WC_Product $product ): string {
	if ( ! $product instanceof WC_Product ) {
		return 'product';
	}

	$kind = sanitize_key( (string) $product->get_meta( '_dtb_product_kind', true ) );
	if ( '' === $kind && $product->is_type( 'variation' ) ) {
		$parent = wc_get_product( $product->get_parent_id() );
		if ( $parent instanceof WC_Product ) {
			$kind = sanitize_key( (string) $parent->get_meta( '_dtb_product_kind', true ) );
		}
	}

	return '' !== $kind ? $kind : 'product';
}

/**
 * Extract toolset component rows from canonical catalog metadata.
 *
 * The catalog remains authoritative for component definitions. Order item
 * snapshots preserve the definition that existed when a new order was placed.
 *
 * @return array<int,array{name:string,sku:string,quantity:int}>
 */
function dtb_order_extract_toolset_components( ?WC_Product $product ): array {
	if ( ! $product instanceof WC_Product ) {
		return [];
	}

	$source = $product;
	if ( $product->is_type( 'variation' ) ) {
		$parent = wc_get_product( $product->get_parent_id() );
		if ( $parent instanceof WC_Product ) {
			$source = $parent;
		}
	}

	$components = [];
	for ( $index = 0; $index < 50; $index++ ) {
		$name = trim( wp_strip_all_tags( (string) $source->get_meta( '_includes_' . $index . '_name', true ) ) );
		$sku  = trim( wp_strip_all_tags( (string) $source->get_meta( '_includes_' . $index . '_sku', true ) ) );

		if ( '' === $name && '' === $sku ) {
			continue;
		}

		$quantity = 1;
		if ( preg_match( '/^\s*(\d+)\s*[x×]\s*/iu', $name, $matches ) ) {
			$quantity = max( 1, absint( $matches[1] ) );
			$name     = trim( preg_replace( '/^\s*\d+\s*[x×]\s*/iu', '', $name ) ?? $name );
		}

		$components[] = [
			'name'     => $name,
			'sku'      => $sku,
			'quantity' => $quantity,
		];
	}

	return $components;
}

/**
 * Snapshot fulfillment identity and kit composition onto a WooCommerce order item.
 *
 * Protected order-item meta is intentionally used so customer-facing order
 * templates are not polluted. WooCommerce remains the commerce system of
 * record; this is historical transaction context, not a parallel catalog.
 */
function dtb_order_snapshot_line_item_fulfillment_meta( WC_Order_Item_Product $item, ?WC_Product $product = null, bool $save = false ): void {
	$product = $product instanceof WC_Product ? $product : dtb_order_get_line_item_product( $item );
	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$snapshot_fields = [
		'_dtb_ordered_product_id'   => (string) $product->get_id(),
		'_dtb_ordered_variation_id' => $product->is_type( 'variation' ) ? (string) $product->get_id() : '',
		'_dtb_ordered_name'         => (string) $product->get_name(),
		'_dtb_ordered_sku'          => (string) $product->get_sku(),
		'_dtb_ordered_mpn'          => (string) $product->get_meta( '_dtb_mpn', true ),
		'_dtb_ordered_brand'        => (string) $product->get_meta( '_dtb_brand_label', true ),
		'_dtb_ordered_tool_family'  => (string) $product->get_meta( '_dtb_tool_family', true ),
	];

	if ( '' === $snapshot_fields['_dtb_ordered_mpn'] ) {
		$snapshot_fields['_dtb_ordered_mpn'] = (string) $product->get_meta( 'schema_mpn', true );
	}
	if ( '' === $snapshot_fields['_dtb_ordered_brand'] ) {
		$snapshot_fields['_dtb_ordered_brand'] = (string) $product->get_meta( '_dtb_brand', true );
	}

	foreach ( $snapshot_fields as $meta_key => $meta_value ) {
		if ( '' === (string) $item->get_meta( $meta_key, true ) && '' !== trim( (string) $meta_value ) ) {
			$item->add_meta_data( $meta_key, sanitize_text_field( (string) $meta_value ), true );
		}
	}

	$kind = (string) $item->get_meta( '_dtb_ordered_product_kind', true );
	if ( '' === $kind ) {
		$kind = dtb_order_get_product_kind( $product );
		$item->add_meta_data( '_dtb_ordered_product_kind', $kind, true );
	}

	if ( 'toolset' === $kind && '' === (string) $item->get_meta( '_dtb_toolset_components_json', true ) ) {
		$components = dtb_order_extract_toolset_components( $product );
		if ( ! empty( $components ) ) {
			$item->add_meta_data( '_dtb_toolset_components_json', wp_json_encode( $components ), true );
		}
	}

	if ( $save ) {
		$item->save();
	}
}

/**
 * Snapshot order-item fulfillment metadata during classic checkout.
 */
function dtb_order_snapshot_checkout_line_item( $item, $cart_item_key, $values, $order ): void {
	unset( $cart_item_key, $order );

	if ( ! $item instanceof WC_Order_Item_Product ) {
		return;
	}

	$product = isset( $values['data'] ) && $values['data'] instanceof WC_Product
		? $values['data']
		: null;

	dtb_order_snapshot_line_item_fulfillment_meta( $item, $product, false );
}
add_action( 'woocommerce_checkout_create_order_line_item', 'dtb_order_snapshot_checkout_line_item', 20, 4 );

/**
 * Snapshot fulfillment metadata for Store API / Blocks checkout orders.
 */
function dtb_order_snapshot_store_api_order( $order ): void {
	if ( ! $order instanceof WC_Order ) {
		return;
	}

	foreach ( $order->get_items( 'line_item' ) as $item ) {
		if ( $item instanceof WC_Order_Item_Product ) {
			dtb_order_snapshot_line_item_fulfillment_meta( $item, null, true );
		}
	}
}
add_action( 'woocommerce_store_api_checkout_order_processed', 'dtb_order_snapshot_store_api_order', 20, 1 );

function dtb_order_format_product_item( WC_Order_Item_Product $item ): array {
	$product_context = dtb_order_resolve_line_item_product_context( $item );
	$product         = $product_context['product'] instanceof WC_Product ? $product_context['product'] : null;
	$image_id        = $product instanceof WC_Product ? (int) $product->get_image_id() : 0;
	if ( $image_id <= 0 && $item->get_variation_id() > 0 ) {
		$parent_product = wc_get_product( $item->get_product_id() );
		$image_id       = $parent_product instanceof WC_Product ? (int) $parent_product->get_image_id() : 0;
	}
	$image_url    = $image_id > 0 ? (string) wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';
	$image_srcset = $image_id > 0 ? (string) wp_get_attachment_image_srcset( $image_id, 'woocommerce_thumbnail' ) : '';

	if ( '' === $image_url && function_exists( 'wc_placeholder_img_src' ) ) {
		$image_url = (string) wc_placeholder_img_src( 'woocommerce_thumbnail' );
	}

	$quantity     = max( 1, (int) $item->get_quantity() );
	$subtotal     = (float) $item->get_subtotal();
	$total        = (float) $item->get_total();
	$ordered_sku  = trim( (string) $item->get_meta( '_dtb_ordered_sku', true ) );
	$product_kind = sanitize_key( (string) $item->get_meta( '_dtb_ordered_product_kind', true ) );
	$brand        = trim( (string) $item->get_meta( '_dtb_ordered_brand', true ) );
	$mpn          = trim( (string) $item->get_meta( '_dtb_ordered_mpn', true ) );
	$tool_family  = sanitize_key( (string) $item->get_meta( '_dtb_ordered_tool_family', true ) );

	if ( $product instanceof WC_Product ) {
		if ( '' === $ordered_sku ) {
			$ordered_sku = (string) $product->get_sku();
		}
		if ( '' === $brand ) {
			$brand = (string) $product->get_meta( '_dtb_brand_label', true );
			if ( '' === $brand ) {
				$brand = (string) $product->get_meta( '_dtb_brand', true );
			}
		}
		if ( '' === $mpn ) {
			$mpn = (string) $product->get_meta( '_dtb_mpn', true );
			if ( '' === $mpn ) {
				$mpn = (string) $product->get_meta( 'schema_mpn', true );
			}
		}
		if ( '' === $tool_family ) {
			$tool_family = sanitize_key( (string) $product->get_meta( '_dtb_tool_family', true ) );
		}
	}
	if ( '' === $product_kind ) {
		$product_kind = dtb_order_get_product_kind( $product );
	}

	$components        = [];
	$components_source = '';
	$snapshot_json     = (string) $item->get_meta( '_dtb_toolset_components_json', true );
	if ( '' !== $snapshot_json ) {
		$decoded = json_decode( $snapshot_json, true );
		if ( is_array( $decoded ) ) {
			foreach ( $decoded as $component ) {
				if ( ! is_array( $component ) ) {
					continue;
				}
				$name = trim( wp_strip_all_tags( (string) ( $component['name'] ?? '' ) ) );
				$sku  = trim( wp_strip_all_tags( (string) ( $component['sku'] ?? '' ) ) );
				if ( '' === $name && '' === $sku ) {
					continue;
				}
				$components[] = [
					'name'           => $name,
					'sku'            => $sku,
					'quantity'       => max( 1, absint( $component['quantity'] ?? 1 ) ),
					'total_quantity' => max( 1, absint( $component['quantity'] ?? 1 ) ) * $quantity,
				];
			}
			$components_source = 'order_snapshot';
		}
	}

	if ( empty( $components ) && 'toolset' === $product_kind ) {
		foreach ( dtb_order_extract_toolset_components( $product ) as $component ) {
			$component['total_quantity'] = max( 1, absint( $component['quantity'] ?? 1 ) ) * $quantity;
			$components[]                = $component;
		}
		if ( ! empty( $components ) ) {
			$components_source = 'catalog_current';
		}
	}

	$variation_meta = [];
	foreach ( $item->get_formatted_meta_data( '', true ) as $meta ) {
		$raw_key = isset( $meta->key ) ? (string) $meta->key : '';
		if ( '' === $raw_key || str_starts_with( $raw_key, '_' ) ) {
			continue;
		}
		$variation_meta[] = [
			'label' => wp_strip_all_tags( (string) ( $meta->display_key ?? $raw_key ) ),
			'value' => wp_strip_all_tags( (string) ( $meta->display_value ?? $meta->value ?? '' ) ),
		];
	}

	$provenance = [];
	if ( 'schematic' === (string) $item->get_meta( '_dtb_source_surface', true ) ) {
		$provenance = [
			'source'          => 'schematic',
			'schematic_id'    => sanitize_key( (string) $item->get_meta( '_dtb_schematic_id', true ) ),
			'schematic_title' => sanitize_text_field( (string) $item->get_meta( '_dtb_schematic_title', true ) ),
			'brand'           => sanitize_text_field( (string) $item->get_meta( '_dtb_schematic_brand', true ) ),
			'category'        => sanitize_text_field( (string) $item->get_meta( '_dtb_schematic_category', true ) ),
			'page_id'         => sanitize_text_field( (string) $item->get_meta( '_dtb_schematic_page_id', true ) ),
			'page_number'     => absint( $item->get_meta( '_dtb_schematic_page_number', true ) ),
			'page_label'      => sanitize_text_field( (string) $item->get_meta( '_dtb_schematic_page_label', true ) ),
			'part_ref'        => sanitize_text_field( (string) $item->get_meta( '_dtb_schematic_part_ref', true ) ),
			'part_sku'        => sanitize_text_field( (string) $item->get_meta( '_dtb_schematic_part_sku', true ) ),
			'part_title'      => sanitize_text_field( (string) $item->get_meta( '_dtb_schematic_part_title', true ) ),
			'variant'         => sanitize_text_field( (string) $item->get_meta( '_dtb_schematic_variant', true ) ),
		];
		$provenance = array_filter( $provenance, static fn( $value ) => '' !== (string) $value && 0 !== $value );
	}

	$catalog_context = [];
	if ( $product instanceof WC_Product ) {
		$schematic_brand = sanitize_text_field( (string) $product->get_meta( '_dtb_schematic_brand', true ) );
		$schematic_group = sanitize_text_field( (string) $product->get_meta( '_dtb_schematic_group', true ) );
		$catalog_context = array_filter(
			[
				'brand'             => sanitize_text_field( $brand ),
				'mpn'               => sanitize_text_field( $mpn ),
				'product_kind'      => $product_kind,
				'tool_family'       => $tool_family,
				'schematic_brand'   => $schematic_brand,
				'schematic_group'   => $schematic_group,
				'catalog_product_id'=> (int) $product->get_id(),
			],
			static fn( $value ) => '' !== (string) $value && 0 !== $value
		);
	}

	$component_units = 0;
	foreach ( $components as $component ) {
		$component_units += absint( $component['total_quantity'] ?? 0 );
	}

	return [
		'id'                 => (int) $item->get_id(),
		'name'               => wp_strip_all_tags( $item->get_name() ),
		'quantity'           => (int) $item->get_quantity(),
		'unit_price'         => $quantity > 0 ? $subtotal / $quantity : $subtotal,
		'subtotal'           => $subtotal,
		'total'              => $total,
		'discount'           => max( 0, $subtotal - $total ),
		'product_id'         => (int) $item->get_product_id(),
		'variation_id'       => (int) $item->get_variation_id(),
		'catalog_product_id' => $product instanceof WC_Product ? (int) $product->get_id() : 0,
		'catalog_resolution' => sanitize_key( (string) ( $product_context['source'] ?? 'unresolved' ) ),
		'sku'                => sanitize_text_field( $ordered_sku ),
		'mpn'                => sanitize_text_field( $mpn ),
		'brand'              => sanitize_text_field( $brand ),
		'product_kind'       => $product_kind,
		'tool_family'        => $tool_family,
		'image'              => esc_url_raw( $image_url ),
		'image_srcset'       => $image_srcset,
		'image_alt'          => $product instanceof WC_Product
			? wp_strip_all_tags( $product->get_name() )
			: wp_strip_all_tags( $item->get_name() ),
		'variation_meta'     => $variation_meta,
		'provenance'         => $provenance,
		'catalog_context'    => $catalog_context,
		'components'         => $components,
		'components_source'  => $components_source,
		'component_count'    => count( $components ),
		'component_units'    => $component_units,
	];
}

function dtb_order_build_status_projection( int $order_id ): array {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return [
			'status'               => 'unknown',
			'label'                => __( 'Unknown', 'drywall-toolbox' ),
			'wc_status'            => 'unknown',
			'fulfillment_substate' => 'pending',
			'is_terminal'          => false,
		];
	}

	$wc_status  = $order->get_status();
	$map        = dtb_order_get_status_map();
	$entry      = $map[ $wc_status ] ?? null;
	$substate   = dtb_order_get_fulfillment_substate( $order_id );
	$label      = $entry['label'] ?? dtb_order_get_status_label( $wc_status );
	$is_terminal = $entry['is_terminal'] ?? false;

	$substates = dtb_order_fulfillment_substates();
	if ( 'processing' === $wc_status && isset( $substates[ $substate ] ) && 'pending' !== $substate ) {
		$label = $substates[ $substate ];
	}

	return [
		'status'               => in_array( $wc_status, [ 'processing' ], true ) && 'shipped' === $substate ? 'shipped' : $wc_status,
		'label'                => $label,
		'wc_status'            => $wc_status,
		'fulfillment_substate' => $substate,
		'is_terminal'          => $is_terminal,
	];
}

function dtb_order_build_tracking_projection( int $order_id ): ?array {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return null;
	}

	$status_proj = dtb_order_build_status_projection( $order_id );
	$timeline    = dtb_order_get_customer_timeline( $order_id );
	$int_state   = dtb_order_get_integration_state( $order_id );
	$order_type  = function_exists( 'dtb_order_resolve_type' )
		? dtb_order_resolve_type( $order )
		: 'product';

	$veeqo = $int_state['veeqo'] ?? [];
	$tracking_number   = ( ! empty( $veeqo['tracking'] ) && is_string( $veeqo['tracking'] ) )
		? sanitize_text_field( $veeqo['tracking'] ) : null;
	$carrier           = ( ! empty( $veeqo['carrier'] ) && is_string( $veeqo['carrier'] ) )
		? sanitize_text_field( $veeqo['carrier'] ) : null;
	$estimated_delivery = get_post_meta( $order_id, '_dtb_estimated_delivery', true ) ?: null;

	$tracking_url = null;
	if ( $tracking_number && $carrier ) {
		$tracking_url = dtb_order_build_tracking_url( $carrier, $tracking_number );
	}

	$items = [];
	foreach ( $order->get_items() as $item ) {
		/** @var WC_Order_Item_Product $item */
		$formatted_item           = dtb_order_format_product_item( $item );
		$formatted_item['status'] = $status_proj['fulfillment_substate'];
		$items[]                  = $formatted_item;
	}

	return [
		'order_id'           => $order_id,
		'order_type'         => $order_type,
		'status'             => $status_proj['status'],
		'label'              => $status_proj['label'],
		'placed_at'          => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : null,
		'last_updated_at'    => $order->get_date_modified() ? $order->get_date_modified()->format( 'c' ) : null,
		'tracking_number'    => $tracking_number,
		'carrier'            => $carrier,
		'tracking_url'       => $tracking_url,
		'estimated_delivery' => $estimated_delivery ? sanitize_text_field( (string) $estimated_delivery ) : null,
		'items'              => $items,
		'timeline'           => $timeline,
		'number'             => $order->get_order_number(),
		'currency'           => $order->get_currency(),
		'subtotal'           => $order->get_subtotal(),
		'discount_total'     => $order->get_discount_total(),
		'shipping_total'     => $order->get_shipping_total(),
		'has_shipping'       => ! empty( $order->get_shipping_methods() ),
		'total_tax'          => $order->get_total_tax(),
		'total'              => $order->get_total(),
	];
}

function dtb_order_get_tracking_projection( int $order_id ): ?array {
	$cache_key = 'dtb_order_tracking_v3_' . $order_id;
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$projection = dtb_order_build_tracking_projection( $order_id );

	if ( is_array( $projection ) ) {
		set_transient( $cache_key, $projection, 2 * MINUTE_IN_SECONDS );
	}

	return $projection;
}
