<?php
/**
 * DTB Order Health Controller — REST health check endpoint handler.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_order_rest_health( WP_REST_Request $request ): WP_REST_Response {
	global $wpdb;

	$table = $wpdb->prefix . 'dtb_order_events';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$table_exists = (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );

	$wc_ok        = class_exists( 'WooCommerce' );
	$queue_ok     = function_exists( 'as_schedule_single_action' );
	$veeqo_ok     = function_exists( 'dtb_veeqo_sync_order' ) || defined( 'DTB_VEEQO_API_KEY' ) || get_option( 'dtb_veeqo_api_key' );
	$quickbooks_ok = function_exists( 'dtb_quickbooks_sync_order' ) || get_option( 'dtb_qbo_client_id' );
	$rewards_ok   = function_exists( 'dtb_rewards_issue_for_order' );

	$health = [
		'ok'           => $wc_ok && $table_exists,
		'woocommerce'  => $wc_ok,
		'payments'     => $wc_ok && (bool) get_option( 'woocommerce_default_gateway' ),
		'queue'        => $queue_ok,
		'veeqo'        => (bool) $veeqo_ok,
		'quickbooks'   => (bool) $quickbooks_ok,
		'rewards'      => (bool) $rewards_ok,
		'events_table' => $table_exists,
	];

	return new WP_REST_Response( $health, $health['ok'] ? 200 : 503 );
}
