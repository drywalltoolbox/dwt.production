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
add_action( 'wp_ajax_dtb_pm_project_compatibility', 'dtb_ajax_pm_project_compatibility' );
add_action( 'wp_ajax_dtb_pm_rollback_compatibility', 'dtb_ajax_pm_rollback_compatibility' );

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
	$after_id = absint( $_POST['after_id'] ?? 0 );
	$max_id = absint( $_POST['max_id'] ?? 0 );
	wp_send_json_success( dtb_product_mapping_repo_audit_page( $after_id, $max_id ) );
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

/** Preview or apply one approved, bounded compatibility projection artifact. */
function dtb_ajax_pm_project_compatibility(): void {
	dtb_product_mapping_validate_ajax_request();
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Administrator access is required to project fitment.' ], 403 );
	}
	$mode = sanitize_key( wp_unslash( $_POST['mode'] ?? 'preview' ) );
	if ( ! in_array( $mode, [ 'preview', 'apply' ], true ) ) {
		wp_send_json_error( [ 'message' => 'Choose preview or apply.' ], 400 );
	}
	$upload = $_FILES['artifact'] ?? null;
	if ( ! is_array( $upload ) || UPLOAD_ERR_OK !== (int) ( $upload['error'] ?? UPLOAD_ERR_NO_FILE ) || empty( $upload['tmp_name'] ) || ! is_uploaded_file( (string) $upload['tmp_name'] ) ) {
		wp_send_json_error( [ 'message' => 'Choose a valid projection JSON file and try again.' ], 400 );
	}
	$path = (string) $upload['tmp_name'];
	$size = filesize( $path );
	$wp_upload_limit = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0;
	$max_artifact_bytes = min( DTB_ProductRelationshipProjectionService::MAX_ARTIFACT_BYTES, $wp_upload_limit > 0 ? $wp_upload_limit : DTB_ProductRelationshipProjectionService::MAX_ARTIFACT_BYTES );
	if ( false === $size || $size < 2 || $size > $max_artifact_bytes || ! is_readable( $path ) ) {
		wp_send_json_error( [ 'message' => sprintf( 'Projection files must be readable and no larger than %s.', size_format( $max_artifact_bytes ) ) ], 400 );
	}
	$expected_sha256 = strtolower( trim( sanitize_text_field( wp_unslash( $_POST['expected_sha256'] ?? '' ) ) ) );
	if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_sha256 ) ) {
		wp_send_json_error( [ 'message' => 'Enter the reviewed artifact SHA-256 as 64 hexadecimal characters.' ], 400 );
	}
	$payload = file_get_contents( $path );
	$artifact = is_string( $payload ) ? json_decode( $payload, true ) : null;
	$release_id = strtolower( (string) ( is_array( $artifact ) ? ( $artifact['release_id'] ?? '' ) : '' ) );
	if ( ! preg_match( '/^[a-f0-9]{24}$/', $release_id ) ) {
		wp_send_json_error( [ 'message' => 'The uploaded file is not a valid compatibility release artifact.' ], 400 );
	}
	if ( 'apply' === $mode ) {
		$confirmation = strtolower( trim( sanitize_text_field( wp_unslash( $_POST['confirm_release_id'] ?? '' ) ) ) );
		if ( ! hash_equals( $release_id, $confirmation ) ) {
			wp_send_json_error( [ 'message' => 'Enter the exact release ID shown by the preview to apply this artifact.' ], 400 );
		}
	}
	try {
		$result = DTB_ProductRelationshipProjectionService::run_artifact( $path, $expected_sha256, 'apply' === $mode );
	} catch ( Throwable $error ) {
		wp_send_json_error( [ 'message' => sanitize_text_field( $error->getMessage() ), 'release_id' => $release_id ], 409 );
	}
	if ( ! empty( $result['error'] ) ) {
		wp_send_json_error( [ 'message' => sanitize_text_field( (string) $result['error'] ), 'release_id' => $release_id, 'result' => $result ], 409 );
	}
	wp_send_json_success( [ 'release_id' => $release_id, 'result' => $result ] );
}

/** Roll back one private projection report after the service verifies its digest and live after-state. */
function dtb_ajax_pm_rollback_compatibility(): void {
	dtb_product_mapping_validate_ajax_request();
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Administrator access is required to roll back fitment.' ], 403 );
	}
	if ( 'ROLLBACK' !== strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['confirm_rollback'] ?? '' ) ) ) ) ) {
		wp_send_json_error( [ 'message' => 'Confirm the rollback by entering ROLLBACK.' ], 400 );
	}
	$path = trim( sanitize_text_field( wp_unslash( $_POST['report_path'] ?? '' ) ) );
	$expected_sha256 = strtolower( trim( sanitize_text_field( wp_unslash( $_POST['report_sha256'] ?? '' ) ) ) );
	if ( '' === $path || ! preg_match( '/^[a-f0-9]{64}$/', $expected_sha256 ) ) {
		wp_send_json_error( [ 'message' => 'Enter the private rollback report path and its 64-character SHA-256.' ], 400 );
	}
	try {
		$result = DTB_ProductRelationshipProjectionService::rollback_report( $path, $expected_sha256 );
	} catch ( Throwable $error ) {
		wp_send_json_error( [ 'message' => sanitize_text_field( $error->getMessage() ) ], 409 );
	}
	wp_send_json_success( $result );
}
