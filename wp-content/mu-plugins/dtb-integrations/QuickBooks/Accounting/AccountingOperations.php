<?php
/**
 * Queue-owned accounting operations: reports, tax preferences, and legacy Stripe settlements.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_QBO_AccountingOperations {
	private const GROUP = 'dtb-orders';

	public static function register(): void {
		add_action( 'dtb_qbo_refresh_accounting_reports', [ self::class, 'refresh_reports' ] );
		add_action( 'dtb_qbo_sync_stripe_settlements', [ self::class, 'sync_settlements' ] );
		add_action( 'dtb_qbo_change_data_capture', [ self::class, 'change_data_capture' ] );
		add_action( 'dtb_qbo_daily_accounting_controls', [ self::class, 'daily_controls' ] );
		add_action( 'action_scheduler_init', [ self::class, 'ensure_schedule' ] );
	}

	public static function ensure_schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( 'dtb_qbo_daily_accounting_controls', [], self::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, 'dtb_qbo_daily_accounting_controls', [], self::GROUP, true );
		}
	}

	public static function enqueue( string $operation ): int|string|false {
		if ( 'settlement' === sanitize_key( $operation ) && ( ! function_exists( 'dtb_qbo_exports_paused' ) || dtb_qbo_exports_paused() ) ) {
			return false;
		}
		$hook = match ( sanitize_key( $operation ) ) {
			'reports'    => 'dtb_qbo_refresh_accounting_reports',
			'settlement' => 'dtb_qbo_sync_stripe_settlements',
			'cdc'        => 'dtb_qbo_change_data_capture',
			default      => '',
		};
		return $hook && function_exists( 'as_enqueue_async_action' )
			? as_enqueue_async_action( $hook, [], self::GROUP, true )
			: false;
	}

	/**
	 * Validate accountant-entered account and tax-code references against the
	 * connected company before policy approval.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function validate_policy( array $policy ): array|WP_Error {
		$account_roles = [ 'deposit_account', 'clearing_account', 'fee_account', 'bank_account' ];
		$ids           = [];
		foreach ( $account_roles as $role ) {
			$id = sanitize_text_field( (string) ( $policy[ $role . '_id' ] ?? '' ) );
			if ( '' !== $id ) {
				$ids[ $role ] = $id;
			}
		}
		if ( $ids ) {
			$quoted   = array_map( static fn( string $id ): string => "'" . str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], $id ) . "'", array_values( $ids ) );
			$response = dtb_qbo_request( 'GET', '/query', [ 'query' => 'SELECT * FROM Account WHERE Id IN (' . implode( ',', $quoted ) . ') AND Active = true MAXRESULTS 10' ] );
			if ( empty( $response['ok'] ) ) {
				return new WP_Error( 'qbo_account_validation_failed', (string) ( $response['error'] ?? 'QuickBooks account validation failed.' ), [ 'status' => 502, 'retryable' => (bool) ( $response['retryable'] ?? false ) ] );
			}
			$accounts = [];
			foreach ( (array) ( $response['data']['QueryResponse']['Account'] ?? [] ) as $account ) {
				$accounts[ (string) ( $account['Id'] ?? '' ) ] = $account;
			}
			foreach ( $ids as $role => $id ) {
				if ( empty( $accounts[ $id ] ) ) {
					return new WP_Error( 'qbo_account_reference_invalid', sprintf( 'The approved %s reference is not an active account in this QuickBooks company.', str_replace( '_', ' ', $role ) ), [ 'status' => 409 ] );
				}
				$expected_name = sanitize_text_field( (string) ( $policy[ $role . '_name' ] ?? '' ) );
				if ( '' !== $expected_name && $expected_name !== sanitize_text_field( (string) ( $accounts[ $id ]['Name'] ?? '' ) ) ) {
					return new WP_Error( 'qbo_account_name_mismatch', sprintf( 'The %s account name does not match QuickBooks.', str_replace( '_', ' ', $role ) ), [ 'status' => 409 ] );
				}
			}
		}
		$tax_code_id = sanitize_text_field( (string) ( $policy['tax_code_id'] ?? '' ) );
		if ( '' !== $tax_code_id ) {
			$safe     = str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], $tax_code_id );
			$response = dtb_qbo_request( 'GET', '/query', [ 'query' => "SELECT * FROM TaxCode WHERE Id = '{$safe}' MAXRESULTS 2" ] );
			$tax_code = (array) ( $response['data']['QueryResponse']['TaxCode'][0] ?? [] );
			if ( empty( $response['ok'] ) || empty( $tax_code['Id'] ) || empty( $tax_code['Active'] ) ) {
				return new WP_Error( 'qbo_tax_code_invalid', 'The approved transaction tax code is not active in this QuickBooks company.', [ 'status' => 409 ] );
			}
		}
		return [ 'ok' => true, 'accountCount' => count( $ids ), 'taxCodeValidated' => '' !== $tax_code_id ];
	}

	public static function daily_controls(): void {
		$policy = DTB_QBO_AccountingLedger::policy();
		if ( ! empty( $policy['daily_reconcile'] ) ) {
			self::enqueue( 'reports' );
			self::enqueue( 'cdc' );
		}
		if ( ! empty( $policy['stripe_sync'] ) ) {
			self::enqueue( 'settlement' );
		}
	}

	/** Daily QBO CDC backstop for webhook loss and out-of-band edits. */
	public static function change_data_capture(): void {
		if ( ! dtb_qbo_enabled() ) {
			return;
		}
		$option = dtb_qbo_option_name( 'accounting_cdc_cursor' );
		$cursor = sanitize_text_field( (string) get_option( $option, gmdate( 'c', time() - DAY_IN_SECONDS ) ) );
		$floor  = gmdate( 'c', time() - ( 29 * DAY_IN_SECONDS ) );
		if ( strtotime( $cursor ) < strtotime( $floor ) ) {
			$cursor = $floor;
		}
		$result = dtb_qbo_request( 'GET', '/cdc', [ 'entities' => 'SalesReceipt,RefundReceipt,JournalEntry', 'changedSince' => $cursor ] );
		if ( empty( $result['ok'] ) ) {
			update_option( dtb_qbo_option_name( 'accounting_cdc_status' ), [ 'state' => 'failed', 'message' => sanitize_text_field( (string) ( $result['error'] ?? '' ) ), 'checkedAt' => gmdate( 'c' ) ], false );
			return;
		}
		$changed  = 0;
		$received = 0;
		foreach ( (array) ( $result['data']['CDCResponse'] ?? [] ) as $response ) {
			$query_response = (array) ( $response['QueryResponse'] ?? [] );
			foreach ( [ 'SalesReceipt', 'RefundReceipt', 'JournalEntry' ] as $entity_type ) {
				foreach ( (array) ( $query_response[ $entity_type ] ?? [] ) as $entity ) {
					++$received;
					$id       = sanitize_text_field( (string) ( $entity['Id'] ?? '' ) );
					$document = $id ? DTB_QBO_AccountingLedger::find_entity( $entity_type, $id ) : null;
					if ( ! $document ) {
						continue;
					}
					$deleted = 'Deleted' === (string) ( $entity['status'] ?? '' );
					$qbo_total = 'JournalEntry' === $entity_type ? (float) $document['expected_total'] : (float) ( $entity['TotalAmt'] ?? 0 );
					$matches = ! $deleted && DTB_QBO_AccountingMath::same_money( $document['expected_total'], $qbo_total );
					DTB_QBO_AccountingLedger::upsert(
						array_merge(
							$document,
							[
								'realm_hash'     => DTB_QBO_AccountingLedger::realm_hash(),
								'state'          => $matches ? 'reconciled' : 'exception',
								'external_state' => $deleted ? 'deleted' : 'changed',
								'qbo_total'      => $qbo_total,
								'variance'       => DTB_QBO_AccountingMath::money( $qbo_total - (float) $document['expected_total'] ),
								'exception_code' => $matches ? null : ( $deleted ? 'qbo_entity_deleted' : 'qbo_external_change_mismatch' ),
								'error_message'  => $matches ? null : 'QuickBooks changed outside DTB and no longer matches the source projection.',
								'trace_id'       => $result['intuit_tid'] ?? $document['trace_id'],
								'reconciled_at'  => $matches ? current_time( 'mysql', true ) : null,
							]
						)
					);
					++$changed;
				}
			}
		}
		$truncated = $received >= 1000;
		if ( ! $truncated ) {
			update_option( $option, gmdate( 'c' ), false );
		}
		update_option(
			dtb_qbo_option_name( 'accounting_cdc_status' ),
			[
				'state'     => $truncated ? 'exception' : 'complete',
				'changed'   => $changed,
				'received'  => $received,
				'message'   => $truncated ? 'QuickBooks CDC reached its 1,000-object response boundary; the cursor was retained for operator recovery.' : '',
				'checkedAt' => gmdate( 'c' ),
			],
			false
		);
	}

	/** Read-only QBO reports and tax-preference refresh. */
	public static function refresh_reports(): void {
		if ( ! dtb_qbo_enabled() ) {
			return;
		}
		$snapshots = [];
		foreach ( [ 'ProfitAndLoss', 'BalanceSheet', 'TrialBalance' ] as $report ) {
			$response = dtb_qbo_request( 'GET', '/reports/' . $report, [ 'accounting_method' => 'Accrual' ] );
			$snapshots[ $report ] = [
				'ok'          => ! empty( $response['ok'] ),
				'header'      => (array) ( $response['data']['Header'] ?? [] ),
				'summary'     => self::report_summary( (array) ( $response['data'] ?? [] ) ),
				'refreshedAt' => gmdate( 'c' ),
				'error'       => empty( $response['ok'] ) ? sanitize_text_field( (string) ( $response['error'] ?? 'Refresh failed.' ) ) : '',
			];
		}
		$preferences = dtb_qbo_request( 'GET', '/preferences' );
		update_option(
			dtb_qbo_option_name( 'accounting_reports' ),
			[
				'reports'        => $snapshots,
				'taxPreference'  => [
					'ok'      => ! empty( $preferences['ok'] ),
					'tracked' => ! empty( $preferences['data']['Preferences']['TaxPrefs']['UsingSalesTax'] ),
					'rawType' => sanitize_text_field( (string) ( $preferences['data']['Preferences']['TaxPrefs']['PartnerTaxEnabled'] ?? '' ) ),
				],
				'refreshedAt'    => gmdate( 'c' ),
			],
			false
		);
	}

	/**
	 * Import Stripe payout/balance-transaction detail into the accounting ledger.
	 * No payment, capture, refund, or checkout behavior is changed.
	 */
	public static function sync_settlements(): void {
		if ( ! function_exists( 'dtb_qbo_exports_paused' ) || dtb_qbo_exports_paused() ) {
			update_option( 'dtb_qbo_stripe_settlement_status', [ 'state' => 'disabled', 'message' => 'DTB QuickBooks settlement posting is disabled while the Intuit WooCommerce Connector owns transaction synchronization.', 'checkedAt' => gmdate( 'c' ) ], false );
			return;
		}
		if ( ! function_exists( 'dtb_qbo_stripe_restricted_key' ) || '' === dtb_qbo_stripe_restricted_key() ) {
			update_option( 'dtb_qbo_stripe_settlement_status', [ 'state' => 'disabled', 'message' => 'Restricted Stripe reporting key is not configured.', 'checkedAt' => gmdate( 'c' ) ], false );
			return;
		}
		$cursor_option = 'dtb_qbo_stripe_settlement_cursor';
		$cursor        = sanitize_text_field( (string) get_option( $cursor_option, '' ) );
		$query         = [ 'limit' => 25, 'status' => 'paid' ];
		if ( '' !== $cursor ) {
			$query['starting_after'] = $cursor;
		}
		$payouts = self::stripe_request( '/v1/payouts', $query );
		if ( is_wp_error( $payouts ) ) {
			update_option( 'dtb_qbo_stripe_settlement_status', [ 'state' => 'failed', 'message' => $payouts->get_error_message(), 'checkedAt' => gmdate( 'c' ) ], false );
			return;
		}
		$count = 0;
		foreach ( (array) ( $payouts['data'] ?? [] ) as $payout ) {
			$payout_id = sanitize_text_field( (string) ( $payout['id'] ?? '' ) );
			if ( '' === $payout_id ) {
				continue;
			}
			$transactions = self::stripe_list_all( '/v1/balance_transactions', [ 'limit' => 100, 'payout' => $payout_id ], 1000 );
			if ( is_wp_error( $transactions ) ) {
				continue;
			}
			$currency = strtoupper( sanitize_text_field( (string) ( $payout['currency'] ?? 'USD' ) ) );
			$fees = 0;
			foreach ( (array) ( $transactions['data'] ?? [] ) as $transaction ) {
				$fees += absint( $transaction['fee'] ?? 0 );
			}
			$amount   = self::stripe_major_amount( absint( $payout['amount'] ?? 0 ), $currency );
			$fee      = self::stripe_major_amount( $fees, $currency );
			$record = [
					'environment'     => dtb_qbo_environment(),
					'realm_hash'      => DTB_QBO_AccountingLedger::realm_hash(),
					'source_type'     => 'stripe_payout',
					'source_key'      => 'stripe_payout:' . $payout_id,
					'document_number' => substr( 'DTB-P-' . preg_replace( '/[^A-Za-z0-9]/', '', $payout_id ), 0, 40 ),
					'direction'       => 'settlement',
					'currency'        => $currency,
					'txn_date'        => gmdate( 'Y-m-d', absint( $payout['arrival_date'] ?? time() ) ),
					'expected_total'  => $amount,
					'payload_total'   => $amount,
					'fee_total'       => $fee,
					'state'           => ! empty( $transactions['truncated'] ) ? 'exception' : 'observed',
					'exception_code'  => ! empty( $transactions['truncated'] ) ? 'stripe_balance_transactions_truncated' : null,
					'error_message'   => ! empty( $transactions['truncated'] ) ? 'Stripe payout exceeds the bounded 1,000 balance-transaction import and requires accountant review.' : null,
					'external_state'  => sanitize_key( (string) ( $payout['status'] ?? 'paid' ) ),
					'payload_hash'    => hash( 'sha256', $payout_id . '|' . $amount . '|' . $fee . '|' . $currency ),
					'policy_version'  => DTB_QBO_AccountingMath::POLICY_VERSION,
					'trace_id'        => sanitize_text_field( (string) ( $transactions['request_id'] ?? '' ) ),
				];
			DTB_QBO_AccountingLedger::upsert( $record );
			if ( empty( $transactions['truncated'] ) ) {
				self::post_settlement_journal( $record, $amount, $fee );
			}
			++$count;
		}
		$payout_rows = (array) ( $payouts['data'] ?? [] );
		$last_payout = end( $payout_rows );
		if ( ! empty( $payouts['has_more'] ) && is_array( $last_payout ) && ! empty( $last_payout['id'] ) ) {
			update_option( $cursor_option, sanitize_text_field( (string) $last_payout['id'] ), false );
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + 5, 'dtb_qbo_sync_stripe_settlements', [], self::GROUP, false );
			}
		} else {
			delete_option( $cursor_option );
		}
		update_option( 'dtb_qbo_stripe_settlement_status', [ 'state' => 'observed', 'count' => $count, 'checkedAt' => gmdate( 'c' ) ], false );
	}

	private static function stripe_major_amount( int $minor, string $currency ): float {
		$zero_decimal = [ 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' ];
		return DTB_QBO_AccountingMath::money( $minor / ( in_array( strtoupper( $currency ), $zero_decimal, true ) ? 1 : 100 ) );
	}

	/** @return array<string,mixed>|WP_Error */
	private static function stripe_list_all( string $path, array $query, int $maximum ): array|WP_Error {
		$all        = [];
		$request_id = '';
		do {
			$page = self::stripe_request( $path, $query );
			if ( is_wp_error( $page ) ) {
				return $page;
			}
			$rows       = (array) ( $page['data'] ?? [] );
			$all        = array_merge( $all, $rows );
			$request_id = (string) ( $page['request_id'] ?? $request_id );
			$last       = end( $rows );
			if ( empty( $page['has_more'] ) || ! is_array( $last ) || empty( $last['id'] ) || count( $all ) >= $maximum ) {
				break;
			}
			$query['starting_after'] = sanitize_text_field( (string) $last['id'] );
		} while ( true );
		return [ 'data' => array_slice( $all, 0, $maximum ), 'request_id' => $request_id, 'truncated' => count( $all ) >= $maximum ];
	}

	/**
	 * Post a balanced clearing journal only when an accountant has approved all
	 * three account mappings. The operation is idempotent by DocNumber.
	 *
	 * @param array<string,mixed> $record Ledger record.
	 */
	private static function post_settlement_journal( array $record, float $amount, float $fee ): void {
		$policy = DTB_QBO_AccountingLedger::policy();
		if ( ! dtb_qbo_enabled() || empty( $policy['bank_account_id'] ) || empty( $policy['fee_account_id'] ) || empty( $policy['clearing_account_id'] ) ) {
			return;
		}
		$doc_number = substr( (string) $record['document_number'], 0, 21 );
		$found      = dtb_qbo_find_entity_by_doc_number( 'JournalEntry', $doc_number );
		if ( is_wp_error( $found ) ) {
			DTB_QBO_AccountingLedger::upsert( array_merge( $record, [ 'state' => 'failed', 'exception_code' => $found->get_error_code(), 'error_message' => $found->get_error_message(), 'retryable' => true ] ) );
			return;
		}
		$gross = DTB_QBO_AccountingMath::money( $amount + $fee );
		$lines = [
			self::journal_line( $amount, 'Debit', $policy['bank_account_id'], $policy['bank_account_name'], 'Stripe payout deposited to bank' ),
			self::journal_line( $gross, 'Credit', $policy['clearing_account_id'], $policy['clearing_account_name'], 'Stripe clearing account release' ),
		];
		if ( $fee > 0 ) {
			array_splice( $lines, 1, 0, [ self::journal_line( $fee, 'Debit', $policy['fee_account_id'], $policy['fee_account_name'], 'Stripe processing fees' ) ] );
		}
		$body  = [
			'DocNumber'   => $doc_number,
			'TxnDate'     => $record['txn_date'],
			'CurrencyRef' => [ 'value' => $record['currency'] ],
			'PrivateNote' => 'Stripe payout clearing projection; policy ' . DTB_QBO_AccountingMath::POLICY_VERSION,
			'Line'        => $lines,
		];
		if ( ! is_array( $found ) ) {
			$response = dtb_qbo_request( 'POST', '/journalentry', [], $body );
			if ( empty( $response['ok'] ) ) {
				DTB_QBO_AccountingLedger::upsert( array_merge( $record, [ 'state' => 'failed', 'exception_code' => 'qbo_settlement_post_failed', 'error_message' => (string) ( $response['error'] ?? 'QuickBooks journal creation failed.' ), 'retryable' => (bool) ( $response['retryable'] ?? false ), 'trace_id' => $response['intuit_tid'] ?? $record['trace_id'] ] ) );
				return;
			}
			$found = dtb_qbo_find_entity_by_doc_number( 'JournalEntry', $doc_number );
		}
		if ( ! is_array( $found ) || ! self::journal_balances( $found, $gross ) ) {
			DTB_QBO_AccountingLedger::upsert( array_merge( $record, [ 'state' => 'exception', 'exception_code' => 'qbo_settlement_mismatch', 'error_message' => 'QuickBooks settlement journal does not match the Stripe payout and fee clearing total.', 'retryable' => false ] ) );
			return;
		}
		DTB_QBO_AccountingLedger::upsert( array_merge( $record, [ 'state' => 'reconciled', 'qbo_total' => $amount, 'variance' => 0, 'qbo_entity_type' => 'JournalEntry', 'qbo_entity_id' => $found['Id'] ?? '', 'qbo_sync_token' => $found['SyncToken'] ?? '', 'posted_at' => current_time( 'mysql', true ), 'reconciled_at' => current_time( 'mysql', true ) ] ) );
	}

	/** @return array<string,mixed> */
	private static function journal_line( float $amount, string $posting_type, string $account_id, string $account_name, string $description ): array {
		return [
			'Amount'                  => DTB_QBO_AccountingMath::money( $amount ),
			'DetailType'              => 'JournalEntryLineDetail',
			'Description'             => $description,
			'JournalEntryLineDetail'  => [
				'PostingType' => $posting_type,
				'AccountRef'  => [ 'value' => sanitize_text_field( $account_id ), 'name' => sanitize_text_field( $account_name ) ],
			],
		];
	}

	private static function journal_balances( array $entity, float $gross ): bool {
		$debits = 0.0;
		$credits = 0.0;
		foreach ( (array) ( $entity['Line'] ?? [] ) as $line ) {
			$type = (string) ( $line['JournalEntryLineDetail']['PostingType'] ?? '' );
			if ( 'Debit' === $type ) {
				$debits += (float) ( $line['Amount'] ?? 0 );
			} elseif ( 'Credit' === $type ) {
				$credits += (float) ( $line['Amount'] ?? 0 );
			}
		}
		return DTB_QBO_AccountingMath::same_money( $debits, $credits ) && DTB_QBO_AccountingMath::same_money( $credits, $gross );
	}

	/** @return array<string,mixed>|WP_Error */
	private static function stripe_request( string $path, array $query ): array|WP_Error {
		$url      = add_query_arg( $query, 'https://api.stripe.com' . $path );
		$response = wp_remote_get(
			$url,
			[
				'timeout' => 20,
				'headers' => [
					'Authorization'  => 'Bearer ' . dtb_qbo_stripe_restricted_key(),
					'Stripe-Version'=> '2026-02-25.clover',
				],
			]
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'stripe_reporting_transport', 'Stripe reporting transport failed.', [ 'retryable' => true ] );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
			return new WP_Error( 'stripe_reporting_failed', 'Stripe reporting request failed.', [ 'retryable' => 429 === $status || $status >= 500 ] );
		}
		$body['request_id'] = sanitize_text_field( (string) wp_remote_retrieve_header( $response, 'request-id' ) );
		return $body;
	}

	/** @return array<int,array<string,string>> */
	private static function report_summary( array $report ): array {
		$rows = [];
		foreach ( (array) ( $report['Rows']['Row'] ?? [] ) as $row ) {
			$summary = (array) ( $row['Summary']['ColData'] ?? [] );
			if ( count( $summary ) >= 2 ) {
				$rows[] = [
					'label' => sanitize_text_field( (string) ( $summary[0]['value'] ?? '' ) ),
					'value' => sanitize_text_field( (string) ( $summary[1]['value'] ?? '' ) ),
				];
			}
		}
		return array_slice( $rows, 0, 20 );
	}
}

DTB_QBO_AccountingOperations::register();
