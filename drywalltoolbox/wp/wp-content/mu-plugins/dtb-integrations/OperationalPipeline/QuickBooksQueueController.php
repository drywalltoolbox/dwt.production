<?php
/**
 * DTB Integrations — QuickBooks Queue Controller.
 *
 * Replaces manual QBO sync execution with queue-routed per-order sync jobs so
 * accounting writes go through dtb-order-platform events, retries, and state.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_operational_pipeline_register_qbo_queue_routes', 20 );

if ( ! function_exists( 'dtb_operational_pipeline_register_qbo_queue_routes' ) ) {
	/** Register the queue-backed replacement for POST /dtb/v1/qbo/sync. */
	function dtb_operational_pipeline_register_qbo_queue_routes(): void {
		register_rest_route(
			'dtb/v1',
			'/qbo/sync',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'dtb_operational_pipeline_qbo_rest_queue_sync',
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
			],
			true
		);
	}
}

if ( ! function_exists( 'dtb_operational_pipeline_queue_qbo_sync_orders' ) ) {
	/**
	 * Queue unsynced paid/fulfilled Woo orders for QuickBooks sync.
	 *
	 * @param int    $limit   Maximum orders to inspect.
	 * @param string $trigger Trigger label.
	 * @return array{queued:int,skipped:int,order_ids:int[],error:string}
	 */
	function dtb_operational_pipeline_queue_qbo_sync_orders( int $limit = 100, string $trigger = 'manual' ): array {
		if ( ! function_exists( 'dtb_qbo_exports_paused' ) || dtb_qbo_exports_paused() ) {
			return [ 'queued' => 0, 'skipped' => 0, 'order_ids' => [], 'error' => 'DTB QuickBooks exports are disabled; WooCommerce sales sync is managed by the Intuit connector.' ];
		}
		if ( ! function_exists( 'dtb_qbo_enabled' ) || ! dtb_qbo_enabled() ) {
			return [ 'queued' => 0, 'skipped' => 0, 'order_ids' => [], 'error' => 'QuickBooks integration is not configured.' ];
		}
		if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'dtb_order_enqueue_job' ) ) {
			return [ 'queued' => 0, 'skipped' => 0, 'order_ids' => [], 'error' => 'WooCommerce or DTB order queue is unavailable.' ];
		}

		$orders = wc_get_orders( [
			'status'     => [ 'processing', 'completed' ],
			'limit'      => max( 1, min( 250, $limit ) ),
			'return'     => 'ids',
			'orderby'    => 'date',
			'order'      => 'ASC',
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

		$queued_ids = [];
		$skipped    = 0;

		foreach ( (array) $orders as $order_id ) {
			$order_id = absint( $order_id );
			if ( $order_id <= 0 ) {
				$skipped++;
				continue;
			}

			$scheduled = dtb_order_enqueue_job( 'dtb_order_sync_quickbooks', $order_id, [ 'action' => 'create', 'trigger' => sanitize_key( $trigger ) ] );
			if ( false === $scheduled ) {
				$skipped++;
				continue;
			}

			$queued_ids[] = $order_id;
		}

		update_option( 'dtb_qbo_last_sync_queued_at', gmdate( 'c' ), false );
		update_option( 'dtb_qbo_last_sync_queued_count', count( $queued_ids ), false );

		if ( function_exists( 'dtb_ops_audit_log' ) ) {
			dtb_ops_audit_log( 'qbo_queue_sync_requested', [
				'trigger' => sanitize_key( $trigger ),
				'queued'  => count( $queued_ids ),
				'skipped' => $skipped,
			] );
		}

		return [ 'queued' => count( $queued_ids ), 'skipped' => $skipped, 'order_ids' => $queued_ids, 'error' => '' ];
	}
}

if ( ! function_exists( 'dtb_operational_pipeline_qbo_rest_queue_sync' ) ) {
	/**
	 * REST callback: queue unsynced orders for QBO instead of syncing inline.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	function dtb_operational_pipeline_qbo_rest_queue_sync( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$limit  = absint( $request->get_param( 'limit' ) ?: 100 );
		$result = dtb_operational_pipeline_queue_qbo_sync_orders( $limit, 'manual_rest' );

		if ( '' !== $result['error'] ) {
			return new WP_Error( 'qbo_queue_sync_unavailable', $result['error'], [ 'status' => 503 ] );
		}

		return new WP_REST_Response( [
			'queued'        => $result['queued'],
			'skipped'       => $result['skipped'],
			'order_ids'     => $result['order_ids'],
			'queued_at'     => get_option( 'dtb_qbo_last_sync_queued_at', null ),
			'execution'     => 'queued',
			'next_step'     => 'Process Action Scheduler/WP-Cron jobs in group dtb-orders.',
		], 202 );
	}
}
