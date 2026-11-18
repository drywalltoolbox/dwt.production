<?php
/**
 * DTB Commerce — Order Type Service
 *
 * Classifies WooCommerce orders into DTB operational types while keeping the
 * canonical WooCommerce order storage model intact.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_order_type_normalize( string $type ): string {
	$type = sanitize_key( str_replace( '-', '_', $type ) );
	if ( in_array( $type, [ '', 'all' ], true ) ) {
		return '';
	}
	if ( in_array( $type, [ 'product', 'product_order', 'purchase', 'purchase_order' ], true ) ) {
		return 'product';
	}
	if ( in_array( $type, [ 'repair', 'repair_order', 'repair_service' ], true ) ) {
		return 'repair';
	}
	if ( in_array( $type, [ 'return', 'return_order', 'returns', 'rma' ], true ) ) {
		return 'return';
	}
	return '';
}

function dtb_order_type_label( string $type ): string {
	return [
		'product' => __( 'Product', 'drywall-toolbox' ),
		'repair'  => __( 'Repair', 'drywall-toolbox' ),
		'return'  => __( 'Return', 'drywall-toolbox' ),
	][ dtb_order_type_normalize( $type ) ] ?? __( 'Product', 'drywall-toolbox' );
}

function dtb_order_type_from_order( WC_Order $order ): string {
	$type = dtb_order_type_normalize( (string) $order->get_meta( '_dtb_order_type', true ) );
	if ( $type ) {
		return $type;
	}
	if ( $order->get_meta( '_dtb_is_repair_order', true ) || $order->get_meta( '_dtb_repair_id', true ) ) {
		return 'repair';
	}
	if ( $order->get_meta( '_dtb_is_return_order', true ) || $order->get_meta( '_dtb_return_id', true ) || $order->get_meta( '_dtb_rma_id', true ) ) {
		return 'return';
	}
	return 'product';
}

function dtb_order_type_badge_type( string $type ): string {
	return [
		'product' => 'info',
		'repair'  => 'warning',
		'return'  => 'danger',
	][ dtb_order_type_normalize( $type ) ] ?? 'info';
}

function dtb_order_type_meta_query( string $type ): array {
	$type = dtb_order_type_normalize( $type );
	if ( '' === $type ) {
		return [];
	}

	if ( 'repair' === $type ) {
		return [
			'relation' => 'OR',
			[ 'key' => '_dtb_order_type', 'value' => [ 'repair', 'repair_service', 'repair_order' ], 'compare' => 'IN' ],
			[ 'key' => '_dtb_is_repair_order', 'value' => '1', 'compare' => '=' ],
			[ 'key' => '_dtb_repair_id', 'compare' => 'EXISTS' ],
		];
	}

	if ( 'return' === $type ) {
		return [
			'relation' => 'OR',
			[ 'key' => '_dtb_order_type', 'value' => [ 'return', 'return_order', 'rma' ], 'compare' => 'IN' ],
			[ 'key' => '_dtb_is_return_order', 'value' => '1', 'compare' => '=' ],
			[ 'key' => '_dtb_return_id', 'compare' => 'EXISTS' ],
			[ 'key' => '_dtb_rma_id', 'compare' => 'EXISTS' ],
		];
	}

	return [
		'relation' => 'AND',
		[
			'relation' => 'OR',
			[ 'key' => '_dtb_order_type', 'compare' => 'NOT EXISTS' ],
			[ 'key' => '_dtb_order_type', 'value' => [ '', 'product', 'product_order', 'purchase_order' ], 'compare' => 'IN' ],
		],
		[ 'key' => '_dtb_is_repair_order', 'compare' => 'NOT EXISTS' ],
		[ 'key' => '_dtb_repair_id', 'compare' => 'NOT EXISTS' ],
		[ 'key' => '_dtb_is_return_order', 'compare' => 'NOT EXISTS' ],
		[ 'key' => '_dtb_return_id', 'compare' => 'NOT EXISTS' ],
		[ 'key' => '_dtb_rma_id', 'compare' => 'NOT EXISTS' ],
	];
}
