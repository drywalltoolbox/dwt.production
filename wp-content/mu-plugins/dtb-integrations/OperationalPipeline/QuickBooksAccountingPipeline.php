<?php
/**
 * DTB Integrations — QuickBooks accounting projection pipeline.
 *
 * All accounting writes are queue-owned. This file maps one captured order or
 * one concrete refund to QuickBooks and reconciles deterministic document IDs
 * before creating anything remotely.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_qbo_accounting_ref( string $key, string $default_id = '', string $default_name = '' ): array {
	$option_key = sanitize_key( $key );
	$key        = strtoupper( $option_key );
	$id_const   = 'DTB_QBO_ITEM_' . $key . '_ID';
	$name_const = 'DTB_QBO_ITEM_' . $key . '_NAME';

	if ( defined( $id_const ) ) {
		$value = constant( $id_const );
		$name  = defined( $name_const ) ? constant( $name_const ) : $default_name;
	} else {
		$environment_id   = function_exists( 'dtb_qbo_option_name' ) ? dtb_qbo_option_name( 'item_' . $option_key . '_id' ) : '';
		$environment_name = function_exists( 'dtb_qbo_option_name' ) ? dtb_qbo_option_name( 'item_' . $option_key . '_name' ) : '';
		$value            = '' !== $environment_id ? get_option( $environment_id, '' ) : '';
		$name             = '' !== $environment_name ? get_option( $environment_name, $default_name ) : $default_name;

		// Compatibility fallback for mappings created before environment isolation.
		if ( '' === (string) $value ) {
			$value = get_option( strtolower( $id_const ), $default_id );
			$name  = get_option( strtolower( $name_const ), $name );
		}
	}

	return [ 'value' => sanitize_text_field( (string) $value ), 'name' => sanitize_text_field( (string) $name ) ];
}

function dtb_qbo_money( mixed $amount ): float {
	return (float) wc_format_decimal( (float) $amount, 2 );
}

function dtb_qbo_require_ref( string $key, string $name ): array|WP_Error {
	$key = sanitize_key( $key );
	if ( class_exists( 'DTB_QuickBooksItemMappingService' ) && ! DTB_QuickBooksItemMappingService::item_ready( $key ) ) {
		return new WP_Error(
			'qbo_reference_unverified',
			sprintf( 'QuickBooks %s item reference is not verified for the connected company.', strtolower( $name ) )
		);
	}

	$ref = dtb_qbo_accounting_ref( $key, '', $name );
	return '' === $ref['value'] ? new WP_Error( 'qbo_reference_missing', sprintf( 'QuickBooks %s item reference is not configured.', strtolower( $name ) ) ) : $ref;
}

function dtb_qbo_product_item_ref_for_order_item( WC_Order_Item_Product $item ): array|WP_Error {
	return dtb_qbo_require_ref( 'product', 'Product Sales' );
}

function dtb_qbo_build_sales_lines_for_order( WC_Order $order, bool $refund_mode = false ): array|WP_Error {
	$prepared = DTB_QBO_AccountingService::prepare_order( $order, false );
	return is_wp_error( $prepared ) ? $prepared : (array) ( $prepared['body']['Line'] ?? [] );
}

function dtb_qbo_build_refund_lines_for_order( WC_Order $order, WC_Order_Refund $refund ): array|WP_Error {
	$prepared = DTB_QBO_AccountingService::prepare_refund( $order, $refund, false );
	return is_wp_error( $prepared ) ? $prepared : (array) ( $prepared['body']['Line'] ?? [] );
}

function dtb_qbo_order_doc_number( WC_Order $order, string $prefix = 'DTB' ): string {
	return substr( sanitize_text_field( $prefix . '-' . $order->get_id() ), 0, 21 );
}

function dtb_qbo_refund_doc_number( WC_Order $order, int $refund_id ): string {
	return substr( sanitize_text_field( 'DTB-R-' . absint( $refund_id ) ), 0, 21 );
}

function dtb_qbo_refund_meta_key( int $refund_id ): string {
	return '_dtb_quickbooks_refund_id_' . absint( $refund_id );
}

function dtb_qbo_find_entity_by_doc_number( string $entity, string $doc_number ): array|WP_Error|null {
	if ( ! in_array( $entity, [ 'SalesReceipt', 'RefundReceipt', 'JournalEntry' ], true ) ) {
		return new WP_Error( 'qbo_invalid_entity', 'Unsupported QuickBooks entity query.' );
	}
	$safe   = str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], $doc_number );
	$result = dtb_qbo_request( 'GET', '/query', [ 'query' => "SELECT * FROM {$entity} WHERE DocNumber = '{$safe}' MAXRESULTS 2" ] );
	if ( empty( $result['ok'] ) ) {
		return new WP_Error( 'qbo_reconciliation_failed', (string) ( $result['error'] ?? 'QuickBooks reconciliation query failed.' ) );
	}
	$rows = (array) ( $result['data']['QueryResponse'][ $entity ] ?? [] );
	if ( count( $rows ) > 1 ) {
		return new WP_Error( 'qbo_duplicate_ambiguous', 'Multiple QuickBooks records use the same DTB document number.' );
	}
	return $rows[0] ?? null;
}

function dtb_qbo_store_order_entity( WC_Order $order, string $id ): void {
	$order->update_meta_data( '_dtb_qbo_synced', '1' );
	$order->update_meta_data( '_dtb_qbo_receipt_id', $id );
	$order->update_meta_data( '_dtb_quickbooks_entity_id', $id );
	$order->update_meta_data( '_dtb_quickbooks_entity_type', 'sales_receipt' );
	$order->save_meta_data();
}

function dtb_qbo_sync_order_pipeline( WC_Order $order ): array|WP_Error {
	$existing_id = (string) ( $order->get_meta( '_dtb_quickbooks_entity_id', true ) ?: $order->get_meta( '_dtb_qbo_receipt_id', true ) );
	if ( '' !== $existing_id || $order->get_meta( '_dtb_qbo_synced' ) ) {
		return new WP_Error( 'already_synced', 'Order already synced to QuickBooks.', [ 'entity_id' => $existing_id ] );
	}
	if ( ! $order->get_date_paid() || '' === (string) $order->get_transaction_id() ) {
		return new WP_Error( 'payment_not_captured', 'QuickBooks projection requires a captured WooCommerce payment.' );
	}
	if ( ! dtb_qbo_enabled() ) {
		return new WP_Error( 'qbo_not_connected', 'QuickBooks is not connected.' );
	}
	if ( function_exists( 'dtb_order_integration_acquire_lock' ) && ! dtb_order_integration_acquire_lock( 'quickbooks', (int) $order->get_id() ) ) {
		return new WP_Error( 'qbo_locked', 'A QuickBooks sync is already in progress for this order.' );
	}
	try {
		$prepared = DTB_QBO_AccountingService::prepare_order( $order );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$doc_number = (string) $prepared['document_number'];
		$found      = dtb_qbo_find_entity_by_doc_number( 'SalesReceipt', $doc_number );
		if ( is_wp_error( $found ) ) {
			DTB_QBO_AccountingService::record( $prepared, [ 'state' => 'failed', 'exception_code' => $found->get_error_code(), 'error_message' => $found->get_error_message(), 'retryable' => true ] );
			return $found;
		}
		if ( is_array( $found ) && ! empty( $found['Id'] ) ) {
			$comparison = DTB_QBO_AccountingService::reconcile( $prepared, $found );
			if ( empty( $comparison['ok'] ) ) {
				DTB_QBO_AccountingService::record( $prepared, [ 'state' => 'exception', 'exception_code' => 'qbo_reconciliation_mismatch', 'error_message' => implode( ', ', $comparison['errors'] ), 'qbo_total' => $comparison['qbo_total'], 'variance' => $comparison['variance'], 'qbo_entity_id' => $found['Id'] ] );
				return new WP_Error( 'qbo_reconciliation_mismatch', 'The existing QuickBooks SalesReceipt does not match WooCommerce.', [ 'retryable' => false, 'comparison' => $comparison ] );
			}
			dtb_qbo_store_order_entity( $order, (string) $found['Id'] );
			DTB_QBO_AccountingService::record( $prepared, [ 'state' => 'reconciled', 'qbo_total' => $comparison['qbo_total'], 'variance' => 0, 'qbo_entity_id' => $found['Id'], 'qbo_sync_token' => $found['SyncToken'] ?? '', 'reconciled_at' => current_time( 'mysql', true ) ] );
			return [ 'SalesReceipt' => $found, 'reconciled' => true ];
		}
		$customer_id = dtb_qbo_get_or_create_customer( $order );
		if ( is_wp_error( $customer_id ) ) {
			DTB_QBO_AccountingService::record( $prepared, [ 'state' => 'failed', 'exception_code' => $customer_id->get_error_code(), 'error_message' => $customer_id->get_error_message(), 'retryable' => (bool) ( $customer_id->get_error_data()['retryable'] ?? false ) ] );
			return $customer_id;
		}
		$body                = (array) $prepared['body'];
		$body['CustomerRef'] = [ 'value' => $customer_id ];
		$result              = dtb_qbo_request( 'POST', '/salesreceipt', [], $body );
		if ( empty( $result['ok'] ) ) {
			DTB_QBO_AccountingService::record( $prepared, [ 'state' => 'failed', 'exception_code' => 'qbo_sync_failed', 'error_message' => (string) ( $result['error'] ?? '' ), 'retryable' => (bool) ( $result['retryable'] ?? false ), 'trace_id' => $result['intuit_tid'] ?? '' ] );
			return new WP_Error( 'qbo_sync_failed', (string) ( $result['error'] ?? 'QuickBooks SalesReceipt creation failed.' ), [ 'status' => $result['status'] ?? 0, 'retryable' => $result['retryable'] ?? false ] );
		}
		$id = (string) ( $result['data']['SalesReceipt']['Id'] ?? '' );
		if ( '' === $id ) {
			return new WP_Error( 'qbo_invalid_success', 'QuickBooks did not return a SalesReceipt ID.' );
		}
		$verified = dtb_qbo_find_entity_by_doc_number( 'SalesReceipt', $doc_number );
		if ( is_wp_error( $verified ) || ! is_array( $verified ) || (string) ( $verified['Id'] ?? '' ) !== $id ) {
			return new WP_Error( 'qbo_reconciliation_failed', 'QuickBooks SalesReceipt could not be reconciled after creation.' );
		}
		$comparison = DTB_QBO_AccountingService::reconcile( $prepared, $verified );
		if ( empty( $comparison['ok'] ) ) {
			DTB_QBO_AccountingService::record( $prepared, [ 'state' => 'exception', 'exception_code' => 'qbo_reconciliation_mismatch', 'error_message' => implode( ', ', $comparison['errors'] ), 'qbo_total' => $comparison['qbo_total'], 'variance' => $comparison['variance'], 'qbo_entity_id' => $id ] );
			return new WP_Error( 'qbo_reconciliation_mismatch', 'QuickBooks accepted a SalesReceipt whose totals do not match WooCommerce.', [ 'retryable' => false, 'comparison' => $comparison ] );
		}
		dtb_qbo_store_order_entity( $order, $id );
		DTB_QBO_AccountingService::record( $prepared, [ 'state' => 'reconciled', 'qbo_total' => $comparison['qbo_total'], 'variance' => 0, 'qbo_entity_id' => $id, 'qbo_sync_token' => $verified['SyncToken'] ?? '', 'trace_id' => $result['intuit_tid'] ?? '', 'posted_at' => current_time( 'mysql', true ), 'reconciled_at' => current_time( 'mysql', true ) ] );
		return $result['data'];
	} finally {
		if ( function_exists( 'dtb_order_integration_release_lock' ) ) {
			dtb_order_integration_release_lock( 'quickbooks', (int) $order->get_id() );
		}
	}
}

function dtb_qbo_sync_refund( WC_Order $order, int $refund_id ): array|WP_Error {
	$refund_id = absint( $refund_id );
	$refund    = $refund_id ? wc_get_order( $refund_id ) : null;
	if ( ! $refund instanceof WC_Order_Refund || (int) $refund->get_parent_id() !== (int) $order->get_id() ) {
		return new WP_Error( 'invalid_refund', 'The WooCommerce refund could not be verified for this order.' );
	}
	$meta_key = dtb_qbo_refund_meta_key( $refund_id );
	if ( '' !== (string) $order->get_meta( $meta_key, true ) ) {
		return new WP_Error( 'already_synced', 'This refund is already synced to QuickBooks.' );
	}
	if ( ! dtb_qbo_enabled() ) {
		return new WP_Error( 'qbo_not_connected', 'QuickBooks is not connected.' );
	}
	if ( function_exists( 'dtb_order_integration_acquire_lock' ) && ! dtb_order_integration_acquire_lock( 'quickbooks', (int) $order->get_id() ) ) {
		return new WP_Error( 'qbo_locked', 'A QuickBooks sync is already in progress for this order.' );
	}
	try {
		$prepared = DTB_QBO_AccountingService::prepare_refund( $order, $refund );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$doc_number = (string) $prepared['document_number'];
		$found      = dtb_qbo_find_entity_by_doc_number( 'RefundReceipt', $doc_number );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		if ( is_array( $found ) && ! empty( $found['Id'] ) ) {
			$comparison = DTB_QBO_AccountingService::reconcile( $prepared, $found );
			if ( empty( $comparison['ok'] ) ) {
				DTB_QBO_AccountingService::record( $prepared, [ 'state' => 'exception', 'exception_code' => 'qbo_reconciliation_mismatch', 'error_message' => implode( ', ', $comparison['errors'] ), 'qbo_total' => $comparison['qbo_total'], 'variance' => $comparison['variance'], 'qbo_entity_id' => $found['Id'] ] );
				return new WP_Error( 'qbo_reconciliation_mismatch', 'The existing QuickBooks RefundReceipt does not match the WooCommerce refund.', [ 'retryable' => false, 'comparison' => $comparison ] );
			}
			$order->update_meta_data( $meta_key, (string) $found['Id'] );
			$order->save_meta_data();
			DTB_QBO_AccountingService::record( $prepared, [ 'state' => 'reconciled', 'qbo_total' => $comparison['qbo_total'], 'variance' => 0, 'qbo_entity_id' => $found['Id'], 'qbo_sync_token' => $found['SyncToken'] ?? '', 'reconciled_at' => current_time( 'mysql', true ) ] );
			return [ 'RefundReceipt' => $found, 'reconciled' => true ];
		}
		$customer_id = dtb_qbo_get_or_create_customer( $order );
		if ( is_wp_error( $customer_id ) ) {
			DTB_QBO_AccountingService::record( $prepared, [ 'state' => 'failed', 'exception_code' => $customer_id->get_error_code(), 'error_message' => $customer_id->get_error_message(), 'retryable' => (bool) ( $customer_id->get_error_data()['retryable'] ?? false ) ] );
			return $customer_id;
		}
		$body                = (array) $prepared['body'];
		$body['CustomerRef'] = [ 'value' => $customer_id ];
		$result              = dtb_qbo_request( 'POST', '/refundreceipt', [], $body );
		if ( empty( $result['ok'] ) ) {
			return new WP_Error( 'qbo_refund_sync_failed', (string) ( $result['error'] ?? 'QuickBooks RefundReceipt creation failed.' ), [ 'status' => $result['status'] ?? 0, 'retryable' => $result['retryable'] ?? false ] );
		}
		$id = (string) ( $result['data']['RefundReceipt']['Id'] ?? '' );
		if ( '' === $id ) {
			return new WP_Error( 'qbo_invalid_success', 'QuickBooks did not return a RefundReceipt ID.' );
		}
		$verified = dtb_qbo_find_entity_by_doc_number( 'RefundReceipt', $doc_number );
		if ( is_wp_error( $verified ) || ! is_array( $verified ) || (string) ( $verified['Id'] ?? '' ) !== $id ) {
			return new WP_Error( 'qbo_reconciliation_failed', 'QuickBooks RefundReceipt could not be reconciled after creation.' );
		}
		$comparison = DTB_QBO_AccountingService::reconcile( $prepared, $verified );
		if ( empty( $comparison['ok'] ) ) {
			DTB_QBO_AccountingService::record( $prepared, [ 'state' => 'exception', 'exception_code' => 'qbo_reconciliation_mismatch', 'error_message' => implode( ', ', $comparison['errors'] ), 'qbo_total' => $comparison['qbo_total'], 'variance' => $comparison['variance'], 'qbo_entity_id' => $id ] );
			return new WP_Error( 'qbo_reconciliation_mismatch', 'QuickBooks accepted a RefundReceipt whose totals do not match WooCommerce.', [ 'retryable' => false, 'comparison' => $comparison ] );
		}
		$order->update_meta_data( $meta_key, $id );
		$order->update_meta_data( '_dtb_quickbooks_refund_id', $id );
		$order->update_meta_data( '_dtb_quickbooks_refund_type', 'refund_receipt' );
		$order->save_meta_data();
		DTB_QBO_AccountingService::record( $prepared, [ 'state' => 'reconciled', 'qbo_total' => $comparison['qbo_total'], 'variance' => 0, 'qbo_entity_id' => $id, 'qbo_sync_token' => $verified['SyncToken'] ?? '', 'trace_id' => $result['intuit_tid'] ?? '', 'posted_at' => current_time( 'mysql', true ), 'reconciled_at' => current_time( 'mysql', true ) ] );
		return $result['data'];
	} finally {
		if ( function_exists( 'dtb_order_integration_release_lock' ) ) {
			dtb_order_integration_release_lock( 'quickbooks', (int) $order->get_id() );
		}
	}
}
