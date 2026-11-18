<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_OpsAuditController' ) ) {
	return;
}

final class DTB_OpsAuditController extends DTB_AbstractRestController {
	public static function register_routes(): void {
		register_rest_route(
			'dtb/v1',
			'/ops/audit',
			[
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				'callback'            => [ self::class, 'handle' ],
			]
		);
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$per_page = self::per_page_from_request( $request, 50, 200 );
		$page     = self::page_from_request( $request, 1 );
		$offset   = ( $page - 1 ) * $per_page;
		$rows     = class_exists( 'DTB_OpsAuditLog' ) ? DTB_OpsAuditLog::recent( $per_page, $offset ) : [];

		return DTB_RestResponseFactory::ok(
			[
				'rows'       => $rows,
				'pagination' => DTB_RestSchema::pagination( $page, $per_page, count( $rows ) ),
			]
		);
	}
}

add_action( 'rest_api_init', [ DTB_OpsAuditController::class, 'register_routes' ], 20 );
