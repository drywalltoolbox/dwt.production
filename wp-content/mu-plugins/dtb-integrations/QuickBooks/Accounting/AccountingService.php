<?php
/**
 * WooCommerce snapshot, payload, ledger, and QuickBooks reconciliation service.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_QBO_AccountingService {
	/**
	 * Build a deterministic accounting document without a remote write.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function prepare_order( WC_Order $order, bool $record = true ): array|WP_Error {
		if ( ! $order->get_date_paid() || '' === (string) $order->get_transaction_id() ) {
			return new WP_Error( 'payment_not_captured', 'QuickBooks projection requires a captured WooCommerce payment.', [ 'retryable' => false ] );
		}

		$snapshot = self::order_snapshot( $order );
		return self::prepare( $snapshot, dtb_qbo_order_doc_number( $order ), 'SalesReceipt', $record );
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public static function prepare_refund( WC_Order $order, WC_Order_Refund $refund, bool $record = true ): array|WP_Error {
		if ( (int) $refund->get_parent_id() !== (int) $order->get_id() ) {
			return new WP_Error( 'invalid_refund', 'The WooCommerce refund does not belong to this order.', [ 'retryable' => false ] );
		}

		$snapshot = self::refund_snapshot( $order, $refund );
		return self::prepare( $snapshot, dtb_qbo_refund_doc_number( $order, $refund->get_id() ), 'RefundReceipt', $record );
	}

	/**
	 * Compare the retrieved QuickBooks transaction to the approved Woo snapshot.
	 *
	 * @param array<string,mixed> $prepared Prepared document.
	 * @param array<string,mixed> $entity   Retrieved QBO entity.
	 * @return array<string,mixed>
	 */
	public static function reconcile( array $prepared, array $entity ): array {
		$expected_total = DTB_QBO_AccountingMath::money( $prepared['composition']['expected_total'] ?? 0 );
		$qbo_total      = DTB_QBO_AccountingMath::money( $entity['TotalAmt'] ?? 0 );
		$expected_tax   = DTB_QBO_AccountingMath::money( $prepared['composition']['tax_total'] ?? 0 );
		$qbo_tax        = DTB_QBO_AccountingMath::money( $entity['TxnTaxDetail']['TotalTax'] ?? 0 );
		$expected_doc   = (string) ( $prepared['document_number'] ?? '' );
		$qbo_doc        = (string) ( $entity['DocNumber'] ?? '' );
		$expected_cur   = strtoupper( (string) ( $prepared['snapshot']['currency'] ?? 'USD' ) );
		$qbo_cur        = strtoupper( (string) ( $entity['CurrencyRef']['value'] ?? $expected_cur ) );
		$errors         = [];

		if ( ! DTB_QBO_AccountingMath::same_money( $expected_total, $qbo_total ) ) {
			$errors[] = 'total_mismatch';
		}
		if ( ! DTB_QBO_AccountingMath::same_money( $expected_tax, $qbo_tax ) ) {
			$errors[] = 'tax_mismatch';
		}
		if ( $expected_doc !== $qbo_doc ) {
			$errors[] = 'document_number_mismatch';
		}
		if ( $expected_cur !== $qbo_cur ) {
			$errors[] = 'currency_mismatch';
		}

		return [
			'ok'             => empty( $errors ),
			'errors'         => $errors,
			'expected_total' => $expected_total,
			'qbo_total'      => $qbo_total,
			'variance'       => DTB_QBO_AccountingMath::money( $qbo_total - $expected_total ),
			'expected_tax'   => $expected_tax,
			'qbo_tax'        => $qbo_tax,
			'currency'       => $qbo_cur,
		];
	}

	/**
	 * Persist prepared or reconciled state without storing customer PII.
	 *
	 * @param array<string,mixed> $prepared Prepared accounting document.
	 * @param array<string,mixed> $state    State overrides.
	 */
	public static function record( array $prepared, array $state = [] ): int|false {
		$snapshot    = (array) ( $prepared['snapshot'] ?? [] );
		$composition = (array) ( $prepared['composition'] ?? [] );
		$safe_lines  = array_map(
			static fn( array $line ): array => [
				'kind'        => sanitize_key( (string) ( $line['kind'] ?? '' ) ),
				'description' => sanitize_text_field( (string) ( $line['description'] ?? '' ) ),
				'amount'      => DTB_QBO_AccountingMath::money( $line['amount'] ?? 0 ),
				'taxable'     => ! empty( $line['taxable'] ),
			],
			array_values( array_filter( (array) ( $snapshot['lines'] ?? [] ), 'is_array' ) )
		);
		$record      = [
			'environment'       => dtb_qbo_environment(),
			'realm_hash'        => DTB_QBO_AccountingLedger::realm_hash(),
			'source_type'       => $snapshot['source_type'] ?? 'order',
			'source_key'        => $snapshot['source_key'] ?? '',
			'source_id'         => $snapshot['source_id'] ?? 0,
			'order_id'          => $snapshot['order_id'] ?? 0,
			'document_number'   => $prepared['document_number'] ?? '',
			'direction'         => $snapshot['direction'] ?? 'sale',
			'currency'          => $snapshot['currency'] ?? 'USD',
			'txn_date'          => $snapshot['txn_date'] ?? gmdate( 'Y-m-d' ),
			'expected_total'    => $composition['expected_total'] ?? 0,
			'payload_total'     => $composition['payload_total'] ?? 0,
			'variance'          => $composition['variance'] ?? 0,
			'subtotal'          => $snapshot['subtotal'] ?? 0,
			'tax_total'         => 'refund' === ( $snapshot['direction'] ?? '' ) ? 0 : ( $snapshot['tax_total'] ?? 0 ),
			'refunded_tax'      => 'refund' === ( $snapshot['direction'] ?? '' ) ? ( $snapshot['tax_total'] ?? 0 ) : 0,
			'discount_total'    => $snapshot['discount_total'] ?? 0,
			'shipping_total'    => $snapshot['shipping_total'] ?? 0,
			'fee_total'         => $snapshot['fee_total'] ?? 0,
			'state'             => $composition['ok'] ? 'prepared' : 'exception',
			'qbo_entity_type'   => $prepared['entity_type'] ?? '',
			'exception_code'    => $composition['ok'] ? null : 'payload_invariant',
			'error_message'     => $composition['ok'] ? null : implode( ' ', (array) ( $composition['errors'] ?? [] ) ),
			'retryable'         => false,
			'payload_hash'      => $prepared['payload_hash'] ?? '',
			'policy_version'    => $composition['policy_version'] ?? DTB_QBO_AccountingMath::POLICY_VERSION,
			'safe_snapshot_json'=> [ 'lines' => $safe_lines ],
			'tax_json'          => $snapshot['taxes'] ?? [],
		];

		return DTB_QBO_AccountingLedger::upsert( array_merge( $record, $state ) );
	}

	/**
	 * @param array<string,mixed> $snapshot Normalized source snapshot.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function prepare( array $snapshot, string $document_number, string $entity_type, bool $record ): array|WP_Error {
		$policy = DTB_QBO_AccountingLedger::policy();
		if ( ! empty( $policy['closed_through'] ) && (string) $snapshot['txn_date'] <= (string) $policy['closed_through'] ) {
			return new WP_Error(
				'qbo_period_closed',
				'The accounting date is inside a locally closed period and requires accountant review.',
				[ 'retryable' => false ]
			);
		}

		$composition = DTB_QBO_AccountingMath::compose( $snapshot, $policy );
		$body        = (array) $composition['body'];
		$body['DocNumber']   = $document_number;
		$body['TxnDate']     = (string) $snapshot['txn_date'];
		$body['PrivateNote'] = sprintf(
			'Drywall Toolbox %s %s; accounting policy %s',
			(string) $snapshot['source_type'],
			(string) $snapshot['source_key'],
			DTB_QBO_AccountingMath::POLICY_VERSION
		);
		if ( ! empty( $policy['deposit_account_id'] ) && 'SalesReceipt' === $entity_type ) {
			$body['DepositToAccountRef'] = [
				'value' => sanitize_text_field( (string) $policy['deposit_account_id'] ),
				'name'  => sanitize_text_field( (string) $policy['deposit_account_name'] ),
			];
		}

		$prepared = [
			'snapshot'        => $snapshot,
			'composition'     => $composition,
			'document_number' => $document_number,
			'entity_type'     => $entity_type,
			'body'            => $body,
			'payload_hash'    => hash( 'sha256', (string) wp_json_encode( $body ) ),
		];
		if ( $record ) {
			self::record( $prepared );
		}

		if ( empty( $composition['ok'] ) ) {
			return new WP_Error(
				'qbo_payload_invariant',
				implode( ' ', (array) $composition['errors'] ),
				[ 'retryable' => false, 'document_number' => $document_number ]
			);
		}
		return $prepared;
	}

	/** @return array<string,mixed> */
	private static function order_snapshot( WC_Order $order ): array {
		$lines = [];
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$ref = dtb_qbo_product_item_ref_for_order_item( $item );
			if ( is_wp_error( $ref ) ) {
				$ref = [];
			}
			$product = $item->get_product();
			$sku     = $product instanceof WC_Product ? (string) $product->get_sku() : '';
			$lines[] = [
				'kind'        => 'product',
				'description' => implode( ' — ', array_filter( [ $item->get_name(), $sku ? 'SKU: ' . $sku : '' ] ) ),
				'amount'      => abs( (float) $item->get_total() ),
				'quantity'    => abs( (float) $item->get_quantity() ),
				'taxable'     => abs( (float) $item->get_total_tax() ) > 0,
				'item_ref'    => $ref,
			];
		}

		$shipping_ref = dtb_qbo_require_ref( 'shipping', 'Shipping' );
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$amount = abs( (float) $item->get_total() );
			if ( $amount <= 0 ) {
				continue;
			}
			$lines[] = [
				'kind' => 'shipping', 'description' => 'Shipping — ' . $item->get_name(),
				'amount' => $amount, 'quantity' => 1, 'taxable' => abs( (float) $item->get_total_tax() ) > 0,
				'item_ref' => is_wp_error( $shipping_ref ) ? [] : $shipping_ref,
			];
		}

		$fee_ref = dtb_qbo_require_ref( 'fee', 'Order Fee' );
		foreach ( $order->get_items( 'fee' ) as $item ) {
			$amount = abs( (float) $item->get_total() );
			if ( $amount <= 0 ) {
				continue;
			}
			$lines[] = [
				'kind' => 'fee', 'description' => 'Fee — ' . $item->get_name(),
				'amount' => $amount, 'quantity' => 1, 'taxable' => abs( (float) $item->get_total_tax() ) > 0,
				'item_ref' => is_wp_error( $fee_ref ) ? [] : $fee_ref,
			];
		}

		return [
			'source_type'    => 'order',
			'source_key'     => 'order:' . $order->get_id(),
			'source_id'      => $order->get_id(),
			'order_id'       => $order->get_id(),
			'direction'      => 'sale',
			'currency'       => $order->get_currency() ?: get_woocommerce_currency(),
			'txn_date'       => $order->get_date_created() ? gmdate( 'Y-m-d', $order->get_date_created()->getTimestamp() ) : gmdate( 'Y-m-d' ),
			'expected_total' => abs( (float) $order->get_total() ),
			'subtotal'       => abs( (float) $order->get_subtotal() ),
			'discount_total' => abs( (float) $order->get_discount_total() ),
			'shipping_total' => abs( (float) $order->get_shipping_total() ),
			'fee_total'      => abs( (float) $order->get_total_fees() ),
			'tax_total'      => abs( (float) $order->get_total_tax() ),
			'taxes'          => self::tax_rows( $order ),
			'lines'          => $lines,
		];
	}

	/** @return array<string,mixed> */
	private static function refund_snapshot( WC_Order $order, WC_Order_Refund $refund ): array {
		$lines      = [];
		$refund_ref = dtb_qbo_require_ref( 'refund', 'Refund' );
		$generic    = is_wp_error( $refund_ref ) ? [] : $refund_ref;

		foreach ( [ 'line_item' => 'product', 'shipping' => 'shipping', 'fee' => 'fee' ] as $item_type => $kind ) {
			foreach ( $refund->get_items( $item_type ) as $item ) {
				$amount = abs( (float) $item->get_total() );
				if ( $amount <= 0 ) {
					continue;
				}
				$lines[] = [
					'kind'        => $kind,
					'description' => sprintf( 'Refund #%d — %s', $refund->get_id(), $item->get_name() ),
					'amount'      => $amount,
					'quantity'    => abs( (float) $item->get_quantity() ),
					'taxable'     => abs( (float) $item->get_total_tax() ) > 0,
					'item_ref'    => $generic,
				];
			}
		}

		$tax_total     = abs( (float) $refund->get_total_tax() );
		$expected      = abs( (float) $refund->get_amount() );
		$current_total = array_sum( array_column( $lines, 'amount' ) ) + $tax_total;
		$remainder     = DTB_QBO_AccountingMath::money( $expected - $current_total );
		if ( $remainder > 0 ) {
			$lines[] = [
				'kind'        => 'refund_adjustment',
				'description' => sprintf( 'Unallocated refund #%d for order #%s', $refund->get_id(), $order->get_order_number() ),
				'amount'      => $remainder,
				'quantity'    => 1,
				'taxable'     => false,
				'item_ref'    => $generic,
			];
		}

		return [
			'source_type'    => 'refund',
			'source_key'     => 'refund:' . $refund->get_id(),
			'source_id'      => $refund->get_id(),
			'order_id'       => $order->get_id(),
			'direction'      => 'refund',
			'currency'       => $order->get_currency() ?: get_woocommerce_currency(),
			'txn_date'       => $refund->get_date_created() ? gmdate( 'Y-m-d', $refund->get_date_created()->getTimestamp() ) : gmdate( 'Y-m-d' ),
			'expected_total' => $expected,
			'subtotal'       => max( 0, $expected - $tax_total ),
			'discount_total' => 0,
			'shipping_total' => self::items_total( $refund->get_items( 'shipping' ) ),
			'fee_total'      => self::items_total( $refund->get_items( 'fee' ) ),
			'tax_total'      => $tax_total,
			'taxes'          => self::tax_rows( $refund ),
			'lines'          => $lines,
		];
	}

	/** @return array<int,array<string,mixed>> */
	private static function tax_rows( WC_Abstract_Order $order ): array {
		$rows = [];
		foreach ( $order->get_items( 'tax' ) as $tax ) {
			if ( ! $tax instanceof WC_Order_Item_Tax ) {
				continue;
			}
			$rows[] = [
				'rate_id'      => absint( $tax->get_rate_id() ),
				'label'        => sanitize_text_field( $tax->get_label() ),
				'rate_percent' => class_exists( 'WC_Tax' ) ? (float) WC_Tax::get_rate_percent_value( $tax->get_rate_id() ) : 0,
				'item_tax'     => abs( (float) $tax->get_tax_total() ),
				'shipping_tax' => abs( (float) $tax->get_shipping_tax_total() ),
				'total'        => abs( (float) $tax->get_tax_total() ) + abs( (float) $tax->get_shipping_tax_total() ),
			];
		}
		return $rows;
	}

	private static function items_total( array $items ): float {
		$total = 0.0;
		foreach ( $items as $item ) {
			if ( is_object( $item ) && method_exists( $item, 'get_total' ) ) {
				$total += abs( (float) $item->get_total() );
			}
		}
		return DTB_QBO_AccountingMath::money( $total );
	}
}
