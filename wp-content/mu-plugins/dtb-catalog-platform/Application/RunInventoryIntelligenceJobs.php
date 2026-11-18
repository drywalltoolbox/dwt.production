<?php
/**
 * Inventory Intelligence scheduled/background job wiring.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'cron_schedules', 'dtb_inventory_intelligence_cron_schedules' );
add_action( 'init', 'dtb_inventory_intelligence_schedule_jobs', 20 );
add_action( 'dtb_inventory_stock_projection_sync', 'dtb_inventory_intelligence_run_stock_projection_sync' );
add_action( 'dtb_inventory_rollup_recompute', 'dtb_inventory_intelligence_run_rollup_recompute' );

/**
 * Register inventory-specific cron intervals.
 *
 * @param array<string,array<string,int|string>> $schedules Existing schedules.
 * @return array<string,array<string,int|string>>
 */
function dtb_inventory_intelligence_cron_schedules( array $schedules ): array {
	$schedules['dtb_every_30_minutes'] = [
		'interval' => 30 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every 30 minutes', 'drywall-toolbox' ),
	];
	$schedules['dtb_hourly_safe'] = [
		'interval' => HOUR_IN_SECONDS,
		'display'  => __( 'Hourly', 'drywall-toolbox' ),
	];
	return $schedules;
}

/**
 * Schedule inventory intelligence background jobs if missing.
 */
function dtb_inventory_intelligence_schedule_jobs(): void {
	if ( ! wp_next_scheduled( 'dtb_inventory_stock_projection_sync' ) ) {
		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'dtb_every_30_minutes', 'dtb_inventory_stock_projection_sync' );
	}

	if ( ! wp_next_scheduled( 'dtb_inventory_rollup_recompute' ) ) {
		wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'dtb_every_30_minutes', 'dtb_inventory_rollup_recompute' );
	}
}

/**
 * Scheduled job: project WooCommerce stock into local stock cache.
 */
function dtb_inventory_intelligence_run_stock_projection_sync(): void {
	$service = new DTB_VeeqoStockSyncService();
	$service->sync_from_woocommerce( true );
}

/**
 * Scheduled job: recompute universal stock rollups.
 */
function dtb_inventory_intelligence_run_rollup_recompute(): void {
	$service = new DTB_InventoryRollupService();
	$service->recompute_all();
}
