<?php
/**
 * DTB Platform — CommandCenterReadModel
 *
 * Queries and aggregates business-observable state for the Command Center.
 * Returns structured data — no raw payloads, no backend diagnostics.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build the complete Command Center read model.
 *
 * @return array
 */
function dtb_command_center_build_read_model(): array {
	return [
		'orders'   => dtb_command_center_orders_summary(),
		'repairs'  => dtb_command_center_repairs_summary(),
		'returns'  => dtb_command_center_returns_summary(),
		'support'  => dtb_command_center_support_summary(),
		'exceptions' => dtb_command_center_exceptions_summary(),
		'generated_at' => current_time( 'c' ),
	];
}

/**
 * Orders summary: attention, payment issues, fulfillment exceptions.
 *
 * @return array
 */
function dtb_command_center_orders_summary(): array {
	$cache_key = 'dtb_cc_orders_summary';
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		return $cached;
	}

	$totals = [
		'active'                   => 0,
		'needs_attention'         => 0,
		'on_hold'                  => 0,
		'pending'                  => 0,
		'payment_issues'          => 0,
		'fulfillment_exceptions'  => 0,
		'pending_payment'         => 0,
		'processing'              => 0,
		'total_today'             => 0,
	];

	if ( function_exists( 'wc_get_orders' ) ) {
		$totals['on_hold']         = dtb_command_center_wc_order_count( [ 'status' => 'on-hold' ] );
		$totals['needs_attention'] = $totals['on_hold'];
		$totals['payment_issues']  = dtb_command_center_wc_order_count( [ 'status' => 'failed' ] );
		$totals['processing']      = dtb_command_center_wc_order_count( [ 'status' => 'processing' ] );
		$totals['pending_payment'] = dtb_command_center_wc_order_count( [ 'status' => 'pending' ] );
		$totals['pending']         = $totals['pending_payment'] + $totals['on_hold'];
		$totals['active']          = $totals['pending'] + $totals['processing'];
		$totals['total_today']     = dtb_command_center_wc_order_count( [
			'date_created' => '>' . ( time() - DAY_IN_SECONDS ),
		] );
	}

	set_transient( $cache_key, $totals, 2 * MINUTE_IN_SECONDS );

	return $totals;
}

/**
 * Count WooCommerce orders without materializing every matching order ID.
 *
 * @param array<string,mixed> $args WC order query args.
 * @return int
 */
function dtb_command_center_wc_order_count( array $args ): int {
	if ( isset( $args['status'] ) && 1 === count( $args ) && function_exists( 'wc_orders_count' ) ) {
		return (int) wc_orders_count( (string) $args['status'] );
	}

	if ( ! function_exists( 'wc_get_orders' ) ) {
		return 0;
	}

	$query_args = array_merge( $args, [
		'limit'    => 1,
		'paginate' => true,
		'return'   => 'ids',
	] );
	$result = wc_get_orders( $query_args );

	return is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;
}

/**
 * Repairs summary.
 *
 * @return array
 */
function dtb_command_center_repairs_summary(): array {
	$cache_key = 'dtb_cc_repairs_summary';
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) return $cached;

	$totals = [
		'awaiting_review'          => 0,
		'awaiting_quote_approval'  => 0,
		'in_progress'              => 0,
		'ready_to_ship'            => 0,
		'total_open'               => 0,
	];

	if ( function_exists( 'dtb_repairs_count_by_status' ) ) {
		$counts = dtb_repairs_count_by_status();
		$sum_statuses = static function ( array $statuses ) use ( $counts ): int {
			$total = 0;
			foreach ( $statuses as $status ) {
				$total += (int) ( $counts[ $status ] ?? 0 );
			}
			return $total;
		};
		$totals['awaiting_review']         = (int) ( $counts['review'] ?? 0 )
			?: $sum_statuses( [ 'submitted', 'reviewed', 'awaiting_customer' ] );
		$totals['awaiting_quote_approval'] = (int) ( $counts['quote_pending'] ?? 0 )
			?: $sum_statuses( [ 'approved', 'quoted', 'quote_accepted' ] );
		$totals['in_progress']             = (int) ( $counts['in_progress'] ?? 0 )
			?: $sum_statuses( [ 'parts_allocated', 'in_progress' ] );
		$totals['ready_to_ship']           = (int) ( $counts['ready_to_ship'] ?? 0 );
		$totals['total_open']              = (int) $totals['awaiting_review']
			+ (int) $totals['awaiting_quote_approval']
			+ (int) $totals['in_progress']
			+ (int) $totals['ready_to_ship'];
	}

	set_transient( $cache_key, $totals, 2 * MINUTE_IN_SECONDS );
	return $totals;
}

/**
 * Returns (RMA) summary.
 *
 * @return array
 */
function dtb_command_center_returns_summary(): array {
	$cache_key = 'dtb_cc_returns_summary';
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) return $cached;

	$totals = [
		'pending_review'     => 0,
		'pending_inspection' => 0,
		'refund_pending'     => 0,
		'total_open'         => 0,
	];

	if ( function_exists( 'dtb_returns_count_by_status' ) ) {
		$counts = dtb_returns_count_by_status();
		$totals['pending_review']     = (int) ( $counts['pending_review'] ?? 0 );
		$totals['pending_inspection'] = (int) ( $counts['item_received'] ?? 0 );
		$totals['refund_pending']     = (int) ( $counts['refund_issued'] ?? 0 );
		$totals['total_open']         =
			(int) ( $counts['pending_review'] ?? 0 )
			+ (int) ( $counts['approved'] ?? 0 )
			+ (int) ( $counts['rejected'] ?? 0 )
			+ (int) ( $counts['awaiting_item'] ?? 0 )
			+ (int) ( $counts['item_received'] ?? 0 )
			+ (int) ( $counts['refund_issued'] ?? 0 )
			+ (int) ( $counts['exchange_sent'] ?? 0 );
	}

	set_transient( $cache_key, $totals, 2 * MINUTE_IN_SECONDS );
	return $totals;
}

/**
 * Support summary.
 *
 * @return array
 */
function dtb_command_center_support_summary(): array {
	$cache_key = 'dtb_cc_support_summary';
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) return $cached;

	$totals = [
		'open'       => 0,
		'past_sla'   => 0,
		'needs_reply' => 0,
		'total_open' => 0,
	];

	if ( function_exists( 'dtb_support_count_by_status' ) ) {
		$counts = dtb_support_count_by_status();
		$totals['open']        = (int) ( $counts['open'] ?? 0 );
		$totals['needs_reply'] = (int) ( $counts['needs_reply'] ?? 0 );
		$totals['past_sla']    = function_exists( 'dtb_support_count_past_sla' )
			? (int) dtb_support_count_past_sla()
			: 0;
		$totals['total_open']  = array_sum( [ $totals['open'], $totals['needs_reply'] ] );
	}

	set_transient( $cache_key, $totals, 2 * MINUTE_IN_SECONDS );
	return $totals;
}

/**
 * Customer-impacting exceptions summary.
 *
 * @return array
 */
function dtb_command_center_exceptions_summary(): array {
	if ( function_exists( 'dtb_admin_get_exception_queues' ) ) {
		$queues = dtb_admin_get_exception_queues();
		return [
			'total'  => array_sum( array_map( static fn( $queue ) => (int) ( $queue['count'] ?? 0 ), $queues ) ),
			'queues' => $queues,
		];
	}

	$orders  = dtb_command_center_orders_summary();
	$repairs = dtb_command_center_repairs_summary();
	$support = dtb_command_center_support_summary();

	return [
		'total' => (int) $orders['needs_attention']
			+ (int) $orders['payment_issues']
			+ (int) $repairs['awaiting_review']
			+ (int) $support['past_sla'],
	];
}
