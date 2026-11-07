<?php
/**
 * DTB Order Workflow Service — WooCommerce lifecycle hook handlers.
 *
 * Registers and handles the woocommerce_new_order and woocommerce_order_refunded
 * hooks, plus dispatches processing-phase async jobs.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'woocommerce_new_order', 'dtb_order_on_created', 10, 2 );
add_action( 'woocommerce_order_refunded', 'dtb_order_on_refunded', 10, 2 );

function dtb_order_on_created( int $order_id, $order ): void {
	if ( $order instanceof WC_Abstract_Order ) {
		$current_type = sanitize_key( (string) $order->get_meta( '_dtb_order_type', true ) );
		if ( '' === $current_type ) {
			$resolved_type = function_exists( 'dtb_order_resolve_type' )
				? dtb_order_resolve_type( $order )
				: 'product';

			$order->update_meta_data( '_dtb_order_type', $resolved_type );
			$order->save_meta_data();
		}
	}

	dtb_order_append_event( $order_id, 'order.created', [
		'source'      => 'checkout',
		'actor_type'  => 'customer',
		'actor_id'    => $order instanceof WC_Abstract_Order ? (int) $order->get_customer_id() : 0,
		'to_status'   => 'pending',
		'visibility'  => 'customer',
		'idempotency_key' => 'order-created:' . $order_id,
		'payload'     => [
			'total'          => $order instanceof WC_Abstract_Order ? (string) $order->get_total() : null,
			'currency'       => $order instanceof WC_Abstract_Order ? $order->get_currency() : null,
			'items_count'    => $order instanceof WC_Abstract_Order ? count( $order->get_items() ) : 0,
			'payment_method' => $order instanceof WC_Abstract_Order ? $order->get_payment_method() : null,
		],
	] );
}

function dtb_order_on_refunded( int $order_id, int $refund_id ): void {
	if ( $order_id <= 0 || $refund_id <= 0 ) {
		return;
	}

	dtb_order_append_event( $order_id, 'order.refunded', [
		'source'          => is_admin() ? 'wp_admin' : 'system',
		'actor_type'      => is_admin() ? 'admin' : 'system',
		'actor_id'        => get_current_user_id() ?: null,
		'visibility'      => 'customer',
		'idempotency_key' => 'order-refunded:' . $order_id . ':' . $refund_id,
		'payload'         => [ 'refund_id' => $refund_id ],
	] );

	$refund_args = [ 'refund_id' => $refund_id ];
	dtb_order_enqueue_job( 'dtb_order_handle_refund', $order_id, $refund_args );
	dtb_order_enqueue_job( 'dtb_order_sync_quickbooks', $order_id, [ 'action' => 'refund', 'refund_id' => $refund_id ] );
}

/**
 * Dispatch receipts are durable per-job markers, separate from worker outcomes.
 * Legacy dispatched orders without the v2 marker are deliberately NOT replayed:
 * the old marker does not prove which provider calls may have completed.
 */
function dtb_order_dispatch_processing_jobs( int $order_id ): void {
	$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	if ( function_exists( 'dtb_checkout_handoff_is_unpaid_order' ) && dtb_checkout_handoff_is_unpaid_order( $order ) ) {
		return;
	}
	// Orders explicitly excluded from external work must not enter recovery.
	if ( function_exists( 'dtb_order_write_boundary_order_should_skip_side_effects' )
		&& dtb_order_write_boundary_order_should_skip_side_effects( $order ) ) {
		return;
	}

	$legacy_key = 'dtb_order_processing_dispatch_' . $order_id;
	$v2_key     = 'dtb_order_processing_dispatch_v2_' . $order_id;
	$managed    = (bool) get_option( $v2_key, false );
	if ( ! $managed ) {
		// If the legacy marker exists, there is no reliable proof that provider
		// work did not execute. Never replay it automatically.
		if ( get_option( $legacy_key, false ) ) {
			return;
		}
		if ( ! add_option( $legacy_key, current_time( 'mysql', true ), '', 'no' ) ) {
			return;
		}
		add_option( $v2_key, '1', '', 'no' );
		dtb_order_append_event( $order_id, 'order.fulfillment_queued', [
			'source' => 'system', 'actor_type' => 'system', 'visibility' => 'operator',
			'idempotency_key' => 'order-processing-dispatch:' . $order_id,
		] );
	}

	$jobs = [
		[ 'dtb_order_send_notification', [ 'template' => 'order-confirmation' ] ],
		[ 'dtb_order_sync_veeqo', [] ],
		[ 'dtb_order_sync_quickbooks', [ 'action' => 'create' ] ],
		[ 'dtb_order_refresh_tracking_projection', [] ],
	];
	foreach ( $jobs as [ $hook, $args ] ) {
		// Disabled or intentionally blocked integrations never acquire a receipt.
		if ( 'dtb_order_sync_quickbooks' === $hook && function_exists( 'dtb_qbo_exports_paused' ) && dtb_qbo_exports_paused() ) {
			continue;
		}
		if ( function_exists( 'dtb_order_write_boundary_should_block_job' ) && dtb_order_write_boundary_should_block_job( $hook, $order_id, $args ) ) {
			continue;
		}
		$receipt = 'dtb_order_dispatch_job_' . hash( 'sha256', $order_id . ':' . $hook . ':' . wp_json_encode( $args ) );
		if ( get_option( $receipt, false ) ) {
			continue;
		}
		// Action Scheduler's pending-action de-duplication protects concurrent
		// dispatch entry; the durable receipt prevents replay after completion.
		$scheduled = dtb_order_enqueue_job( $hook, $order_id, $args );
		if ( false !== $scheduled && 0 !== $scheduled ) {
			add_option( $receipt, current_time( 'mysql', true ), '', 'no' );
		} else {
			// Preserve a searchable error; no side effects are retried here.
			error_log( sprintf( '[DTB Orders] Dispatch scheduling failed: order %d, hook %s.', $order_id, $hook ) );
		}
	}
}
