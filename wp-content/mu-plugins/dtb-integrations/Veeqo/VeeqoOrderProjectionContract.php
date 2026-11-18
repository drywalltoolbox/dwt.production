<?php
/**
 * Canonical Veeqo order projection contract.
 *
 * Defined before OperationalPipeline/OrderIntegrationContracts.php so the
 * compatibility contract does not install its weaker API-key-only readiness
 * implementation. Execution still occurs only from the DTB order queue.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'dtb_veeqo_sync_order' ) ) {
	function dtb_veeqo_sync_order( int $order_id, WC_Order $order ): array {
		$readiness = function_exists( 'dtb_veeqo_production_readiness' )
			? dtb_veeqo_production_readiness()
			: [ 'ready' => false, 'missing' => [ 'production_configuration' ] ];

		if ( empty( $readiness['ready'] ) ) {
			$message = 'Veeqo order projection is not production-ready.';
			$missing = array_values( array_map( 'sanitize_key', (array) ( $readiness['missing'] ?? [] ) ) );
			if ( function_exists( 'dtb_order_integration_set_meta_state' ) ) {
				dtb_order_integration_set_meta_state( $order, 'veeqo', 'not_configured', [ 'error' => $message . ' Missing: ' . implode( ', ', $missing ) ] );
			}
			return [
				'status' => 'not_configured', 'veeqo_order_id' => null, 'tracking_number' => null,
				'carrier' => null, 'inventory_reserved' => false,
				'message' => $message . ( $missing ? ' Missing: ' . implode( ', ', $missing ) . '.' : '' ), 'retryable' => false,
			];
		}

		$existing_id = absint( $order->get_meta( '_dtb_veeqo_order_id', true ) ?: $order->get_meta( '_veeqo_order_id', true ) );
		if ( $existing_id > 0 ) {
			if ( function_exists( 'dtb_order_integration_set_meta_state' ) ) {
				dtb_order_integration_set_meta_state( $order, 'veeqo', 'already_synced', [ 'order_id' => $existing_id ] );
			}
			return [
				'status' => 'already_synced', 'veeqo_order_id' => $existing_id,
				'tracking_number' => (string) $order->get_meta( '_tracking_number', true ),
				'carrier' => (string) $order->get_meta( '_tracking_carrier', true ),
				'inventory_reserved' => true, 'message' => 'Order already has a Veeqo order ID.', 'retryable' => false,
			];
		}

		if ( ! function_exists( 'dtb_order_integration_acquire_lock' ) || ! dtb_order_integration_acquire_lock( 'veeqo', $order_id ) ) {
			return [ 'status' => 'locked', 'veeqo_order_id' => null, 'tracking_number' => null, 'carrier' => null, 'inventory_reserved' => false, 'message' => 'A Veeqo sync is already in progress for this order.', 'retryable' => true ];
		}

		$correlation_key = 'veeqo-order:' . $order_id . ':v1';
		try {
			if ( ! function_exists( 'dtb_veeqo_build_order_payload' ) || ! function_exists( 'dtb_veeqo_request' ) ) {
				throw new DTB_Order_Integration_Exception( 'Veeqo order sync functions are unavailable.', false, 0 );
			}

			$order->update_meta_data( '_dtb_veeqo_correlation_key', $correlation_key );
			$order->save_meta_data();
			$payload = dtb_veeqo_build_order_payload( $order );
			if ( null === $payload ) {
				throw new DTB_Order_Integration_Exception( 'Veeqo order payload could not be built. Check configuration, line-item SKUs, sellable mappings, and shipping address.', false, 422 );
			}

			// Preserve the human/operator-visible Woo order number in Veeqo. The
			// request helper derives its POST /orders Idempotency-Key from this stable
			// channel_order_number; the DTB correlation key is persisted separately.
			if ( empty( $payload['order']['channel_order_number'] ) ) {
				$payload['order']['channel_order_number'] = ltrim( (string) $order->get_order_number(), '#' );
			}
			$result = dtb_veeqo_request( 'POST', '/orders', [], $payload );
			if ( empty( $result['ok'] ) ) {
				$status = (int) ( $result['status'] ?? 0 );
				$error  = sanitize_text_field( (string) ( $result['error'] ?? 'Veeqo API error.' ) );
				$retryable = function_exists( 'dtb_order_integration_retryable_error' )
					? dtb_order_integration_retryable_error( $status, $error )
					: ( 0 === $status || 408 === $status || 425 === $status || 429 === $status || $status >= 500 );
				if ( function_exists( 'dtb_veeqo_log' ) ) {
					dtb_veeqo_log( 'error', 'order_create_rejected', 'Veeqo rejected queued Woo order projection.', [
						'wc_order_id' => $order_id, 'status' => $status, 'error' => $error,
						'payload' => function_exists( 'dtb_veeqo_order_payload_diagnostics' ) ? dtb_veeqo_order_payload_diagnostics( $payload ) : [],
					] );
				}
				throw new DTB_Order_Integration_Exception( $error, $retryable, $status );
			}

			$data     = is_array( $result['data'] ?? null ) ? $result['data'] : [];
			$veeqo_id = absint( $data['id'] ?? $data['order']['id'] ?? 0 );
			if ( $veeqo_id <= 0 ) {
				throw new DTB_Order_Integration_Exception( 'Veeqo API response did not include an order ID.', false, 502 );
			}

			$order->update_meta_data( '_veeqo_order_id', $veeqo_id );
			$order->update_meta_data( '_dtb_veeqo_order_id', $veeqo_id );
			if ( function_exists( 'dtb_order_integration_set_meta_state' ) ) {
				dtb_order_integration_set_meta_state( $order, 'veeqo', 'synced', [ 'order_id' => $veeqo_id ] );
			} else {
				$order->save_meta_data();
			}
			if ( class_exists( 'DTB_VeeqoSyncJob' ) ) {
				DTB_VeeqoSyncJob::log_timestamp( 'order_queue' );
			}
			return [ 'status' => 'synced', 'veeqo_order_id' => $veeqo_id, 'tracking_number' => null, 'carrier' => null, 'inventory_reserved' => true, 'message' => 'Order synced to Veeqo.', 'retryable' => false ];
		} catch ( DTB_Order_Integration_Exception $e ) {
			if ( function_exists( 'dtb_order_integration_set_meta_state' ) ) {
				dtb_order_integration_set_meta_state( $order, 'veeqo', 'failed', [ 'error' => $e->getMessage() ] );
			}
			throw $e;
		} finally {
			if ( function_exists( 'dtb_order_integration_release_lock' ) ) {
				dtb_order_integration_release_lock( 'veeqo', $order_id );
			}
		}
	}
}
