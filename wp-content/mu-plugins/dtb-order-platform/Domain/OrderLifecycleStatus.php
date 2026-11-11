<?php
/**
 * Domain: Order Lifecycle Status — WC status map, labels, fulfillment substates.
 *
 * This file is also the canonical registration point for the custom
 * `wc-shipped` WooCommerce order status: a first-class post status distinct
 * from `wc-processing` (paid, preparing) and `wc-completed` (delivered/done).
 *
 * @package drywall-toolbox
 */
defined( 'ABSPATH' ) || exit;

function dtb_order_get_status_map(): array {
return [
'pending'    => [ 'label' => __( 'Order Received',       'drywall-toolbox' ), 'description' => __( 'Order started, payment not confirmed', 'drywall-toolbox' ), 'is_terminal' => false ],
'on-hold'    => [ 'label' => __( 'Payment Under Review',  'drywall-toolbox' ), 'description' => __( 'Awaiting confirmation or manual review', 'drywall-toolbox' ), 'is_terminal' => false ],
'processing' => [ 'label' => __( 'Processing',            'drywall-toolbox' ), 'description' => __( 'Paid and preparing for fulfillment', 'drywall-toolbox' ), 'is_terminal' => false ],
'shipped'    => [ 'label' => __( 'Shipped',               'drywall-toolbox' ), 'description' => __( 'Package shipped, in transit to customer', 'drywall-toolbox' ), 'is_terminal' => false ],
'completed'  => [ 'label' => __( 'Delivered / Completed', 'drywall-toolbox' ), 'description' => __( 'Fulfillment complete', 'drywall-toolbox' ), 'is_terminal' => true ],
'cancelled'  => [ 'label' => __( 'Cancelled',             'drywall-toolbox' ), 'description' => __( 'Order cancelled', 'drywall-toolbox' ), 'is_terminal' => true ],
'refunded'   => [ 'label' => __( 'Refunded',              'drywall-toolbox' ), 'description' => __( 'Full or partial refund processed', 'drywall-toolbox' ), 'is_terminal' => true ],
'failed'     => [ 'label' => __( 'Payment Failed',        'drywall-toolbox' ), 'description' => __( 'Payment did not complete', 'drywall-toolbox' ), 'is_terminal' => true ],
];
}

/**
 * Register the custom `wc-shipped` WooCommerce order post status.
 *
 * Hooked to `init` (required by WordPress for custom post statuses).
 */
function dtb_order_register_shipped_post_status(): void {
	register_post_status( 'wc-shipped', [
		'label'                     => _x( 'Shipped', 'Order status', 'drywall-toolbox' ),
		'public'                    => false,
		'exclude_from_search'       => true,
		'show_in_admin_all_list'    => true,
		'show_in_admin_status_list' => true,
		/* translators: %s: number of orders. */
		'label_count'               => _n_noop(
			'Shipped <span class="count">(%s)</span>',
			'Shipped <span class="count">(%s)</span>',
			'drywall-toolbox'
		),
	] );
}
add_action( 'init', 'dtb_order_register_shipped_post_status' );

/**
 * Insert the `wc-shipped` status into WooCommerce's order status list,
 * positioned after Processing and before Completed.
 *
 * @param array<string,string> $statuses WooCommerce order statuses.
 * @return array<string,string>
 */
function dtb_order_add_shipped_to_wc_order_statuses( array $statuses ): array {
	$new_statuses = [];

	foreach ( $statuses as $key => $label ) {
		$new_statuses[ $key ] = $label;
		if ( 'wc-processing' === $key ) {
			$new_statuses['wc-shipped'] = _x( 'Shipped', 'Order status', 'drywall-toolbox' );
		}
	}

	if ( ! isset( $new_statuses['wc-shipped'] ) ) {
		$new_statuses['wc-shipped'] = _x( 'Shipped', 'Order status', 'drywall-toolbox' );
	}

	return $new_statuses;
}
add_filter( 'wc_order_statuses', 'dtb_order_add_shipped_to_wc_order_statuses' );

function dtb_order_terminal_statuses(): array {
return array_keys( array_filter( dtb_order_get_status_map(), static fn( $v ) => $v['is_terminal'] ) );
}

function dtb_order_get_status_label( string $wc_status ): string {
$map = dtb_order_get_status_map();
return $map[ $wc_status ]['label'] ?? ucwords( str_replace( '-', ' ', $wc_status ) );
}

function dtb_order_get_allowed_transitions( string $status ): array {
	static $map = null;

	if ( null === $map ) {
		$map = [
			'pending'    => [ 'on-hold', 'processing', 'cancelled', 'failed' ],
			'on-hold'    => [ 'processing', 'cancelled' ],
			'processing' => [ 'shipped', 'completed', 'on-hold', 'cancelled', 'refunded' ],
			'shipped'    => [ 'completed', 'refunded' ],
			'completed'  => [ 'refunded' ],
			'cancelled'  => [],
			'refunded'   => [],
			'failed'     => [ 'pending', 'on-hold', 'cancelled' ],
		];
	}

	return $map[ $status ] ?? [];
}

function dtb_order_fulfillment_substates(): array {
return [
'pending'            => __( 'Preparing', 'drywall-toolbox' ),
'inventory_reserved' => __( 'Inventory Reserved', 'drywall-toolbox' ),
'picked'             => __( 'Picking', 'drywall-toolbox' ),
'packed'             => __( 'Packed', 'drywall-toolbox' ),
'shipped'            => __( 'Shipped', 'drywall-toolbox' ),
'delivered'          => __( 'Delivered', 'drywall-toolbox' ),
'exception'          => __( 'Processing Delay', 'drywall-toolbox' ),
];
}
