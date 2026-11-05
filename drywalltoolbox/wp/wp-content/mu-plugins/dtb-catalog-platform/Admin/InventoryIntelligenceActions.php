<?php
/**
 * Inventory Intelligence admin AJAX actions.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! dtb_is_admin_or_ajax_request() ) {
	return;
}

add_action( 'wp_ajax_dtb_inventory_health', 'dtb_ajax_inventory_health' );
add_action( 'wp_ajax_dtb_inventory_sync_stock', 'dtb_ajax_inventory_sync_stock' );
add_action( 'wp_ajax_dtb_inventory_recompute_rollups', 'dtb_ajax_inventory_recompute_rollups' );
add_action( 'wp_ajax_dtb_inventory_full_rebuild', 'dtb_ajax_inventory_full_rebuild' );
add_action( 'wp_ajax_dtb_inventory_project_universal_parts', 'dtb_ajax_inventory_project_universal_parts' );
add_action( 'wp_ajax_dtb_inventory_universal_seed_summary', 'dtb_ajax_inventory_universal_seed_summary' );
add_action( 'wp_ajax_dtb_inventory_list_rollups', 'dtb_ajax_inventory_list_rollups' );
add_action( 'wp_ajax_dtb_inventory_true_stockouts', 'dtb_ajax_inventory_true_stockouts' );
add_action( 'wp_ajax_dtb_inventory_substitute_preview', 'dtb_ajax_inventory_substitute_preview' );

/**
 * Validate Inventory Intelligence admin AJAX requests.
 */
function dtb_inventory_validate_ajax_request(): void {
	check_ajax_referer( 'dtb_inventory_intelligence_nonce', 'nonce' );
	if ( ! current_user_can( 'dtb_manage_inventory_intelligence' ) ) {
		wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
	}
}

/**
 * AJAX: inventory health summary.
 */
function dtb_ajax_inventory_health(): void {
	dtb_inventory_validate_ajax_request();
	$service            = new DTB_InventoryIntelligenceService();
	$projection_service = new DTB_UniversalPartsProjectionService();
	wp_send_json_success(
		array_merge(
			$service->health(),
			[ 'universal_seed' => $projection_service->summary() ]
		)
	);
}

/**
 * AJAX: committed universal seed summary.
 */
function dtb_ajax_inventory_universal_seed_summary(): void {
	dtb_inventory_validate_ajax_request();
	$projection_service = new DTB_UniversalPartsProjectionService();
	wp_send_json_success( $projection_service->summary() );
}

/**
 * AJAX: project committed universal-parts seed members onto Woo part products.
 */
function dtb_ajax_inventory_project_universal_parts(): void {
	dtb_inventory_validate_ajax_request();
	$mode = sanitize_key( $_POST['mode'] ?? 'dry_run' );
	if ( ! in_array( $mode, [ 'dry_run', 'apply' ], true ) ) {
		$mode = 'dry_run';
	}

	$projection_service = new DTB_UniversalPartsProjectionService();
	$projection_result = $projection_service->sync_members( $mode );
	if ( ! empty( $projection_result['error'] ) ) {
		wp_send_json_error( [ 'code' => $projection_result['error'], 'message' => $projection_result['message'] ], 503 );
		return;
	}
	wp_send_json_success( $projection_result );
}

/**
 * AJAX: sync local stock cache from WooCommerce projection.
 */
function dtb_ajax_inventory_sync_stock(): void {
	dtb_inventory_validate_ajax_request();
	$sync_service = new DTB_VeeqoStockSyncService();
	$result       = $sync_service->sync_from_woocommerce( true );
	wp_send_json_success(
		array_merge(
			$result,
			[ 'message' => sprintf( 'Stock cache synced from WooCommerce projection. %d SKUs updated.', (int) ( $result['updated'] ?? 0 ) ) ]
		)
	);
}

/**
 * AJAX: recompute universal stock rollups.
 */
function dtb_ajax_inventory_recompute_rollups(): void {
	dtb_inventory_validate_ajax_request();
	$rollup_service = new DTB_InventoryRollupService();
	$result         = $rollup_service->recompute_all();
	wp_send_json_success(
		array_merge(
			$result,
			[ 'message' => sprintf( 'Inventory rollups recomputed. %d universal groups updated.', (int) ( $result['updated'] ?? 0 ) ) ]
		)
	);
}

/**
 * AJAX: run full safe rebuild sequence.
 */
function dtb_ajax_inventory_full_rebuild(): void {
	dtb_inventory_validate_ajax_request();

	$projection_service = new DTB_UniversalPartsProjectionService();
	$stock_service      = new DTB_VeeqoStockSyncService();
	$rollup_service     = new DTB_InventoryRollupService();

	$projection_result = $projection_service->sync_members( 'apply' );
	if ( ! empty( $projection_result['error'] ) ) {
		wp_send_json_error( [ 'code' => $projection_result['error'], 'message' => $projection_result['message'] ], 503 );
		return;
	}
	$stock_result      = $stock_service->sync_from_woocommerce( true );
	$rollup_result     = $rollup_service->recompute_all();

	wp_send_json_success(
		[
			'projection' => $projection_result,
			'stock'      => $stock_result,
			'rollup'     => $rollup_result,
			'message'    => sprintf(
				'Full rebuild complete. %d universal products projected, %d stock rows updated, %d rollups recomputed.',
				(int) ( $projection_result['updated'] ?? 0 ),
				(int) ( $stock_result['updated'] ?? 0 ),
				(int) ( $rollup_result['updated'] ?? 0 )
			),
		]
	);
}

/**
 * AJAX: paginated universal stock rollups.
 */
function dtb_ajax_inventory_list_rollups(): void {
	dtb_inventory_validate_ajax_request();
	$page   = max( 1, absint( $_POST['page'] ?? 1 ) );
	$signal = sanitize_key( $_POST['signal'] ?? '' );
	$search = sanitize_text_field( $_POST['search'] ?? '' );

	if ( ! in_array( $signal, [ '', 'none', 'watch', 'reorder', 'critical' ], true ) ) {
		$signal = '';
	}

	$service = new DTB_InventoryIntelligenceService();
	wp_send_json_success( $service->list_universal_stock( $page, $signal, $search ) );
}

/**
 * AJAX: true universal stockouts.
 */
function dtb_ajax_inventory_true_stockouts(): void {
	dtb_inventory_validate_ajax_request();
	$service = new DTB_InventoryIntelligenceService();
	wp_send_json_success( [ 'items' => $service->true_stockouts() ] );
}

/**
 * AJAX: SKU substitution preview.
 */
function dtb_ajax_inventory_substitute_preview(): void {
	dtb_inventory_validate_ajax_request();
	$sku = sanitize_text_field( $_POST['sku'] ?? '' );
	if ( '' === trim( $sku ) ) {
		wp_send_json_error( [ 'message' => 'SKU is required.' ], 400 );
	}

	$service = new DTB_InventoryIntelligenceService();
	wp_send_json_success( $service->substitute_preview( $sku ) );
}
