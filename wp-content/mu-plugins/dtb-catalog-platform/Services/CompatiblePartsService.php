<?php
/**
 * Compatible parts service.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get compatibility view model.
 *
 * @param int    $product_id Product ID.
 * @param string $mode part|tool.
 * @return array
 */
function dtb_product_mapping_get_compatibility( int $product_id, string $mode ) {
	return dtb_product_mapping_repo_get_compatibility( $product_id, $mode );
}

/**
 * Get paged parts view model.
 *
 * @param string $brand Brand filter.
 * @param string $search Search query.
 * @param int    $paged Page number.
 * @return array
 */
function dtb_product_mapping_get_parts( string $brand, string $search, int $paged ): array {
	return dtb_product_mapping_repo_get_parts( $brand, $search, $paged );
}
