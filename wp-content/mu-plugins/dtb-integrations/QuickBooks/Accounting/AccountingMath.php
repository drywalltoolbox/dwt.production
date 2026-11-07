<?php
/**
 * Pure QuickBooks accounting payload arithmetic.
 *
 * This class deliberately has no WordPress or WooCommerce dependency so the
 * monetary contract can be exercised from deterministic CLI fixtures.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_QBO_AccountingMath {
	public const POLICY_VERSION = 'dtb-qbo-accounting-v2';

	/**
	 * Compose exact QuickBooks transaction lines from a normalized Woo snapshot.
	 *
	 * @param array<string,mixed> $snapshot Normalized accounting snapshot.
	 * @param array<string,mixed> $policy   Approved accounting policy.
	 * @return array<string,mixed>
	 */
	public static function compose( array $snapshot, array $policy ): array {
		$errors       = [];
		$lines        = [];
		$line_total   = 0.0;
		$tax_total    = self::money( $snapshot['tax_total'] ?? 0 );
		$expected     = self::money( $snapshot['expected_total'] ?? 0 );
		$tax_code_id  = self::clean_reference( $policy['tax_code_id'] ?? '' );
		$tax_code     = self::clean_reference( $policy['tax_code_name'] ?? '' );
		$taxable_base = 0.0;

		foreach ( (array) ( $snapshot['lines'] ?? [] ) as $source_line ) {
			if ( ! is_array( $source_line ) ) {
				continue;
			}

			$amount = self::money( $source_line['amount'] ?? 0 );
			if ( $amount <= 0 ) {
				continue;
			}

			$item_ref = (array) ( $source_line['item_ref'] ?? [] );
			$item_id  = self::clean_reference( $item_ref['value'] ?? '' );
			if ( '' === $item_id ) {
				$errors[] = 'Every accounting line requires a verified QuickBooks Item reference.';
				continue;
			}

			$taxable = ! empty( $source_line['taxable'] );
			if ( $taxable ) {
				$taxable_base += $amount;
			}

			$detail = [
				'ItemRef'    => [
					'value' => $item_id,
					'name'  => self::clean_reference( $item_ref['name'] ?? '' ),
				],
				'TaxCodeRef' => [ 'value' => $taxable && $tax_total > 0 ? 'TAX' : 'NON' ],
			];

			$quantity = (float) ( $source_line['quantity'] ?? 0 );
			if ( $quantity > 0 ) {
				$unit_price = round( $amount / $quantity, 7 );
				if ( self::same_money( $amount, $unit_price * $quantity ) ) {
					$detail['Qty']       = $quantity;
					$detail['UnitPrice'] = $unit_price;
				}
			}

			$lines[] = [
				'Amount'      => $amount,
				'DetailType'  => 'SalesItemLineDetail',
				'Description' => self::description( $source_line['description'] ?? '' ),
				'SalesItemLineDetail' => $detail,
			];
			$line_total += $amount;
		}

		if ( empty( $lines ) ) {
			$errors[] = 'The accounting document has no positive lines.';
		}

		if ( $tax_total > 0 && $taxable_base <= 0 ) {
			$errors[] = 'Tax was collected but no taxable accounting line exists.';
		}

		if ( $tax_total > 0 && '' === $tax_code_id ) {
			$errors[] = 'A verified QuickBooks transaction tax code is required for taxable documents.';
		}

		$payload_total = self::money( $line_total + $tax_total );
		if ( ! self::same_money( $expected, $payload_total ) ) {
			$errors[] = sprintf(
				'Accounting total invariant failed: WooCommerce %0.2f does not equal QuickBooks payload %0.2f.',
				$expected,
				$payload_total
			);
		}

		$body = [
			'Line'        => $lines,
			'CurrencyRef' => [ 'value' => strtoupper( substr( (string) ( $snapshot['currency'] ?? 'USD' ), 0, 3 ) ) ],
		];

		if ( $tax_total > 0 ) {
			$body['TxnTaxDetail'] = [
				'TxnTaxCodeRef' => [
					'value' => $tax_code_id,
					'name'  => $tax_code,
				],
				'TotalTax'      => $tax_total,
			];
		}

		return [
			'ok'             => empty( $errors ),
			'errors'         => array_values( array_unique( $errors ) ),
			'body'           => $body,
			'line_total'     => self::money( $line_total ),
			'tax_total'      => $tax_total,
			'payload_total'  => $payload_total,
			'expected_total' => $expected,
			'variance'       => self::money( $payload_total - $expected ),
			'policy_version' => self::POLICY_VERSION,
		];
	}

	public static function money( mixed $amount ): float {
		return round( (float) $amount, 2 );
	}

	public static function same_money( mixed $left, mixed $right ): bool {
		return abs( self::money( $left ) - self::money( $right ) ) < 0.005;
	}

	private static function clean_reference( mixed $value ): string {
		return substr( trim( preg_replace( '/[\x00-\x1F\x7F]/u', '', (string) $value ) ), 0, 100 );
	}

	private static function description( mixed $value ): string {
		return substr( trim( preg_replace( '/[\x00-\x1F\x7F]/u', ' ', (string) $value ) ), 0, 4000 );
	}
}
