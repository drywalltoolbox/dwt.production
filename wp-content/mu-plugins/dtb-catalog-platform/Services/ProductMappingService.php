<?php
/**
 * Product Mapping service.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Product search service.
 *
 * @param string $query Search query.
 * @param string $product_type_csv Optional CSV type list.
 * @return array[]
 */
function dtb_product_mapping_search_products( string $query, string $product_type_csv = '' ): array {
	return dtb_product_mapping_repo_search_products( $query, $product_type_csv );
}
