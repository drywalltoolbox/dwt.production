<?php
/**
 * Product Mapping validation helpers.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validate Product Mapping AJAX nonce and capability.
 */
function dtb_product_mapping_validate_ajax_request(): void {
	check_ajax_referer( 'dtb_mapping_nonce', 'nonce' );

	if ( ! current_user_can( 'dtb_manage_product_mapping' ) ) {
		wp_send_json_error( [], 403 );
	}
}
