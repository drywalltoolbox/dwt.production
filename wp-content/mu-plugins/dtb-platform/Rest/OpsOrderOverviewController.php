<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_OpsOrderOverviewController' ) ) {
	return;
}

final class DTB_OpsOrderOverviewController extends DTB_AbstractRestController {
	public static function register_routes(): void {
		register_rest_route(
			'dtb/v1',
			'/ops/orders/overview',
			[
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static fn() => function_exists( 'dtb_oo_can_view' ) ? dtb_oo_can_view() : current_user_can( 'manage_options' ),
				'callback'            => [ self::class, 'handle' ],
			]
		);
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$kpis = function_exists( 'dtb_oo_get_overview_kpis' ) ? dtb_oo_get_overview_kpis() : [];
		return DTB_RestResponseFactory::ok( [ 'kpis' => $kpis ] );
	}
}

add_action( 'rest_api_init', [ DTB_OpsOrderOverviewController::class, 'register_routes' ], 20 );
