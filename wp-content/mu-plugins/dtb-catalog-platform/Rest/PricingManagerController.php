<?php
/**
 * DTB Catalog Platform — Pricing Manager admin REST controller.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_pricing_manager_register_routes' );

function dtb_pricing_manager_register_routes(): void {
	$permission = static fn(): bool => current_user_can( 'dtb_manage_catalog_pricing' );
	register_rest_route( 'dtb/v1', '/admin/pricing/products', [
		'methods' => WP_REST_Server::READABLE,
		'callback' => 'dtb_pricing_manager_rest_products',
		'permission_callback' => $permission,
		'args' => [
			'search' => [ 'sanitize_callback' => 'sanitize_text_field' ],
			'brand' => [ 'sanitize_callback' => 'sanitize_text_field' ],
			'status' => [ 'sanitize_callback' => 'sanitize_key' ],
			'map_only' => [ 'sanitize_callback' => 'rest_sanitize_boolean' ],
			'page' => [ 'sanitize_callback' => 'absint' ],
			'per_page' => [ 'sanitize_callback' => 'absint' ],
			'sort' => [ 'sanitize_callback' => 'sanitize_key' ],
			'direction' => [ 'sanitize_callback' => 'sanitize_key' ],
		],
	] );
	register_rest_route( 'dtb/v1', '/admin/pricing/data', [ 'methods' => WP_REST_Server::READABLE, 'callback' => static fn(): WP_REST_Response => new WP_REST_Response( dtb_pricing_get_data_summary(), 200 ), 'permission_callback' => $permission ] );
	register_rest_route( 'dtb/v1', '/admin/pricing/product/(?P<id>\d+)', [
		[ 'methods' => WP_REST_Server::READABLE, 'callback' => 'dtb_pricing_manager_rest_product', 'permission_callback' => $permission ],
		[ 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dtb_pricing_manager_rest_update_product', 'permission_callback' => $permission ],
	] );
	register_rest_route( 'dtb/v1', '/admin/pricing/settings', [ 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dtb_pricing_manager_rest_update_settings', 'permission_callback' => $permission ] );
	register_rest_route( 'dtb/v1', '/admin/pricing/apply', [ 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dtb_pricing_manager_rest_apply_selected', 'permission_callback' => $permission ] );
}

function dtb_pricing_manager_rest_products( WP_REST_Request $request ): WP_REST_Response {
	return new WP_REST_Response( dtb_pricing_query_products( [
		'search' => $request->get_param( 'search' ), 'brand' => $request->get_param( 'brand' ),
		'status' => $request->get_param( 'status' ), 'map_only' => $request->get_param( 'map_only' ),
		'page' => $request->get_param( 'page' ), 'per_page' => $request->get_param( 'per_page' ),
		'sort' => $request->get_param( 'sort' ), 'direction' => $request->get_param( 'direction' ),
	] ), 200 );
}

function dtb_pricing_manager_rest_product( WP_REST_Request $request ) {
	$product = dtb_pricing_get_product( absint( $request['id'] ) );
	return null === $product ? new WP_Error( 'dtb_pricing_product_not_found', __( 'Product not found.', 'drywall-toolbox' ), [ 'status' => 404 ] ) : new WP_REST_Response( $product, 200 );
}

function dtb_pricing_manager_rest_update_product( WP_REST_Request $request ) {
	$payload = $request->get_json_params(); $fields = [];
	foreach ( [ 'regular_price', 'sale_price', 'map_price', 'map_source' ] as $field ) {
		if ( is_array( $payload ) && array_key_exists( $field, $payload ) ) { $fields[ $field ] = $payload[ $field ]; }
	}
	$updated = dtb_pricing_update_product( absint( $request['id'] ), $fields );
	return is_wp_error( $updated ) ? $updated : new WP_REST_Response( $updated, 200 );
}

function dtb_pricing_manager_rest_update_settings( WP_REST_Request $request ) {
	$payload = $request->get_json_params();
	if ( ! is_array( $payload ) ) { return new WP_Error( 'dtb_pricing_invalid_policy', __( 'Pricing policy payload is required.', 'drywall-toolbox' ), [ 'status' => 400 ] ); }
	$mapping = [
		'target_margin' => 'global_target_margin',
		'minimum_margin' => 'global_minimum_margin',
		'no_change_threshold_pct' => 'no_change_threshold_pct',
		'review_change_threshold_pct' => 'review_change_threshold_pct',
		'block_change_threshold_pct' => 'block_change_threshold_pct',
	];
	$incoming = [];
	foreach ( $mapping as $request_key => $policy_key ) {
		if ( array_key_exists( $request_key, $payload ) ) {
			if ( ! is_numeric( $payload[ $request_key ] ) ) { return new WP_Error( 'dtb_pricing_invalid_policy_value', __( 'Pricing policy values must be numeric.', 'drywall-toolbox' ), [ 'status' => 400 ] ); }
			$incoming[ $policy_key ] = (float) $payload[ $request_key ];
		}
	}
	if ( [] === $incoming ) { return new WP_Error( 'dtb_pricing_empty_policy', __( 'No supported pricing policy settings were supplied.', 'drywall-toolbox' ), [ 'status' => 400 ] ); }
	$current  = dtb_pricing_get_policy_settings();
	$proposed = array_merge( $current, $incoming );
	if ( $proposed['global_minimum_margin'] <= 0 || $proposed['global_target_margin'] >= 100 || $proposed['global_target_margin'] < $proposed['global_minimum_margin'] ) {
		return new WP_Error( 'dtb_pricing_invalid_policy_range', __( 'Minimum and target margins must be valid percentages, with target margin greater than or equal to minimum margin.', 'drywall-toolbox' ), [ 'status' => 400 ] );
	}
	$policy = dtb_pricing_set_policy_settings( $incoming );
	return new WP_REST_Response( [ 'policy' => $policy, 'target_margin' => $policy['global_target_margin'] ], 200 );
}

function dtb_pricing_manager_rest_apply_selected( WP_REST_Request $request ) {
	$payload = $request->get_json_params();
	$items = is_array( $payload ) && isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : [];
	if ( [] === $items ) { return new WP_Error( 'dtb_pricing_empty_apply', __( 'Select at least one pricing recommendation.', 'drywall-toolbox' ), [ 'status' => 400 ] ); }
	return new WP_REST_Response( dtb_pricing_apply_selected( $items ), 200 );
}
