<?php
/**
 * Checkout confirmation integrity for customer-facing order REST responses.
 *
 * A Woo Checkout Block may create a temporary `checkout-draft` order before
 * payment/finalization completes. Customer-facing success UI must never treat
 * that draft (or any other unpaid DTB checkout order) as a confirmed purchase.
 *
 * WooCommerce remains the source of truth for order/payment state. This layer
 * only projects a conservative confirmation contract into DTB order responses.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'rest_post_dispatch', 'dtb_order_harden_checkout_confirmation_response', 40, 3 );

/**
 * Add explicit payment-confirmation state and fail closed for unfinalized orders.
 *
 * @param mixed           $response REST response.
 * @param WP_REST_Server  $server   REST server.
 * @param WP_REST_Request $request  REST request.
 * @return mixed
 */
function dtb_order_harden_checkout_confirmation_response( $response, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	if ( is_wp_error( $response ) || ! $request instanceof WP_REST_Request ) {
		return $response;
	}

	$route = (string) $request->get_route();
	if ( ! preg_match( '#^/dtb/v1/orders/(?P<id>\d+)(?:/tracking)?$#', $route, $matches ) ) {
		return $response;
	}

	if ( ! ( $response instanceof WP_REST_Response || $response instanceof WP_HTTP_Response ) ) {
		return $response;
	}

	$order_id = absint( $matches['id'] ?? 0 );
	$order    = $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
	if ( ! $order instanceof WC_Order ) {
		return $response;
	}

	$data = $response->get_data();
	if ( ! is_array( $data ) ) {
		return $response;
	}

	$status = sanitize_key( (string) $order->get_status() );
	$is_dtb_checkout = function_exists( 'dtb_checkout_handoff_is_order' )
		? dtb_checkout_handoff_is_order( $order )
		: false;
	$payment_confirmed = function_exists( 'dtb_checkout_handoff_has_captured_payment' )
		? dtb_checkout_handoff_has_captured_payment( $order )
		: ( null !== $order->get_date_paid() && '' !== trim( (string) $order->get_transaction_id() ) );

	/*
	 * Zero-total orders do not require a captured payment, but they still must have
	 * left the temporary Checkout Block draft lifecycle before being confirmable.
	 */
	$zero_total_finalized = (float) $order->get_total() <= 0
		&& ! in_array( $status, [ 'checkout-draft', 'draft', 'auto-draft', 'pending', 'failed', 'cancelled', 'refunded', 'trash' ], true );
	$order_confirmed = $is_dtb_checkout
		? ( $payment_confirmed || $zero_total_finalized )
		: in_array( $status, [ 'processing', 'completed', 'shipped' ], true );

	$data['payment_confirmed'] = (bool) $payment_confirmed;
	$data['order_confirmed']   = (bool) $order_confirmed;
	$data['is_checkout_draft'] = in_array( $status, [ 'checkout-draft', 'draft', 'auto-draft' ], true );

	/* Existing frontend success gating uses payment_required. Make that projection
	 * fail closed until the authoritative order is genuinely confirmable. */
	if ( ! $order_confirmed ) {
		$data['payment_required'] = true;
	}

	$response->set_data( $data );
	return $response;
}
