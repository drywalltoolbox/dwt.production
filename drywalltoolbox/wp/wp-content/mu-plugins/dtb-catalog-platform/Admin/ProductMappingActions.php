<?php
/**
 * Product Mapping admin AJAX actions.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! dtb_is_admin_or_ajax_request() ) {
	return;
}

add_action( 'wp_ajax_dtb_pm_search_products', 'dtb_ajax_pm_search_products' );
add_action( 'wp_ajax_dtb_pm_get_compatibility', 'dtb_ajax_pm_get_compatibility' );
add_action( 'wp_ajax_dtb_pm_get_parts', 'dtb_ajax_pm_get_parts' );
add_action( 'wp_ajax_dtb_pm_relationship_audit_page', 'dtb_ajax_pm_relationship_audit_page' );
add_action( 'wp_ajax_dtb_pm_get_relationships', 'dtb_ajax_pm_get_relationships' );
add_action( 'wp_ajax_dtb_pm_save_relationships', 'dtb_ajax_pm_save_relationships' );

/**
 * AJAX: Search products.
 */
function dtb_ajax_pm_search_products() {
	dtb_product_mapping_validate_ajax_request();

	$query        = sanitize_text_field( $_POST['q'] ?? '' );
	$product_type = sanitize_text_field( $_POST['product_type'] ?? '' );
	wp_send_json_success( dtb_product_mapping_search_products( $query, $product_type ) );
}

/**
 * AJAX: Get compatibility mapping.
 */
function dtb_ajax_pm_get_compatibility() {
	dtb_product_mapping_validate_ajax_request();

	$product_id = absint( $_POST['product_id'] ?? 0 );
	$mode       = sanitize_text_field( $_POST['mode'] ?? 'part' );

	if ( ! $product_id || 'part' !== $mode ) {
		wp_send_json_error( [ 'message' => 'Select a valid part to inspect fitment.' ], 400 );
	}

	$result = dtb_product_mapping_get_compatibility( $product_id, $mode );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
	}
	wp_send_json_success( $result );
}

/**
 * AJAX: Get parts listing.
 */
function dtb_ajax_pm_get_parts() {
	dtb_product_mapping_validate_ajax_request();

	$brand  = sanitize_text_field( $_POST['brand'] ?? '' );
	$search = sanitize_text_field( $_POST['search'] ?? '' );
	$paged  = absint( $_POST['paged'] ?? 1 );

	wp_send_json_success( dtb_product_mapping_get_parts( $brand, $search, $paged ) );
}

/** AJAX: One bounded page of live relationship inputs for the read-only integrity scan. */
function dtb_ajax_pm_relationship_audit_page(): void {
	dtb_product_mapping_validate_ajax_request();
	$page = max( 1, absint( $_POST['page'] ?? 1 ) );
	wp_send_json_success( dtb_product_mapping_repo_audit_page( $page ) );
}

/**
 * AJAX: Get upsell/cross-sell relationships.
 */
function dtb_ajax_pm_get_relationships() {
	dtb_product_mapping_validate_ajax_request();

	$product_id = absint( $_POST['product_id'] ?? 0 );
	if ( ! $product_id ) {
		wp_send_json_error( [ 'message' => 'No product ID.' ] );
	}

	$result = dtb_product_mapping_get_relationships( $product_id );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( [ 'message' => $result->get_error_message() ] );
	}

	wp_send_json_success( $result );
}

/**
 * AJAX: Save upsell/cross-sell relationships.
 */
function dtb_ajax_pm_save_relationships() {
	dtb_product_mapping_validate_ajax_request();

	$product_id = absint( $_POST['product_id'] ?? 0 );
	if ( ! $product_id ) {
		wp_send_json_error( [ 'message' => 'No product ID.' ] );
	}

	$result = dtb_product_mapping_save_relationships(
		$product_id,
		$_POST['upsell_ids'] ?? '',
		$_POST['crosssell_ids'] ?? '',
		sanitize_text_field( wp_unslash( $_POST['version'] ?? '' ) )
	);

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( [ 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ], 'relationship_conflict' === $result->get_error_code() ? 409 : 400 );
	}

	wp_send_json_success( $result );
}
