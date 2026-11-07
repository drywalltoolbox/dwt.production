<?php
/**
 * Veeqo → WooCommerce fulfillment-status application (single source of truth).
 *
 * Given a WooCommerce order, a Veeqo order status string, and optional tracking
 * info, this module maps the Veeqo status to a WooCommerce order status and
 * applies it idempotently. It intentionally knows nothing about *how* the
 * Veeqo status was learned — both the (currently disabled) webhook receiver
 * in OperationalPipeline/VeeqoWebhookPipelineController.php and the
 * VeeqoOrderStatusPoller.php polling job call into this file so the
 * status→WooCommerce mapping and side effects (order status transition,
 * tracking meta, order event/notification, rank bookkeeping) are defined
 * exactly once.
 *
 * Veeqo order-status vocabulary (verified 2026-07-30):
 *   - https://developers.veeqo.com/resources/order_statuses
 *     lists: awaiting_payment, awaiting_stock, awaiting_fulfillment, shipped,
 *     on_hold, cancelled, refunded.
 *   - https://github.com/VeeqoAPI/api-docs/blob/master/resources/orders.md
 *     corroborates the same enum on the Order resource's `status` field.
 *   - https://developers.veeqo.com/api/operations/view-tracking-events/
 *     (`GET /shipping/tracking_events/{shipment_id}`) documents a *separate*,
 *     shipment-level tracking-event vocabulary: awaiting_collection,
 *     in_transit, out_for_delivery, delivered. This is NOT the same field as
 *     the order-level `status` and is not polled by this module: correlating
 *     a Veeqo shipment_id from the order payload well enough to safely call
 *     that endpoint was not confirmed from the available docs, so no
 *     Veeqo status is currently mapped to WooCommerce `completed`. Reaching
 *     `completed` remains an explicit operator action (or a future, verified
 *     enhancement here) rather than an automatic poll/webhook outcome.
 *
 * The historical webhook code (before this refactor) assumed a *different*,
 * unverified vocabulary (awaiting_fulfillment, allocated, printed, shipped) —
 * that assumption is corrected here, since it was never validated against a
 * real Veeqo payload (Veeqo has no webhook support to validate against).
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Veeqo mirrors fulfillment state; it is never the authority for the original
 * paid-order confirmation email.
 *
 * The status update below is deliberately wrapped in a short-lived transient.
 * Suppressing only while that marker is present prevents a Veeqo poll/webhook
 * from re-sending WooCommerce's customer processing email while preserving the
 * legitimate email sent by the payment/checkout lifecycle.
 */
add_filter( 'woocommerce_email_enabled_customer_processing_order', 'dtb_veeqo_suppress_processing_email_during_fulfillment_sync', -100, 3 );
function dtb_veeqo_suppress_processing_email_during_fulfillment_sync( bool $enabled, $order, $email = null ): bool {
	if ( ! $enabled || ! $order instanceof WC_Order ) {
		return $enabled;
	}

	return get_transient( 'dtb_veeqo_webhook_updating_order_' . (int) $order->get_id() )
		? false
		: $enabled;
}

/**
 * Monotonic fulfillment rank per confirmed Veeqo order status.
 *
 * Only statuses confirmed against the docs above are ranked. Anything else
 * (including Veeqo's `on_hold`, whose interaction with an in-progress
 * WooCommerce fulfillment is not documented clearly enough to act on safely)
 * returns 0, which callers treat as "unmapped — leave alone, log it".
 *
 * cancelled/refunded intentionally outrank shipped so a late-arriving
 * cancellation/refund always wins over an earlier shipped rank, even though
 * they are not "further along" in a shipping sense.
 */
function dtb_veeqo_order_status_rank( string $status ): int {
	return [
		'awaiting_stock'       => 10,
		'awaiting_fulfillment' => 20,
		'shipped'              => 40,
		'cancelled'            => 90,
		'refunded'             => 100,
	][ sanitize_key( $status ) ] ?? 0;
}

/**
 * Map a confirmed Veeqo order status to a WooCommerce order status.
 *
 * Returns '' for anything not confidently mapped (including `awaiting_payment`
 * and `on_hold`, which are deliberately left as explicit no-ops rather than
 * guessed at) — see the file header for why.
 */
function dtb_veeqo_order_status_to_wc_status( string $status ): string {
	return [
		'awaiting_stock'       => 'processing',
		'awaiting_fulfillment' => 'processing',
		'shipped'              => 'shipped',
		'cancelled'            => 'cancelled',
		'refunded'             => 'refunded',
	][ sanitize_key( $status ) ] ?? '';
}

/**
 * Apply a known Veeqo order status (and optional tracking info) to a
 * WooCommerce order, idempotently.
 *
 * Idempotency is keyed off the computed rank (and, for events, the tracking
 * number), not off a per-call reference — this module is invoked repeatedly
 * on a short poll interval for orders whose Veeqo status has not changed, and
 * must not re-transition, re-notify, or re-log on every no-op poll.
 *
 * @param WC_Order $order        The WooCommerce order to update.
 * @param string   $veeqo_status Raw Veeqo order status string (e.g. 'shipped').
 * @param array    $context {
 *     Optional context.
 *
 *     @type string $source           'webhook' or 'poll'. Used only for logging/attribution.
 *     @type string $reference        Free-form reference for the order note/event (event hash, poll run id, ...).
 *     @type int    $veeqo_order_id   Veeqo order id, persisted onto the order for future correlation.
 *     @type string $tracking_number  Tracking number, if known.
 *     @type string $tracking_carrier Tracking carrier, if known.
 *     @type string $correlation_key  Opaque correlation key to persist (e.g. 'veeqo-order:123').
 * }
 * @return array{applied:bool,result:string,wc_status:string,rank:int,veeqo_status:string}
 */
function dtb_veeqo_apply_order_fulfillment_status( WC_Order $order, string $veeqo_status, array $context = [] ): array {
	$veeqo_status = sanitize_key( $veeqo_status );
	$rank         = dtb_veeqo_order_status_rank( $veeqo_status );
	$wc_status    = dtb_veeqo_order_status_to_wc_status( $veeqo_status );

	if ( '' === $wc_status || $rank <= 0 ) {
		return [
			'applied'      => false,
			'result'       => 'unmapped_status',
			'wc_status'    => '',
			'rank'         => $rank,
			'veeqo_status' => $veeqo_status,
		];
	}

	$order_id         = (int) $order->get_id();
	$source           = sanitize_key( (string) ( $context['source'] ?? 'unknown' ) );
	$reference        = sanitize_text_field( (string) ( $context['reference'] ?? '' ) );
	$veeqo_order_id   = absint( $context['veeqo_order_id'] ?? 0 );
	$tracking_number  = sanitize_text_field( (string) ( $context['tracking_number'] ?? '' ) );
	$tracking_carrier = sanitize_text_field( (string) ( $context['tracking_carrier'] ?? '' ) );
	$correlation_key  = sanitize_text_field( (string) ( $context['correlation_key'] ?? '' ) );

	$current_rank              = absint( $order->get_meta( '_dtb_veeqo_fulfillment_rank', true ) );
	$previous_tracking_number  = (string) $order->get_meta( '_dtb_veeqo_tracking_number', true );
	$previous_tracking_carrier = (string) $order->get_meta( '_dtb_veeqo_tracking_carrier', true );
	$previous_status           = (string) $order->get_status();

	if ( $rank < $current_rank ) {
		if ( function_exists( 'dtb_order_append_event' ) ) {
			dtb_order_append_event( $order_id, 'integration.veeqo.status_stale', [
				'source'          => 'veeqo_' . $source,
				'actor_type'      => 'veeqo',
				'visibility'      => 'operator',
				'idempotency_key' => sprintf( 'veeqo-status-stale:%d:%d:%s', $order_id, $rank, $reference ?: 'norev' ),
				'payload'         => [
					'veeqo_status'  => $veeqo_status,
					'current_rank'  => $current_rank,
					'incoming_rank' => $rank,
					'source'        => $source,
				],
			] );
		}
		return [
			'applied'      => false,
			'result'       => 'stale',
			'wc_status'    => $wc_status,
			'rank'         => $rank,
			'veeqo_status' => $veeqo_status,
		];
	}

	if ( function_exists( 'dtb_checkout_handoff_is_unpaid_order' ) && dtb_checkout_handoff_is_unpaid_order( $order ) && in_array( $wc_status, [ 'processing', 'shipped', 'completed' ], true ) ) {
		return [
			'applied'      => false,
			'result'       => 'unpaid_order',
			'wc_status'    => $wc_status,
			'rank'         => $rank,
			'veeqo_status' => $veeqo_status,
		];
	}

	$rank_changed             = $rank > $current_rank;
	$tracking_changed         = '' !== $tracking_number && $tracking_number !== $previous_tracking_number;
	$tracking_carrier_changed = '' !== $tracking_carrier && $tracking_carrier !== $previous_tracking_carrier;
	$identity_changed         = ( $veeqo_order_id > 0 && $veeqo_order_id !== absint( $order->get_meta( '_dtb_veeqo_order_id', true ) ) )
		|| ( '' !== $correlation_key && $correlation_key !== (string) $order->get_meta( '_dtb_veeqo_correlation_key', true ) );

	/*
	 * A same-rank poll is not allowed to restore a WooCommerce status. Woo owns
	 * payment/order lifecycle state and Veeqo owns fulfillment state. This is
	 * the critical monotonicity rule that keeps a recurring poll from causing
	 * repeated processing transitions and customer emails.
	 */
	$status_changed = $rank_changed && $previous_status !== $wc_status;

	if ( ! $rank_changed && ! $tracking_changed && ! $tracking_carrier_changed && ! $identity_changed ) {
		return [
			'applied'        => false,
			'result'         => 'no_change',
			'wc_status'      => $wc_status,
			'rank'           => $rank,
			'veeqo_status'   => $veeqo_status,
			'status_changed' => false,
			'rank_changed'   => false,
		];
	}

	if ( $veeqo_order_id > 0 ) {
		$order->update_meta_data( '_dtb_veeqo_order_id', $veeqo_order_id );
		$order->update_meta_data( '_veeqo_order_id', $veeqo_order_id );
	}
	if ( $rank_changed ) {
		$order->update_meta_data( '_dtb_veeqo_fulfillment_rank', $rank );
	}
	if ( '' !== $correlation_key ) {
		$order->update_meta_data( '_dtb_veeqo_correlation_key', $correlation_key );
	}
	if ( '' !== $tracking_number ) {
		$order->update_meta_data( '_tracking_number', $tracking_number );
		$order->update_meta_data( '_dtb_veeqo_tracking_number', $tracking_number );
	}
	if ( '' !== $tracking_carrier ) {
		$order->update_meta_data( '_tracking_carrier', $tracking_carrier );
		$order->update_meta_data( '_dtb_veeqo_tracking_carrier', $tracking_carrier );
	}
	$order->save_meta_data();

	if ( $status_changed ) {
		// Same transient key the (dead) webhook path used, so any other code
		// still gating off it during this refactor continues to behave.
		set_transient( 'dtb_veeqo_webhook_updating_order_' . $order_id, '1', 60 );
		try {
			$order->update_status(
				$wc_status,
				sprintf( '[Veeqo] Status updated via %s (Veeqo status: %s).', $source, $veeqo_status ?: 'unknown' )
			);
		} finally {
			delete_transient( 'dtb_veeqo_webhook_updating_order_' . $order_id );
		}
	}

	if ( function_exists( 'dtb_order_update_integration_state' ) ) {
		dtb_order_update_integration_state( $order_id, 'veeqo', [
			'status'         => 'synced',
			'order_id'       => $veeqo_order_id ?: null,
			'source_status'  => $veeqo_status,
			'tracking'       => $tracking_number ?: null,
			'carrier'        => $tracking_carrier ?: null,
			'last_success_at' => current_time( 'mysql', true ),
			'error'          => null,
		] );
	}

	if ( ( $rank_changed || $tracking_changed || $tracking_carrier_changed || $identity_changed ) && function_exists( 'dtb_order_append_event' ) ) {
		dtb_order_append_event( $order_id, 'integration.veeqo.status_applied', [
			'source'          => 'veeqo_' . $source,
			'actor_type'      => 'veeqo',
			'visibility'      => 'operator',
			'idempotency_key' => sprintf( 'veeqo-status-applied:%d:%d:%s', $order_id, $rank, $tracking_number ?: 'no-track' ),
			'payload'         => [
				'veeqo_status'    => $veeqo_status,
				'wc_status'       => $wc_status,
				'veeqo_order_id'  => $veeqo_order_id ?: null,
				'tracking_number' => $tracking_number ?: null,
				'source'          => $source,
				'reference'       => $reference ?: null,
			],
		] );
	}

	if ( $rank_changed && 'shipped' === $wc_status ) {
		dtb_veeqo_dispatch_shipped_notification( $order, $context );
	}

	if ( ( $rank_changed || $tracking_changed || $tracking_carrier_changed ) && function_exists( 'dtb_order_enqueue_job' ) ) {
		dtb_order_enqueue_job( 'dtb_order_refresh_tracking_projection', $order_id );
	}

	return [
		'applied'       => true,
		'result'        => 'processed',
		'wc_status'     => $wc_status,
		'rank'          => $rank,
		'veeqo_status'  => $veeqo_status,
		'status_changed' => $status_changed,
		'rank_changed'  => $rank_changed,
	];
}

/**
 * Notify the customer that an order has shipped, preferring the native
 * WooCommerce Fulfillment projection and falling back to the legacy ad-hoc
 * notification exactly when the native path does not (yet) own this
 * shipment. Never both, never neither.
 *
 * @param WC_Order $order   Woo order.
 * @param array    $context Same context passed to dtb_veeqo_apply_order_fulfillment_status().
 * @return void
 */
function dtb_veeqo_dispatch_shipped_notification( WC_Order $order, array $context ): void {
	$native_notified = false;

	if ( function_exists( 'dtb_veeqo_project_order_shipments' ) ) {
		$veeqo_payload  = is_array( $context['veeqo_payload'] ?? null ) ? $context['veeqo_payload'] : [];
		$veeqo_order_id = absint( $context['veeqo_order_id'] ?? 0 );

		if ( $veeqo_order_id > 0 ) {
			$projection       = dtb_veeqo_project_order_shipments( $order, $veeqo_payload, $veeqo_order_id );
			$native_notified  = ! empty( $projection['native_notified'] );
		}
	}

	if ( $native_notified ) {
		return;
	}

	if ( function_exists( 'dtb_order_enqueue_job' ) ) {
		dtb_order_enqueue_job( 'dtb_order_send_notification', (int) $order->get_id(), [ 'template' => 'order-shipped' ] );
	}
}
