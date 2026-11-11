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
