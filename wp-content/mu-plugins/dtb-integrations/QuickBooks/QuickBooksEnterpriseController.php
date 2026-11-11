<?php
/**
 * Enterprise QuickBooks wp-admin read models.
 *
 * Bounded, redacted operational views over WooCommerce projection state and
 * Action Scheduler. Accounting writes remain queue-owned by dtb-orders.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_QuickBooksEnterpriseController {
	private const REST_NAMESPACE = 'dtb/v1';
	private const MAX_PAGE_SIZE  = 100;
	private const MAX_SCAN       = 500;
	private const CACHE_TTL      = 10;

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/admin/qbo/enterprise',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ self::class, 'rest_view' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'args'                => [
					'view'  => [ 'type' => 'string', 'default' => 'overview', 'sanitize_callback' => 'sanitize_key' ],
					'page'  => [ 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ],
					'limit' => [ 'type' => 'integer', 'default' => 25, 'sanitize_callback' => 'absint' ],
				],
			]
		);
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function rest_view( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'qbo_woocommerce_unavailable', 'WooCommerce order services are unavailable.', [ 'status' => 503 ] );
		}

		$view    = sanitize_key( (string) $request->get_param( 'view' ) );
		$page    = max( 1, absint( $request->get_param( 'page' ) ) );
		$limit   = min( self::MAX_PAGE_SIZE, max( 1, absint( $request->get_param( 'limit' ) ) ) );
		$filters = [
			'date_from' => sanitize_text_field( (string) $request->get_param( 'date_from' ) ),
			'date_to'   => sanitize_text_field( (string) $request->get_param( 'date_to' ) ),
			'search'    => sanitize_text_field( (string) $request->get_param( 'search' ) ),
		];
		$allowed = [ 'overview', 'transactions', 'exceptions', 'tax', 'settlement', 'reports', 'rules', 'automation', 'audit' ];
		if ( ! in_array( $view, $allowed, true ) ) {
			return new WP_Error( 'qbo_invalid_enterprise_view', 'Unsupported QuickBooks operations view.', [ 'status' => 400 ] );
		}

		$payload = match ( $view ) {
			'overview'     => self::ledger_overview(),
			'transactions' => self::ledger_view( $page, $limit, [], '', $filters ),
			'exceptions'   => self::ledger_view( $page, $limit, [ 'exception', 'failed' ], '', $filters ),
			'tax'          => self::tax_center(),
			'settlement'   => self::ledger_view( $page, $limit, [], 'stripe_payout', $filters ),
			'reports'      => self::reports(),
			'rules'        => [ 'policy' => DTB_QBO_AccountingLedger::policy(), 'items' => array_values( DTB_QuickBooksItemMappingService::status() ) ],
			'automation'   => self::automation(),
			'audit'        => self::ledger_view( $page, $limit, [], '', $filters ),
		};

		return new WP_REST_Response(
			[
				'ok'          => true,
				'view'        => $view,
				'generatedAt' => gmdate( 'c' ),
				'data'        => $payload,
			],
			200
		);
	}

	private static function ledger_overview(): array {
		$to     = gmdate( 'Y-m-d' );
		$from   = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$ledger = DTB_QBO_AccountingLedger::query( [ 'page' => 1, 'limit' => 8 ] );
		return [
			'metrics'    => DTB_QBO_AccountingLedger::metrics( $from, $to ),
			'latest'     => $ledger['rows'],
			'period'     => [ 'from' => $from, 'to' => $to ],
			'connection' => DTB_QuickBooksAdminController::read_model(),
		];
	}

	private static function ledger_view( int $page, int $limit, array $states = [], string $source_type = '', array $filters = [] ): array {
		$args = array_merge( [ 'page' => $page, 'limit' => $limit ], array_filter( $filters ) );
		if ( $states ) {
			$args['states'] = $states;
		}
		if ( $source_type ) {
			$args['source_type'] = $source_type;
		}
		return DTB_QBO_AccountingLedger::query( $args );
	}

	private static function tax_center(): array {
		$totals        = [];
		$collected     = 0.0;
		$reversed      = 0.0;
		$page          = 1;
		do {
			$result = DTB_QBO_AccountingLedger::query( [ 'page' => $page, 'limit' => 100 ] );
			foreach ( $result['rows'] as $document ) {
				$direction = (string) ( $document['direction'] ?? '' );
				$collected += 'sale' === $direction ? (float) ( $document['tax_total'] ?? 0 ) : 0;
				$reversed  += 'refund' === $direction ? (float) ( $document['refunded_tax'] ?? 0 ) : 0;
				foreach ( (array) ( $document['taxes'] ?? [] ) as $tax ) {
					$key = (string) ( $tax['rate_id'] ?? 0 ) . '|' . (string) ( $tax['label'] ?? 'Unclassified' );
					if ( ! isset( $totals[ $key ] ) ) {
						$totals[ $key ] = [ 'rateId' => absint( $tax['rate_id'] ?? 0 ), 'jurisdiction' => sanitize_text_field( (string) ( $tax['label'] ?? 'Unclassified' ) ), 'rate' => (float) ( $tax['rate_percent'] ?? 0 ), 'collected' => 0.0, 'reversed' => 0.0 ];
					}
					$totals[ $key ][ 'refund' === $direction ? 'reversed' : 'collected' ] += (float) ( $tax['total'] ?? 0 );
				}
			}
			++$page;
		} while ( $page <= (int) ( $result['pages'] ?? 1 ) );
		$reports = get_option( dtb_qbo_option_name( 'accounting_reports' ), [] );
		return [
			'rows'          => array_values( $totals ),
			'collected'     => DTB_QBO_AccountingMath::money( $collected ),
			'reversed'      => DTB_QBO_AccountingMath::money( $reversed ),
			'liability'     => DTB_QBO_AccountingMath::money( $collected - $reversed ),
			'taxPreference' => (array) ( $reports['taxPreference'] ?? [] ),
			'policy'        => DTB_QBO_AccountingLedger::policy(),
		];
	}

	private static function reports(): array {
		return (array) get_option( dtb_qbo_option_name( 'accounting_reports' ), [ 'reports' => [], 'refreshedAt' => '' ] );
	}

	private static function automation(): array {
		return [
			'policy'     => DTB_QBO_AccountingLedger::policy(),
			'sync'       => DTB_QuickBooksSyncAdminController::read_model(),
			'settlement' => (array) get_option( 'dtb_qbo_stripe_settlement_status', [ 'state' => 'disabled', 'message' => 'Legacy Stripe settlement import is retired; WooPayments is the active gateway.' ] ),
			'cdc'        => (array) get_option( dtb_qbo_option_name( 'accounting_cdc_status' ), [ 'state' => 'never_run' ] ),
			'queueGroup' => 'dtb-orders',
		];
	}

	private static function overview(): array {
		$cache_key = 'dtb_qbo_enterprise_overview_' . dtb_qbo_environment();
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			$cached['cached'] = true;
			return $cached;
		}

		$orders     = self::recent_orders( 100 );
		$gross      = 0.0;
		$refunded   = 0.0;
		$eligible   = 0;
		$synced     = 0;
		$pending    = 0;
		$failed     = 0;
		$latest     = [];

		foreach ( $orders as $order ) {
			$gross    += (float) $order->get_total();
			$refunded += abs( (float) $order->get_total_refunded() );
			if ( self::is_accounting_eligible( $order ) ) {
				++$eligible;
				$state = self::projection_state( $order );
				if ( 'synced' === $state ) {
					++$synced;
				} elseif ( 'failed' === $state ) {
					++$failed;
				} else {
					++$pending;
				}
			}
			if ( count( $latest ) < 8 ) {
				$latest[] = self::order_row( $order );
			}
		}

		$data = [
			'metrics' => [
				'gross'      => wc_format_decimal( $gross, 2 ),
				'refunded'   => wc_format_decimal( $refunded, 2 ),
				'eligible'   => $eligible,
				'synced'     => $synced,
				'pending'    => $pending,
				'failed'     => $failed,
				'syncRate'   => $eligible > 0 ? round( ( $synced / $eligible ) * 100, 1 ) : 0.0,
				'sampleSize' => count( $orders ),
				'currency'   => get_woocommerce_currency(),
			],
			'latest'     => $latest,
			'connection' => DTB_QuickBooksAdminController::read_model(),
			'cached'     => false,
		];
		set_transient( $cache_key, $data, self::CACHE_TTL );
		return $data;
	}

	private static function transactions( int $page, int $limit ): array {
		$result = wc_get_orders( [
			'limit' => $limit, 'page' => $page, 'paginate' => true,
			'orderby' => 'date', 'order' => 'DESC', 'status' => array_keys( wc_get_order_statuses() ),
		] );
		$rows = [];
		foreach ( (array) $result->orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$rows[] = self::order_row( $order );
			}
		}
		return self::page_payload( $rows, $page, $limit, (int) $result->total, false );
	}

	private static function refunds( int $page, int $limit ): array {
		$rows = [];
		foreach ( self::recent_orders( self::MAX_SCAN ) as $order ) {
			foreach ( $order->get_refunds() as $refund ) {
				if ( ! $refund instanceof WC_Order_Refund ) {
					continue;
				}
				$entity_id = (string) $order->get_meta( dtb_qbo_refund_meta_key( $refund->get_id() ), true );
				$rows[] = [
					'id' => $refund->get_id(), 'orderId' => $order->get_id(), 'orderNumber' => $order->get_order_number(),
					'amount' => wc_format_decimal( abs( (float) $refund->get_amount() ), 2 ), 'currency' => $order->get_currency(),
					'reason' => sanitize_text_field( $refund->get_reason() ),
					'date' => $refund->get_date_created() ? gmdate( 'c', $refund->get_date_created()->getTimestamp() ) : '',
					'entityId' => sanitize_text_field( $entity_id ), 'state' => '' !== $entity_id ? 'synced' : 'pending',
					'adminUrl' => admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order->get_id() ),
				];
			}
		}
		usort( $rows, static fn( array $a, array $b ): int => strcmp( (string) $b['date'], (string) $a['date'] ) );
		return self::slice_payload( $rows, $page, $limit, count( self::recent_orders( self::MAX_SCAN ) ) >= self::MAX_SCAN );
	}

	private static function customers( int $page, int $limit ): array {
		$customers = [];
		foreach ( self::recent_orders( self::MAX_SCAN ) as $order ) {
			$email = sanitize_email( $order->get_billing_email() );
			$key   = '' !== $email ? strtolower( $email ) : 'order-' . $order->get_id();
			if ( ! isset( $customers[ $key ] ) ) {
				$user_id   = (int) $order->get_user_id();
				$entity_id = $user_id > 0 ? (string) get_user_meta( $user_id, '_dtb_qbo_customer_id_' . dtb_qbo_environment(), true ) : '';
				$customers[ $key ] = [
					'name' => sanitize_text_field( trim( $order->get_formatted_billing_full_name() ) ), 'email' => $email,
					'orders' => 0, 'total' => 0.0, 'entityId' => sanitize_text_field( $entity_id ),
					'lastOrder' => $order->get_date_created() ? gmdate( 'c', $order->get_date_created()->getTimestamp() ) : '',
					'registered' => $user_id > 0,
				];
			}
			++$customers[ $key ]['orders'];
			$customers[ $key ]['total'] += (float) $order->get_total();
		}
		$rows = array_values( $customers );
		usort( $rows, static fn( array $a, array $b ): int => strcmp( (string) $b['lastOrder'], (string) $a['lastOrder'] ) );
		foreach ( $rows as &$row ) {
			$row['total'] = wc_format_decimal( $row['total'], 2 );
			$row['state'] = '' !== $row['entityId'] ? 'synced' : ( $row['registered'] ? 'unmapped' : 'guest' );
		}
		unset( $row );
		return self::slice_payload( $rows, $page, $limit, count( self::recent_orders( self::MAX_SCAN ) ) >= self::MAX_SCAN );
	}

	private static function reconciliation( int $page, int $limit ): array {
		$rows = [];
		$orders = self::recent_orders( self::MAX_SCAN );
		foreach ( $orders as $order ) {
			if ( self::is_accounting_eligible( $order ) && 'synced' !== self::projection_state( $order ) ) {
				$rows[] = self::order_row( $order );
			}
		}
		return self::slice_payload( $rows, $page, $limit, count( $orders ) >= self::MAX_SCAN );
	}

	private static function activity( int $page, int $limit ): array {
		$rows = [];
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$candidate_limit = min( self::MAX_SCAN, max( $limit * 5, 100 ) );
			$actions = as_get_scheduled_actions( [
				'group' => 'dtb-orders', 'per_page' => $candidate_limit, 'orderby' => 'date', 'order' => 'DESC',
			], 'OBJECT' );
			foreach ( (array) $actions as $action ) {
				if ( ! is_object( $action ) || ! method_exists( $action, 'get_hook' ) ) {
					continue;
				}
				$hook = sanitize_key( (string) $action->get_hook() );
				if ( ! in_array( $hook, [ 'dtb_order_sync_quickbooks', 'dtb_qbo_sync_order', 'dtb_qbo_sync_refund' ], true ) && ! str_contains( $hook, 'quickbooks' ) ) {
					continue;
				}
				$schedule = method_exists( $action, 'get_schedule' ) ? $action->get_schedule() : null;
				$date     = is_object( $schedule ) && method_exists( $schedule, 'get_date' ) ? $schedule->get_date() : null;
				$rows[]   = [
					'hook' => $hook,
					'status' => method_exists( $action, 'get_status' ) ? sanitize_key( (string) $action->get_status() ) : 'unknown',
					'date' => $date instanceof DateTimeInterface ? $date->format( DATE_ATOM ) : '',
				];
			}
			return self::slice_payload( $rows, $page, $limit, count( (array) $actions ) >= $candidate_limit );
		}
		return self::page_payload( [], $page, $limit, 0, false );
	}

	private static function recent_orders( int $limit ): array {
		$orders = wc_get_orders( [ 'limit' => min( self::MAX_SCAN, max( 1, $limit ) ), 'orderby' => 'date', 'order' => 'DESC', 'status' => array_keys( wc_get_order_statuses() ) ] );
		return array_values( array_filter( (array) $orders, static fn( $order ): bool => $order instanceof WC_Order ) );
	}

	private static function is_accounting_eligible( WC_Order $order ): bool {
		return (bool) $order->get_date_paid() && '' !== (string) $order->get_transaction_id();
	}

	private static function order_row( WC_Order $order ): array {
		$entity_id = (string) ( $order->get_meta( '_dtb_quickbooks_entity_id', true ) ?: $order->get_meta( '_dtb_qbo_receipt_id', true ) );
		return [
			'id' => $order->get_id(), 'number' => $order->get_order_number(),
			'date' => $order->get_date_created() ? gmdate( 'c', $order->get_date_created()->getTimestamp() ) : '',
			'customer' => sanitize_text_field( trim( $order->get_formatted_billing_full_name() ) ), 'email' => sanitize_email( $order->get_billing_email() ),
			'total' => wc_format_decimal( (float) $order->get_total(), 2 ), 'currency' => $order->get_currency(),
			'orderStatus' => sanitize_key( $order->get_status() ), 'paid' => self::is_accounting_eligible( $order ),
			'entityId' => sanitize_text_field( $entity_id ),
			'docNumber' => function_exists( 'dtb_qbo_order_doc_number' ) ? dtb_qbo_order_doc_number( $order ) : 'DTB-' . $order->get_id(),
			'state' => self::projection_state( $order ),
			'adminUrl' => admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order->get_id() ),
		];
	}

	private static function projection_state( WC_Order $order ): string {
		if ( '' !== (string) ( $order->get_meta( '_dtb_quickbooks_entity_id', true ) ?: $order->get_meta( '_dtb_qbo_receipt_id', true ) ) ) {
			return 'synced';
		}
		if ( $order->get_meta( '_dtb_qbo_sync_error', true ) || $order->get_meta( '_dtb_quickbooks_error', true ) ) {
			return 'failed';
		}
		return self::is_accounting_eligible( $order ) ? 'pending' : 'ineligible';
	}

	private static function slice_payload( array $rows, int $page, int $limit, bool $truncated ): array {
		$total  = count( $rows );
		$offset = ( $page - 1 ) * $limit;
		return self::page_payload( array_slice( $rows, $offset, $limit ), $page, $limit, $total, $truncated );
	}

	private static function page_payload( array $rows, int $page, int $limit, int $total, bool $truncated ): array {
		return [
			'rows' => array_values( $rows ), 'page' => $page, 'limit' => $limit, 'total' => $total,
			'pages' => max( 1, (int) ceil( $total / $limit ) ), 'truncated' => $truncated,
		];
	}
}

DTB_QuickBooksEnterpriseController::register();
