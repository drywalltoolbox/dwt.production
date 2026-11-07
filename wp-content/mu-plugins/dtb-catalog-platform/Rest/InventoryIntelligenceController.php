<?php
/**
 * Inventory Intelligence REST controller.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_InventoryIntelligenceController {
	public static function register_routes(): void {
		register_rest_route(
			'dtb/v1',
			'/inventory/health',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'handle_health' ],
				'permission_callback' => [ self::class, 'can_read' ],
			]
		);

		register_rest_route(
			'dtb/v1',
			'/inventory/universal-parts',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'handle_universal_parts' ],
				'permission_callback' => [ self::class, 'can_read' ],
				'args'                => [
					'page' => [
						'sanitize_callback' => 'absint',
					],
					'signal' => [
						'sanitize_callback' => 'sanitize_key',
					],
					'search' => [
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			'dtb/v1',
			'/inventory/substitutes/(?P<sku>[A-Za-z0-9._-]+)',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'handle_substitutes' ],
				'permission_callback' => [ self::class, 'can_read' ],
				'args'                => [
					'sku' => [
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			'dtb/v1',
			'/inventory/recompute',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'handle_recompute' ],
				'permission_callback' => [ self::class, 'can_write' ],
			]
		);
	}

	public static function can_read(): bool {
		return current_user_can( 'dtb_manage_inventory_intelligence' ) || current_user_can( 'dtb_manage_parts' );
	}

	public static function can_write(): bool {
		return current_user_can( 'dtb_manage_inventory_intelligence' );
	}

	public static function handle_health(): WP_REST_Response {
		$service = new DTB_InventoryIntelligenceService();
		return new WP_REST_Response( $service->health(), 200 );
	}

	public static function handle_universal_parts( WP_REST_Request $request ): WP_REST_Response {
		$page   = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
		$signal = sanitize_key( (string) $request->get_param( 'signal' ) );
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		if ( ! in_array( $signal, [ '', 'none', 'watch', 'reorder', 'critical' ], true ) ) {
			$signal = '';
		}

		$service = new DTB_InventoryIntelligenceService();
		return new WP_REST_Response( $service->list_universal_stock( $page, $signal, $search ), 200 );
	}

	public static function handle_substitutes( WP_REST_Request $request ): WP_REST_Response {
		$sku     = sanitize_text_field( (string) $request->get_param( 'sku' ) );
		$service = new DTB_InventoryIntelligenceService();
		return new WP_REST_Response( $service->substitute_preview( $sku ), 200 );
	}

	public static function handle_recompute(): WP_REST_Response {
		$sync_service   = new DTB_VeeqoStockSyncService();
		$rollup_service = new DTB_InventoryRollupService();

		$stock_result  = $sync_service->sync_from_woocommerce( true );
		$rollup_result = $rollup_service->recompute_all();

		return new WP_REST_Response(
			[
				'stock'  => $stock_result,
				'rollup' => $rollup_result,
			],
			200
		);
	}
}
