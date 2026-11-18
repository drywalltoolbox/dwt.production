<?php
/**
 * DTB Commerce — Order Admin Query Service
 *
 * Shared query/count helpers for the orders admin page and live REST fragment.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalize an orders queue filter.
 *
 * @param string $filter Raw filter/status.
 * @return string
 */
function dtb_orders_admin_normalize_filter( string $filter ): string {
	$filter = sanitize_key( str_replace( '-', '_', $filter ) );
	if ( '' === $filter || 'all' === $filter ) {
		return '';
	}

	if ( function_exists( 'dtb_admin_normalize_workflow_queue_filter' ) ) {
		$canonical = dtb_admin_normalize_workflow_queue_filter( 'product_order', $filter );
		if ( '' !== $canonical ) {
			return $canonical;
		}
	}

	if ( in_array( $filter, [ 'active', 'pending_all' ], true ) ) {
		return str_replace( '_', '-', $filter );
	}

	$filter = str_replace( '_', '-', $filter );
	$valid  = array_map(
		static fn( string $status ): string => sanitize_key( preg_replace( '/^wc-/', '', $status ) ),
		array_keys( function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : [] )
	);

	return in_array( $filter, $valid, true ) ? $filter : '';
}

/**
 * Return WooCommerce statuses for an orders queue filter.
 *
 * @param string $filter Queue filter.
 * @return string[]
 */
function dtb_orders_admin_statuses_for_filter( string $filter ): array {
	$filter = dtb_orders_admin_normalize_filter( $filter );
	if ( '' === $filter ) {
		return [];
	}

	$aggregate_filters = [
		'active'      => [ 'pending', 'on-hold', 'processing' ],
		'pending-all' => [ 'pending', 'on-hold' ],
	];
	if ( isset( $aggregate_filters[ $filter ] ) ) {
		return array_map(
			static fn( string $status ): string => 'wc-' . $status,
			$aggregate_filters[ $filter ]
		);
	}

	$statuses = function_exists( 'dtb_admin_get_workflow_queue_filter_statuses' )
		? dtb_admin_get_workflow_queue_filter_statuses( 'product_order', $filter )
		: [];
	if ( empty( $statuses ) ) {
		$statuses = [ $filter ];
	}

	return array_values( array_map(
		static fn( string $status ): string => str_starts_with( $status, 'wc-' ) ? $status : 'wc-' . $status,
		$statuses
	) );
}

/**
 * Build WooCommerce order query args for admin queue listings.
 *
 * @param string $filter Queue filter/status.
 * @param string $search Search string.
 * @param int    $paged  Page number.
 * @param int    $limit  Page size.
 * @return array<string,mixed>
 */
function dtb_orders_admin_build_query_args( string $filter, string $search = '', int $paged = 1, int $limit = 25, string $order_type = '' ): array {
	$args = [
		'limit'  => max( 1, $limit ),
		'paged'  => max( 1, $paged ),
		'return' => 'objects',
	];

	$statuses = dtb_orders_admin_statuses_for_filter( $filter );
	if ( $statuses ) {
		$args['status'] = 1 === count( $statuses ) ? $statuses[0] : $statuses;
	}
	if ( '' !== $search ) {
		$args['s'] = $search;
	}
	if ( function_exists( 'dtb_order_type_meta_query' ) ) {
		$type_query = dtb_order_type_meta_query( $order_type );
		if ( $type_query ) {
			$args['meta_query'] = $type_query;
		}
	}

	return $args;
}

/**
 * Count orders for the admin queue.
 *
 * @param string $filter Queue filter/status.
 * @param string $search Search string.
 * @return int
 */
function dtb_orders_admin_count( string $filter = '', string $search = '', string $order_type = '' ): int {
	$args     = [];
	$statuses = dtb_orders_admin_statuses_for_filter( $filter );
	if ( $statuses ) {
		$args['status'] = 1 === count( $statuses ) ? $statuses[0] : $statuses;
	}
	if ( '' !== $search ) {
		$args['s'] = $search;
	}
	if ( function_exists( 'dtb_order_type_meta_query' ) ) {
		$type_query = dtb_order_type_meta_query( $order_type );
		if ( $type_query ) {
			$args['meta_query'] = $type_query;
		}
	}

	return function_exists( 'dtb_admin_wc_order_count' ) ? dtb_admin_wc_order_count( $args ) : 0;
}
