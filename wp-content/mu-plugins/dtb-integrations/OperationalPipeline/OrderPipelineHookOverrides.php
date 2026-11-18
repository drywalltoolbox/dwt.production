<?php
/**
 * DTB Integrations — Order Pipeline Hook Overrides.
 *
 * Converts legacy direct Woo/Veeqo/QuickBooks side-effect hooks into queue-routed
 * orchestration so external writes remain observable, retryable, and idempotent.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

// Woo-to-Veeqo status writes are routed through the queued job below.
add_action( 'woocommerce_order_status_changed', 'dtb_operational_pipeline_route_veeqo_status_change', 20, 4 );

// QuickBooksClient.php registers a daily direct batch sync. Route the daily cron
// through dtb-order-platform per-order jobs instead, preserving event/audit state.
remove_action( 'dtb_qbo_daily_sync', 'dtb_qbo_cron_sync' );
add_action( 'dtb_qbo_daily_sync', 'dtb_operational_pipeline_qbo_daily_queue_sync' );

if ( ! function_exists( 'dtb_operational_pipeline_map_wc_to_veeqo_status' ) ) {
	/**
	 * Map WooCommerce order status to the Veeqo status payload.
	 *
	 * @param string   $wc_status Woo order status without wc- prefix.
	 * @param WC_Order $order     Order object.
	 * @return string|null
	 */
	function dtb_operational_pipeline_map_wc_to_veeqo_status( string $wc_status, WC_Order $order ): ?string {
		$tracking_number = trim( (string) ( $order->get_meta( '_tracking_number', true ) ?: $order->get_meta( '_dtb_veeqo_tracking_number', true ) ) );
		$completed_veeqo = '' !== $tracking_number ? 'shipped' : 'awaiting_fulfillment';

		$map = [
			'processing' => 'awaiting_fulfillment',
			'on-hold'    => 'awaiting_fulfillment',
			'completed'  => $completed_veeqo,
			'cancelled'  => 'cancelled',
			'refunded'   => 'refunded',
		];

		return $map[ sanitize_key( $wc_status ) ] ?? null;
	}
}

if ( ! function_exists( 'dtb_operational_pipeline_get_veeqo_order_id' ) ) {
	/**
	 * Resolve the Veeqo order ID from explicit job args or canonical/legacy order meta.
	 *
	 * @param WC_Order $order Woo order.
	 * @param array    $args  Optional job args.
	 * @return int
	 */
	function dtb_operational_pipeline_get_veeqo_order_id( WC_Order $order, array $args = [] ): int {
		$arg_id = absint( $args['veeqo_order_id'] ?? 0 );
		if ( $arg_id > 0 ) {
			return $arg_id;
		}

		$canonical = absint( $order->get_meta( '_dtb_veeqo_order_id', true ) );
		if ( $canonical > 0 ) {
			return $canonical;
		}

		return absint( $order->get_meta( '_veeqo_order_id', true ) );
	}
}

if ( ! function_exists( 'dtb_operational_pipeline_route_veeqo_status_change' ) ) {
	/**
	 * Queue Veeqo side effects when Woo order status changes.
	 *
	 * @param int      $order_id    Woo order ID.
	 * @param string   $old_status  Previous status.
	 * @param string   $new_status  New status.
	 * @param WC_Order $order       Woo order object.
	 */
	function dtb_operational_pipeline_route_veeqo_status_change( int $order_id, string $old_status, string $new_status, $order ): void {
		if ( ! $order instanceof WC_Order ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( function_exists( 'dtb_order_is_woopayments_test_order' ) && dtb_order_is_woopayments_test_order( $order ) ) {
			return;
		}
		if ( ! function_exists( 'dtb_veeqo_enabled' ) || ! dtb_veeqo_enabled() ) {
			return;
		}

		$veeqo_order_id = dtb_operational_pipeline_get_veeqo_order_id( $order );

		if ( $veeqo_order_id <= 0 ) {
			return;
		}

		$veeqo_status = dtb_operational_pipeline_map_wc_to_veeqo_status( $new_status, $order );
		if ( null === $veeqo_status ) {
			return;
		}

		if ( function_exists( 'dtb_order_enqueue_job' ) ) {
			dtb_order_enqueue_job( 'dtb_order_sync_veeqo_status', $order_id, [
				'trigger'        => 'wc_status_changed',
				'from_status'    => sanitize_key( $old_status ),
				'wc_status'      => sanitize_key( $new_status ),
				'veeqo_order_id' => $veeqo_order_id,
				'veeqo_status'   => $veeqo_status,
			] );
		}
	}
}

add_action( 'dtb_order_sync_veeqo_status', 'dtb_operational_pipeline_sync_veeqo_status_job', 10, 2 );

if ( ! function_exists( 'dtb_operational_pipeline_sync_veeqo_status_job' ) ) {
	/**
	 * Queue job: sync a Woo status transition to an already-created Veeqo order.
	 *
	 * @param int   $order_id Woo order ID.
	 * @param array $args     Job args.
	 */
	function dtb_operational_pipeline_sync_veeqo_status_job( int $order_id, array $args = [] ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( function_exists( 'dtb_order_is_woopayments_test_order' ) && dtb_order_is_woopayments_test_order( $order ) ) {
			return;
		}
		$attempt        = isset( $args['attempt'] ) ? max( 1, absint( $args['attempt'] ) ) : 1;
		$veeqo_order_id = dtb_operational_pipeline_get_veeqo_order_id( $order, $args );
		$veeqo_status   = sanitize_key( (string) ( $args['veeqo_status'] ?? '' ) );

		if ( $veeqo_order_id <= 0 || '' === $veeqo_status ) {
			if ( function_exists( 'dtb_order_update_integration_state' ) ) {
				dtb_order_update_integration_state( $order_id, 'veeqo', [
					'status'        => 'failed',
					'error'         => 'Cannot sync Veeqo status without Veeqo order ID and target status.',
					'retryable'     => false,
					'last_error_at' => current_time( 'mysql', true ),
				] );
			}
			return;
		}

		if ( ! function_exists( 'dtb_veeqo_request' ) ) {
			if ( function_exists( 'dtb_order_update_integration_state' ) ) {
				dtb_order_update_integration_state( $order_id, 'veeqo', [
					'status'        => 'failed',
					'order_id'      => $veeqo_order_id,
					'error'         => 'Veeqo request function is unavailable.',
					'retryable'     => false,
					'last_error_at' => current_time( 'mysql', true ),
				] );
			}
			return;
		}

		$result = dtb_veeqo_request( 'PUT', '/orders/' . $veeqo_order_id, [], [ 'status' => $veeqo_status ] );
		if ( ! empty( $result['ok'] ) ) {
			if ( function_exists( 'dtb_order_update_integration_state' ) ) {
				dtb_order_update_integration_state( $order_id, 'veeqo', [
					'status'          => 'synced',
					'order_id'        => $veeqo_order_id,
					'source_status'   => $veeqo_status,
					'last_success_at' => current_time( 'mysql', true ),
					'error'           => null,
				] );
			}
			if ( function_exists( 'dtb_order_append_event' ) ) {
				dtb_order_append_event( $order_id, 'integration.veeqo.status_synced', [
					'source'     => 'cron',
					'actor_type' => 'veeqo',
					'visibility' => 'operator',
					'payload'    => [ 'veeqo_order_id' => $veeqo_order_id, 'veeqo_status' => $veeqo_status ],
				] );
			}
			return;
		}

		$status_code  = (int) ( $result['status'] ?? 0 );
		$error        = sanitize_text_field( (string) ( $result['error'] ?? 'Veeqo status sync failed.' ) );
		$is_retryable = function_exists( 'dtb_order_integration_retryable_error' )
			? dtb_order_integration_retryable_error( $status_code, $error )
			: ! in_array( $status_code, [ 400, 401, 403, 404, 409, 410, 422 ], true );

		if ( function_exists( 'dtb_order_update_integration_state' ) ) {
			dtb_order_update_integration_state( $order_id, 'veeqo', [
				'status'        => 'failed',
				'order_id'      => $veeqo_order_id,
				'source_status' => $veeqo_status,
				'error'         => $error,
				'retryable'     => $is_retryable,
				'attempt'       => $attempt,
				'last_error_at' => current_time( 'mysql', true ),
			] );
		}
		if ( function_exists( 'dtb_order_append_event' ) ) {
			dtb_order_append_event( $order_id, 'integration.veeqo.status_failed', [
				'source'     => 'cron',
				'actor_type' => 'system',
				'visibility' => 'operator',
				'payload'    => [ 'status_code' => $status_code, 'retryable' => $is_retryable, 'attempt' => $attempt ],
			] );
		}

		if ( $is_retryable && function_exists( 'dtb_order_retry_job' ) ) {
			dtb_order_retry_job( 'dtb_order_sync_veeqo_status', $order_id, $args );
		}
	}
}

if ( ! function_exists( 'dtb_operational_pipeline_qbo_daily_queue_sync' ) ) {
	/**
	 * Daily QBO cron replacement: enqueue per-order sync jobs instead of syncing
	 * inline through the legacy batch function.
	 */
	function dtb_operational_pipeline_qbo_daily_queue_sync(): void {
		if ( ! function_exists( 'dtb_qbo_enabled' ) || ! dtb_qbo_enabled() || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$orders = wc_get_orders( [
			'status'     => [ 'processing', 'completed' ],
			'limit'      => 100,
			'return'     => 'ids',
			'meta_query' => [
				'relation' => 'AND',
				[
					'key'     => '_dtb_qbo_synced',
					'compare' => 'NOT EXISTS',
				],
				[
					'key'     => '_dtb_quickbooks_entity_id',
					'compare' => 'NOT EXISTS',
				],
			],
		] );

		$queued = 0;
		foreach ( (array) $orders as $order_id ) {
			$order_id = absint( $order_id );
			if ( $order_id <= 0 ) {
				continue;
			}
			if ( function_exists( 'dtb_order_enqueue_job' ) ) {
				dtb_order_enqueue_job( 'dtb_order_sync_quickbooks', $order_id, [ 'action' => 'create', 'trigger' => 'qbo_daily_queue' ] );
				$queued++;
			}
		}

		update_option( 'dtb_qbo_last_sync_queued_at', gmdate( 'c' ), false );
		update_option( 'dtb_qbo_last_sync_queued_count', $queued, false );

		if ( function_exists( 'dtb_ops_audit_log' ) ) {
			dtb_ops_audit_log( 'qbo_daily_queue_sync', [ 'queued' => $queued ] );
		}
	}
}
