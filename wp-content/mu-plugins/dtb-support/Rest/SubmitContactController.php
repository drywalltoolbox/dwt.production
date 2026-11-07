<?php
/**
 * REST — SubmitContactController: public endpoint for contact form submissions.
 *
 * Route: POST /wp-json/dtb/v1/support/submit
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the public contact-submit REST route.
 */
function dtb_support_register_submit_route(): void {
	register_rest_route( 'dtb/v1', '/support/submit', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'dtb_support_rest_submit_contact',
		'permission_callback' => '__return_true', // Public endpoint.
		'args'                => [
			'name'    => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
			'email'   => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_email' ],
			'subject' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
			'message' => [ 'type' => 'string', 'required' => true ],
			'type'    => [ 'type' => 'string', 'required' => false, 'default' => 'contact' ],
			'order_id'   => [ 'type' => 'integer', 'required' => false ],
			'product_id' => [ 'type' => 'integer', 'required' => false ],
			'website' => [ 'type' => 'string', 'required' => false, 'default' => '' ], // honeypot
		],
	] );
}
add_action( 'rest_api_init', 'dtb_support_register_submit_route' );

/**
 * Handle POST /dtb/v1/support/submit.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function dtb_support_rest_submit_contact( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$data = $request->get_params();

	$valid = dtb_support_validate_contact_payload( $data );
	if ( is_wp_error( $valid ) ) {
		return new WP_REST_Response(
			[ 'success' => false, 'errors' => $valid->get_error_messages() ],
			$valid->get_error_data()['status'] ?? 422
		);
	}

	$result = dtb_support_submit_contact_request( $data );
	if ( is_wp_error( $result ) ) {
		return new WP_REST_Response(
			[ 'success' => false, 'message' => $result->get_error_message() ],
			500
		);
	}

	return new WP_REST_Response( [
		'success'       => true,
		'ticket_id'     => $result['ticket_id'],
		'ticket_number' => $result['ticket_number'],
		'public_token'  => $result['public_token'] ?? '',
		'message'       => __( 'Your message has been received. We\'ll be in touch shortly.', 'drywall-toolbox' ),
	], 201 );
}
