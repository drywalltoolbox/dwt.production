<?php
/**
 * Supported Veeqo REST compatibility aliases.
 *
 * The historical Veeqo client no longer registers admin inventory/status routes.
 * These aliases preserve known callers while delegating to canonical read models,
 * queues, and permission checks. No alias performs catalog-wide synchronous work.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_Veeqo_Compatibility_Controller {
	public static function register(): void {
		// Preserve the existing server-authoritative Woo shipping-policy adapter.
		if ( function_exists( 'dtb_veeqo_route_shipping_rates' ) ) {
			register_rest_route( 'dtb/v1', '/veeqo/shipping-rates', [
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => '__return_true',
				'callback'            => 'dtb_veeqo_route_shipping_rates',
			], true );
		}

		// Preserve the historical public repair intake URL until all clients migrate
		// to the owning repair-service namespace. The existing callback retains its
		// validation and persistence contract.
		if ( function_exists( 'dtb_veeqo_route_repair_request' ) ) {
			register_rest_route( 'dtb/v1', '/repair-request', [
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => '__return_true',
				'callback'            => 'dtb_veeqo_route_repair_request',
			], true );
		}

		register_rest_route( 'dtb/v1', '/veeqo/status', [
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => [ __CLASS__, 'can_manage' ],
			'callback'            => static fn(): WP_REST_Response => rest_ensure_response( DTB_Veeqo_Admin_Read_Model::overview() ),
		], true );

		register_rest_route( 'dtb/v1', '/veeqo/inventory', [
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => [ __CLASS__, 'can_manage' ],
			'callback'            => static fn( WP_REST_Request $request ): WP_REST_Response => rest_ensure_response( DTB_Veeqo_Admin_Read_Model::inventory( $request->get_params() ) ),
			'args'                => [
				'page'     => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1, 'sanitize_callback' => 'absint' ],
				'per_page' => [ 'type' => 'integer', 'minimum' => 10, 'maximum' => 100, 'default' => 50, 'sanitize_callback' => 'absint' ],
			],
		], true );

		$reconcile = [
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => [ __CLASS__, 'can_manage' ],
			'callback'            => [ __CLASS__, 'reconcile' ],
			'args'                => [ 'dry_run' => [ 'type' => 'boolean', 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ] ],
		];
		register_rest_route( 'dtb/v1', '/veeqo/inventory/pull', $reconcile, true );
		register_rest_route( 'dtb/v1', '/veeqo/map-skus', $reconcile, true );

		register_rest_route( 'dtb/v1', '/veeqo/sync-order/(?P<order_id>[\d]+)', [
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => [ __CLASS__, 'can_manage' ],
			'callback'            => [ 'DTB_Veeqo_Admin_Controller', 'retry_order' ],
			'args'                => [
				'order_id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1, 'sanitize_callback' => 'absint' ],
				'reason'   => [ 'type' => 'string', 'default' => 'compatibility_route', 'sanitize_callback' => 'sanitize_text_field' ],
			],
		], true );

		register_rest_route( 'dtb/v1', '/veeqo/webhooks/ensure', [
			'methods'             => WP_REST_Server::DELETABLE,
			'permission_callback' => [ __CLASS__, 'can_manage' ],
			'callback'            => static fn(): WP_Error => new WP_Error(
				'veeqo_webhook_registration_retired',
				'Automatic Veeqo webhook registration is retired. Inbound fulfillment remains fail-closed until the upstream authentication contract is verified.',
				[ 'status' => 410 ]
			),
		], true );
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	public static function reconcile( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'DTB_Veeqo_Operation_Store' ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'code' => 'operation_store_unavailable', 'message' => 'Veeqo operation storage is unavailable.' ], 503 );
		}
		$result = DTB_Veeqo_Operation_Store::enqueue( rest_sanitize_boolean( $request->get_param( 'dry_run' ) ) );
		return new WP_REST_Response( $result, absint( $result['status'] ?? 200 ) );
	}
}

add_action( 'rest_api_init', [ 'DTB_Veeqo_Compatibility_Controller', 'register' ], 60 );
