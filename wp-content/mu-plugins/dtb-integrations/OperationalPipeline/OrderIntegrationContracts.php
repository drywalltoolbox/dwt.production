<?php
/**
 * DTB Integrations — Order Integration Contracts.
 *
 * Normalizes the function contracts consumed by dtb-order-platform so Veeqo and
 * QuickBooks can be orchestrated through the order event/queue pipeline instead
 * of ad-hoc integration-specific hooks.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_Order_Integration_Exception' ) ) {
	final class DTB_Order_Integration_Exception extends RuntimeException {
		private bool $retryable;
		private int $status_code;

		public function __construct( string $message, bool $retryable = false, int $status_code = 0 ) {
			parent::__construct( $message, $status_code );
			$this->retryable   = $retryable;
			$this->status_code = $status_code;
		}

		public function is_retryable(): bool {
			return $this->retryable;
		}

		public function status_code(): int {
			return $this->status_code;
		}
	}
}

if ( ! function_exists( 'dtb_order_integration_retryable_error' ) ) {
	function dtb_order_integration_retryable_error( int $status_code = 0, string $message = '' ): bool {
		if ( in_array( $status_code, [ 400, 401, 403, 404, 409, 410, 422 ], true ) ) {
			return false;
		}

		if ( 0 === $status_code || 408 === $status_code || 425 === $status_code || 429 === $status_code || $status_code >= 500 ) {
			return true;
		}

		return ! preg_match( '/\b(400|401|403|404|409|410|422)\b/', $message );
	}
}

if ( ! function_exists( 'dtb_order_integration_lock_key' ) ) {
	function dtb_order_integration_lock_key( string $system, int $order_id ): string {
		return 'dtb_' . sanitize_key( $system ) . '_order_sync_lock_' . max( 0, $order_id );
	}
}

if ( ! function_exists( 'dtb_order_integration_acquire_lock' ) ) {
	function dtb_order_integration_acquire_lock( string $system, int $order_id, int $ttl = 300 ): bool {
		$key = dtb_order_integration_lock_key( $system, $order_id );
		if ( get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, wp_generate_uuid4(), max( 30, $ttl ) );
		return true;
	}
}

if ( ! function_exists( 'dtb_order_integration_release_lock' ) ) {
	function dtb_order_integration_release_lock( string $system, int $order_id ): void {
		delete_transient( dtb_order_integration_lock_key( $system, $order_id ) );
	}
}

if ( ! function_exists( 'dtb_order_integration_set_meta_state' ) ) {
	function dtb_order_integration_set_meta_state( WC_Order $order, string $system, string $status, array $data = [] ): void {
		$system = sanitize_key( $system );
		$now    = current_time( 'mysql', true );

		$order->update_meta_data( '_dtb_' . $system . '_sync_status', sanitize_key( $status ) );
		$order->update_meta_data( '_dtb_' . $system . '_last_sync_attempt_at', $now );

		if ( in_array( $status, [ 'synced', 'already_synced', 'skipped' ], true ) ) {
			$order->update_meta_data( '_dtb_' . $system . '_last_synced_at', $now );
			$order->delete_meta_data( '_dtb_' . $system . '_sync_error' );
		}

		if ( isset( $data['error'] ) && '' !== (string) $data['error'] ) {
			$order->update_meta_data( '_dtb_' . $system . '_sync_error', substr( sanitize_text_field( (string) $data['error'] ), 0, 1000 ) );
		}

		foreach ( [ 'entity_id', 'order_id', 'tracking_number', 'receipt_id', 'invoice_id' ] as $field ) {
			if ( isset( $data[ $field ] ) && null !== $data[ $field ] && '' !== (string) $data[ $field ] ) {
				$order->update_meta_data( '_dtb_' . $system . '_' . $field, sanitize_text_field( (string) $data[ $field ] ) );
			}
		}

		$order->save_meta_data();
	}
}

if ( ! function_exists( 'dtb_veeqo_sync_order' ) ) {
	/**
	 * Canonical order-platform contract: sync one WooCommerce order to Veeqo.
	 *
	 * @param int      $order_id Woo order ID.
	 * @param WC_Order $order    Woo order object.
	 * @return array{status:string,veeqo_order_id:int|null,tracking_number:string|null,carrier:string|null,inventory_reserved:bool,message:string,retryable:bool}
	 * @throws DTB_Order_Integration_Exception On sync failure.
	 */
	function dtb_veeqo_sync_order( int $order_id, WC_Order $order ): array {
		if ( ! function_exists( 'dtb_veeqo_enabled' ) || ! dtb_veeqo_enabled() ) {
			dtb_order_integration_set_meta_state( $order, 'veeqo', 'not_configured' );
			return [ 'status' => 'not_configured', 'veeqo_order_id' => null, 'tracking_number' => null, 'carrier' => null, 'inventory_reserved' => false, 'message' => 'Veeqo is not configured.', 'retryable' => false ];
		}

		$existing_id = absint( $order->get_meta( '_dtb_veeqo_order_id', true ) ?: $order->get_meta( '_veeqo_order_id', true ) );
		$correlation_key = 'veeqo-order:' . $order_id . ':v1';
		if ( $existing_id > 0 ) {
			dtb_order_integration_set_meta_state( $order, 'veeqo', 'already_synced', [ 'order_id' => $existing_id ] );
			return [ 'status' => 'already_synced', 'veeqo_order_id' => $existing_id, 'tracking_number' => (string) $order->get_meta( '_tracking_number', true ), 'carrier' => (string) $order->get_meta( '_tracking_carrier', true ), 'inventory_reserved' => true, 'message' => 'Order already has a Veeqo order ID.', 'retryable' => false ];
		}

		if ( ! dtb_order_integration_acquire_lock( 'veeqo', $order_id ) ) {
			return [ 'status' => 'locked', 'veeqo_order_id' => null, 'tracking_number' => null, 'carrier' => null, 'inventory_reserved' => false, 'message' => 'A Veeqo sync is already in progress for this order.', 'retryable' => true ];
		}

		try {
			if ( ! function_exists( 'dtb_veeqo_build_order_payload' ) || ! function_exists( 'dtb_veeqo_request' ) ) {
				throw new DTB_Order_Integration_Exception( 'Veeqo order sync functions are unavailable.', false, 0 );
			}

			$order->update_meta_data( '_dtb_veeqo_correlation_key', $correlation_key );
			$order->save_meta_data();
			$payload = dtb_veeqo_build_order_payload( $order );
			if ( null === $payload ) {
				throw new DTB_Order_Integration_Exception( 'Veeqo order payload could not be built. Check order line items, SKUs, and shipping address.', false, 422 );
			}

			$payload['order']['channel_order_number'] = $correlation_key;
			if ( $existing_id > 0 ) {
				$result = dtb_veeqo_request( 'PUT', '/orders/' . $existing_id, [], $payload );
			} else {
				$result = dtb_veeqo_request( 'POST', '/orders', [], $payload );
			}
			if ( empty( $result['ok'] ) ) {
				$status = (int) ( $result['status'] ?? 0 );
				$error  = sanitize_text_field( (string) ( $result['error'] ?? 'Veeqo API error.' ) );
				$diagnostics = function_exists( 'dtb_veeqo_order_payload_diagnostics' )
					? dtb_veeqo_order_payload_diagnostics( $payload )
					: [];
				if ( function_exists( 'dtb_veeqo_log' ) ) {
					dtb_veeqo_log( 'error', 'order_create_rejected', 'Veeqo rejected Woo order create request.', [
						'wc_order_id' => $order_id,
						'status'      => $status,
						'error'       => $error,
						'payload'     => $diagnostics,
					] );
				}
				if ( 404 === $status ) {
					$error .= ' Verify Veeqo Channel ID, Delivery Method ID, Warehouse ID, and sellable IDs in the payload diagnostics.';
				}
				throw new DTB_Order_Integration_Exception( $error, dtb_order_integration_retryable_error( $status, $error ), $status );
			}

			$data     = is_array( $result['data'] ?? null ) ? $result['data'] : [];
			$veeqo_id = $existing_id > 0 ? $existing_id : absint( $data['id'] ?? $data['order']['id'] ?? 0 );
			if ( $veeqo_id <= 0 ) {
				throw new DTB_Order_Integration_Exception( 'Veeqo API response did not include an order ID.', false, 502 );
			}

			$order->update_meta_data( '_veeqo_order_id', $veeqo_id );
			$order->update_meta_data( '_dtb_veeqo_order_id', $veeqo_id );
			dtb_order_integration_set_meta_state( $order, 'veeqo', 'synced', [ 'order_id' => $veeqo_id ] );

			if ( function_exists( 'dtb_veeqo_log' ) ) {
				dtb_veeqo_log( 'info', 'order_synced_pipeline', 'Order synced to Veeqo through DTB order queue.', [ 'wc_order_id' => $order_id, 'veeqo_order_id' => $veeqo_id ] );
			}
			if ( class_exists( 'DTB_VeeqoSyncJob' ) ) {
				DTB_VeeqoSyncJob::log_timestamp( 'order_queue' );
			}

			return [ 'status' => $existing_id > 0 ? 'updated' : 'synced', 'veeqo_order_id' => $veeqo_id, 'tracking_number' => null, 'carrier' => null, 'inventory_reserved' => true, 'message' => $existing_id > 0 ? 'Existing Veeqo order reconciled.' : 'Order synced to Veeqo.', 'retryable' => false ];
		} catch ( DTB_Order_Integration_Exception $e ) {
			dtb_order_integration_set_meta_state( $order, 'veeqo', 'failed', [ 'error' => $e->getMessage() ] );
			throw $e;
		} finally {
			dtb_order_integration_release_lock( 'veeqo', $order_id );
		}
	}
}

if ( ! function_exists( 'dtb_quickbooks_sync_order' ) ) {
	/**
	 * Canonical order-platform contract: sync one WooCommerce order to QuickBooks.
	 *
	 * @param int      $order_id Woo order ID.
	 * @param WC_Order $order    Woo order object.
	 * @param string   $action   create|refund.
	 * @return array{status:string,entity_id:string|null,entity_type:string,message:string,retryable:bool}
	 * @throws DTB_Order_Integration_Exception On sync failure.
	 */
	function dtb_quickbooks_sync_order( int $order_id, WC_Order $order, string $action = 'create' ): array {
		$action = sanitize_key( $action ?: 'create' );

		if ( ! function_exists( 'dtb_qbo_enabled' ) || ! dtb_qbo_enabled() ) {
			dtb_order_integration_set_meta_state( $order, 'quickbooks', 'not_configured' );
			return [ 'status' => 'not_configured', 'entity_id' => null, 'entity_type' => '', 'message' => 'QuickBooks is not configured.', 'retryable' => false ];
		}

		if ( 'refund' === $action && ! function_exists( 'dtb_qbo_sync_refund' ) ) {
			dtb_order_integration_set_meta_state( $order, 'quickbooks', 'skipped', [ 'error' => 'QuickBooks refund sync is not implemented yet.' ] );
			return [ 'status' => 'skipped', 'entity_id' => null, 'entity_type' => 'refund', 'message' => 'QuickBooks refund sync is not implemented yet.', 'retryable' => false ];
		}

		$existing_id = (string) ( $order->get_meta( '_dtb_quickbooks_entity_id', true ) ?: $order->get_meta( '_dtb_qbo_receipt_id', true ) ?: $order->get_meta( '_dtb_quickbooks_invoice_id', true ) );
		if ( '' !== $existing_id && 'create' === $action ) {
			dtb_order_integration_set_meta_state( $order, 'quickbooks', 'already_synced', [ 'entity_id' => $existing_id ] );
			return [ 'status' => 'already_synced', 'entity_id' => $existing_id, 'entity_type' => 'sales_receipt', 'message' => 'Order already has a QuickBooks entity ID.', 'retryable' => false ];
		}

		if ( ! dtb_order_integration_acquire_lock( 'quickbooks', $order_id ) ) {
			return [ 'status' => 'locked', 'entity_id' => null, 'entity_type' => '', 'message' => 'A QuickBooks sync is already in progress for this order.', 'retryable' => true ];
		}

		try {
			if ( 'refund' === $action && function_exists( 'dtb_qbo_sync_refund' ) ) {
				$result = dtb_qbo_sync_refund( $order );
			} elseif ( function_exists( 'dtb_qbo_sync_order' ) ) {
				$result = dtb_qbo_sync_order( $order );
			} else {
				throw new DTB_Order_Integration_Exception( 'QuickBooks sync functions are unavailable.', false, 0 );
			}

			if ( is_wp_error( $result ) ) {
				$code    = (string) $result->get_error_code();
				$message = $result->get_error_message();
				if ( 'already_synced' === $code ) {
					$entity_id = (string) ( $order->get_meta( '_dtb_quickbooks_entity_id', true ) ?: $order->get_meta( '_dtb_qbo_receipt_id', true ) );
					return [ 'status' => 'already_synced', 'entity_id' => $entity_id ?: null, 'entity_type' => 'sales_receipt', 'message' => $message, 'retryable' => false ];
				}
				// 'qbo_not_connected' is the actual code dtb_qbo_sync_order()/dtb_qbo_sync_refund()
				// return (see QuickBooksAccountingPipeline.php) — this previously read
				// 'qbo_not_configured', a code that error path never emits, so a
				// not-connected failure reaching this branch was incorrectly marked
				// retryable instead of terminal.
				throw new DTB_Order_Integration_Exception( $message, ! in_array( $code, [ 'no_line_items', 'qbo_not_connected', 'already_synced' ], true ), 0 );
			}

			$data      = is_array( $result ) ? $result : [];
			$entity_id = (string) ( $data['SalesReceipt']['Id'] ?? $data['Invoice']['Id'] ?? $data['RefundReceipt']['Id'] ?? $order->get_meta( '_dtb_qbo_receipt_id', true ) ?? '' );
			$type      = isset( $data['RefundReceipt'] ) ? 'refund_receipt' : ( isset( $data['Invoice'] ) ? 'invoice' : 'sales_receipt' );

			if ( '' !== $entity_id ) {
				$order->update_meta_data( '_dtb_quickbooks_entity_id', $entity_id );
				$order->update_meta_data( '_dtb_quickbooks_entity_type', $type );
			}

			dtb_order_integration_set_meta_state( $order, 'quickbooks', 'synced', [ 'entity_id' => $entity_id, 'receipt_id' => $entity_id ] );

			return [ 'status' => 'synced', 'entity_id' => $entity_id ?: null, 'entity_type' => $type, 'message' => 'Order synced to QuickBooks.', 'retryable' => false ];
		} catch ( DTB_Order_Integration_Exception $e ) {
			dtb_order_integration_set_meta_state( $order, 'quickbooks', 'failed', [ 'error' => $e->getMessage() ] );
			throw $e;
		} finally {
			dtb_order_integration_release_lock( 'quickbooks', $order_id );
		}
	}
}
