<?php
/**
 * DTB Integrations — QuickBooks Job Override.
 *
 * Replaces the default dtb-order-platform QuickBooks queue handler with the
 * hardened accounting pipeline when available. Accounting writes remain behind
 * queue semantics and refund jobs are keyed to concrete WooCommerce refund IDs.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

remove_action( 'dtb_order_sync_quickbooks', 'dtb_order_job_sync_quickbooks', 10 );
add_action( 'dtb_order_sync_quickbooks', 'dtb_operational_pipeline_job_sync_quickbooks', 10, 2 );

if ( ! function_exists( 'dtb_operational_pipeline_qbo_retryable_error' ) ) {
	function dtb_operational_pipeline_qbo_retryable_error( WP_Error|Throwable|string $error ): bool {
		if ( $error instanceof WP_Error ) {
			$data = $error->get_error_data();
			if ( is_array( $data ) && array_key_exists( 'retryable', $data ) ) {
				return (bool) $data['retryable'];
			}
			$code = (string) $error->get_error_code();
			if ( in_array( $code, [
				'already_synced',
				'no_line_items',
				'no_refund_total',
				'invalid_refund',
				'payment_not_captured',
				'qbo_not_connected',
				'qbo_reference_missing',
				'qbo_customer_failed',
				'qbo_invalid_entity',
				'qbo_duplicate_ambiguous',
				'qbo_invalid_success',
			], true ) ) {
				return false;
			}
			if ( in_array( $code, [ 'qbo_locked', 'qbo_refresh_locked' ], true ) ) {
				return true;
			}
		}

		$message = $error instanceof WP_Error ? $error->get_error_message() : ( $error instanceof Throwable ? $error->getMessage() : (string) $error );
		if ( preg_match( '/\b(400|401|403|404|409|410|422)\b/', $message ) ) {
			return false;
		}
		return true;
	}
}

if ( ! function_exists( 'dtb_operational_pipeline_job_sync_quickbooks' ) ) {
	function dtb_operational_pipeline_job_sync_quickbooks( int $order_id, array $args = [] ): void {
		$attempt   = isset( $args['attempt'] ) ? max( 1, absint( $args['attempt'] ) ) : 1;
		$action    = sanitize_key( (string) ( $args['action'] ?? 'create' ) );
		$refund_id = absint( $args['refund_id'] ?? 0 );
		$order     = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$event_context = [
			'action'    => $action,
			'attempt'   => $attempt,
			'handler'   => 'accounting_pipeline',
			'refund_id' => $refund_id ?: null,
		];
		if ( ! function_exists( 'dtb_qbo_exports_paused' ) || dtb_qbo_exports_paused() ) {
			if ( function_exists( 'dtb_order_update_integration_state' ) ) {
				dtb_order_update_integration_state( $order_id, 'quickbooks', [
					'status'  => 'disabled',
					'error'   => 'DTB QuickBooks writes are disabled; WooCommerce sales sync is managed by the Intuit connector.',
					'attempt' => $attempt,
				] );
			}
			if ( function_exists( 'dtb_order_append_event' ) ) {
				dtb_order_append_event( $order_id, 'integration.quickbooks.skipped', [
					'source'     => 'cron',
					'actor_type' => 'system',
					'visibility' => 'operator',
					'payload'    => $event_context + [ 'reason' => 'quickbooks_exports_paused' ],
				] );
			}
			return;
		}
		if ( function_exists( 'dtb_order_append_event' ) ) {
			dtb_order_append_event( $order_id, 'integration.quickbooks.queued', [
				'source'     => 'cron',
				'actor_type' => 'system',
				'visibility' => 'operator',
				'payload'    => $event_context,
			] );
		}

		try {
			if ( ! function_exists( 'dtb_qbo_enabled' ) || ! dtb_qbo_enabled() ) {
				if ( function_exists( 'dtb_order_update_integration_state' ) ) {
					dtb_order_update_integration_state( $order_id, 'quickbooks', [ 'status' => 'not_configured', 'error' => 'QuickBooks integration is not connected.', 'retryable' => false, 'attempt' => $attempt ] );
				}
				return;
			}

			if ( 'refund' === $action ) {
				$result = $refund_id > 0 && function_exists( 'dtb_qbo_sync_refund' )
					? dtb_qbo_sync_refund( $order, $refund_id )
					: new WP_Error( 'invalid_refund', 'QuickBooks refund jobs require a valid WooCommerce refund ID.' );
			} else {
				$result = function_exists( 'dtb_qbo_sync_order_pipeline' )
					? dtb_qbo_sync_order_pipeline( $order )
					: new WP_Error( 'qbo_sync_unavailable', 'QuickBooks sync pipeline is unavailable.' );
			}

			if ( is_wp_error( $result ) ) {
				$code    = (string) $result->get_error_code();
				$message = $result->get_error_message();

				if ( 'already_synced' === $code ) {
					$entity_id = 'refund' === $action && $refund_id > 0 && function_exists( 'dtb_qbo_refund_meta_key' )
						? (string) $order->get_meta( dtb_qbo_refund_meta_key( $refund_id ), true )
						: (string) ( $order->get_meta( '_dtb_quickbooks_entity_id', true ) ?: $order->get_meta( '_dtb_qbo_receipt_id', true ) );
					if ( function_exists( 'dtb_order_update_integration_state' ) ) {
						dtb_order_update_integration_state( $order_id, 'quickbooks', [ 'status' => 'already_synced', 'entity_id' => $entity_id ?: null, 'error' => null, 'retryable' => false, 'attempt' => $attempt, 'action' => $action, 'refund_id' => $refund_id ?: null ] );
					}
					return;
				}

				$retryable = dtb_operational_pipeline_qbo_retryable_error( $result );
				if ( function_exists( 'dtb_order_update_integration_state' ) ) {
					dtb_order_update_integration_state( $order_id, 'quickbooks', [ 'status' => 'failed', 'error' => $message, 'retryable' => $retryable, 'attempt' => $attempt, 'action' => $action, 'refund_id' => $refund_id ?: null, 'last_error_at' => current_time( 'mysql', true ) ] );
				}
				update_post_meta( $order_id, '_dtb_quickbooks_sync_status', 'failed' );
				update_post_meta( $order_id, '_dtb_quickbooks_sync_error', substr( sanitize_text_field( $message ), 0, 1000 ) );
				if ( function_exists( 'dtb_order_append_event' ) ) {
					dtb_order_append_event( $order_id, 'integration.quickbooks.failed', [ 'source' => 'cron', 'actor_type' => 'system', 'visibility' => 'operator', 'payload' => [ 'action' => $action, 'refund_id' => $refund_id ?: null, 'error_code' => $code, 'retryable' => $retryable, 'attempt' => $attempt ] ] );
				}
				if ( $retryable && function_exists( 'dtb_order_retry_job' ) ) {
					dtb_order_retry_job( 'dtb_order_sync_quickbooks', $order_id, $args );
				}
				return;
			}

			$entity_id = '';
			$type      = 'sales_receipt';
			if ( is_array( $result ) ) {
				$entity_id = (string) ( $result['SalesReceipt']['Id'] ?? $result['RefundReceipt']['Id'] ?? '' );
				$type      = isset( $result['RefundReceipt'] ) ? 'refund_receipt' : 'sales_receipt';
			}

			if ( function_exists( 'dtb_order_update_integration_state' ) ) {
				dtb_order_update_integration_state( $order_id, 'quickbooks', [ 'status' => 'synced', 'entity_id' => $entity_id ?: null, 'entity_type' => $type, 'action' => $action, 'refund_id' => $refund_id ?: null, 'error' => null, 'retryable' => false, 'attempt' => $attempt, 'last_success_at' => current_time( 'mysql', true ) ] );
			}
			update_post_meta( $order_id, '_dtb_quickbooks_sync_status', 'synced' );
			delete_post_meta( $order_id, '_dtb_quickbooks_sync_error' );
			if ( function_exists( 'dtb_order_append_event' ) ) {
				dtb_order_append_event( $order_id, 'integration.quickbooks.synced', [ 'source' => 'cron', 'actor_type' => 'quickbooks', 'visibility' => 'operator', 'idempotency_key' => 'quickbooks:' . $action . ':' . $order_id . ':' . ( $refund_id ?: 0 ), 'payload' => [ 'action' => $action, 'refund_id' => $refund_id ?: null, 'entity_id' => $entity_id ?: null, 'entity_type' => $type, 'handler' => 'accounting_pipeline' ] ] );
			}
		} catch ( Throwable $e ) {
			$retryable = dtb_operational_pipeline_qbo_retryable_error( $e );
			if ( function_exists( 'dtb_order_update_integration_state' ) ) {
				dtb_order_update_integration_state( $order_id, 'quickbooks', [ 'status' => 'failed', 'error' => $e->getMessage(), 'retryable' => $retryable, 'attempt' => $attempt, 'action' => $action, 'refund_id' => $refund_id ?: null, 'last_error_at' => current_time( 'mysql', true ) ] );
			}
			update_post_meta( $order_id, '_dtb_quickbooks_sync_status', 'failed' );
			update_post_meta( $order_id, '_dtb_quickbooks_sync_error', substr( sanitize_text_field( $e->getMessage() ), 0, 1000 ) );
			if ( function_exists( 'dtb_order_append_event' ) ) {
				dtb_order_append_event( $order_id, 'integration.quickbooks.failed', [ 'source' => 'cron', 'actor_type' => 'system', 'visibility' => 'operator', 'payload' => [ 'action' => $action, 'refund_id' => $refund_id ?: null, 'error_type' => get_class( $e ), 'retryable' => $retryable, 'attempt' => $attempt ] ] );
			}
			if ( $retryable && function_exists( 'dtb_order_retry_job' ) ) {
				dtb_order_retry_job( 'dtb_order_sync_quickbooks', $order_id, $args );
			}
		}
	}
}
