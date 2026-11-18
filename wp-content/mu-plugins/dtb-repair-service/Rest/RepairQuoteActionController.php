<?php
/**
 * Rest — RepairQuoteActionController: POST /wp-json/dtb/v1/repairs/{id}/quote
 *
 * Customer-facing quote accept/decline endpoint for status page actions.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_repair_register_quote_action_route' );

function dtb_repair_register_quote_action_route(): void {
	register_rest_route(
		'dtb/v1',
		'/repairs/(?P<id>\d+)/quote',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'dtb_repair_rest_quote_action',
			'permission_callback' => '__return_true',
			'args'                => [
				'id'     => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
				'token'  => [ 'type' => 'string', 'required' => false, 'default' => '' ],
				'action' => [ 'type' => 'string', 'required' => true ],
			],
		]
	);
}

function dtb_repair_rest_quote_action( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$repair_id = (int) $request->get_param( 'id' );
	$token     = sanitize_text_field( (string) $request->get_param( 'token' ) );
	$action    = sanitize_key( (string) $request->get_param( 'action' ) );

	$access = function_exists( 'dtb_validate_repair_access' )
		? dtb_validate_repair_access( $repair_id, $token )
		: true;
	if ( is_wp_error( $access ) ) {
		return $access;
	}

	if ( ! in_array( $action, [ 'accept', 'decline' ], true ) ) {
		return new WP_Error(
			'dtb_repair_quote_invalid_action',
			__( 'Invalid quote action.', 'drywall-toolbox' ),
			[ 'status' => 400 ]
		);
	}

	$current = function_exists( 'dtb_get_repair_status' )
		? dtb_get_repair_status( $repair_id )
		: (string) get_post_meta( $repair_id, '_repair_status', true );

	if ( 'quoted' !== $current ) {
		return new WP_Error(
			'dtb_repair_quote_not_actionable',
			__( 'This repair quote is no longer awaiting a response.', 'drywall-toolbox' ),
			[ 'status' => 409 ]
		);
	}

	$quote_status = 'accept' === $action ? 'accepted' : 'declined';
	$target       = 'accept' === $action ? 'quote_accepted' : 'quote_declined';

	if ( function_exists( 'dtb_repair_get_quote' ) && function_exists( 'dtb_repair_save_quote' ) ) {
		$quote           = dtb_repair_get_quote( $repair_id );
		$quote['status'] = $quote_status;
		dtb_repair_save_quote(
			$repair_id,
			$quote,
			[
				'actor_type' => 'customer',
				'actor_id'   => get_current_user_id() ?: null,
				'source'     => 'customer_status_page',
			]
		);
	} else {
		update_post_meta( $repair_id, '_repair_quote_status', $quote_status );
	}

	if ( ! function_exists( 'dtb_transition_repair_status' ) ) {
		return new WP_Error(
			'dtb_repair_workflow_unavailable',
			__( 'Repair workflow is temporarily unavailable.', 'drywall-toolbox' ),
			[ 'status' => 503 ]
		);
	}

	$result = dtb_transition_repair_status(
		$repair_id,
		$target,
		[
			'actor_type' => 'customer',
			'actor_id'   => get_current_user_id() ?: null,
			'source'     => 'customer_status_page',
			'note'       => 'accept' === $action
				? __( 'Quote accepted by customer.', 'drywall-toolbox' )
				: __( 'Quote declined by customer.', 'drywall-toolbox' ),
			'payload'    => [
				'quote_status' => $quote_status,
			],
		]
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$projection = function_exists( 'dtb_build_repair_status_projection' )
		? dtb_build_repair_status_projection( $repair_id )
		: [ 'id' => $repair_id, 'status' => $target ];

	return new WP_REST_Response(
		[
			'success' => true,
			'message' => 'accept' === $action
				? __( 'Quote accepted.', 'drywall-toolbox' )
				: __( 'Quote declined.', 'drywall-toolbox' ),
			'data'    => $projection,
		],
		200
	);
}
