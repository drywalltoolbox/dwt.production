<?php
/**
 * Catalog Health admin actions.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_ajax_dtb_catalog_health_scan', 'dtb_catalog_health_ajax_scan' );
add_action( 'wp_ajax_dtb_catalog_health_meta_scan', 'dtb_catalog_health_ajax_meta_scan' );
add_action( 'wp_ajax_dtb_catalog_health_flush', 'dtb_catalog_health_ajax_flush' );
add_action( 'wp_ajax_dtb_catalog_health_export_csv', 'dtb_catalog_health_ajax_export_csv' );

/**
 * Canonical capability check for Catalog Health admin actions.
 */
function dtb_catalog_health_can_manage(): bool {
	$legacy_cap = defined( 'DTB_CAP_CATALOG' ) ? DTB_CAP_CATALOG : 'manage_woocommerce';
	return current_user_can( 'dtb_manage_catalog_health' ) || current_user_can( $legacy_cap );
}

/**
 * AJAX: scan variable products.
 */
function dtb_catalog_health_ajax_scan(): void {
	check_ajax_referer( 'dtb_catalog_health', 'nonce' );

	if ( ! dtb_catalog_health_can_manage() ) {
		wp_send_json_error( 'Permission denied.' );
	}

	$page     = max( 1, (int) ( $_POST['page'] ?? 1 ) );
	$per_page = max( 1, min( 50, (int) ( $_POST['per_page'] ?? 20 ) ) );
	$issues   = dtb_catalog_health_run_scan( $page, $per_page );

	wp_send_json_success( [ 'html' => dtb_catalog_health_render_results( $issues ) ] );
}

/**
 * AJAX: flush product/catalog cache.
 */
function dtb_catalog_health_ajax_flush(): void {
	check_ajax_referer( 'dtb_catalog_health', 'nonce' );

	if ( ! dtb_catalog_health_can_manage() ) {
		wp_send_json_error( 'Permission denied.' );
	}

	if ( function_exists( 'dtb_invalidate_product_cache' ) ) {
		dtb_invalidate_product_cache();
	}

	if ( function_exists( 'dtb_log_cache_event' ) ) {
		dtb_log_cache_event( 'admin_catalog_health_flush', [ 'user_id' => get_current_user_id() ] );
	}

	wp_send_json_success( [ 'message' => 'Product cache flushed successfully.' ] );
}

/**
 * AJAX: export variable-product issues as CSV.
 */
function dtb_catalog_health_ajax_export_csv(): void {
	check_ajax_referer( 'dtb_catalog_health', 'nonce' );

	if ( ! dtb_catalog_health_can_manage() ) {
		wp_die( 'Permission denied.' );
	}

	$issues = dtb_catalog_health_run_scan( 1, 100 );

	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="dtb-catalog-health-' . gmdate( 'Y-m-d' ) . '.csv"' );
	header( 'Pragma: no-cache' );

	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	fputcsv( $out, [ 'Product ID', 'Product Name', 'SKU', 'Issue Severity', 'Issue Code', 'Message', 'Edit URL' ] );

	foreach ( $issues as $issue ) {
		fputcsv( $out, [
			$issue['product_id'],
			$issue['product_name'],
			$issue['sku'],
			$issue['severity'],
			$issue['code'],
			$issue['message'],
			get_edit_post_link( (int) $issue['product_id'], '' ),
		] );
	}

	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	exit;
}

/**
 * AJAX: scan DTB product meta completeness.
 */
function dtb_catalog_health_ajax_meta_scan(): void {
	check_ajax_referer( 'dtb_catalog_health', 'nonce' );

	if ( ! dtb_catalog_health_can_manage() ) {
		wp_send_json_error( 'Permission denied.' );
	}

	$page     = max( 1, (int) ( $_POST['page'] ?? 1 ) );
	$per_page = max( 1, min( 50, (int) ( $_POST['per_page'] ?? 20 ) ) );
	$issues   = dtb_catalog_health_run_dtb_meta_scan( $page, $per_page );

	wp_send_json_success( [
		'html'       => dtb_catalog_health_render_results( $issues ),
		'page'       => $page,
		'issueCount' => count( $issues ),
	] );
}
