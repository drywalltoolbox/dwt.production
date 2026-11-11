<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_OpsLocalQueueController' ) ) {
	return;
}

final class DTB_OpsLocalQueueController extends DTB_AbstractRestController {
	public static function register_routes(): void {
		register_rest_route(
			'dtb/v1',
			'/ops/queue/local',
			[
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static fn() => function_exists( 'dtb_oo_can_view' ) ? dtb_oo_can_view() : current_user_can( 'manage_options' ),
				'callback'            => [ self::class, 'handle' ],
			]
		);
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$args = [
			'paged'    => self::page_from_request( $request, 1 ),
			'per_page' => self::per_page_from_request( $request, 20, 200 ),
			'status'   => sanitize_text_field( (string) ( $request->get_param( 'status' ) ?? '' ) ),
		];

		$queue = class_exists( 'DTB_OrderOperationsQueueInspector' ) ? DTB_OrderOperationsQueueInspector::list_jobs( $args ) : [ 'items' => [] ];
		return DTB_RestResponseFactory::ok( [ 'queue' => $queue ] );
	}
}

add_action( 'rest_api_init', [ DTB_OpsLocalQueueController::class, 'register_routes' ], 20 );
