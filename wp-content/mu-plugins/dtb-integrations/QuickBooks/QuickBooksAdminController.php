<?php
/**
 * QuickBooks administrator control-center REST controller.
 *
 * Provides a redacted read model and explicit operator actions. Accounting
 * writes remain queue-owned by the canonical order platform.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_QuickBooksAdminController {
	private const REST_NAMESPACE = 'dtb/v1';

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'register_rest_routes' ] );
		add_action( 'admin_init', [ self::class, 'redirect_oauth_notice' ], 5 );
		add_action( 'admin_post_dtb_qbo_accounting_export', [ self::class, 'export_csv' ] );
	}

	/**
	 * Move the legacy OAuth callback landing page into the permanent control
	 * center while preserving the redacted result code.
	 */
	public static function redirect_oauth_notice(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice = sanitize_key( wp_unslash( $_GET['dtb_qbo_notice'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page   = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		global $pagenow;

		if ( '' === $notice || 'index.php' !== $pagenow || 'dtb-quickbooks' === $page ) {
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'           => 'dtb-quickbooks',
					'dtb_qbo_notice' => $notice,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function register_rest_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/admin/qbo/dashboard',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ self::class, 'rest_dashboard' ],
				'permission_callback' => [ self::class, 'can_manage' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/admin/qbo/accounting/control',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'rest_accounting_control' ],
				'permission_callback' => [ self::class, 'can_manage' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/admin/qbo/items/discover',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'rest_discover_items' ],
				'permission_callback' => [ self::class, 'can_manage' ],
			]
		);

	}

	public static function rest_accounting_control( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		if ( 'dry_run' === $action ) {
			$order = wc_get_order( absint( $request->get_param( 'order_id' ) ) );
			if ( ! $order instanceof WC_Order ) {
				return new WP_Error( 'qbo_order_not_found', 'The WooCommerce order was not found.', [ 'status' => 404 ] );
			}
			$result = DTB_QBO_AccountingService::prepare_order( $order, false );
			return is_wp_error( $result ) ? $result : new WP_REST_Response( [ 'ok' => true, 'preview' => [ 'document' => $result['document_number'], 'hash' => $result['payload_hash'], 'composition' => $result['composition'], 'body' => $result['body'] ] ], 200 );
		}
		if ( 'save_rules' === $action ) {
			$input      = (array) $request->get_param( 'policy' );
			$validation = DTB_QBO_AccountingOperations::validate_policy( $input );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
			$policy = DTB_QBO_AccountingLedger::save_policy( $input, get_current_user_id() );
			return new WP_REST_Response( [ 'ok' => true, 'policy' => $policy ], 200 );
		}
		if ( 'review_exemption' === $action ) {
			$order  = wc_get_order( absint( $request->get_param( 'order_id' ) ) );
			$status = sanitize_key( (string) $request->get_param( 'status' ) );
			if ( ! $order instanceof WC_Order || ! in_array( $status, [ 'approved', 'rejected' ], true ) ) {
				return new WP_Error( 'qbo_invalid_exemption_review', 'A valid order and exemption review decision are required.', [ 'status' => 400 ] );
			}
			$order->update_meta_data( '_dtb_tax_exemption_status', $status );
			$order->update_meta_data( '_dtb_tax_exemption_reviewed_by', get_current_user_id() );
			$order->update_meta_data( '_dtb_tax_exemption_reviewed_at', gmdate( 'c' ) );
			$order->save_meta_data();
			if ( function_exists( 'dtb_order_append_event' ) ) {
				dtb_order_append_event( $order->get_id(), 'accounting.tax_exemption_reviewed', [ 'source' => 'admin', 'actor_type' => 'user', 'visibility' => 'operator', 'payload' => [ 'status' => $status, 'reviewed_by' => get_current_user_id() ] ] );
			}
			return new WP_REST_Response( [ 'ok' => true, 'status' => $status ], 200 );
		}
		if ( 'close_period' === $action ) {
			$date = sanitize_text_field( (string) $request->get_param( 'closed_through' ) );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				return new WP_Error( 'qbo_invalid_close_date', 'A valid close date is required.', [ 'status' => 400 ] );
			}
			$open = DTB_QBO_AccountingLedger::query( [ 'page' => 1, 'limit' => 1, 'states' => [ 'exception', 'failed', 'pending', 'queued' ], 'date_to' => $date ] );
			if ( (int) $open['total'] > 0 ) {
				return new WP_Error( 'qbo_close_blocked', 'Resolve all exceptions and pending documents through the close date before closing.', [ 'status' => 409, 'unresolved' => (int) $open['total'] ] );
			}
			$policy = DTB_QBO_AccountingLedger::save_policy( [ 'closed_through' => $date ], get_current_user_id() );
			return new WP_REST_Response( [ 'ok' => true, 'policy' => $policy ], 200 );
		}
		if ( in_array( $action, [ 'reports', 'settlement' ], true ) ) {
			if ( 'settlement' === $action && ( ! function_exists( 'dtb_qbo_exports_paused' ) || dtb_qbo_exports_paused() ) ) {
				return new WP_Error( 'qbo_settlement_disabled', 'The legacy Stripe settlement importer is disabled. WooPayments is the active gateway.', [ 'status' => 409 ] );
			}
			$job = DTB_QBO_AccountingOperations::enqueue( $action );
			return $job ? new WP_REST_Response( [ 'ok' => true, 'queued' => true, 'actionId' => $job ], 202 ) : new WP_Error( 'qbo_queue_unavailable', 'Action Scheduler is unavailable.', [ 'status' => 503 ] );
		}
		if ( 'reconcile' === $action ) {
			$document = DTB_QBO_AccountingLedger::get( absint( $request->get_param( 'document_id' ) ) );
			if ( ! $document || empty( $document['order_id'] ) || ! function_exists( 'dtb_order_enqueue_job' ) ) {
				return new WP_Error( 'qbo_document_not_reconcilable', 'This accounting document cannot be queued for reconciliation.', [ 'status' => 409 ] );
			}
			$args = 'refund' === $document['source_type']
				? [ 'action' => 'refund', 'refund_id' => absint( $document['source_id'] ), 'trigger' => 'qbo_control_center' ]
				: [ 'action' => 'create', 'trigger' => 'qbo_control_center' ];
			$job = dtb_order_enqueue_job( 'dtb_order_sync_quickbooks', absint( $document['order_id'] ), $args );
			return new WP_REST_Response( [ 'ok' => (bool) $job, 'queued' => (bool) $job, 'actionId' => $job ], $job ? 202 : 409 );
		}
		return new WP_Error( 'qbo_invalid_control_action', 'Unsupported accounting action.', [ 'status' => 400 ] );
	}

	public static function export_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export accounting data.', 'drywall-toolbox' ) );
		}
		check_admin_referer( 'dtb_qbo_accounting_export' );
		$rows = [];
		$page = 1;
		do {
			$result = DTB_QBO_AccountingLedger::query( [ 'page' => $page, 'limit' => 100 ] );
			$rows   = array_merge( $rows, $result['rows'] );
			++$page;
		} while ( $page <= (int) $result['pages'] );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="dtb-accounting-ledger-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, [ 'Date', 'Source', 'Document', 'Direction', 'Currency', 'Expected', 'QBO total', 'Variance', 'Tax', 'Refunded tax', 'Fees', 'State', 'Exception', 'Policy', 'Payload hash', 'Trace ID' ] );
		foreach ( $rows as $row ) {
			fputcsv( $output, [ $row['txn_date'], $row['source_key'], $row['document_number'], $row['direction'], $row['currency'], $row['expected_total'], $row['qbo_total'], $row['variance'], $row['tax_total'], $row['refunded_tax'], $row['fee_total'], $row['state'], $row['exception_code'], $row['policy_version'], $row['payload_hash'], $row['trace_id'] ] );
		}
		fclose( $output );
		exit;
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function rest_dashboard(): WP_REST_Response {
		return new WP_REST_Response( self::read_model(), 200 );
	}

	public static function rest_discover_items(): WP_REST_Response|WP_Error {
		$result = DTB_QuickBooksItemMappingService::discover_and_store();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			[
				'ok'        => true,
				'discovery' => $result,
				'dashboard' => self::read_model(),
			],
			200
		);
	}

	/**
	 * Build the redacted control-center read model.
	 *
	 * @return array<string, mixed>
	 */
	public static function read_model(): array {
		$status_response = DTB_QuickBooksOAuthController::rest_status();
		$status          = (array) $status_response->get_data();
		$mappings        = DTB_QuickBooksItemMappingService::status();
		$items_ready     = DTB_QuickBooksItemMappingService::ready();
		$connected       = ! empty( $status['connected'] );
		$company_ready   = ! empty( $status['company_verified'] );
		$webhook_ready   = ! empty( $status['webhook_verifier_configured'] );
		$credentials     = ! empty( $status['credentials_configured'] );
		$token_expires   = (int) ( $status['token_expires_at'] ?? 0 );
		$company         = get_option( dtb_qbo_option_name( 'company' ), [] );
		$mapping_verified_at = '';
		$accounting_policy   = DTB_QBO_AccountingLedger::policy();

		foreach ( $mappings as $mapping ) {
			if ( ! empty( $mapping['verified_at'] ) ) {
				$mapping_verified_at = sanitize_text_field( (string) $mapping['verified_at'] );
				break;
			}
		}

		$checks = [
			'credentials' => [
				'label'       => __( 'Credentials', 'drywall-toolbox' ),
				'complete'    => $credentials,
				'description' => $credentials ? __( 'Client credentials are configured.', 'drywall-toolbox' ) : __( 'Client credentials are missing.', 'drywall-toolbox' ),
			],
			'connection'  => [
				'label'       => __( 'OAuth connection', 'drywall-toolbox' ),
				'complete'    => $connected,
				'description' => $connected ? __( 'Encrypted access and refresh tokens are stored.', 'drywall-toolbox' ) : __( 'QuickBooks is not connected.', 'drywall-toolbox' ),
			],
			'company'     => [
				'label'       => __( 'Company verification', 'drywall-toolbox' ),
				'complete'    => $company_ready,
				'description' => $company_ready ? __( 'The connected company was verified through the Intuit API.', 'drywall-toolbox' ) : __( 'Company access has not been verified.', 'drywall-toolbox' ),
			],
			'items'       => [
				'label'       => __( 'Accounting items', 'drywall-toolbox' ),
				'complete'    => $items_ready,
				'description' => $items_ready ? __( 'All required Service items are mapped and verified for the connected company.', 'drywall-toolbox' ) : __( 'Accounting item mappings are missing, stale, or not verified for the connected company.', 'drywall-toolbox' ),
			],
			'webhook'     => [
				'label'       => __( 'Webhook verification', 'drywall-toolbox' ),
				'complete'    => $webhook_ready,
				'description' => $webhook_ready ? __( 'Signed webhook verification is configured.', 'drywall-toolbox' ) : __( 'Webhook verifier configuration is missing.', 'drywall-toolbox' ),
			],
			'tax_policy'  => [
				'label'       => __( 'Tax mapping', 'drywall-toolbox' ),
				'complete'    => '' !== (string) $accounting_policy['tax_code_id'],
				'description' => '' !== (string) $accounting_policy['tax_code_id'] ? __( 'An accountant-approved QuickBooks transaction tax code is configured.', 'drywall-toolbox' ) : __( 'The QuickBooks transaction tax code requires accountant approval.', 'drywall-toolbox' ),
			],
		];

		$ready = $credentials && $connected && $company_ready && $items_ready && $webhook_ready && '' !== (string) $accounting_policy['tax_code_id'];

		return [
			'ok'          => true,
			'generatedAt' => gmdate( 'c' ),
			'status'      => $status,
			'readiness'   => [
				'ready'  => $ready,
				'checks' => $checks,
			],
			'items'       => array_values( $mappings ),
			'mapping'     => [
				'ready'      => $items_ready,
				'verifiedAt' => $mapping_verified_at,
			],
			'token'       => [
				'expiresAt'    => $token_expires,
				'expiresAtIso' => $token_expires > 0 ? gmdate( 'c', $token_expires ) : '',
				'expired'      => $token_expires > 0 && $token_expires <= time(),
			],
			'company'     => [
				'name'        => sanitize_text_field( (string) ( $status['company_name'] ?? '' ) ),
				'country'     => is_array( $company ) ? sanitize_text_field( (string) ( $company['country'] ?? '' ) ) : '',
				'verifiedAt'  => is_array( $company ) ? sanitize_text_field( (string) ( $company['verified_at'] ?? '' ) ) : '',
				'realmSuffix' => sanitize_text_field( (string) ( $status['realm_suffix'] ?? '' ) ),
			],
			'links'       => [
				'quickbooks' => 'sandbox' === dtb_qbo_environment() ? 'https://sandbox.qbo.intuit.com/app/homepage' : 'https://qbo.intuit.com/app/homepage',
				'orders'     => admin_url( 'admin.php?page=wc-orders' ),
				'scheduler'  => admin_url( 'tools.php?page=action-scheduler&status=pending&s=quickbooks' ),
			],
		];
	}
}

DTB_QuickBooksAdminController::register();
