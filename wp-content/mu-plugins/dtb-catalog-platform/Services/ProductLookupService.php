<?php
defined( 'ABSPATH' ) || exit;

/**
 * Normalize a WC product payload into the DTB catalog DTO.
 */
function dtb_catalog_lookup_normalize_product( array $wc_product, ?array $parent_wc = null ): array {
	$dto = DTB_CatalogProductNormalizer::normalize( $wc_product, $parent_wc );
	return dtb_catalog_product_finalize( $dto );
}

/**
 * Fetch and normalize products by IDs.
 *
 * @param int[] $ids
 * @return array[]
 */
function dtb_catalog_lookup_products_by_ids( array $ids ): array {
	$raw_products = dtb_catalog_wc_fetch_products_by_ids( $ids );
	$items        = [];

	foreach ( $raw_products as $raw_product ) {
		if ( is_array( $raw_product ) ) {
			$items[] = dtb_catalog_lookup_normalize_product( $raw_product );
		}
	}

	return $items;
}

/**
 * Fetch and normalize product detail by slug.
 */
function dtb_catalog_lookup_product_detail_by_slug( string $slug ): ?array {
	$wc_product = dtb_catalog_wc_fetch_product_by_slug( $slug );
	if ( ! is_array( $wc_product ) ) {
		return null;
	}

	return dtb_catalog_lookup_normalize_product( $wc_product );
}

/**
 * Fetch and normalize product detail by ID.
 */
function dtb_catalog_lookup_product_detail_by_id( int $product_id ): ?array {
	$wc_product = dtb_catalog_wc_fetch_product_by_id( $product_id );
	if ( ! is_array( $wc_product ) ) {
		return null;
	}

	return dtb_catalog_lookup_normalize_product( $wc_product );
}

/**
 * Resolve one exact, public WooCommerce SKU into catalog-platform projections.
 *
 * A null result means the exact identity is confirmed absent or not public.
 * Runtime/normalization failures return WP_Error so callers cannot turn an
 * outage into a false not-found response.
 *
 * @return array{type:string,product:array,variation:?array}|null|WP_Error
 */
function dtb_catalog_lookup_exact_sku_projection( string $sku ) {
	global $wpdb;

	if ( ! function_exists( 'wc_get_product_id_by_sku' ) || ! function_exists( 'wc_get_product' ) ) {
		return new WP_Error( 'catalog_runtime_unavailable', 'Product catalog is temporarily unavailable.', [ 'status' => 503 ] );
	}

	try {
		$product_id = absint( wc_get_product_id_by_sku( $sku ) );
	} catch ( Throwable $error ) {
		return new WP_Error( 'catalog_lookup_failed', 'Product catalog is temporarily unavailable.', [ 'status' => 503 ] );
	}
	$current_db_error = (string) ( $wpdb->last_error ?? '' );
	if ( '' !== $current_db_error ) {
		return new WP_Error( 'catalog_lookup_failed', 'Product catalog is temporarily unavailable.', [ 'status' => 503 ] );
	}
	if ( $product_id <= 0 ) {
		return null;
	}

	try {
		$product = wc_get_product( $product_id );
	} catch ( Throwable $error ) {
		return new WP_Error( 'catalog_lookup_failed', 'Product catalog is temporarily unavailable.', [ 'status' => 503 ] );
	}
	if ( '' !== (string) ( $wpdb->last_error ?? '' ) ) {
		return new WP_Error( 'catalog_lookup_failed', 'Product catalog is temporarily unavailable.', [ 'status' => 503 ] );
	}
	if ( ! $product instanceof WC_Product ) {
		return null;
	}
	if ( (string) $product->get_sku() !== $sku || 'publish' !== $product->get_status() ) {
		return null;
	}

	if ( $product instanceof WC_Product_Variation ) {
		$parent_id = absint( $product->get_parent_id() );
		$parent    = $parent_id > 0 ? wc_get_product( $parent_id ) : false;
		if ( '' !== (string) ( $wpdb->last_error ?? '' ) ) {
			return new WP_Error( 'catalog_lookup_failed', 'Product catalog is temporarily unavailable.', [ 'status' => 503 ] );
		}
		if (
			! $parent instanceof WC_Product
			|| 'publish' !== $parent->get_status()
			|| ! $parent->is_visible()
			|| ! $product->variation_is_visible()
		) {
			return null;
		}

		$parent_dto = dtb_catalog_lookup_product_detail_by_id( $parent_id );
		if ( ! is_array( $parent_dto ) ) {
			return new WP_Error( 'catalog_projection_failed', 'Product catalog is temporarily unavailable.', [ 'status' => 503 ] );
		}

		$variation_dto = DTB_VariationReadModelService::get_normalized_by_id( $product_id, false );
		if ( ! is_array( $variation_dto ) ) {
			return new WP_Error( 'catalog_projection_failed', 'Product catalog is temporarily unavailable.', [ 'status' => 503 ] );
		}
		$variation_dto['slug'] = $parent_dto['slug'] ?? '';

		return [
			'type'      => 'variation',
			'product'   => dtb_catalog_lookup_compact_sku_projection( $parent_dto ),
			'variation' => dtb_catalog_lookup_compact_sku_projection( $variation_dto ),
		];
	}

	if ( ! $product->is_visible() ) {
		return null;
	}
	$product_dto = dtb_catalog_lookup_product_detail_by_id( $product_id );
	if ( ! is_array( $product_dto ) ) {
		return new WP_Error( 'catalog_projection_failed', 'Product catalog is temporarily unavailable.', [ 'status' => 503 ] );
	}

	return [
		'type'      => 'product',
		'product'   => dtb_catalog_lookup_compact_sku_projection( $product_dto ),
		'variation' => null,
	];
}

/** Keep exact SKU resolution to the fields consumed by hotspot/link surfaces. */
function dtb_catalog_lookup_compact_sku_projection( array $dto ): array {
	$projection = [];
	foreach ( [ 'id', 'parentId', 'slug', 'name', 'sku', 'type', 'brand', 'price', 'inventory', 'variation', 'attributes' ] as $field ) {
		if ( array_key_exists( $field, $dto ) ) {
			$projection[ $field ] = $dto[ $field ];
		}
	}

	$media = is_array( $dto['media'] ?? null ) ? $dto['media'] : [];
	$projection['media'] = [
		'image'  => (string) ( $media['image'] ?? '' ),
		'images' => array_values( (array) ( $media['images'] ?? [] ) ),
	];

	return $projection;
}

/** Project collection items to the storefront card contract. */
function dtb_catalog_product_card_projection( array $dto ): array {
	$fields = [
		'id', 'type', 'productKind', 'sku', 'mpn', 'upc', 'slug', 'name', 'brand',
		'category', 'displayCategory', 'toolFamily', 'toolRole', 'isParts', 'isVariable',
		'isRepairable', 'parentId', 'parentSku', 'defaultVariationId', 'defaultVariationSku',
		'commerceMode', 'price', 'inventory', 'media', 'builder', 'variation', 'attributes', 'cardProduct',
	];
	return array_intersect_key( $dto, array_fill_keys( $fields, true ) );
}

/**
 * Fetch compact storefront projections keyed by exact WooCommerce entity ID.
 * Supports both top-level products and child variations without SKU searching.
 *
 * @param int[] $ids
 * @return array<int,array<string,mixed>>
 */
function dtb_catalog_lookup_storefront_projections_by_ids( array $ids ): array {
	$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	$products = [];
	$top_level_ids = [];

	foreach ( $ids as $id ) {
		$product = wc_get_product( $id );
		if ( ! $product || 'publish' !== $product->get_status() ) {
			continue;
		}
		$products[ $id ] = $product;
		if ( ! $product instanceof WC_Product_Variation ) {
			$top_level_ids[] = $id;
		}
	}

	$top_level_dtos = [];
	foreach ( dtb_catalog_lookup_products_by_ids( $top_level_ids ) as $dto ) {
		if ( (int) ( $dto['id'] ?? 0 ) > 0 ) {
			$top_level_dtos[ (int) $dto['id'] ] = $dto;
		}
	}

	$projections = [];
	foreach ( $products as $id => $product ) {
		$is_variation = $product instanceof WC_Product_Variation;
		$dto = $product instanceof WC_Product_Variation
			? DTB_VariationReadModelService::get_normalized_by_id( $id )
			: ( $top_level_dtos[ $id ] ?? null );
		if ( ! is_array( $dto ) ) {
			continue;
		}

		$parent_id = (int) ( $dto['parentId'] ?? 0 );
		// A variation's own slug is not a storefront route. Preserve the parent
		// product identity so consumers can construct the canonical React route
		// without trusting a legacy WooCommerce permalink.
		$parent_slug = '';
		if ( $is_variation && $parent_id > 0 ) {
			$parent_slug = sanitize_title( (string) get_post_field( 'post_name', $parent_id ) );
		}

		$projections[ $id ] = [
			'id'          => (int) ( $dto['id'] ?? $id ),
			'parent_id'   => $parent_id,
			'parent_slug' => $parent_slug,
			'type'        => sanitize_key( (string) ( $dto['type'] ?? $product->get_type() ) ),
			'slug'        => $is_variation ? '' : sanitize_title( (string) ( $dto['slug'] ?? '' ) ),
			'name'        => sanitize_text_field( (string) ( $dto['name'] ?? $product->get_name() ) ),
			'sku'         => sanitize_text_field( (string) ( $dto['sku'] ?? $product->get_sku() ) ),
			'price'       => isset( $dto['price']['value'] ) ? (float) $dto['price']['value'] : null,
			'stock_status'=> sanitize_key( (string) ( $dto['inventory']['stockStatus'] ?? 'unknown' ) ),
			'purchasable' => true === ( $dto['inventory']['purchasable'] ?? false ),
			'images'      => array_values( array_filter( array_map( 'esc_url_raw', (array) ( $dto['media']['images'] ?? [] ) ) ) ),
		];
	}

	return $projections;
}
