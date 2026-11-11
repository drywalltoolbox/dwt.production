<?php
/**
 * Product Mapping variation repository.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Search Woo products for Product Mapping dropdowns.
 *
 * @param string $query Search query.
 * @param string $product_type_csv Optional CSV of product types.
 * @return array[]
 */
function dtb_product_mapping_repo_search_products( string $query, string $product_type_csv = '' ): array {
	$args = [
		'limit'  => 25,
		's'      => $query,
		'status' => 'publish',
		'return' => 'objects',
	];

	if ( '' !== $product_type_csv ) {
		$registered_types = array_keys( wc_get_product_types() );
		$requested_types  = array_values( array_unique( array_filter( array_map( 'sanitize_key', explode( ',', $product_type_csv ) ) ) ) );
		$allowed_types    = array_values( array_intersect( $requested_types, $registered_types ) );
		if ( empty( $allowed_types ) ) {
			return [];
		}
		$args['type'] = $allowed_types;
	}

	$products = wc_get_products( $args );
	$results  = [];

	foreach ( $products as $product ) {
		$results[] = [
			'id'   => $product->get_id(),
			'name' => $product->get_name(),
			'sku'  => $product->get_sku(),
			'type' => $product->get_type(),
			'catalog_visibility' => $product->get_catalog_visibility(),
		];
	}

	return $results;
}

/**
 * Retired relationship-tool variation mutation. WooCommerce owns variation lifecycle.
 *
 * @param int   $parent_id Parent product ID.
 * @param int   $variation_id Variation ID.
 * @param string $sku SKU.
 * @param string $price Regular price.
 * @param string $sale_price Sale price.
 * @param string $stock Stock quantity.
 * @param array  $attributes Attribute map.
 * @return array|WP_Error
 */
function dtb_product_mapping_repo_save_variation( int $parent_id, int $variation_id, string $sku, string $price, string $sale_price, string $stock, array $attributes ) {
	return new WP_Error( 'variation_lifecycle_owned_by_woocommerce', 'Variation creation and updates belong to WooCommerce product administration and are not part of Product Relationships.' );
}

/**
 * Retired relationship-tool variation deletion. WooCommerce owns variation lifecycle.
 *
 * @param int $variation_id Variation ID.
 * @return array|WP_Error
 */
function dtb_product_mapping_repo_delete_variation( int $variation_id ) {
	return new WP_Error( 'variation_lifecycle_owned_by_woocommerce', 'Variation deletion belongs to WooCommerce product administration and is not part of Product Relationships.' );
}
