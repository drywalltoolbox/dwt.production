<?php
/**
 * Rest — RepairCommentController: POST /wp-json/dtb/v1/repairs/{id}/comment
 *
 * Customer-facing lightweight comment endpoint for status page updates.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_repair_register_comment_route' );

function dtb_repair_register_comment_route(): void {
	register_rest_route(
		'dtb/v1',
		'/repairs/(?P<id>\d+)/comment',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'dtb_repair_rest_comment',
			'permission_callback' => '__return_true',
			'args'                => [
				'id'      => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
				'token'   => [ 'type' => 'string', 'required' => false, 'default' => '' ],
				'comment' => [ 'type' => 'string', 'required' => true ],
			],
		]
	);
}

function dtb_repair_rest_comment( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$repair_id = (int) $request->get_param( 'id' );
	$post      = get_post( $repair_id );
	if ( ! $post || 'dtb_repair_request' !== $post->post_type ) {
		return new WP_Error( 'dtb_repair_not_found', __( 'Repair request not found.', 'drywall-toolbox' ), [ 'status' => 404 ] );
	}

	$token = sanitize_text_field( (string) $request->get_param( 'token' ) );
	$access = function_exists( 'dtb_validate_repair_access' )
		? dtb_validate_repair_access( $repair_id, $token )
		: true;
	if ( is_wp_error( $access ) ) {
		return $access;
	}

	$status   = (string) get_post_meta( $repair_id, '_repair_status', true );
	$terminal = [ 'closed', 'completed', 'cancelled', 'quote_declined' ];
	if ( in_array( $status, $terminal, true ) ) {
		return new WP_Error(
			'dtb_repair_terminal',
			__( 'Comments are disabled for this repair status.', 'drywall-toolbox' ),
			[ 'status' => 409 ]
		);
	}

	$raw_comment = (string) $request->get_param( 'comment' );
	$comment     = trim( wp_strip_all_tags( $raw_comment ) );
	if ( '' === $comment ) {
		return new WP_Error( 'dtb_repair_comment_empty', __( 'Please enter a comment.', 'drywall-toolbox' ), [ 'status' => 422 ] );
	}

	$max_chars = 600;
	if ( function_exists( 'mb_strlen' ) ) {
		if ( mb_strlen( $comment ) > $max_chars ) {
			return new WP_Error( 'dtb_repair_comment_too_long', __( 'Comment is too long.', 'drywall-toolbox' ), [ 'status' => 422 ] );
		}
	} elseif ( strlen( $comment ) > $max_chars ) {
		return new WP_Error( 'dtb_repair_comment_too_long', __( 'Comment is too long.', 'drywall-toolbox' ), [ 'status' => 422 ] );
	}

	$event_id = false;
	if ( function_exists( 'dtb_repair_append_event' ) ) {
		$event_id = dtb_repair_append_event(
			$repair_id,
			'repair.note_added',
			[
				'actor_type' => 'customer',
				'actor_id'   => get_current_user_id() ?: null,
				'source'     => 'customer_status_page',
				'visibility' => 'customer',
				'payload'    => [ 'note' => $comment ],
			]
		);
	}

	/**
	 * Fire a server-side alert hook when a customer posts a new repair message.
	 *
	 * Integrations can listen here to send proactive notifications (email/SMS/etc).
	 */
	do_action(
		'dtb_repair_customer_message_posted',
		$repair_id,
		$comment,
		[
			'event_id'   => is_numeric( $event_id ) ? (int) $event_id : 0,
			'event_type' => 'repair.note_added',
			'actor_type' => 'customer',
			'source'     => 'customer_status_page',
			'visibility' => 'customer',
		]
	);

	return new WP_REST_Response(
		[
			'success' => true,
			'message' => __( 'Your update has been sent to our repair team.', 'drywall-toolbox' ),
			'data'    => [ 'comment' => $comment ],
		],
		200
	);
}
