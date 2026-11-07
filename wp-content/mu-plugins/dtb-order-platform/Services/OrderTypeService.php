<?php
/**
 * DTB Order Type Service — resolve product vs repair service order types.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve an order's specialized type.
 *
 * Returns one of:
 * - product
 * - repair_service
 *
 * @param int|WC_Abstract_Order $order_or_id Order ID or Woo order object.
 * @return string
 */
function dtb_order_resolve_type( int|WC_Abstract_Order $order_or_id ): string {
	$order = $order_or_id instanceof WC_Abstract_Order ? $order_or_id : wc_get_order( $order_or_id );
	if ( ! $order ) {
		return 'product';
	}

	$explicit_type = sanitize_key( (string) $order->get_meta( '_dtb_order_type', true ) );
	if ( 'repair_service' === $explicit_type || 'product' === $explicit_type ) {
		return $explicit_type;
	}

	$is_repair_flag = (string) $order->get_meta( '_dtb_is_repair_order', true );
	$repair_id      = (int) $order->get_meta( '_dtb_repair_id', true );

	if ( '1' === $is_repair_flag || $repair_id > 0 ) {
		return 'repair_service';
	}

	return 'product';
}
