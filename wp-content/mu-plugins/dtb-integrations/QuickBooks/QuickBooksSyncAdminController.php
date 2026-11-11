<?php
/**
 * QuickBooks synchronization operator REST controller.
 *
 * Exposes bounded queue controls and redacted readiness diagnostics. Accounting
 * writes remain owned by the canonical dtb-orders Action Scheduler pipeline.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_QuickBooksSyncAdminController {
	private const REST_NAMESPACE = 'dtb/v1';
	private const MAX_BATCH      = 100;

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/admin/qbo/sync/status',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ self::class, 'status' ],
				'permission_callback' => [ self::class, 'can_manage' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/admin/qbo/sync/queue',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'queue' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'args'                => [
					'limit' => [
						'type'              => 'integer',
						'default'           => 25,
						'minimum'           => 1,
						'maximum'           => self::MAX_BATCH,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function status(): WP_REST_Response {
		return new WP_REST_Response( self::read_model(), 200 );
	}

	public static function queue( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$readiness = self::read_model();
		if ( ! empty( $readiness['exports_paused'] ) ) {
			return new WP_Error(
				'qbo_sync_disabled',
				__( 'DTB QuickBooks exports are disabled; WooCommerce sales sync is managed by the Intuit connector.', 'drywall-toolbox' ),
				[ 'status' => 409, 'blockers' => $readiness['blockers'] ]
			);
		}
		if ( empty( $readiness['ready'] ) ) {
			return new WP_Error(
				'qbo_sync_not_ready',
				__( 'QuickBooks synchronization is blocked until all connection and accounting-item checks pass.', 'drywall-toolbox' ),
				[
					'status'   => 409,
					'blockers' => $readiness['blockers'],
				]
			);
		}

		if ( ! function_exists( 'dtb_operational_pipeline_queue_qbo_sync_orders' ) ) {
			return new WP_Error(
				'qbo_queue_unavailable',
				__( 'The canonical QuickBooks queue service is unavailable.', 'drywall-toolbox' ),
				[ 'status' => 503 ]
			);
		}

		$limit  = min( self::MAX_BATCH, max( 1, absint( $request->get_param( 'limit' ) ?: 25 ) ) );
		$result = dtb_operational_pipeline_queue_qbo_sync_orders( $limit, 'qbo_control_center' );

		if ( ! empty( $result['error'] ) ) {
			return new WP_Error(
				'qbo_queue_failed',
				sanitize_text_field( (string) $result['error'] ),
				[ 'status' => 503 ]
			);
		}

		return new WP_REST_Response(
			[
				'ok'      => true,
				'queued'  => absint( $result['queued'] ?? 0 ),
				'skipped' => absint( $result['skipped'] ?? 0 ),
				'limit'   => $limit,
				'status'  => self::read_model(),
			],
			202
		);
	}

	/**
	 * Build a redacted synchronization read model.
	 *
	 * @return array<string, mixed>
	 */
	public static function read_model(): array {
		$blockers = [];
		$connected = function_exists( 'dtb_qbo_enabled' ) && dtb_qbo_enabled();
		$mapped    = class_exists( 'DTB_QuickBooksItemMappingService' ) && DTB_QuickBooksItemMappingService::ready();
		$queue     = function_exists( 'dtb_operational_pipeline_queue_qbo_sync_orders' );
		$policy    = class_exists( 'DTB_QBO_AccountingLedger' ) ? DTB_QBO_AccountingLedger::policy() : [];
		$exports_paused = ! function_exists( 'dtb_qbo_exports_paused' ) || dtb_qbo_exports_paused();

		if ( $exports_paused ) {
			$blockers[] = __( 'DTB QuickBooks writes are disabled while the Intuit WooCommerce Connector owns WooCommerce sales sync.', 'drywall-toolbox' );
		}

		if ( ! $connected ) {
			$blockers[] = __( 'QuickBooks is not connected.', 'drywall-toolbox' );
		}
		if ( ! $mapped ) {
			$blockers[] = __( 'Accounting item mappings are not verified for the connected company.', 'drywall-toolbox' );
		}
		if ( ! $queue ) {
			$blockers[] = __( 'The canonical dtb-orders queue service is unavailable.', 'drywall-toolbox' );
		}
		if ( empty( $policy['tax_code_id'] ) ) {
			$blockers[] = __( 'An accountant-approved QuickBooks transaction tax code is required.', 'drywall-toolbox' );
		}

		$placeholder_constants = [];
		foreach ( [ 'PRODUCT', 'SHIPPING', 'FEE', 'DISCOUNT', 'REFUND' ] as $key ) {
			$constant = 'DTB_QBO_ITEM_' . $key . '_ID';
			$value    = defined( $constant ) ? trim( (string) constant( $constant ) ) : '';
			if ( '' !== $value && preg_match( '/^(PASTE_|REPLACE_|YOUR_|TODO|TBD)/i', $value ) ) {
				$placeholder_constants[] = $constant;
			}
		}

		if ( ! empty( $placeholder_constants ) ) {
			$blockers[] = __( 'Placeholder QuickBooks Item IDs remain in wp-config.php.', 'drywall-toolbox' );
		}

		return [
			'ok'                    => true,
			'ready'                 => empty( $blockers ),
			'exports_paused'        => $exports_paused,
			'connected'             => $connected,
			'items_verified'        => $mapped,
			'queue_available'       => $queue,
			'blockers'              => $blockers,
			'placeholder_constants' => $placeholder_constants,
			'last_queued_at'        => sanitize_text_field( (string) get_option( 'dtb_qbo_last_sync_queued_at', '' ) ),
			'last_queued_count'     => absint( get_option( 'dtb_qbo_last_sync_queued_count', 0 ) ),
			'execution'             => $exports_paused ? 'disabled' : 'queued',
			'queue_group'           => 'dtb-orders',
			'hook'                  => 'dtb_order_sync_quickbooks',
		];
	}
}

DTB_QuickBooksSyncAdminController::register();
