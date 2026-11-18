<?php
/**
 * Product Mapping mutation use cases.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Retired variation mutation compatibility entry point.
 *
 * @param array $payload Variation payload.
 * @return array|WP_Error
 */
function dtb_product_mapping_run_save_variation( array $payload ) {
	return new WP_Error( 'variation_lifecycle_owned_by_woocommerce', 'Variation creation and updates belong to WooCommerce product administration and are not part of Product Relationships.' );
}

/**
 * Retired variation deletion compatibility entry point.
 *
 * @param array $payload Variation payload.
 * @return array|WP_Error
 */
function dtb_product_mapping_run_delete_variation( array $payload ) {
	return new WP_Error( 'variation_lifecycle_owned_by_woocommerce', 'Variation deletion belongs to WooCommerce product administration and is not part of Product Relationships.' );
}
