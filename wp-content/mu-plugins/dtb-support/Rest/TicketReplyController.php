<?php
/**
 * REST — TicketReplyController: handles reply submissions from staff and customers.
 *
 * Routes:
 *   POST /wp-json/dtb/v1/support/tickets/(?P<id>\d+)/reply
 *   POST /wp-json/dtb/v1/support/tickets/(?P<id>\d+)/reply/public
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register reply routes.
 */
function dtb_support_register_reply_routes(): void {
	// Staff reply (authenticated).
	register_rest_route( 'dtb/v1', '/support/tickets/(?P<id>\d+)/reply', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'dtb_support_rest_staff_reply',
		'permission_callback' => 'dtb_support_staff_reply_permission',
		'args'                => [
			'message'     => [ 'type' => 'string', 'required' => true ],
			'is_internal' => [ 'type' => 'boolean', 'required' => false, 'default' => false ],
		],
	] );

	// Customer reply (public, token-authenticated via hash).
	register_rest_route( 'dtb/v1', '/support/tickets/(?P<id>\d+)/reply/public', [
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dtb_support_rest_public_reply_redirect',
			'permission_callback' => '__return_true',
			'args'                => [
				'token' => [ 'type' => 'string', 'required' => true ],
			],
		],
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'dtb_support_rest_customer_reply',
			'permission_callback' => '__return_true',
			'args'                => [
				'message' => [ 'type' => 'string', 'required' => true ],
				'token'   => [ 'type' => 'string', 'required' => true ],
			],
		],
	] );

	register_rest_route( 'dtb/v1', '/support/tickets/(?P<id>\d+)/status/public', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'dtb_support_rest_public_status',
		'permission_callback' => '__return_true',
		'args'                => [
			'token' => [ 'type' => 'string', 'required' => true ],
		],
	] );
}
add_action( 'rest_api_init', 'dtb_support_register_reply_routes' );

/**
 * Check reply vs internal-note capability.
 */
function dtb_support_staff_reply_permission( WP_REST_Request $request ): bool|WP_Error {
	$is_internal = (bool) $request->get_param( 'is_internal' );
	$message     = $is_internal
		? __( 'You do not have permission to add internal support notes.', 'drywall-toolbox' )
		: __( 'You do not have permission to reply to support tickets.', 'drywall-toolbox' );

	if ( function_exists( 'dtb_support_rest_require_capabilities' ) ) {
		return dtb_support_rest_require_capabilities(
			[
				$is_internal ? 'dtb_add_support_notes' : 'dtb_reply_support_tickets',
				'dtb_manage_support',
			],
			$message
		);
	}

	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'rest_forbidden', __( 'Authentication required.', 'drywall-toolbox' ), [ 'status' => 401 ] );
	}

	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}

	$cap = $is_internal ? 'dtb_add_support_notes' : 'dtb_reply_support_tickets';
	if ( current_user_can( $cap ) || current_user_can( 'dtb_manage_support' ) ) {
		return true;
	}

	return new WP_Error( 'rest_forbidden', $message, [ 'status' => 403 ] );
}

/**
 * Detect macro placeholders in support reply text.
 */
function dtb_support_rest_has_template_tokens( string $message ): bool {
	return (bool) preg_match( '/\{\{\s*[a-z0-9_]+\s*\}\}|(?<!\{)\{\s*[a-z0-9_]+\s*\}(?!\})/i', $message );
}

/**
 * Render any macro tokens before a staff reply is persisted or emailed.
 */
function dtb_support_rest_prepare_staff_reply_message( int $ticket_id, string $message ): string {
	if ( ! dtb_support_rest_has_template_tokens( $message ) ) {
		return $message;
	}

	$ticket = dtb_support_get_ticket( $ticket_id );
	if ( ! $ticket || ! function_exists( 'dtb_support_render_macro' ) ) {
		return $message;
	}

	$rendered = dtb_support_render_macro( $message, $ticket );
	return '' !== trim( $rendered ) ? $rendered : $message;
}

/**
 * Prepare public-facing conversation message bodies and clean legacy macro residue.
 */
function dtb_support_rest_prepare_public_message_body( object $event, object $ticket ): string {
	$body = (string) ( $event->body ?? '' );

	if ( function_exists( 'dtb_support_render_macro' ) && dtb_support_rest_has_template_tokens( $body ) ) {
		$body = dtb_support_render_macro( $body, $ticket );
	} elseif ( function_exists( 'dtb_support_clean_rendered_macro' ) ) {
		$body = dtb_support_clean_rendered_macro( $body );
	}

	$body = wp_strip_all_tags( $body );
	return function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $body, true ) : trim( $body );
}

/**
 * POST /dtb/v1/support/tickets/{id}/reply   (staff)
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function dtb_support_rest_staff_reply( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$ticket_id  = (int) $request->get_param( 'id' );
	$message    = trim( sanitize_textarea_field( wp_unslash( (string) ( $request->get_param( 'message' ) ?? '' ) ) ) );
	$is_internal = (bool) $request->get_param( 'is_internal' );
	$actor_id   = get_current_user_id();

	if ( '' === $message ) {
		return new WP_Error( 'dtb_support_empty', __( 'Message cannot be empty.', 'drywall-toolbox' ), [ 'status' => 422 ] );
	}

	$message = dtb_support_rest_prepare_staff_reply_message( $ticket_id, $message );

	$event_id = dtb_support_add_reply( $ticket_id, $message, 'staff', $actor_id, $is_internal );
	if ( is_wp_error( $event_id ) ) {
		return new WP_REST_Response( [ 'success' => false, 'message' => $event_id->get_error_message() ], 422 );
	}

	$ticket = dtb_support_get_ticket( $ticket_id );
	$events = dtb_support_get_events( $ticket_id, 'operator' );
	$events = function_exists( 'dtb_support_rest_prepare_ticket_events' )
		? dtb_support_rest_prepare_ticket_events( $ticket_id, (array) $events )
		: (array) $events;
	$detail = $ticket && function_exists( 'dtb_support_build_workbench_detail_payload' )
		? dtb_support_build_workbench_detail_payload( $ticket, $events )
		: [];

	return new WP_REST_Response( [ 'success' => true, 'event_id' => $event_id, 'detail' => $detail ], 201 );
}

/**
 * POST /dtb/v1/support/tickets/{id}/reply/public   (customer, token-gated)
 *
 * The token is a signed, expiring HMAC-SHA256 of "ticket_id:customer_email:expires"
 * using AUTH_KEY. Tokens are valid for DTB_SUPPORT_PUBLIC_REPLY_TOKEN_TTL seconds
 * (default: 30 days). Verification uses constant-time comparison.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function dtb_support_rest_customer_reply( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$ticket_id = (int) $request->get_param( 'id' );
	$token     = sanitize_text_field( $request->get_param( 'token' ) ?? '' );
	$message   = trim( sanitize_textarea_field( wp_unslash( (string) ( $request->get_param( 'message' ) ?? '' ) ) ) );

	$ticket = dtb_support_validate_public_reply_token( $ticket_id, $token );
	if ( is_wp_error( $ticket ) ) {
		return $ticket;
	}

	if ( '' === $message ) {
		return new WP_Error( 'dtb_support_empty', __( 'Message cannot be empty.', 'drywall-toolbox' ), [ 'status' => 422 ] );
	}

	$event_id = dtb_support_add_reply( $ticket_id, $message, 'customer', 0, false );
	if ( is_wp_error( $event_id ) ) {
		return new WP_REST_Response( [ 'success' => false, 'message' => $event_id->get_error_message() ], 422 );
	}

	return new WP_REST_Response( [
		'success'  => true,
		'event_id' => $event_id,
		'message'  => __( 'Your reply has been sent.', 'drywall-toolbox' ),
	], 201 );
}

function dtb_support_rest_public_reply_redirect( WP_REST_Request $request ): WP_REST_Response {
	$ticket_id = (int) $request->get_param( 'id' );
	$token     = sanitize_text_field( $request->get_param( 'token' ) ?? '' );
	$location  = add_query_arg(
		[ 'token' => $token ],
		home_url( '/support/status/' . $ticket_id )
	);

	return new WP_REST_Response(
		[
			'success'  => true,
			'location' => esc_url_raw( $location ),
		],
		302,
		[ 'Location' => esc_url_raw( $location ) ]
	);
}

function dtb_support_rest_public_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$ticket_id = (int) $request->get_param( 'id' );
	$token     = sanitize_text_field( $request->get_param( 'token' ) ?? '' );
	$ticket    = dtb_support_validate_public_reply_token( $ticket_id, $token );

	if ( is_wp_error( $ticket ) ) {
		return $ticket;
	}

	$events = array_filter(
		dtb_support_get_events( $ticket_id, 'customer' ),
		static fn( $event ) => dtb_support_event_is_public( (string) $event->event_type, (string) $event->visibility )
	);

	$timeline = array_map(
		static fn( $event ) => [
			'id'          => (int) $event->id,
			'type'        => (string) $event->event_type,
			'body'        => dtb_support_rest_prepare_public_message_body( $event, $ticket ),
			'actor_type'  => (string) $event->actor_type,
			'occurred_at' => (string) $event->created_at,
		],
		array_values( $events )
	);

	return new WP_REST_Response( [
		'id'              => (int) $ticket->id,
		'ticket_number'   => (string) $ticket->ticket_number,
		'status'          => (string) $ticket->status,
		'label'           => function_exists( 'dtb_support_status_label' ) ? dtb_support_status_label( (string) $ticket->status ) : ucwords( str_replace( '_', ' ', (string) $ticket->status ) ),
		'subject'         => (string) $ticket->subject,
		'ticket_type'     => (string) $ticket->ticket_type,
		'priority'        => (string) $ticket->priority,
		'customer_name'   => (string) $ticket->customer_name,
		'created_at'      => (string) $ticket->created_at,
		'last_updated_at' => (string) $ticket->updated_at,
		'timeline'        => $timeline,
	] );
}

function dtb_support_validate_public_reply_token( int $ticket_id, string $token ) {
	$ticket = dtb_support_get_ticket( $ticket_id );
	if ( ! $ticket ) {
		return new WP_Error( 'dtb_support_forbidden', __( 'Invalid or expired reply link.', 'drywall-toolbox' ), [ 'status' => 403 ] );
	}

	$parts = explode( ':', $token, 2 );
	if ( 2 !== count( $parts ) ) {
		return new WP_Error( 'dtb_support_forbidden', __( 'Invalid or expired reply link.', 'drywall-toolbox' ), [ 'status' => 403 ] );
	}

	[ $expires_str, $provided_hmac ] = $parts;
	if ( ! ctype_digit( $expires_str ) || 64 !== strlen( $provided_hmac ) || ! ctype_xdigit( $provided_hmac ) ) {
		return new WP_Error( 'dtb_support_forbidden', __( 'Invalid or expired reply link.', 'drywall-toolbox' ), [ 'status' => 403 ] );
	}

	$expires = (int) $expires_str;
	if ( $expires < time() ) {
		return new WP_Error( 'dtb_support_forbidden', __( 'Invalid or expired reply link.', 'drywall-toolbox' ), [ 'status' => 403 ] );
	}

	$expected_hmac = hash_hmac( 'sha256', $ticket_id . ':' . $ticket->customer_email . ':' . $expires, AUTH_KEY );
	if ( ! hash_equals( $expected_hmac, $provided_hmac ) ) {
		return new WP_Error( 'dtb_support_forbidden', __( 'Invalid or expired reply link.', 'drywall-toolbox' ), [ 'status' => 403 ] );
	}

	return $ticket;
}

/**
 * Generate an expiring signed token for the public customer reply endpoint.
 *
 * Token format: "{expires}:{hmac-sha256}"
 * The HMAC covers "ticket_id:customer_email:expires" using AUTH_KEY.
 *
 * @param int      $ticket_id
 * @param string   $customer_email
 * @param int|null $ttl  Token lifetime in seconds. null = use system default (30 days).
 * @return string
 */
function dtb_support_generate_public_reply_token( int $ticket_id, string $customer_email, ?int $ttl = null ): string {
	if ( null === $ttl ) {
		$ttl = (int) apply_filters(
			'dtb_support_public_reply_token_ttl',
			defined( 'DTB_SUPPORT_PUBLIC_REPLY_TOKEN_TTL' ) ? DTB_SUPPORT_PUBLIC_REPLY_TOKEN_TTL : ( 30 * DAY_IN_SECONDS )
		);
	}
	$expires = time() + max( 1, $ttl );
	$hmac    = hash_hmac( 'sha256', $ticket_id . ':' . $customer_email . ':' . $expires, AUTH_KEY );
	return $expires . ':' . $hmac;
}
