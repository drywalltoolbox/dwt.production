<?php
/**
 * DTB Platform — AdminExceptionQueueService
 *
 * Deterministic exception queues used by Command Center and module workbenches.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return exception queue counts.
 *
 * @return array<string,array{label:string,count:int,module:string,href:string}>
 */
function dtb_admin_get_exception_queues(): array {
	$admin = admin_url( 'admin.php' );
	$queues = [
		'orders_attention' => [
			'label'  => __( 'Order Attention', 'drywall-toolbox' ),
			'count'  => dtb_admin_exception_order_attention(),
			'module' => 'orders',
			'href'   => add_query_arg( [ 'page' => 'dtb-orders', 'status' => 'attention' ], $admin ),
		],
		'orders_failed_payment' => [
			'label'  => __( 'Payment Failed', 'drywall-toolbox' ),
			'count'  => dtb_admin_exception_order_failed(),
			'module' => 'orders',
			'href'   => add_query_arg( [ 'page' => 'dtb-orders', 'status' => 'failed' ], $admin ),
		],
		'needs_reply' => [
			'label'  => __( 'Needs Reply', 'drywall-toolbox' ),
			'count'  => dtb_admin_exception_support_needs_reply(),
			'module' => 'support',
			'href'   => add_query_arg( [ 'page' => 'dtb-support', 'queue' => 'needs_reply' ], $admin ),
		],
		'sla_risk' => [
			'label'  => __( 'SLA Risk', 'drywall-toolbox' ),
			'count'  => dtb_admin_exception_support_sla_risk(),
			'module' => 'support',
			'href'   => add_query_arg( [ 'page' => 'dtb-support', 'queue' => 'overdue' ], $admin ),
		],
		'missing_linked_order' => [
			'label'  => __( 'Missing Linked Order', 'drywall-toolbox' ),
			'count'  => dtb_admin_exception_missing_linked_order(),
			'module' => 'operations',
			'href'   => add_query_arg( [ 'page' => 'dtb-command-center', 'exception' => 'missing_linked_order' ], $admin ),
		],
		'integration_failed' => [
			'label'  => __( 'Integration Failed', 'drywall-toolbox' ),
			'count'  => dtb_admin_exception_integration_failed(),
			'module' => 'integrations',
			'href'   => add_query_arg( [ 'page' => 'dtb-system-manager', 'panel' => 'integrations' ], $admin ),
		],
		'refund_exchange_pending' => [
			'label'  => __( 'Refund/Exchange Pending', 'drywall-toolbox' ),
			'count'  => dtb_admin_exception_return_refund_exchange_pending(),
			'module' => 'returns',
			'href'   => add_query_arg( [ 'page' => 'dtb-returns', 'tab' => 'item_received' ], $admin ),
		],
		'repair_quote_pending' => [
			'label'  => __( 'Repair Quote Pending', 'drywall-toolbox' ),
			'count'  => dtb_admin_exception_repair_quote_pending(),
			'module' => 'repair',
			'href'   => add_query_arg( [ 'page' => 'dtb-repairs', 'status' => 'quote_pending' ], $admin ),
		],
		'repair_ready_to_ship' => [
			'label'  => __( 'Repair Ready to Ship', 'drywall-toolbox' ),
			'count'  => dtb_admin_exception_repair_ready_to_ship(),
			'module' => 'repair',
			'href'   => add_query_arg( [ 'page' => 'dtb-repairs', 'status' => 'ready_to_ship' ], $admin ),
		],
	];

	return $queues;
}

/**
 * Render module-scoped exception queue chips for queue workbenches.
 *
 * @param string|string[] $modules Module keys to include.
 * @return string
 */
function dtb_admin_render_module_exception_chips( string|array $modules ): string {
	$modules = array_map( 'sanitize_key', (array) $modules );
	$queues  = dtb_admin_get_exception_queues();
	$kpis    = [];

	foreach ( $queues as $queue ) {
		$module = sanitize_key( (string) ( $queue['module'] ?? '' ) );
		$count  = (int) ( $queue['count'] ?? 0 );
		if ( $count <= 0 || ! in_array( $module, $modules, true ) ) {
			continue;
		}

		$kpis[] = [
			'value'      => $count,
			'label'      => (string) ( $queue['label'] ?? __( 'Exception', 'drywall-toolbox' ) ),
			'icon'       => 'dashicons-flag',
			'icon_color' => in_array( $module, [ 'support', 'orders' ], true ) ? 'danger' : 'warning',
			'href'       => (string) ( $queue['href'] ?? '' ),
			'trend'      => __( 'Exception queue', 'drywall-toolbox' ),
			'trend_dir'  => 'flat',
		];
	}

	if ( empty( $kpis ) || ! function_exists( 'dtb_admin_ui_kpi_grid' ) ) {
		return '';
	}

	return '<div class="dtb-section dtb-section--exceptions">'
		. '<div class="dtb-section__header"><h2>' . esc_html__( 'Exception Queues', 'drywall-toolbox' ) . '</h2></div>'
		. dtb_admin_ui_kpi_grid( $kpis )
		. '</div>';
}

function dtb_admin_exception_order_attention(): int {
	return function_exists( 'dtb_orders_admin_count' ) ? (int) dtb_orders_admin_count( 'attention' ) : 0;
}

function dtb_admin_exception_order_failed(): int {
	return function_exists( 'dtb_orders_admin_count' ) ? (int) dtb_orders_admin_count( 'failed' ) : 0;
}

function dtb_admin_exception_support_needs_reply(): int {
	if ( function_exists( 'dtb_support_get_queue_counts' ) ) {
		$counts = dtb_support_get_queue_counts();
		return (int) ( $counts['needs_reply'] ?? $counts['pending_staff'] ?? 0 );
	}
	return 0;
}

function dtb_admin_exception_support_sla_risk(): int {
	if ( function_exists( 'dtb_support_count_past_sla' ) ) {
		return (int) dtb_support_count_past_sla();
	}
	if ( function_exists( 'dtb_support_get_queue_counts' ) ) {
		$counts = dtb_support_get_queue_counts();
		return (int) ( $counts['overdue'] ?? $counts['sla_breached'] ?? 0 );
	}
	return 0;
}

function dtb_admin_exception_return_refund_exchange_pending(): int {
	if ( function_exists( 'dtb_returns_count_by_status' ) ) {
		$counts = dtb_returns_count_by_status();
		return (int) ( $counts['item_received'] ?? 0 );
	}
	return 0;
}

function dtb_admin_exception_repair_quote_pending(): int {
	if ( function_exists( 'dtb_repairs_count_by_status' ) ) {
		$counts = dtb_repairs_count_by_status();
		return (int) ( $counts['quote_pending'] ?? $counts['awaiting_quote_approval'] ?? 0 );
	}
	return 0;
}

function dtb_admin_exception_repair_ready_to_ship(): int {
	if ( function_exists( 'dtb_repairs_count_by_status' ) ) {
		$counts = dtb_repairs_count_by_status();
		return (int) ( $counts['ready_to_ship'] ?? 0 );
	}
	return 0;
}

function dtb_admin_exception_missing_linked_order(): int {
	$count = 0;

	if ( function_exists( 'dtb_returns_count_by_status' ) ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dtb_returns';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $exists ) {
			$count += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE (order_id IS NULL OR order_id = 0) AND status NOT IN ('closed','rejected')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	$q = new WP_Query( [
		'post_type'      => 'dtb_repair_request',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'meta_query'     => [
			'relation' => 'AND',
			[
				'relation' => 'OR',
				[ 'key' => '_repair_wc_order_id', 'compare' => 'NOT EXISTS' ],
				[ 'key' => '_repair_wc_order_id', 'value' => '', 'compare' => '=' ],
				[ 'key' => '_repair_wc_order_id', 'value' => '0', 'compare' => '=' ],
			],
			[
				'key'     => '_repair_status',
				'value'   => [ 'closed', 'cancelled', 'quote_declined' ],
				'compare' => 'NOT IN',
			],
		], // phpcs:ignore WordPress.DB.SlowDBQuery
	] );
	$count += (int) $q->found_posts;

	return $count;
}

function dtb_admin_exception_integration_failed(): int {
	$count = 0;

	if ( function_exists( 'wc_get_orders' ) ) {
		$orders = wc_get_orders( [
			'limit'      => 50,
			'return'     => 'ids',
			'meta_query' => [
				'relation' => 'OR',
				[ 'key' => '_dtb_veeqo_sync_status', 'value' => 'failed' ],
				[ 'key' => '_dtb_quickbooks_sync_status', 'value' => 'failed' ],
			],
		] );
		$count += is_array( $orders ) ? count( $orders ) : 0;
	}

	if ( function_exists( 'dtb_support_outbox_counts' ) ) {
		$outbox = dtb_support_outbox_counts();
		$count += (int) ( $outbox['failed'] ?? 0 );
	}

	return $count;
}
