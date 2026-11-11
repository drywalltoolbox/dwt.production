<?php
/**
 * Rest — RepairHealthController: GET /wp-json/dtb/v1/repairs/health
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_repair_register_health_route' );

function dtb_repair_register_health_route(): void {
register_rest_route(
'dtb/v1',
'/repairs/health',
[
'methods'             => WP_REST_Server::READABLE,
'callback'            => 'dtb_repair_rest_health',
'permission_callback' => '__return_true',
]
);
}

function dtb_repair_rest_health(): WP_REST_Response {
	global $wpdb;

	$table  = $wpdb->prefix . 'dtb_repair_events';
	$checks = [];

	// Event table existence.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$table_exists           = (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
	$checks['event_table']  = $table_exists ? 'ok' : 'missing';

	// Action Scheduler.
	$checks['action_scheduler'] = function_exists( 'as_schedule_single_action' ) ? 'ok' : 'unavailable';

	// WooCommerce.
	$checks['woocommerce'] = function_exists( 'wc_get_order' ) ? 'ok' : 'inactive';

	// Veeqo.
	$checks['veeqo'] = ( function_exists( 'dtb_veeqo_enabled' ) && dtb_veeqo_enabled() ) ? 'ok' : 'not_configured';

	// QuickBooks.
	$checks['quickbooks'] = ( function_exists( 'dtb_qbo_enabled' ) && dtb_qbo_enabled() ) ? 'ok' : 'not_configured';

	$all_ok = ! in_array( 'missing', $checks, true ) && ! in_array( 'inactive', $checks, true );

	return new WP_REST_Response(
		[
			'healthy'  => $all_ok,
			'checks'   => $checks,
			'ts'       => gmdate( 'Y-m-d\TH:i:s\Z' ),
		],
		$all_ok ? 200 : 503
	);
}
