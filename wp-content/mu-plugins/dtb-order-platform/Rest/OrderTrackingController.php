<?php
/**
 * DTB Order Tracking Controller — REST handler for tracking endpoint.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_order_rest_get_tracking( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$order_id = (int) $request->get_param( 'id' );
	$order    = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

	if ( $order instanceof WC_Order && function_exists( 'dtb_payment_is_incomplete_checkout_order' ) && dtb_payment_is_incomplete_checkout_order( $order ) ) {
		return new WP_Error( 'dtb_not_found', 'Order not found.', [ 'status' => 404 ] );
	}

	$projection = dtb_order_get_tracking_projection( $order_id );

	if ( null === $projection ) {
		return new WP_Error( 'dtb_not_found', 'Order not found.', [ 'status' => 404 ] );
	}

	return new WP_REST_Response( $projection, 200 );
}
