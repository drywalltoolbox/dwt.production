<?php
defined( 'ABSPATH' ) || exit;

/**
 * DTB Order Operations — Read Models
 *
 * Query, projection, and display helpers for the Order Operations dashboard.
 * All functions here are read-only.  Mutations live in dtb-order-operations-actions.php.
 *
 * Provides:
 *   dtb_oo_get_settings()                   — Retrieve dashboard settings with defaults
 *   dtb_oo_can_view()                        — Permission: view the dashboard
 *   dtb_oo_can_mutate_orders()               — Permission: mutate product orders
 *   dtb_oo_can_mutate_repairs()              — Permission: mutate repair orders
 *   dtb_oo_can_manage_settings()             — Permission: change dashboard settings
 *   dtb_oo_get_overview_kpis()               — Overview tab KPI values
 *   dtb_oo_get_product_orders()              — Filtered product-order list
 *   dtb_oo_product_order_row_projection()    — Single WC order → row struct
 *   dtb_oo_get_repair_orders()               — Filtered repair-order list
 *   dtb_oo_repair_order_row_projection()     — Single repair CPT → row struct
 *   dtb_oo_get_order_timeline()              — All events for an order (admin view)
 *   dtb_oo_get_repair_timeline()             — All events for a repair (admin view)
 *   dtb_oo_get_local_queue()                 — Local job queue (excludes external syncs)
 *   dtb_oo_get_combined_audit_log()          — Aggregated audit events
 *   dtb_oo_sla_state()                       — Compute SLA state from age
 *   dtb_oo_age_label()                       — Human-readable age string
 *
 * @package drywall-toolbox
 */


// =============================================================================
// SECTION 1 — CONSTANTS & SETTINGS
// =============================================================================

/** Settings option key. */
if ( ! defined( 'DTB_OO_SETTINGS_KEY' ) ) {
	define( 'DTB_OO_SETTINGS_KEY', 'dtb_oo_settings' );
}

/** Custom capability for viewing the Order Operations dashboard. */
if ( ! defined( 'DTB_OO_CAP_VIEW' ) ) {
	define( 'DTB_OO_CAP_VIEW', 'dtb_manage_order_operations' );
}

/** Custom capability for managing repairs from the dashboard. */
if ( ! defined( 'DTB_OO_CAP_REPAIRS' ) ) {
	define( 'DTB_OO_CAP_REPAIRS', 'dtb_manage_repairs' );
}

/** Nonce action used by all dashboard mutations. */
if ( ! defined( 'DTB_OO_NONCE_ACTION' ) ) {
	define( 'DTB_OO_NONCE_ACTION', 'dtb_oo_nonce' );
}

/**
 * Return dashboard settings merged with defaults.
 *
 * @return array{
 *   poll_interval:int, sla_warning_hours:int, sla_breach_hours:int,
 *   page_size:int, audit_retention_days:int, enabled_tabs:string[],
 *   display_timezone:string
 * }
 */
function dtb_oo_get_settings(): array {
	$defaults = [
		'poll_interval'       => 180,     // seconds
		'sla_warning_hours'   => 72,
		'sla_breach_hours'    => 120,
		'page_size'           => 25,
		'audit_retention_days'=> 90,
		'enabled_tabs'        => [ 'overview', 'product_orders', 'repair_orders', 'queue', 'audit_log', 'settings' ],
		'display_timezone'    => 'UTC',
	];

	$stored = get_option( DTB_OO_SETTINGS_KEY, [] );

	if ( ! is_array( $stored ) ) {
		$stored = [];
	}

	return array_merge( $defaults, $stored );
}

// =============================================================================
// SECTION 2 — PERMISSION HELPERS
// =============================================================================

/**
 * Can the current user view the Order Operations dashboard?
 */
function dtb_oo_can_view(): bool {
	return current_user_can( 'manage_options' )
		|| current_user_can( 'manage_woocommerce' )
		|| current_user_can( DTB_OO_CAP_VIEW );
}

/**
 * Can the current user mutate product orders?
 */
function dtb_oo_can_mutate_orders(): bool {
	return current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' );
}

/**
 * Can the current user mutate repair orders?
 */
function dtb_oo_can_mutate_repairs(): bool {
	return current_user_can( 'manage_options' )
		|| current_user_can( 'manage_woocommerce' )
		|| current_user_can( DTB_OO_CAP_REPAIRS );
}

/**
 * Can the current user change dashboard settings?
 */
function dtb_oo_can_manage_settings(): bool {
	return current_user_can( 'manage_options' );
}

// =============================================================================
// SECTION 3 — SLA & AGE HELPERS
// =============================================================================

/**
 * Compute the SLA state based on age in seconds.
 *
 * @param int $age_seconds   Age since submitted/created.
 * @param int $warning_hours Hours at which to show a warning.
 * @param int $breach_hours  Hours at which SLA is breached.
 * @return string  'healthy' | 'warning' | 'breached'
 */
function dtb_oo_sla_state( int $age_seconds, int $warning_hours = 72, int $breach_hours = 120 ): string {
	$age_hours = $age_seconds / HOUR_IN_SECONDS;

	if ( $age_hours >= $breach_hours ) {
		return 'breached';
	}
	if ( $age_hours >= $warning_hours ) {
		return 'warning';
	}
	return 'healthy';
}

/**
 * Format an age in seconds to a human-readable string.
 *
 * @param int $seconds
 * @return string  e.g. '2d 4h', '45m', '< 1m'
 */
function dtb_oo_age_label( int $seconds ): string {
	if ( $seconds < 60 ) {
		return '< 1m';
	}
	if ( $seconds < HOUR_IN_SECONDS ) {
		return (int) ( $seconds / 60 ) . 'm';
	}
	if ( $seconds < DAY_IN_SECONDS ) {
		$h = (int) ( $seconds / HOUR_IN_SECONDS );
		$m = (int) ( ( $seconds % HOUR_IN_SECONDS ) / 60 );
		return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
	}
	$d = (int) ( $seconds / DAY_IN_SECONDS );
	$h = (int) ( ( $seconds % DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
	return $h > 0 ? "{$d}d {$h}h" : "{$d}d";
}

// =============================================================================
// SECTION 4 — OVERVIEW KPIS
// =============================================================================

/**
 * Aggregate all KPIs for the Overview tab.
 * Returns data relevant to product orders and repair orders only.
 *
 * @return array<string, array{label:string, value:string|int, warn:bool, badge:string}>
 */
function dtb_oo_get_overview_kpis(): array {
	$settings    = dtb_oo_get_settings();
	$warn_hours  = (int) $settings['sla_warning_hours'];
	$breach_hours = (int) $settings['sla_breach_hours'];
	$kpis        = [];

	// ---- Product Order KPIs ----
	if ( function_exists( 'wc_get_orders' ) ) {
		// Orders today.
		$today_start = gmdate( 'Y-m-d 00:00:00' );
		$orders_today = wc_get_orders( [
			'status'       => [ 'wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed', 'wc-cancelled' ],
			'date_created' => '>' . strtotime( $today_start ),
			'limit'        => -1,
			'return'       => 'ids',
		] );
		$kpis['orders_today'] = [
			'label' => 'Orders Today',
			'value' => count( $orders_today ),
			'warn'  => false,
			'badge' => 'blue',
		];

		// Open product orders (pending + on-hold).
		$open_orders = wc_get_orders( [
			'status' => [ 'wc-pending', 'wc-on-hold' ],
			'limit'  => -1,
			'return' => 'ids',
		] );
		$open_count = count( $open_orders );
		$kpis['open_product_orders'] = [
			'label' => 'Open Product Orders',
			'value' => $open_count,
			'warn'  => $open_count > 20,
			'badge' => $open_count > 20 ? 'red' : 'blue',
		];

		// Processing.
		$proc_orders = wc_get_orders( [
			'status' => [ 'wc-processing' ],
			'limit'  => -1,
			'return' => 'ids',
		] );
		$proc_count = count( $proc_orders );
		$kpis['processing_product_orders'] = [
			'label' => 'Processing',
			'value' => $proc_count,
			'warn'  => $proc_count > 50,
			'badge' => $proc_count > 50 ? 'yellow' : 'blue',
		];

		// On-hold.
		$hold_orders = wc_get_orders( [
			'status' => [ 'wc-on-hold' ],
			'limit'  => -1,
			'return' => 'ids',
		] );
		$hold_count = count( $hold_orders );
		$kpis['onhold_product_orders'] = [
			'label' => 'On-Hold Orders',
			'value' => $hold_count,
			'warn'  => $hold_count > 10,
			'badge' => $hold_count > 10 ? 'yellow' : 'blue',
		];

		// Recently completed (last 24h).
		$recent_completed = wc_get_orders( [
			'status'       => [ 'wc-completed' ],
			'date_modified'=> '>' . ( time() - DAY_IN_SECONDS ),
			'limit'        => -1,
			'return'       => 'ids',
		] );
		$kpis['recent_completed_orders'] = [
			'label' => 'Completed (24h)',
			'value' => count( $recent_completed ),
			'warn'  => false,
			'badge' => 'green',
		];

		// Stale product orders: processing > sla_warning_hours.
		$stale_cutoff   = time() - ( $warn_hours * HOUR_IN_SECONDS );
		$stale_orders   = wc_get_orders( [
			'status'       => [ 'wc-processing', 'wc-on-hold' ],
			'date_created' => '<' . $stale_cutoff,
			'limit'        => -1,
			'return'       => 'ids',
		] );
		$stale_count = count( $stale_orders );
		$kpis['stale_product_orders'] = [
			'label' => 'Stale Product Orders',
			'value' => $stale_count,
			'warn'  => $stale_count > 0,
			'badge' => $stale_count > 0 ? 'red' : 'green',
		];
	}

	// ---- Repair Order KPIs ----
	$repair_args_base = [
		'post_type'      => 'dtb_repair_request',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	];

	$all_open_statuses = [ 'submitted', 'reviewed', 'awaiting_customer', 'approved', 'quoted',
		'quote_accepted', 'parts_allocated', 'in_progress', 'ready_to_ship' ];

	$open_repairs_ids = get_posts( array_merge( $repair_args_base, [
		'meta_query' => [ [
			'key'     => '_repair_status',
			'value'   => $all_open_statuses,
			'compare' => 'IN',
		] ],
	] ) );
	$open_repair_count = count( $open_repairs_ids );
	$kpis['open_repair_orders'] = [
		'label' => 'Open Repair Orders',
		'value' => $open_repair_count,
		'warn'  => $open_repair_count > 30,
		'badge' => $open_repair_count > 30 ? 'red' : 'blue',
	];

	// Awaiting review.
	$awaiting_review = get_posts( array_merge( $repair_args_base, [
		'meta_query' => [ [ 'key' => '_repair_status', 'value' => 'submitted' ] ],
	] ) );
	$kpis['repairs_awaiting_review'] = [
		'label' => 'Awaiting Review',
		'value' => count( $awaiting_review ),
		'warn'  => count( $awaiting_review ) > 5,
		'badge' => count( $awaiting_review ) > 5 ? 'yellow' : 'blue',
	];

	// Awaiting customer.
	$awaiting_cust = get_posts( array_merge( $repair_args_base, [
		'meta_query' => [ [ 'key' => '_repair_status', 'value' => 'awaiting_customer' ] ],
	] ) );
	$kpis['repairs_awaiting_customer'] = [
		'label' => 'Awaiting Customer',
		'value' => count( $awaiting_cust ),
		'warn'  => false,
		'badge' => 'yellow',
	];

	// In progress.
	$in_prog = get_posts( array_merge( $repair_args_base, [
		'meta_query' => [ [ 'key' => '_repair_status', 'value' => 'in_progress' ] ],
	] ) );
	$kpis['repairs_in_progress'] = [
		'label' => 'Repairs In Progress',
		'value' => count( $in_prog ),
		'warn'  => false,
		'badge' => 'blue',
	];

	// Ready to ship.
	$ready = get_posts( array_merge( $repair_args_base, [
		'meta_query' => [ [ 'key' => '_repair_status', 'value' => 'ready_to_ship' ] ],
	] ) );
	$kpis['repairs_ready_to_ship'] = [
		'label' => 'Ready to Ship',
		'value' => count( $ready ),
		'warn'  => count( $ready ) > 5,
		'badge' => count( $ready ) > 5 ? 'yellow' : 'green',
	];

	// SLA warnings: open repairs older than warning threshold.
	$sla_warn_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $warn_hours * HOUR_IN_SECONDS ) );
	$sla_warn_repairs = get_posts( array_merge( $repair_args_base, [
		'date_query' => [ [ 'before' => $sla_warn_cutoff, 'column' => 'post_date_gmt' ] ],
		'meta_query' => [ [
			'key'     => '_repair_status',
			'value'   => array_diff( $all_open_statuses, [ 'completed', 'closed', 'cancelled', 'quote_declined' ] ),
			'compare' => 'IN',
		] ],
	] ) );
	$sla_warn_count = count( $sla_warn_repairs );
	$kpis['sla_warnings'] = [
		'label' => 'SLA Warnings',
		'value' => $sla_warn_count,
		'warn'  => $sla_warn_count > 0,
		'badge' => $sla_warn_count > 0 ? 'yellow' : 'green',
	];

	// Stale repair orders (older than breach threshold).
	$sla_breach_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $breach_hours * HOUR_IN_SECONDS ) );
	$stale_repairs = get_posts( array_merge( $repair_args_base, [
		'date_query' => [ [ 'before' => $sla_breach_cutoff, 'column' => 'post_date_gmt' ] ],
		'meta_query' => [ [
			'key'     => '_repair_status',
			'value'   => array_diff( $all_open_statuses, [ 'completed', 'closed', 'cancelled', 'quote_declined' ] ),
			'compare' => 'IN',
		] ],
	] ) );
	$stale_repair_count = count( $stale_repairs );
	$kpis['stale_repair_orders'] = [
		'label' => 'SLA Breached',
		'value' => $stale_repair_count,
		'warn'  => $stale_repair_count > 0,
		'badge' => $stale_repair_count > 0 ? 'red' : 'green',
	];

	// Failed local actions (from audit log, last 24h).
	if ( function_exists( 'dtb_oo_count_recent_failed_actions' ) ) {
		$failed = dtb_oo_count_recent_failed_actions();
		$kpis['failed_local_actions'] = [
			'label' => 'Failed Local Actions',
			'value' => $failed,
			'warn'  => $failed > 0,
			'badge' => $failed > 0 ? 'red' : 'green',
		];
	}

	return $kpis;
}

/**
 * Count operator-visible error events in the last 24 hours.
 *
 * @return int
 */
function dtb_oo_count_recent_failed_actions(): int {
	global $wpdb;

	$table  = $wpdb->prefix . 'dtb_audit_log';
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(1) FROM {$table} WHERE log_timestamp > %s AND event LIKE %s",
			$cutoff,
			'%_error%'
		)
	);

	return $count;
}

// =============================================================================
// SECTION 5 — PRODUCT ORDERS QUERY
// =============================================================================

/**
 * Query product orders with filters, returning a paged list of row projections.
 *
 * @param array $args {
 *   @type string   $woo_status         WC status slug (without wc- prefix).
 *   @type string   $fulfillment_substate
 *   @type string   $tracking_state     'tracking_available' | 'no_tracking' | ''
 *   @type string   $stale              'stale' | ''
 *   @type string   $date_from          Y-m-d
 *   @type string   $date_to            Y-m-d
 *   @type string   $customer           Name search
 *   @type string   $email
 *   @type string   $order_id           Exact order ID
 *   @type string   $tracking_number    Partial tracking number search
 *   @type int      $paged              Page number (1-based)
 *   @type int      $per_page
 * }
 * @return array{ rows: array[], total: int, pages: int }
 */
function dtb_oo_get_product_orders( array $args = [] ): array {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return [ 'rows' => [], 'total' => 0, 'pages' => 0 ];
	}

	$settings  = dtb_oo_get_settings();
	$per_page  = max( 1, min( 100, (int) ( $args['per_page'] ?? $settings['page_size'] ) ) );
	$paged     = max( 1, (int) ( $args['paged'] ?? 1 ) );
	$offset    = ( $paged - 1 ) * $per_page;

	$query_args = [
		'limit'  => $per_page,
		'offset' => $offset,
		'return' => 'objects',
		'orderby'=> 'date',
		'order'  => 'DESC',
	];

	// Status filter.
	if ( ! empty( $args['woo_status'] ) ) {
		$query_args['status'] = 'wc-' . sanitize_key( $args['woo_status'] );
	}

	// Specific order ID.
	if ( ! empty( $args['order_id'] ) ) {
		$query_args['id'] = (int) $args['order_id'];
	}

	// Customer / email filters.
	if ( ! empty( $args['email'] ) ) {
		$query_args['customer'] = sanitize_email( $args['email'] );
	}

	// Date range.
	if ( ! empty( $args['date_from'] ) ) {
		$query_args['date_created'] = '>=' . strtotime( sanitize_text_field( $args['date_from'] ) );
	}
	if ( ! empty( $args['date_to'] ) ) {
		$to_ts = strtotime( sanitize_text_field( $args['date_to'] ) . ' 23:59:59' );
		if ( isset( $query_args['date_created'] ) ) {
			// Replace with range.
			$from_ts = strtotime( sanitize_text_field( $args['date_from'] ) );
			$query_args['date_created'] = $from_ts . '...' . $to_ts;
		} else {
			$query_args['date_created'] = '<=' . $to_ts;
		}
	}

	// Meta query for fulfillment substate.
	$meta_query = [];
	if ( ! empty( $args['fulfillment_substate'] ) ) {
		$meta_query[] = [
			'key'   => '_dtb_fulfillment_substate',
			'value' => sanitize_key( $args['fulfillment_substate'] ),
		];
	}

	// Stale filter: created before SLA warning threshold.
	$sla_settings = dtb_oo_get_settings();
	if ( ! empty( $args['stale'] ) ) {
		$stale_cutoff = time() - ( (int) $sla_settings['sla_warning_hours'] * HOUR_IN_SECONDS );
		$query_args['date_created'] = '<' . $stale_cutoff;
		if ( empty( $query_args['status'] ) ) {
			$query_args['status'] = [ 'wc-processing', 'wc-on-hold' ];
		}
	}

	if ( $meta_query ) {
		$query_args['meta_query'] = $meta_query;
	}

	// Get total count for pagination.
	$count_args         = $query_args;
	$count_args['limit']  = -1;
	$count_args['return'] = 'ids';
	unset( $count_args['offset'] );
	$all_ids = wc_get_orders( $count_args );
	$total   = is_array( $all_ids ) ? count( $all_ids ) : 0;

	// Customer name search: WC doesn't support direct search by billing_first_name, filter post-query.
	$customer_search = ! empty( $args['customer'] ) ? sanitize_text_field( $args['customer'] ) : '';

	// Get paged results.
	$orders = wc_get_orders( $query_args );
	if ( ! is_array( $orders ) ) {
		$orders = [];
	}

	$rows = [];
	foreach ( $orders as $order ) {
		if ( ! $order instanceof WC_Abstract_Order ) {
			continue;
		}

		// Apply client-side name filter (WC query doesn't support it natively).
		if ( $customer_search ) {
			$full_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			if ( false === stripos( $full_name, $customer_search ) ) {
				continue;
			}
		}

		$row = dtb_oo_product_order_row_projection( $order );
		if ( $row ) {
			$rows[] = $row;
		}
	}

	return [
		'rows'  => $rows,
		'total' => $total,
		'pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
	];
}

/**
 * Build a product-order row projection from a WC_Order object.
 *
 * @param WC_Abstract_Order|int $order_or_id
 * @return array|null  Null if order not found.
 */
function dtb_oo_product_order_row_projection( $order_or_id ): ?array {
	if ( is_int( $order_or_id ) ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_or_id ) : null;
	} else {
		$order = $order_or_id;
	}

	if ( ! $order instanceof WC_Abstract_Order ) {
		return null;
	}

	$order_id = (int) $order->get_id();
	$woo_status = $order->get_status();
	$substate  = function_exists( 'dtb_order_get_fulfillment_substate' )
		? dtb_order_get_fulfillment_substate( $order_id )
		: 'pending';

	// Integration state for tracking.
	$int_state = function_exists( 'dtb_order_get_integration_state' )
		? dtb_order_get_integration_state( $order_id )
		: [];
	$tracking_number = $int_state['veeqo']['tracking'] ?? null;
	$tracking_state  = ( $tracking_number && is_string( $tracking_number ) )
		? 'tracking_available'
		: 'no_tracking';

	// Last event.
	$last_event_row = function_exists( 'dtb_order_get_last_event' )
		? dtb_order_get_last_event( $order_id )
		: null;
	$last_event = $last_event_row ? (string) $last_event_row->event_type : '';
	$last_updated_at = $last_event_row ? (string) $last_event_row->created_at : (string) $order->get_date_modified();

	// Age.
	$date_created = $order->get_date_created();
	$created_ts   = $date_created instanceof WC_DateTime ? $date_created->getTimestamp() : 0;
	$age_seconds  = $created_ts > 0 ? ( time() - $created_ts ) : 0;

	// Customer info — only operational fields.
	$customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	$customer_email = $order->get_billing_email();

	// DTB status: WC status mapped to DTB label.
	$dtb_status = function_exists( 'dtb_order_build_status_projection' )
		? ( dtb_order_build_status_projection( $order_id )['status'] ?? $woo_status )
		: $woo_status;

	return [
		'entity_type'         => 'product_order',
		'order_id'            => $order_id,
		'date_created'        => $date_created ? $date_created->format( 'c' ) : '',
		'customer_name'       => wp_strip_all_tags( $customer_name ),
		'customer_email'      => sanitize_email( $customer_email ),
		'woo_status'          => $woo_status,
		'dtb_status'          => sanitize_key( $dtb_status ),
		'fulfillment_substate'=> $substate,
		'tracking_state'      => $tracking_state,
		'tracking_number'     => $tracking_number ? sanitize_text_field( $tracking_number ) : null,
		'item_count'          => count( $order->get_items() ),
		'total'               => wp_strip_all_tags( wc_price( $order->get_total() ) ),
		'age_seconds'         => $age_seconds,
		'age_label'           => dtb_oo_age_label( $age_seconds ),
		'last_event'          => $last_event,
		'last_updated_at'     => $last_updated_at,
		'wc_edit_url'         => function_exists( 'get_edit_post_link' )
			? esc_url( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) )
			: '',
	];
}

// =============================================================================
// SECTION 6 — REPAIR ORDERS QUERY
// =============================================================================

/**
 * Query repair orders with filters.
 *
 * @param array $args {
 *   @type string $repair_status     Repair status slug.
 *   @type string $brand             Tool brand.
 *   @type string $service_tier      standard | express | warranty.
 *   @type string $assigned_tech_id  WP user ID.
 *   @type string $sla_state         warning | breached | ''
 *   @type string $date_from         Y-m-d
 *   @type string $date_to           Y-m-d
 *   @type string $customer          Name search.
 *   @type string $email
 *   @type string $repair_id         Exact post ID.
 *   @type string $model
 *   @type string $serial
 *   @type int    $paged
 *   @type int    $per_page
 * }
 * @return array{ rows: array[], total: int, pages: int }
 */
function dtb_oo_get_repair_orders( array $args = [] ): array {
	$settings = dtb_oo_get_settings();
	$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? $settings['page_size'] ) ) );
	$paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );

	$query_args = [
		'post_type'      => 'dtb_repair_request',
		'posts_per_page' => $per_page,
		'paged'          => $paged,
		'orderby'        => 'date',
		'order'          => 'DESC',
	];

	// Exact repair ID.
	if ( ! empty( $args['repair_id'] ) ) {
		$query_args['p'] = (int) $args['repair_id'];
	}

	$meta_query = [];

	// Status filter.
	if ( ! empty( $args['repair_status'] ) ) {
		$meta_query[] = [
			'key'   => '_repair_status',
			'value' => sanitize_key( $args['repair_status'] ),
		];
	}

	// Brand filter.
	if ( ! empty( $args['brand'] ) ) {
		$meta_query[] = [
			'key'     => '_repair_tool_brand',
			'value'   => sanitize_text_field( $args['brand'] ),
			'compare' => '=',
		];
	}

	// Service tier.
	if ( ! empty( $args['service_tier'] ) ) {
		$meta_query[] = [
			'key'   => '_repair_service_tier',
			'value' => sanitize_key( $args['service_tier'] ),
		];
	}

	// Assigned tech.
	if ( ! empty( $args['assigned_tech_id'] ) ) {
		$meta_query[] = [
			'key'   => '_repair_assigned_tech_id',
			'value' => (int) $args['assigned_tech_id'],
		];
	}

	// Model search.
	if ( ! empty( $args['model'] ) ) {
		$meta_query[] = [
			'key'     => '_repair_model',
			'value'   => sanitize_text_field( $args['model'] ),
			'compare' => 'LIKE',
		];
	}

	// Serial search.
	if ( ! empty( $args['serial'] ) ) {
		$meta_query[] = [
			'key'     => '_repair_serial',
			'value'   => sanitize_text_field( $args['serial'] ),
			'compare' => 'LIKE',
		];
	}

	// Email filter.
	if ( ! empty( $args['email'] ) ) {
		$meta_query[] = [
			'key'     => '_repair_customer_email',
			'value'   => sanitize_email( $args['email'] ),
			'compare' => '=',
		];
	}

	if ( $meta_query ) {
		$query_args['meta_query'] = $meta_query;
	}

	// Date range.
	if ( ! empty( $args['date_from'] ) || ! empty( $args['date_to'] ) ) {
		$date_query = [];
		if ( ! empty( $args['date_from'] ) ) {
			$date_query['after'] = sanitize_text_field( $args['date_from'] );
		}
		if ( ! empty( $args['date_to'] ) ) {
			$date_query['before']    = sanitize_text_field( $args['date_to'] ) . ' 23:59:59';
			$date_query['inclusive'] = true;
		}
		$query_args['date_query'] = [ $date_query ];
	}

	// SLA filter: applied post-query since it depends on current time.
	$sla_filter = ! empty( $args['sla_state'] ) ? sanitize_key( $args['sla_state'] ) : '';

	// Count query for pagination.
	$count_args         = $query_args;
	$count_args['posts_per_page'] = -1;
	$count_args['fields']        = 'ids';
	unset( $count_args['paged'] );
	$all_ids = get_posts( $count_args );
	$total   = count( $all_ids );

	// Paged results.
	$posts = get_posts( $query_args );
	if ( ! is_array( $posts ) ) {
		$posts = [];
	}

	$sla_settings = dtb_oo_get_settings();
	$rows = [];

	foreach ( $posts as $post ) {
		$row = dtb_oo_repair_order_row_projection( $post->ID );
		if ( ! $row ) {
			continue;
		}

		// Apply SLA filter.
		if ( $sla_filter && $row['sla_state'] !== $sla_filter ) {
			continue;
		}

		// Apply customer name search.
		if ( ! empty( $args['customer'] ) ) {
			if ( false === stripos( $row['customer_name'], sanitize_text_field( $args['customer'] ) ) ) {
				continue;
			}
		}

		$rows[] = $row;
	}

	return [
		'rows'  => $rows,
		'total' => $total,
		'pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
	];
}

/**
 * Build a repair-order row projection from a repair post ID.
 *
 * @param int $repair_id  Post ID of the dtb_repair_request CPT.
 * @return array|null  Null if post not found or wrong type.
 */
function dtb_oo_repair_order_row_projection( int $repair_id ): ?array {
	$post = get_post( $repair_id );
	if ( ! $post || 'dtb_repair_request' !== $post->post_type ) {
		return null;
	}

	$settings     = dtb_oo_get_settings();
	$warn_hours   = (int) $settings['sla_warning_hours'];
	$breach_hours = (int) $settings['sla_breach_hours'];

	// Core meta.
	$repair_status  = (string) get_post_meta( $repair_id, '_repair_status', true );
	$customer_name  = wp_strip_all_tags( (string) get_post_meta( $repair_id, '_repair_customer_name', true ) );
	$customer_email = sanitize_email( (string) get_post_meta( $repair_id, '_repair_customer_email', true ) );
	$brand          = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_tool_brand', true ) );
	$model          = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_model', true ) );
	$serial         = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_serial', true ) );
	$service_tier   = sanitize_key( (string) get_post_meta( $repair_id, '_repair_service_tier', true ) );
	$tech_id        = (int) get_post_meta( $repair_id, '_repair_assigned_tech_id', true );

	// Assigned technician name.
	$tech_name = '';
	if ( $tech_id > 0 ) {
		$tech_user = get_userdata( $tech_id );
		if ( $tech_user instanceof WP_User ) {
			$tech_name = wp_strip_all_tags( $tech_user->display_name ?: $tech_user->user_login );
		}
	}

	// Age.
	$submitted_ts = strtotime( $post->post_date_gmt );
	$age_seconds  = $submitted_ts > 0 ? ( time() - $submitted_ts ) : 0;
	$sla_state    = dtb_oo_sla_state( $age_seconds, $warn_hours, $breach_hours );

	// Last event.
	$last_event_row = function_exists( 'dtb_repair_get_last_event' )
		? dtb_repair_get_last_event( $repair_id )
		: null;
	$last_event     = $last_event_row ? (string) $last_event_row->event_type : '';
	$last_updated_at = $last_event_row ? (string) $last_event_row->created_at : $post->post_modified_gmt;

	// Media attachments: get count only, not URLs.
	$media_ids  = get_post_meta( $repair_id, '_repair_media_ids', true );
	$media_count = is_array( $media_ids ) ? count( $media_ids ) : 0;

	return [
		'entity_type'        => 'repair_order',
		'repair_id'          => $repair_id,
		'submitted_at'       => gmdate( 'c', $submitted_ts ),
		'customer_name'      => $customer_name,
		'customer_email'     => $customer_email,
		'brand'              => $brand,
		'model'              => $model,
		'serial'             => $serial,
		'service_tier'       => $service_tier,
		'repair_status'      => $repair_status,
		'status_label'       => function_exists( 'dtb_get_repair_status_label' )
			? dtb_get_repair_status_label( $repair_status )
			: ucwords( str_replace( '_', ' ', $repair_status ) ),
		'assigned_tech_id'   => $tech_id ?: null,
		'assigned_technician'=> $tech_name,
		'sla_state'          => $sla_state,
		'age_seconds'        => $age_seconds,
		'age_label'          => dtb_oo_age_label( $age_seconds ),
		'last_event'         => $last_event,
		'last_updated_at'    => $last_updated_at,
		'media_count'        => $media_count,
		'edit_url'           => esc_url( admin_url( 'post.php?post=' . $repair_id . '&action=edit' ) ),
	];
}

// =============================================================================
// SECTION 7 — TIMELINE QUERIES
// =============================================================================

/**
 * Return the full admin event timeline for a product order.
 *
 * @param int $order_id
 * @return array[]  Array of event rows (safe for operator display).
 */
function dtb_oo_get_order_timeline( int $order_id ): array {
	if ( ! function_exists( 'dtb_order_get_events' ) ) {
		return [];
	}

	$events = dtb_order_get_events( $order_id, [ 'order' => 'ASC', 'limit' => 500 ] );

	$timeline = [];
	foreach ( $events as $row ) {
		$timeline[] = [
			'id'          => (int) $row->id,
			'event_type'  => sanitize_text_field( (string) $row->event_type ),
			'from_status' => sanitize_text_field( (string) ( $row->from_status ?? '' ) ),
			'to_status'   => sanitize_text_field( (string) ( $row->to_status ?? '' ) ),
			'actor_type'  => sanitize_text_field( (string) ( $row->actor_type ?? 'system' ) ),
			'actor_id'    => (int) ( $row->actor_id ?? 0 ),
			'source'      => sanitize_text_field( (string) ( $row->source ?? 'system' ) ),
			'visibility'  => sanitize_text_field( (string) ( $row->visibility ?? 'internal' ) ),
			'occurred_at' => (string) $row->created_at,
		];
	}

	return $timeline;
}

/**
 * Return the full admin event timeline for a repair order.
 *
 * @param int $repair_id
 * @return array[]
 */
function dtb_oo_get_repair_timeline( int $repair_id ): array {
	if ( ! function_exists( 'dtb_repair_get_events' ) ) {
		return [];
	}

	$events = dtb_repair_get_events( $repair_id, null, 500 );

	$timeline = [];
	foreach ( $events as $row ) {
		$timeline[] = [
			'id'          => (int) $row->id,
			'event_type'  => sanitize_text_field( (string) $row->event_type ),
			'from_status' => sanitize_text_field( (string) ( $row->from_status ?? '' ) ),
			'to_status'   => sanitize_text_field( (string) ( $row->to_status ?? '' ) ),
			'actor_type'  => sanitize_text_field( (string) ( $row->actor_type ?? 'system' ) ),
			'actor_id'    => (int) ( $row->actor_id ?? 0 ),
			'source'      => sanitize_text_field( (string) ( $row->source ?? 'system' ) ),
			'visibility'  => sanitize_text_field( (string) ( $row->visibility ?? 'internal' ) ),
			'occurred_at' => (string) $row->created_at,
		];
	}

	return $timeline;
}

// =============================================================================
// SECTION 8 — LOCAL QUEUE INSPECTOR
// =============================================================================

/**
 * Local job types surfaced in the Queue / Actions tab.
 * External-sync job types are excluded per spec.
 *
 * @return string[]
 */
function dtb_oo_local_queue_job_types(): array {
	return [
		'dtb_order_refresh_tracking_projection',
		'dtb_order_send_notification',
		'dtb_order_archive_completed',
		'dtb_repair_refresh_projection',
		'dtb_repair_send_notification',
		'dtb_repair_recalculate_sla',
		'dtb_repair_archive_closed',
	];
}

/**
 * Query the local job queue (Action Scheduler if available, WP Cron fallback).
 *
 * @param array $args {
 *   @type string $status      pending | in-progress | failed | complete | ''
 *   @type int    $per_page
 *   @type int    $paged
 * }
 * @return array{ rows: array[], total: int, pages: int }
 */
function dtb_oo_get_local_queue( array $args = [] ): array {
	$settings = dtb_oo_get_settings();
	$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? $settings['page_size'] ) ) );
	$paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );
	$status   = sanitize_key( $args['status'] ?? '' );

	if ( function_exists( 'as_get_scheduled_actions' ) ) {
		return dtb_oo_local_queue_from_action_scheduler( $per_page, $paged, $status );
	}

	// Fallback: inspect WP Cron.
	return dtb_oo_local_queue_from_wpcron( $per_page, $paged );
}

/**
 * Query the Action Scheduler table for local DTB jobs.
 *
 * @param int    $per_page
 * @param int    $paged
 * @param string $status_filter
 * @return array{ rows: array[], total: int, pages: int }
 */
function dtb_oo_local_queue_from_action_scheduler( int $per_page, int $paged, string $status_filter ): array {
	global $wpdb;

	$allowed_hooks = dtb_oo_local_queue_job_types();
	$placeholders  = implode( ', ', array_fill( 0, count( $allowed_hooks ), '%s' ) );
	$table         = $wpdb->prefix . 'actionscheduler_actions';

	// Check table exists.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$table_exists = $wpdb->get_var(
		$wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	);

	if ( ! $table_exists ) {
		return [ 'rows' => [], 'total' => 0, 'pages' => 0 ];
	}

	$where_clauses = [ "hook IN ({$placeholders})" ]; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$params        = $allowed_hooks;

	if ( $status_filter ) {
		$where_clauses[] = 'status = %s';
		$params[]        = $status_filter;
	}

	$where_sql = 'WHERE ' . implode( ' AND ', $where_clauses );

	// Total count.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$total = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(1) FROM {$table} {$where_sql}",
			...$params
		)
	);

	$offset = ( $paged - 1 ) * $per_page;

	// Paged rows.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$raw_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT action_id, hook, status, args, `group`, attempts, scheduled_date_gmt, last_attempt_gmt, claim_id, extended_args
			 FROM {$table}
			 {$where_sql}
			 ORDER BY scheduled_date_gmt DESC
			 LIMIT %d OFFSET %d",
			...array_merge( $params, [ $per_page, $offset ] )
		)
	);

	$rows = [];
	foreach ( (array) $raw_rows as $r ) {
		// Decode and redact args.
		$decoded_args = json_decode( (string) $r->args, true );
		if ( ! is_array( $decoded_args ) ) {
			$decoded_args = [];
		}
		$redacted_args = dtb_oo_redact_payload( $decoded_args );

		// Extract entity type and ID from args.
		$entity_id   = is_array( $decoded_args ) && isset( $decoded_args[0] ) ? (int) $decoded_args[0] : 0;
		$entity_type = dtb_oo_entity_type_from_hook( (string) $r->hook );

		$rows[] = [
			'job_id'            => (int) $r->action_id,
			'entity_type'       => $entity_type,
			'entity_id'         => $entity_id,
			'job_type'          => sanitize_text_field( (string) $r->hook ),
			'status'            => sanitize_text_field( (string) $r->status ),
			'attempts'          => (int) $r->attempts,
			'created_at'        => '',  // AS doesn't store creation time separately.
			'last_run'          => (string) $r->last_attempt_gmt,
			'next_run'          => (string) $r->scheduled_date_gmt,
			'last_error_summary'=> '',
			'args_preview'      => wp_json_encode( $redacted_args ),
		];
	}

	return [
		'rows'  => $rows,
		'total' => $total,
		'pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
	];
}

/**
 * Derive entity type from an Action Scheduler hook name.
 *
 * @param string $hook
 * @return string  'product_order' | 'repair_order' | 'system'
 */
function dtb_oo_entity_type_from_hook( string $hook ): string {
	if ( str_starts_with( $hook, 'dtb_order_' ) ) {
		return 'product_order';
	}
	if ( str_starts_with( $hook, 'dtb_repair_' ) ) {
		return 'repair_order';
	}
	return 'system';
}

/**
 * WP Cron fallback queue inspector.
 *
 * @param int $per_page
 * @param int $paged
 * @return array{ rows: array[], total: int, pages: int }
 */
function dtb_oo_local_queue_from_wpcron( int $per_page, int $paged ): array {
	$crons    = _get_cron_array();
	$allowed  = dtb_oo_local_queue_job_types();
	$all_rows = [];

	if ( is_array( $crons ) ) {
		foreach ( $crons as $timestamp => $hooks ) {
			foreach ( (array) $hooks as $hook => $events ) {
				if ( ! in_array( $hook, $allowed, true ) ) {
					continue;
				}
				foreach ( (array) $events as $key => $event ) {
					$args = $event['args'] ?? [];
					$entity_id = is_array( $args ) && isset( $args[0] ) ? (int) $args[0] : 0;
					$all_rows[] = [
						'job_id'            => 0,
						'entity_type'       => dtb_oo_entity_type_from_hook( $hook ),
						'entity_id'         => $entity_id,
						'job_type'          => $hook,
						'status'            => 'pending',
						'attempts'          => 0,
						'created_at'        => '',
						'last_run'          => '',
						'next_run'          => gmdate( 'Y-m-d H:i:s', (int) $timestamp ),
						'last_error_summary'=> '',
						'args_preview'      => wp_json_encode( dtb_oo_redact_payload( (array) $args ) ),
					];
				}
			}
		}
	}

	$total  = count( $all_rows );
	$offset = ( $paged - 1 ) * $per_page;
	$rows   = array_slice( $all_rows, $offset, $per_page );

	return [
		'rows'  => $rows,
		'total' => $total,
		'pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
	];
}

// =============================================================================
// SECTION 9 — COMBINED AUDIT LOG
// =============================================================================

/**
 * Retrieve a unified audit log for product orders and repair orders.
 *
 * Combines:
 *   - wp_dtb_audit_log (operator-sourced events)
 *   - wp_dtb_order_events (operator/customer visibility events)
 *   - wp_dtb_repair_events (operator/customer visibility events)
 *
 * @param array $args {
 *   @type string $entity_type   'product_order' | 'repair_order' | ''
 *   @type int    $entity_id
 *   @type string $actor
 *   @type string $event_type
 *   @type string $visibility
 *   @type string $source
 *   @type string $date_from     Y-m-d
 *   @type string $date_to       Y-m-d
 *   @type int    $paged
 *   @type int    $per_page
 * }
 * @return array{ rows: array[], total: int, pages: int }
 */
function dtb_oo_get_combined_audit_log( array $args = [] ): array {
	global $wpdb;

	$settings = dtb_oo_get_settings();
	$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? $settings['page_size'] ) ) );
	$paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );
	$offset   = ( $paged - 1 ) * $per_page;

	$entity_type = sanitize_key( $args['entity_type'] ?? '' );
	$entity_id   = (int) ( $args['entity_id'] ?? 0 );
	$date_from   = ! empty( $args['date_from'] ) ? sanitize_text_field( $args['date_from'] ) : '';
	$date_to     = ! empty( $args['date_to'] ) ? sanitize_text_field( $args['date_to'] ) : '';

	$all_rows = [];

	$audit_table  = $wpdb->prefix . 'dtb_audit_log';
	$order_events = $wpdb->prefix . 'dtb_order_events';
	$repair_events = $wpdb->prefix . 'dtb_repair_events';

	// ---- 1. dtb_audit_log ----
	if ( ! in_array( $entity_type, [ 'repair_order' ], true ) ) {
		$where  = [ '1=1' ];
		$params = [];
		if ( $date_from ) {
			$where[]  = 'log_timestamp >= %s';
			$params[] = $date_from . ' 00:00:00';
		}
		if ( $date_to ) {
			$where[]  = 'log_timestamp <= %s';
			$params[] = $date_to . ' 23:59:59';
		}
		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$audit_rows = $wpdb->get_results(
			$params
				? $wpdb->prepare( "SELECT id, log_timestamp, user_id, event, context, ip FROM {$audit_table} WHERE {$where_sql} ORDER BY id DESC LIMIT 500", ...$params )
				: "SELECT id, log_timestamp, user_id, event, context, ip FROM {$audit_table} ORDER BY id DESC LIMIT 500"
		);

		foreach ( (array) $audit_rows as $r ) {
			$all_rows[] = [
				'time'        => (string) $r->log_timestamp,
				'entity_type' => 'system',
				'entity_id'   => 0,
				'event_type'  => sanitize_text_field( (string) $r->event ),
				'actor'       => dtb_oo_actor_label( (int) $r->user_id ),
				'source'      => 'audit_log',
				'visibility'  => 'operator',
				'summary'     => wp_strip_all_tags( substr( (string) $r->context, 0, 200 ) ),
			];
		}
	}

	// ---- 2. Order events ----
	if ( ! in_array( $entity_type, [ 'repair_order' ], true ) ) {
		$where  = [ "visibility IN ('customer','operator')" ];
		$params = [];
		if ( $entity_id > 0 ) {
			$where[]  = 'order_id = %d';
			$params[] = $entity_id;
		}
		if ( $date_from ) {
			$where[]  = 'created_at >= %s';
			$params[] = $date_from . ' 00:00:00';
		}
		if ( $date_to ) {
			$where[]  = 'created_at <= %s';
			$params[] = $date_to . ' 23:59:59';
		}
		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$order_rows = $wpdb->get_results(
			$params
				? $wpdb->prepare( "SELECT id, order_id, event_type, actor_type, actor_id, source, visibility, created_at FROM {$order_events} WHERE {$where_sql} ORDER BY created_at DESC LIMIT 500", ...$params )
				: "SELECT id, order_id, event_type, actor_type, actor_id, source, visibility, created_at FROM {$order_events} WHERE {$where_sql} ORDER BY created_at DESC LIMIT 500"
		);

		foreach ( (array) $order_rows as $r ) {
			$all_rows[] = [
				'time'        => (string) $r->created_at,
				'entity_type' => 'product_order',
				'entity_id'   => (int) $r->order_id,
				'event_type'  => sanitize_text_field( (string) $r->event_type ),
				'actor'       => dtb_oo_actor_label( (int) $r->actor_id ),
				'source'      => sanitize_text_field( (string) $r->source ),
				'visibility'  => sanitize_text_field( (string) $r->visibility ),
				'summary'     => sanitize_text_field( str_replace( [ '.', '_' ], ' ', (string) $r->event_type ) ),
			];
		}
	}

	// ---- 3. Repair events ----
	if ( ! in_array( $entity_type, [ 'product_order' ], true ) ) {
		$where  = [ "visibility IN ('customer','operator')" ];
		$params = [];
		if ( $entity_id > 0 ) {
			$where[]  = 'repair_id = %d';
			$params[] = $entity_id;
		}
		if ( $date_from ) {
			$where[]  = 'created_at >= %s';
			$params[] = $date_from . ' 00:00:00';
		}
		if ( $date_to ) {
			$where[]  = 'created_at <= %s';
			$params[] = $date_to . ' 23:59:59';
		}
		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$repair_rows = $wpdb->get_results(
			$params
				? $wpdb->prepare( "SELECT id, repair_id, event_type, actor_type, actor_id, source, visibility, created_at FROM {$repair_events} WHERE {$where_sql} ORDER BY created_at DESC LIMIT 500", ...$params )
				: "SELECT id, repair_id, event_type, actor_type, actor_id, source, visibility, created_at FROM {$repair_events} WHERE {$where_sql} ORDER BY created_at DESC LIMIT 500"
		);

		foreach ( (array) $repair_rows as $r ) {
			$all_rows[] = [
				'time'        => (string) $r->created_at,
				'entity_type' => 'repair_order',
				'entity_id'   => (int) $r->repair_id,
				'event_type'  => sanitize_text_field( (string) $r->event_type ),
				'actor'       => dtb_oo_actor_label( (int) $r->actor_id ),
				'source'      => sanitize_text_field( (string) $r->source ),
				'visibility'  => sanitize_text_field( (string) $r->visibility ),
				'summary'     => sanitize_text_field( str_replace( [ '.', '_' ], ' ', (string) $r->event_type ) ),
			];
		}
	}

	// Sort by time DESC.
	usort( $all_rows, static fn( $a, $b ) => strcmp( (string) $b['time'], (string) $a['time'] ) );

	$total = count( $all_rows );
	$rows  = array_slice( $all_rows, $offset, $per_page );

	return [
		'rows'  => $rows,
		'total' => $total,
		'pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
	];
}

/**
 * Return a safe actor label for display.
 *
 * @param int $user_id
 * @return string
 */
function dtb_oo_actor_label( int $user_id ): string {
	if ( $user_id <= 0 ) {
		return 'system';
	}
	$user = get_userdata( $user_id );
	if ( $user instanceof WP_User ) {
		return esc_html( $user->user_login );
	}
	return 'user:' . $user_id;
}

// =============================================================================
// SECTION 10 — PAYLOAD REDACTION
// =============================================================================

/**
 * Redact sensitive keys from a payload before exposing in the UI.
 *
 * @param array $payload
 * @return array
 */
function dtb_oo_redact_payload( array $payload ): array {
	static $deny_patterns = [
		'secret', 'token', 'key', 'password', 'credential', 'auth',
		'card_number', 'cvv', 'cvc', 'stack_trace', 'raw_error',
		'webhook_body', 'gateway_raw', 'payment_method_details',
		'quickbooks', 'veeqo_api', 'oauth',
	];

	$out = [];
	foreach ( $payload as $k => $v ) {
		$lower_key = strtolower( (string) $k );
		$redacted  = false;
		foreach ( $deny_patterns as $pattern ) {
			if ( str_contains( $lower_key, $pattern ) ) {
				$redacted = true;
				break;
			}
		}
		if ( $redacted ) {
			$out[ $k ] = '[REDACTED]';
		} elseif ( is_array( $v ) ) {
			$out[ $k ] = dtb_oo_redact_payload( $v );
		} elseif ( is_scalar( $v ) || null === $v ) {
			$out[ $k ] = $v;
		}
	}
	return $out;
}
