<?php
/**
 * Product relationship service.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Load upsell and cross-sell mappings.
 *
 * @param int $product_id Product ID.
 * @return array|WP_Error
 */
function dtb_product_mapping_get_relationships( int $product_id ) {
	return dtb_product_mapping_repo_get_relationships( $product_id );
}

/**
 * Save upsell and cross-sell mappings.
 *
 * @param int          $product_id Product ID.
 * @param string|array $upsell_raw Upsell raw IDs.
 * @param string|array $cross_raw Cross-sell raw IDs.
 * @return array|WP_Error
 */
function dtb_product_mapping_save_relationships( int $product_id, $upsell_raw, $cross_raw, string $expected_version = '' ) {
	return dtb_product_mapping_repo_save_relationships( $product_id, $upsell_raw, $cross_raw, $expected_version );
}
