<?php
/**
 * Rest — RepairStatusController: GET /wp-json/dtb/v1/repairs/{id}/status
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_repair_register_status_route' );

function dtb_repair_register_status_route(): void {
	$routes = [
		'/repairs/(?P<id>\d+)/status',
		'/repairs/status/(?P<id>\d+)',
	];

	foreach ( $routes as $route ) {
		register_rest_route(
			'dtb/v1',
			$route,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'dtb_repair_rest_status',
				'permission_callback' => '__return_true',
				'args'                => [
					'id'    => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
					'token' => [ 'type' => 'string', 'required' => false, 'default' => '' ],
				],
			]
		);
	}
}

function dtb_repair_rest_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
$repair_id    = (int) $request->get_param( 'id' );
$public_token = sanitize_text_field( (string) $request->get_param( 'token' ) );

$access = dtb_validate_repair_access( $repair_id, $public_token );
if ( is_wp_error( $access ) ) {
return $access;
}

$projection = dtb_build_repair_status_projection( $repair_id );

return new WP_REST_Response( [ 'success' => true, 'data' => $projection ], 200 );
}
