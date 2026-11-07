<?php
/**
 * Product relationship service.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Load product cross-sell mappings.
 *
 * @param int $product_id Product ID.
 * @return array|WP_Error
 */
function dtb_product_mapping_get_relationships( int $product_id ) {
	return dtb_product_mapping_repo_get_relationships( $product_id );
}

/**
 * Save product cross-sell mappings.
 *
 * @param int          $product_id Product ID.
 * @param string|array $cross_raw Cross-sell raw IDs.
 * @return array|WP_Error
 */
function dtb_product_mapping_save_relationships( int $product_id, $cross_raw, string $expected_version = '' ) {
	return dtb_product_mapping_repo_save_relationships( $product_id, $cross_raw, $expected_version );
}
