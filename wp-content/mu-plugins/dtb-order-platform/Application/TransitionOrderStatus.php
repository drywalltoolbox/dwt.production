<?php
/**
 * DTB Transition Order Status — WooCommerce status-change handler.
 *
 * Records order-status lifecycle transitions. Payment/refund events and external
 * side effects remain owned by their dedicated WooCommerce hooks so status
 * changes cannot duplicate payment/refund timeline events or accounting jobs.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'woocommerce_order_status_changed', 'dtb_order_on_status_changed', 10, 4 );

function dtb_order_on_status_changed( int $order_id, string $from_status, string $to_status, $order ): void {
	if ( ! $order instanceof WC_Order && function_exists( 'wc_get_order' ) ) {
		$order = wc_get_order( $order_id );
	}

	if ( $order instanceof WC_Order && function_exists( 'dtb_checkout_handoff_is_unpaid_order' ) && dtb_checkout_handoff_is_unpaid_order( $order ) ) {
		// Never project a transient processing/completed status as paid. The official
		// The payment provider must first provide captured payment/date-paid state.
		if ( in_array( $to_status, [ 'processing', 'completed' ], true ) ) {
			return;
		}
	}

	$actor_id   = get_current_user_id();
	$actor_type = $actor_id ? 'admin' : 'system';
	$source     = is_admin() ? 'wp_admin' : 'system';

	$event_map = [
		'pending'    => 'order.pending',
		'on-hold'    => 'order.on_hold',
		'processing' => 'order.processing',
		'completed'  => 'order.completed',
		'cancelled'  => 'order.cancelled',
		'refunded'   => 'order.refund_status_changed',
		'failed'     => 'order.failed',
	];

	$event_type = $event_map[ $to_status ] ?? ( 'order.status_changed.' . sanitize_key( $to_status ) );

	dtb_order_append_event( $order_id, $event_type, [
		'from_status' => $from_status,
		'to_status'   => $to_status,
		'actor_type'  => $actor_type,
		'actor_id'    => $actor_id ?: null,
		'source'      => $source,
		'payload'     => [
			'from' => $from_status,
			'to'   => $to_status,
		],
	] );

	if ( 'processing' === $to_status ) {
		dtb_order_dispatch_processing_jobs( $order_id );
	}

	if ( 'completed' === $to_status ) {
		// Captured-payment lifecycle dispatch already owns accounting/fulfillment.
		// Completion only archives the operational projection.
		dtb_order_enqueue_job( 'dtb_order_archive_completed', $order_id );
	}
}
