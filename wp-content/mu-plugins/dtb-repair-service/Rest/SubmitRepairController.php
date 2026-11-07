<?php
/**
 * Rest — SubmitRepairController: POST /wp-json/dtb/v1/repairs/submit
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_repair_register_submit_route' );

function dtb_repair_register_submit_route(): void {
register_rest_route(
'dtb/v1',
'/repairs/submit',
[
'methods'             => WP_REST_Server::CREATABLE,
'callback'            => 'dtb_repair_rest_submit',
'permission_callback' => '__return_true',
]
);
}

function dtb_repair_rest_submit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
$data = $request->get_json_params() ?: $request->get_body_params();

$valid = dtb_validate_repair_submit( $data );
if ( is_wp_error( $valid ) ) {
	$errors = $valid->get_error_messages();

	return new WP_REST_Response(
		[
			'success' => false,
			'message' => $errors[0] ?? __( 'Repair submission failed validation.', 'drywall-toolbox' ),
			'errors'  => $errors,
		],
		422
	);
}

// Shipping price/name/id are never trusted from the browser. Recalculate the
// current repair policy quote and persist only the selected server-owned rate.
$shipping_rate = function_exists( 'dtb_repair_validate_shipping_selection' )
	? dtb_repair_validate_shipping_selection( $data )
	: new WP_Error(
		'dtb_repair_shipping_unavailable',
		__( 'Return shipping could not be validated. Please refresh shipping options and try again.', 'drywall-toolbox' ),
		[ 'status' => 503 ]
	);
if ( is_wp_error( $shipping_rate ) ) {
	$status = (int) ( $shipping_rate->get_error_data()['status'] ?? 422 );
	return new WP_REST_Response(
		[
			'success' => false,
			'code'    => $shipping_rate->get_error_code(),
			'message' => $shipping_rate->get_error_message(),
		],
		$status
	);
}

$data['shipping_rate_id']    = (string) $shipping_rate['id'];
$data['shipping_rate_name']  = (string) $shipping_rate['name'];
$data['shipping_rate_price'] = (float) $shipping_rate['price'];

$result = dtb_submit_repair_request( $data );
if ( is_wp_error( $result ) ) {
return $result;
}

$public_token = function_exists( 'dtb_repair_ensure_public_token' )
	? dtb_repair_ensure_public_token( $result )
	: sanitize_text_field( (string) get_post_meta( $result, '_repair_public_token', true ) );
if ( '' === $public_token ) {
	$public_token = function_exists( 'dtb_repair_generate_public_token' )
		? dtb_repair_generate_public_token()
		: wp_generate_password( 32, false, false );
	update_post_meta( $result, '_repair_public_token', $public_token );
}

$tracking_url = function_exists( 'dtb_repair_tracking_url' )
	? dtb_repair_tracking_url( (int) $result, $public_token )
	: add_query_arg(
		[ 'token' => $public_token ],
		home_url( '/repairs/status/' . $result )
	);

return new WP_REST_Response(
[
'success'      => true,
'repair_id'    => $result,
'public_token' => $public_token,
'status'       => '' !== (string) get_post_meta( $result, '_repair_status', true )
	? (string) get_post_meta( $result, '_repair_status', true )
	: 'submitted',
'tracking_url' => $tracking_url,
'message'      => __( 'Your repair request has been submitted.', 'drywall-toolbox' ),
],
201
);
}
