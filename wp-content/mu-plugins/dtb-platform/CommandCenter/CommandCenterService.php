<?php
/**
 * DTB Platform — CommandCenterService
 *
 * Thin service layer between the controller/page and the read model.
 * Handles cache invalidation and data shaping.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Invalidate all Command Center cached data.
 */
function dtb_command_center_flush_cache(): void {
	delete_transient( 'dtb_cc_orders_summary' );
	delete_transient( 'dtb_cc_repairs_summary' );
	delete_transient( 'dtb_cc_returns_summary' );
	delete_transient( 'dtb_cc_support_summary' );
}

// Flush cache on key state changes.
add_action( 'woocommerce_order_status_changed', 'dtb_command_center_flush_cache' );
add_action( 'dtb_repair_status_changed',        'dtb_command_center_flush_cache' );
add_action( 'dtb_return_status_changed',        'dtb_command_center_flush_cache' );
add_action( 'dtb_support_ticket_status_changed','dtb_command_center_flush_cache' );

/**
 * Get the Command Center data for the page.
 * Merges read model with deep-link URLs.
 *
 * @return array
 */
function dtb_command_center_get_dashboard_data(): array {
	$model = dtb_command_center_build_read_model();

	$admin = admin_url( 'admin.php' );

	$model['links'] = [
		'orders_attention'   => add_query_arg( [ 'page' => 'dtb-orders',  'status' => 'attention' ], $admin ),
		'orders_failed'      => add_query_arg( [ 'page' => 'dtb-orders',  'status' => 'failed' ], $admin ),
		'orders_processing'  => add_query_arg( [ 'page' => 'dtb-orders',  'status' => 'processing' ], $admin ),
		'repairs_review'     => add_query_arg( [ 'page' => 'dtb-repairs', 'status' => 'review' ], $admin ),
		'repairs_quote'      => add_query_arg( [ 'page' => 'dtb-repairs', 'status' => 'quote_pending' ], $admin ),
		'repairs_progress'   => add_query_arg( [ 'page' => 'dtb-repairs', 'status' => 'in_progress' ], $admin ),
		'returns_review'     => add_query_arg( [ 'page' => 'dtb-returns', 'tab' => 'pending_review' ], $admin ),
		'returns_inspection' => add_query_arg( [ 'page' => 'dtb-returns', 'tab' => 'item_received' ], $admin ),
		'returns_refund'     => add_query_arg( [ 'page' => 'dtb-returns', 'tab' => 'refund_issued' ], $admin ),
		'support_open'       => add_query_arg( [ 'page' => 'dtb-support', 'filter' => 'open' ], $admin ),
		'support_past_sla'   => add_query_arg( [ 'page' => 'dtb-support', 'filter' => 'past_sla' ], $admin ),
		'system_manager'     => add_query_arg( [ 'page' => 'dtb-system-manager' ], $admin ),
	];

	return $model;
}

/**
 * Render visible exception queue chips/cards for the Command Center.
 *
 * @param array<string,mixed> $exceptions Exception summary.
 * @return string
 */
function dtb_command_center_render_exception_queues( array $exceptions ): string {
	$queues = (array) ( $exceptions['queues'] ?? [] );
	if ( empty( $queues ) ) {
		return '';
	}

	$kpis = [];
	foreach ( $queues as $queue ) {
		$count = (int) ( $queue['count'] ?? 0 );
		if ( $count <= 0 ) {
			continue;
		}

		$module = sanitize_key( (string) ( $queue['module'] ?? 'operations' ) );
		$kpis[] = [
			'value'      => $count,
			'label'      => (string) ( $queue['label'] ?? __( 'Exception', 'drywall-toolbox' ) ),
			'icon'       => 'dashicons-flag',
			'icon_color' => in_array( $module, [ 'support', 'integrations' ], true ) ? 'danger' : 'warning',
			'href'       => (string) ( $queue['href'] ?? '' ),
			'trend'      => ucwords( str_replace( '_', ' ', $module ) ),
			'trend_dir'  => 'flat',
		];
	}

	if ( empty( $kpis ) ) {
		return '';
	}

	ob_start();
	echo '<div class="dtb-section">';
	echo '<div class="dtb-section__header"><h2>' . esc_html__( 'Exception Queues', 'drywall-toolbox' ) . '</h2></div>';
	echo dtb_admin_ui_kpi_grid( $kpis ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '</div>';

	return (string) ob_get_clean();
}
