<?php
/**
 * Refund lifecycle hardening.
 *
 * WooCommerce owns refund creation and native refund notifications. DTB consumes
 * each concrete WC_Order_Refund exactly once for projections/accounting and does
 * not infer refund-versus-cancellation behavior from the parent order status.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

remove_action( 'dtb_order_handle_refund', 'dtb_order_job_handle_refund', 10 );
add_action( 'dtb_order_handle_refund', 'dtb_order_job_handle_refund_projection', 10, 2 );

function dtb_order_job_handle_refund_projection( int $order_id, array $args = [] ): void {
	$refund_id = absint( $args['refund_id'] ?? 0 );
	$order     = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
	$refund    = $refund_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $refund_id ) : null;

	if ( ! $order instanceof WC_Order || ! $refund instanceof WC_Order_Refund ) {
		return;
	}
	if ( (int) $refund->get_parent_id() !== $order_id ) {
		return;
	}
	if ( function_exists( 'dtb_order_job_should_skip_order_side_effects' ) && dtb_order_job_should_skip_order_side_effects( $order ) ) {
		return;
	}

	if ( function_exists( 'dtb_order_append_event' ) ) {
		dtb_order_append_event(
			$order_id,
			'order.refund_projection_refreshed',
			[
				'source'          => 'cron',
				'actor_type'      => 'system',
				'visibility'      => 'operator',
				'idempotency_key' => 'refund-projection:' . $order_id . ':' . $refund_id,
				'payload'         => [
					'refund_id' => $refund_id,
					'amount'    => (string) $refund->get_amount(),
				],
			]
		);
	}

	if ( function_exists( 'dtb_order_enqueue_job' ) ) {
		dtb_order_enqueue_job( 'dtb_order_refresh_tracking_projection', $order_id, [ 'refund_id' => $refund_id ] );
	}
}
