<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_OpsSettingsController' ) ) {
	return;
}

final class DTB_OpsSettingsController extends DTB_AbstractRestController {
	public static function register_routes(): void {
		register_rest_route(
			'dtb/v1',
			'/ops/settings',
			[
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static fn() => function_exists( 'dtb_oo_can_manage_settings' ) ? dtb_oo_can_manage_settings() : current_user_can( 'manage_options' ),
				'callback'            => [ self::class, 'get' ],
			]
		);

		register_rest_route(
			'dtb/v1',
			'/ops/settings',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => static fn() => function_exists( 'dtb_oo_can_manage_settings' ) ? dtb_oo_can_manage_settings() : current_user_can( 'manage_options' ),
				'callback'            => [ self::class, 'update' ],
			]
		);
	}

	public static function get( WP_REST_Request $request ): WP_REST_Response {
		$settings = function_exists( 'dtb_oo_get_settings' ) ? dtb_oo_get_settings() : [];
		return DTB_RestResponseFactory::ok( [ 'settings' => $settings ] );
	}

	public static function update( WP_REST_Request $request ): WP_REST_Response {
		$raw = $request->get_json_params();
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}

		$result = function_exists( 'dtb_oo_save_settings' )
			? dtb_oo_save_settings( $raw )
			: [ 'ok' => false, 'message' => 'Settings service unavailable.' ];

		return rest_ensure_response( $result );
	}
}

add_action( 'rest_api_init', [ DTB_OpsSettingsController::class, 'register_routes' ], 20 );
