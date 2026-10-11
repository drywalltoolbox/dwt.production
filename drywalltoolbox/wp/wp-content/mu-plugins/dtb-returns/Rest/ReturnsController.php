<?php
/**
 * DTB Returns — ReturnsController
 *
 * REST endpoints:
 *   GET  /dtb/v1/returns                    → list returns
 *   POST /dtb/v1/returns                    → create return
 *   GET  /dtb/v1/returns/{id}               → get return
 *   POST /dtb/v1/returns/{id}/status        → transition status
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_returns_rest_register_routes(): void {
	register_rest_route( 'dtb/v1', '/returns', [
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dtb_returns_rest_list',
			'permission_callback' => fn() => current_user_can( 'dtb_manage_returns' ),
		],
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'dtb_returns_rest_create',
			'permission_callback' => fn() => current_user_can( 'dtb_manage_returns' ),
		],
	] );

	// Legacy compatibility submission. Public, but order number + checkout email
	// are verified against WooCommerce before any return record is created.
	register_rest_route( 'dtb/v1', '/returns/request', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'dtb_returns_rest_public_submit',
		'permission_callback' => '__return_true',
		'args'                => [
			'order_number'   => [ 'type' => 'string',  'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
			'customer_name'  => [ 'type' => 'string',  'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
			'customer_email' => [ 'type' => 'string',  'required' => true,  'sanitize_callback' => 'sanitize_email' ],
			'reason'         => [ 'type' => 'string',  'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
			'notes'          => [ 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ],
		],
	] );

	register_rest_route( 'dtb/v1', '/returns/mine', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'dtb_returns_rest_customer_list',
		'permission_callback' => 'dtb_jwt_permission',
	] );

	register_rest_route( 'dtb/v1', '/returns/status/(?P<id>\d+)', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'dtb_returns_rest_public_status',
		'permission_callback' => '__return_true',
		'args'                => [
			'id'    => [ 'type' => 'integer', 'minimum' => 1 ],
			'token' => [ 'type' => 'string', 'required' => true ],
		],
	] );

	register_rest_route( 'dtb/v1', '/returns/(?P<id>\d+)', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'dtb_returns_rest_get',
		'permission_callback' => fn() => current_user_can( 'dtb_manage_returns' ),
		'args'                => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
	] );

	register_rest_route( 'dtb/v1', '/returns/(?P<id>\d+)/status', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'dtb_returns_rest_transition_status',
		'permission_callback' => fn() => current_user_can( 'dtb_manage_returns' ),
		'args'                => [
			'id'     => [ 'type' => 'integer', 'minimum' => 1 ],
			'status' => [ 'type' => 'string',  'required' => true ],
		],
	] );

	// ── Admin: sync customer/order data from WooCommerce ─────────────────────
	register_rest_route( 'dtb/v1', '/returns/(?P<id>\d+)/sync-order', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'dtb_returns_rest_sync_order',
		'permission_callback' => fn() => current_user_can( 'dtb_manage_returns' ),
		'args'                => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
	] );

	// ── Admin: enriched detail (modal) ────────────────────────────────────────
	register_rest_route( 'dtb/v1', '/returns/(?P<id>\d+)/detail', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'dtb_returns_rest_admin_detail',
		'permission_callback' => fn() => current_user_can( 'dtb_manage_returns' ),
		'args'                => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
	] );

	// ── Admin namespace aliases — canonical workbench routes ──────────────────
	register_rest_route( 'dtb/v1', '/admin/returns/(?P<id>\d+)/detail', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'dtb_returns_rest_admin_detail',
		'permission_callback' => fn() => is_user_logged_in() && current_user_can( 'dtb_manage_returns' ),
		'args'                => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
	] );

	register_rest_route( 'dtb/v1', '/admin/returns/(?P<id>\d+)/actions', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'dtb_returns_rest_admin_action',
		'permission_callback' => fn() => is_user_logged_in() && current_user_can( 'dtb_manage_returns' ),
		'args'                => [
			'id'              => [ 'type' => 'integer', 'minimum' => 1 ],
			'action_type'     => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ],
			'status'          => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_key' ],
			'resolution'      => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_key' ],
			'note'            => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ],
			'idempotency_key' => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
		],
	] );

	register_rest_route( 'dtb/v1', '/admin/returns/bulk', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'dtb_returns_rest_admin_bulk_action',
		'permission_callback' => fn() => is_user_logged_in() && current_user_can( 'dtb_manage_returns' ),
		'args'                => [
			'action' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ],
			'ids'    => [ 'type' => 'array', 'required' => true ],
		],
	] );

	// ── Admin: PATCH update (status / resolution / note) ─────────────────────
	register_rest_route( 'dtb/v1', '/returns/(?P<id>\d+)', [
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dtb_returns_rest_get',
			'permission_callback' => fn() => current_user_can( 'dtb_manage_returns' ),
			'args'                => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
		],
		[
			'methods'             => 'PATCH',
			'callback'            => 'dtb_returns_rest_admin_patch',
			'permission_callback' => fn() => current_user_can( 'dtb_manage_returns' ),
			'args'                => [
				'id'         => [ 'type' => 'integer', 'minimum' => 1 ],
				'status'     => [ 'type' => 'string',  'required' => false ],
				'resolution' => [ 'type' => 'string',  'required' => false ],
				'note'       => [ 'type' => 'string',  'required' => false ],
			],
		],
	] );
}

function dtb_returns_rest_customer_list( WP_REST_Request $request ): WP_REST_Response {
	$user = DTB_CurrentUserResolver::resolve_user();
	if ( ! $user ) {
		return new WP_REST_Response( [ 'code' => 'dtb_returns_unauthorized', 'message' => 'Authentication required.' ], 401 );
	}

	$page     = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );
	$per_page = min( 50, max( 1, (int) ( $request->get_param( 'per_page' ) ?? 20 ) ) );
	$query    = new WP_Query( [
		'post_type'      => 'dtb_return',
		'post_status'    => 'publish',
		'posts_per_page' => $per_page,
		'paged'          => $page,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'meta_query'     => [
			[
				'key'     => '_dtb_return_customer_email',
				'value'   => sanitize_email( $user->user_email ),
				'compare' => '=',
			],
		],
	] );

	$items = array_map(
		static function ( WP_Post $post ): array {
			$entity               = DTB_Return_Entity::from_post( $post );
			$item                 = $entity->to_array();
			$item['public_token'] = dtb_returns_generate_public_status_token( $entity->id, $entity->customer_email );
			return $item;
		},
		$query->posts
	);

	return new WP_REST_Response( [
		'returns'  => $items,
		'page'     => $page,
		'per_page' => $per_page,
		'total'    => (int) $query->found_posts,
		'has_more' => $page < (int) $query->max_num_pages,
	], 200 );
}

function dtb_returns_rest_list( WP_REST_Request $request ): WP_REST_Response {
	$result = dtb_returns_query( [
		'status'   => sanitize_key( $request->get_param( 'status' ) ?? 'all' ),
		'search'   => sanitize_text_field( $request->get_param( 's' ) ?? '' ),
		'page'     => (int) ( $request->get_param( 'page' ) ?? 1 ),
		'per_page' => (int) ( $request->get_param( 'per_page' ) ?? 20 ),
	] );

	return new WP_REST_Response( [
		'items'  => array_map( fn( $e ) => $e->to_array(), $result['items'] ),
		'total'  => $result['total'],
		'pages'  => $result['pages'],
		'counts' => dtb_returns_count_by_status(),
	] );
}

function dtb_returns_rest_create( WP_REST_Request $request ): WP_REST_Response {
	$data   = $request->get_json_params() ?: (array) $request->get_body_params();
	$result = dtb_return_create( $data );

	if ( is_wp_error( $result ) ) {
		return new WP_REST_Response( [ 'error' => $result->get_error_message() ], (int) ( $result->get_error_data()['status'] ?? 400 ) );
	}

	return new WP_REST_Response( dtb_returns_get( $result )?->to_array(), 201 );
}

function dtb_returns_rest_get( WP_REST_Request $request ): WP_REST_Response {
	$entity = dtb_returns_get( (int) $request->get_param( 'id' ) );
	if ( ! $entity ) {
		return new WP_REST_Response( [ 'error' => 'Return not found.' ], 404 );
	}
	return new WP_REST_Response( $entity->to_array() );
}

/**
 * Legacy public return submission handler.
 *
 * Compatibility callers must prove possession of the WooCommerce order number
 * and checkout email. Customer identity and order ID are then re-derived from
 * WooCommerce before persistence or notification. The current React storefront
 * uses the stricter lookup-token + item-level verified submission contract.
 *
 * Rate-limited to 3 submissions per IP per 10 minutes via transient.
 */
function dtb_returns_rest_public_submit( WP_REST_Request $request ): WP_REST_Response {
	// ── Rate limit ────────────────────────────────────────────────────────────
	$ip          = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
	$rate_key    = 'dtb_return_rl_' . md5( $ip );
	$rate_count  = (int) get_transient( $rate_key );
	if ( $rate_count >= 3 ) {
		return new WP_REST_Response(
			[ 'error' => __( 'Too many submissions. Please wait a few minutes and try again.', 'drywall-toolbox' ) ],
			429
		);
	}
	set_transient( $rate_key, $rate_count + 1, 10 * MINUTE_IN_SECONDS );

	// ── Verify the WooCommerce order and derive customer identity ─────────────
	$order_number   = (string) $request->get_param( 'order_number' );
	$customer_email = (string) $request->get_param( 'customer_email' );
	$reason         = (string) $request->get_param( 'reason' );
	$notes          = (string) $request->get_param( 'notes' );

	$order = dtb_returns_resolve_verified_order( $order_number, $customer_email );
	if ( is_wp_error( $order ) ) {
		return new WP_REST_Response(
			[ 'error' => $order->get_error_message() ],
			(int) ( $order->get_error_data()['status'] ?? 404 )
		);
	}

	$order_id       = (int) $order->get_id();
	$order_number   = (string) $order->get_order_number();
	$customer_email = sanitize_email( (string) $order->get_billing_email() );
	$customer_name  = trim( (string) $order->get_formatted_billing_full_name() );
	if ( '' === $customer_name ) {
		$customer_name = __( 'Customer', 'drywall-toolbox' );
	}

	// ── Create the compatibility return record from verified server data ─────
	$result = dtb_return_create( [
		'order_id'       => $order_id,
		'order_number'   => $order_number,
		'customer_name'  => $customer_name,
		'customer_email' => $customer_email,
		'reason'         => $reason,
		'notes'          => $notes,
		'resolution'     => '',
	] );

	if ( is_wp_error( $result ) ) {
		return new WP_REST_Response(
			[ 'error' => $result->get_error_message() ],
			(int) ( $result->get_error_data()['status'] ?? 400 )
		);
	}

	$return_id = (int) $result;
	$public_token = dtb_returns_generate_public_status_token( $return_id, $customer_email );

	// ── Notify admin ──────────────────────────────────────────────────────────
	$site_name  = get_bloginfo( 'name' ) ?: 'Drywall Toolbox';
	$admin_to   = 'elliott.miller@drywalltoolbox.com'; // TODO: swap to support@ when inbox exists
	$subject    = sprintf( '[%s] New Return Request — Order %s', $site_name, $order_number );
	$admin_url  = admin_url( 'admin.php?page=dtb-returns&action=view&return_id=' . $return_id );

	$body  = "A new return request has been submitted.\n\n";
	$body .= "Return ID  : #{$return_id}\n";
	$body .= "Order      : {$order_number}\n";
	$body .= "Customer   : {$customer_name} <{$customer_email}>\n";
	$body .= "Reason     : {$reason}\n";
	if ( $notes ) {
		$body .= "Notes      : {$notes}\n";
	}
	$body .= "\nView in admin: {$admin_url}\n";

	$headers = [
		'Content-Type: text/plain; charset=UTF-8',
		'Reply-To: ' . $customer_name . ' <' . $customer_email . '>',
	];

	if ( function_exists( 'dtb_send_email' ) ) {
		dtb_send_email( [
			'to'           => $admin_to,
			'subject'      => $subject,
			'message'      => $body,
			'headers'      => $headers,
			'content_type' => 'text/plain',
			'context'      => [ 'module' => 'dtb-returns', 'route' => 'public-submit' ],
		] );
	} else {
		wp_mail( $admin_to, $subject, $body, $headers );
	}

	$status_url = add_query_arg( [ 'token' => $public_token ], home_url( '/returns/status/' . $return_id ) );
	$customer_subject = sprintf( '[%s] Return request received — #%d', $site_name, $return_id );
	
	// Plain text version for fallback
	$customer_body_plain  = "Hi {$customer_name},\n\n";
	$customer_body_plain .= "We received your return request for order {$order_number}.\n\n";
	$customer_body_plain .= "Return reference: #{$return_id}\n";
	$customer_body_plain .= "Current status: Pending Review\n\n";
	$customer_body_plain .= "Track your return status here:\n{$status_url}\n\n";
	$customer_body_plain .= "Please do not ship items back until our team sends your RMA instructions.\n\n";
	$customer_body_plain .= "{$site_name} Support Team\n";
	
	// HTML version with branded template
	$customer_body_html = '';
	if ( function_exists( 'dtb_render_branded_email' ) ) {
			$customer_body_html = dtb_render_branded_email( [
				'title'       => 'Return Request Received',
				'preheader'   => "Return request #{ $return_id} received and under review",
				'greeting'    => "Hi {$customer_name},",
			'intro'       => "We've received your return request for order {$order_number}. Our team will review it and send you RMA instructions soon.",
			'details'     => [
				[ 'label' => 'Return Reference', 'value' => "#{$return_id}" ],
				[ 'label' => 'Order Number', 'value' => $order_number ],
				[ 'label' => 'Status', 'value' => 'Pending Review' ],
			],
			'body_html'   => '<p><strong>Important:</strong> Please do not ship items back until our team sends your RMA instructions.</p>',
			'cta_url'     => $status_url,
				'cta_label'   => 'Track Return Status',
				'signoff'     => 'The Drywall Toolbox Team',
				'footer_note' => 'Questions about your return? Reply to this email and our support team will help.',
			] );
	}
	
	$customer_headers = [
		'Reply-To: ' . $site_name . ' Support <info@drywalltoolbox.com>',
	];

	if (
		is_email( $customer_email )
		&& ( ! function_exists( 'dtb_account_email_preference' ) || dtb_account_email_preference( $customer_email, 'return_updates' ) )
	) {
		if ( function_exists( 'dtb_send_email' ) ) {
			dtb_send_email( [
				'to'           => $customer_email,
				'subject'      => $customer_subject,
				'message'      => $customer_body_html ?: $customer_body_plain,
				'content_type' => $customer_body_html ? 'text/html' : 'text/plain',
				'is_html'      => (bool) $customer_body_html,
				'alt_body'     => $customer_body_html ? $customer_body_plain : '',
				'headers'      => $customer_headers,
				'context'      => [ 'module' => 'dtb-returns', 'route' => 'public-submit-customer' ],
			] );
		} else {
			wp_mail( $customer_email, $customer_subject, $customer_body_plain, array_merge( $customer_headers, [ 'Content-Type: text/plain; charset=UTF-8' ] ) );
		}
	}

	return new WP_REST_Response(
		[
			'return_id'    => $return_id,
			'public_token' => $public_token,
			'status_url'   => $status_url,
			'message'      => __( 'Return request submitted successfully.', 'drywall-toolbox' ),
		],
		201
	);
}

function dtb_returns_rest_public_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$return_id = (int) $request->get_param( 'id' );
	$token     = sanitize_text_field( (string) $request->get_param( 'token' ) );
	$entity    = dtb_returns_validate_public_status_token( $return_id, $token );

	if ( is_wp_error( $entity ) ) {
		return $entity;
	}

	$data   = $entity->to_array();
	$events = function_exists( 'dtb_returns_admin_get_events' ) ? dtb_returns_admin_get_events( $return_id ) : [];

	return new WP_REST_Response( [
		'id'              => (int) $entity->id,
		'return_number'   => '#' . (int) $entity->id,
		'status'          => (string) $entity->status->value(),
		'label'           => (string) $entity->status->label(),
		'order_number'    => (string) ( $data['order_number'] ?? '' ),
		'reason'          => (string) ( $data['reason'] ?? '' ),
		'resolution'      => (string) ( $data['resolution'] ?? '' ),
		'customer_name'   => (string) ( $data['customer_name'] ?? '' ),
		'created_at'      => get_post_time( 'Y-m-d H:i:s', true, $return_id ) ?: '',
		'last_updated_at' => get_post_modified_time( 'Y-m-d H:i:s', true, $return_id ) ?: '',
		'timeline'        => array_map(
			static fn( $event ) => [
				'type'        => (string) ( $event['action'] ?? 'return.updated' ),
				'label'       => (string) ( $event['summary'] ?? 'Return updated' ),
				'occurred_at' => (string) ( $event['ts'] ?? '' ),
			],
			(array) $events
		),
	] );
}

function dtb_returns_generate_public_status_token( int $return_id, string $customer_email, ?int $ttl = null ): string {
	if ( null === $ttl ) {
		$ttl = (int) apply_filters( 'dtb_returns_public_status_token_ttl', 30 * DAY_IN_SECONDS );
	}
	$expires = time() + max( 1, $ttl );
	$hmac    = hash_hmac( 'sha256', $return_id . ':' . sanitize_email( $customer_email ) . ':' . $expires, AUTH_KEY );
	return $expires . ':' . $hmac;
}

function dtb_returns_validate_public_status_token( int $return_id, string $token ): DTB_Return_Entity|WP_Error {
	$entity = dtb_returns_get( $return_id );
	if ( ! $entity ) {
		return new WP_Error( 'dtb_returns_forbidden', __( 'Invalid or expired return tracking link.', 'drywall-toolbox' ), [ 'status' => 403 ] );
	}

	$parts = explode( ':', $token, 2 );
	if ( 2 !== count( $parts ) ) {
		return new WP_Error( 'dtb_returns_forbidden', __( 'Invalid or expired return tracking link.', 'drywall-toolbox' ), [ 'status' => 403 ] );
	}

	[ $expires_str, $provided_hmac ] = $parts;
	if ( ! ctype_digit( $expires_str ) || 64 !== strlen( $provided_hmac ) || ! ctype_xdigit( $provided_hmac ) ) {
		return new WP_Error( 'dtb_returns_forbidden', __( 'Invalid or expired return tracking link.', 'drywall-toolbox' ), [ 'status' => 403 ] );
	}

	$expires = (int) $expires_str;
	if ( $expires < time() ) {
		return new WP_Error( 'dtb_returns_forbidden', __( 'Invalid or expired return tracking link.', 'drywall-toolbox' ), [ 'status' => 403 ] );
	}

	$expected_hmac = hash_hmac( 'sha256', $return_id . ':' . sanitize_email( (string) $entity->customer_email ) . ':' . $expires, AUTH_KEY );
	if ( ! hash_equals( $expected_hmac, $provided_hmac ) ) {
		return new WP_Error( 'dtb_returns_forbidden', __( 'Invalid or expired return tracking link.', 'drywall-toolbox' ), [ 'status' => 403 ] );
	}

	return $entity;
}

function dtb_returns_rest_transition_status( WP_REST_Request $request ): WP_REST_Response {
	$result = dtb_return_transition_status(
		(int) $request->get_param( 'id' ),
		sanitize_key( $request->get_param( 'status' ) )
	);

	if ( is_wp_error( $result ) ) {
		return new WP_REST_Response( [ 'error' => $result->get_error_message() ], (int) ( $result->get_error_data()['status'] ?? 400 ) );
	}

	return new WP_REST_Response( dtb_returns_get( (int) $request->get_param( 'id' ) )?->to_array() );
}

/**
 * Build structured admin workbench actions for a return request.
 *
 * @param string $status      Current return status.
 * @param array  $workflow    Workflow definition.
 * @param array  $permissions Permission flags.
 * @return array<int,array<string,mixed>>
 */
function dtb_returns_build_admin_actions( string $status, array $workflow, array $permissions ): array {
	$status  = sanitize_key( $status );
	$labels  = (array) ( $workflow['labels'] ?? [] );
	$allowed = function_exists( 'dtb_return_get_allowed_transitions' )
		? dtb_return_get_allowed_transitions( $status )
		: [];
	$map     = [
		'approved'       => [ 'approve', __( 'Approve Return', 'drywall-toolbox' ), __( 'Approve eligibility and move the request into RMA preparation.', 'drywall-toolbox' ) ],
		'rejected'       => [ 'reject', __( 'Reject Return', 'drywall-toolbox' ), __( 'Reject the request and record the decision for staff review.', 'drywall-toolbox' ) ],
		'awaiting_item'  => [ 'mark_awaiting_item', __( 'Awaiting Item', 'drywall-toolbox' ), __( 'Mark the return as waiting for the customer shipment.', 'drywall-toolbox' ) ],
		'item_received'  => [ 'mark_item_received', __( 'Item Received', 'drywall-toolbox' ), __( 'Confirm the returned item has arrived and is ready for disposition.', 'drywall-toolbox' ) ],
		'refund_issued'  => [ 'issue_refund', __( 'Issue Refund', 'drywall-toolbox' ), __( 'Move the return to refund-issued after refund handling is complete.', 'drywall-toolbox' ) ],
		'exchange_sent'  => [ 'send_exchange', __( 'Exchange Sent', 'drywall-toolbox' ), __( 'Mark replacement or exchange fulfillment as sent.', 'drywall-toolbox' ) ],
		'closed'         => [ 'close', __( 'Close Return', 'drywall-toolbox' ), __( 'Close the return after final resolution is complete.', 'drywall-toolbox' ) ],
	];
	$actions = [];

	if ( ! empty( $permissions['can_transition'] ) ) {
		foreach ( $allowed as $target ) {
			$target = sanitize_key( (string) $target );
			if ( ! isset( $map[ $target ] ) ) {
				continue;
			}

			[ $action_type, $label, $description ] = $map[ $target ];
			$actions[] = [
				'id'            => 'return_' . $action_type,
				'type'          => 'server_action',
				'action_type'   => $action_type,
				'target_status' => $target,
				'group'         => 'Workflow',
				'label'         => $label ?: sprintf(
					/* translators: %s: target status label. */
					__( 'Move to %s', 'drywall-toolbox' ),
					(string) ( $labels[ $target ] ?? ucwords( str_replace( '_', ' ', $target ) ) )
				),
				'description'   => $description,
				'confirm'       => in_array( $action_type, [ 'reject', 'issue_refund', 'send_exchange', 'close' ], true ),
			];
		}
	}

	if ( ! empty( $permissions['can_transition'] ) ) {
		$actions[] = [
			'id'          => 'set_resolution',
			'type'        => 'form_action',
			'action_type' => 'set_resolution',
			'group'       => 'Resolution',
			'label'       => __( 'Set Resolution', 'drywall-toolbox' ),
			'description' => __( 'Choose refund, exchange, store credit, or replacement before final disposition.', 'drywall-toolbox' ),
		];
	}

	if ( ! empty( $permissions['can_note'] ) ) {
		$actions[] = [
			'id'          => 'add_note',
			'type'        => 'form_action',
			'action_type' => 'add_note',
			'group'       => 'Notes',
			'label'       => __( 'Add Internal Note', 'drywall-toolbox' ),
			'description' => __( 'Record operator-only return context for the team.', 'drywall-toolbox' ),
		];
	}

	return $actions;
}

// =============================================================================
// ADMIN: ENRICHED DETAIL (modal) — GET /dtb/v1/returns/{id}/detail
// =============================================================================

function dtb_returns_rest_admin_detail( WP_REST_Request $request ): WP_REST_Response {
	$id     = (int) $request->get_param( 'id' );
	$entity = dtb_returns_get( $id );

	if ( ! $entity ) {
		return new WP_REST_Response( [ 'error' => 'Return not found.' ], 404 );
	}

	$data              = $entity->to_array();
	$data['rma_label'] = '#' . $entity->id;
	$data['allowed_transitions'] = function_exists( 'dtb_return_get_allowed_transitions' )
		? array_values( dtb_return_get_allowed_transitions( sanitize_key( (string) $entity->status->value() ) ) )
		: [];
	$data['all_statuses'] = class_exists( 'DTB_Return_Status' )
		? array_values( DTB_Return_Status::all() )
		: [];
	$workflow_def = function_exists( 'dtb_admin_get_workflow_definition' )
		? dtb_admin_get_workflow_definition( 'return' )
		: [];

	// ── Staff notes (appended JSON array in meta) ─────────────────────────────
	$raw_notes           = get_post_meta( $id, '_dtb_return_staff_notes', true );
	$notes               = is_array( $raw_notes ) ? $raw_notes : ( $raw_notes ? json_decode( $raw_notes, true ) : [] );
	$data['staff_notes'] = is_array( $notes ) ? $notes : [];

	// ── Audit log events for this return ─────────────────────────────────────
	$events = dtb_returns_admin_get_events( $id );

	// ── Live WooCommerce order data ───────────────────────────────────────────
	$order_data = null;
	if ( $entity->order_id && function_exists( 'wc_get_order' ) ) {
		$wc_order = wc_get_order( $entity->order_id );
		if ( $wc_order instanceof WC_Order ) {
			$order_data = dtb_returns_format_order_snapshot( $wc_order );

			// Auto-sync: silently backfill blank return meta fields from live order.
			$changed   = false;
			$wc_name   = trim( $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name() );
			$wc_email  = $wc_order->get_billing_email();
			$wc_number = '#' . $wc_order->get_id();

			if ( '' === $entity->customer_name && '' !== $wc_name ) {
				update_post_meta( $id, '_dtb_return_customer_name', $wc_name );
				$data['customer_name'] = $wc_name;
				$changed = true;
			}
			if ( '' === $entity->customer_email && '' !== $wc_email ) {
				update_post_meta( $id, '_dtb_return_customer_email', $wc_email );
				$data['customer_email'] = $wc_email;
				$changed = true;
			}
			if ( '' === $entity->order_number ) {
				update_post_meta( $id, '_dtb_return_order_number', $wc_number );
				$data['order_number'] = $wc_number;
				$changed = true;
			}
			if ( $changed ) {
				dtb_audit_log_write( 'return.auto_synced', [
					'return_id' => $id,
					'order_id'  => $entity->order_id,
				] );
			}
		}
	}

	$customer = function_exists( 'dtb_admin_get_customer_context' )
		? dtb_admin_get_customer_context( [
			'customer_email'   => sanitize_email( (string) ( $data['customer_email'] ?? '' ) ),
			'customer_user_id' => absint( $data['customer_user_id'] ?? 0 ),
			'order_id'         => absint( $data['order_id'] ?? 0 ),
			'exclude_module'   => 'returns',
		] )
		: [];
	$linked_records = function_exists( 'dtb_admin_get_linked_records' )
		? dtb_admin_get_linked_records( 'returns', $id )
		: [];
	$integrations = function_exists( 'dtb_admin_get_integration_state' )
		? dtb_admin_get_integration_state( 'returns', $id, $data )
		: [];
	$timeline = function_exists( 'dtb_admin_get_timeline' )
		? dtb_admin_get_timeline( 'returns', $id, [ 'events' => $events ] )
		: $events;

	$permissions = [
		'can_transition' => current_user_can( 'dtb_manage_returns' ) && ! empty( $data['allowed_transitions'] ),
		'can_note'       => current_user_can( 'dtb_manage_returns' ),
		'can_sync_order' => current_user_can( 'dtb_manage_returns' ),
	];

	$payload = [
		'ok'     => true,
		'record' => $data,
		'return' => $data,
		'customer' => $customer,
		'linked_records' => $linked_records,
		'linked' => $linked_records, // TODO: remove after returns JS reads linked_records only.
		'workflow' => [
			'key'                 => 'return',
			'status'              => $entity->status->value(),
			'label'               => (string) ( $workflow_def['labels'][ $entity->status->value() ] ?? $entity->status->label() ),
			'all_statuses'        => array_values( (array) ( $workflow_def['statuses'] ?? $data['all_statuses'] ) ),
			'labels'              => (array) ( $workflow_def['labels'] ?? [] ),
			'terminal_statuses'   => array_values( (array) ( $workflow_def['terminal_statuses'] ?? [] ) ),
			'allowed_transitions' => $data['allowed_transitions'],
		],
		'intelligence' => [
			'next_best_action' => (string) ( $workflow_def['next_best_action_defaults'][ $entity->status->value() ] ?? '' ),
			'risk_flags'       => in_array( $entity->status->value(), (array) ( $workflow_def['risk_states'] ?? [] ), true ) ? [ 'return_attention' ] : [],
		],
		'communication' => [
			'customer_email' => sanitize_email( (string) ( $data['customer_email'] ?? '' ) ),
			'staff_notes_count' => count( (array) ( $data['staff_notes'] ?? [] ) ),
		],
		'integrations' => $integrations,
		'timeline' => $timeline,
		'events' => $events,
		'order'  => $order_data,
		'actions' => dtb_returns_build_admin_actions( (string) $entity->status->value(), $workflow_def, $permissions ),
		'permissions' => $permissions,
		'meta' => [
			'fetched_at'    => gmdate( 'c' ),
			'poll_after_ms' => 60000,
		],
	];

	if ( function_exists( 'dtb_admin_prepare_workbench_payload' ) ) {
		$payload = dtb_admin_prepare_workbench_payload( $payload );
	}

	return new WP_REST_Response( $payload );
}

/**
 * Fetch audit-log events relevant to a specific return ID.
 *
 * @param int $return_id
 * @return array<int, array{action:string,ts:string,actor_label:string,summary:string,age_label:string}>
 */
function dtb_returns_admin_get_events( int $return_id ): array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT action, context_json, created_at_utc, user_id
			 FROM {$wpdb->prefix}dtb_audit_log
			 WHERE action LIKE 'return.%%' AND context_json LIKE %s
			 ORDER BY id ASC
			 LIMIT 100",
			'%"return_id":' . $return_id . '%'
		),
		ARRAY_A
	);

	if ( empty( $rows ) ) {
		return [];
	}

	$events = [];
	foreach ( (array) $rows as $row ) {
		$ctx        = json_decode( $row['context_json'] ?? '{}', true );
		$action     = (string) $row['action'];
		$ts         = (string) $row['created_at_utc'];
		$user_id    = (int) $row['user_id'];
		$actor      = $user_id ? ( get_userdata( $user_id )->display_name ?? 'Admin' ) : 'System';

		// Human-readable summary per action type.
		$summary_map = [
			'return.created'        => 'Return request created',
			'return.status_changed' => sprintf(
				'Status changed: %s → %s',
				ucwords( str_replace( '_', ' ', (string) ( $ctx['ctx']['from'] ?? $ctx['from'] ?? '' ) ) ),
				ucwords( str_replace( '_', ' ', (string) ( $ctx['ctx']['to']   ?? $ctx['to']   ?? '' ) ) )
			),
		];
		$summary = $summary_map[ $action ] ?? ucwords( str_replace( [ '_', '.' ], ' ', $action ) );

		// Age label relative to now.
		$ts_parsed = strtotime( $ts );
		$diff      = max( 0, time() - (int) $ts_parsed );
		if ( $diff < 60 ) {
			$age = 'Just now';
		} elseif ( $diff < 3600 ) {
			$age = (int) round( $diff / 60 ) . 'm ago';
		} elseif ( $diff < 86400 ) {
			$age = (int) round( $diff / 3600 ) . 'h ago';
		} else {
			$age = (int) round( $diff / 86400 ) . 'd ago';
		}

		$events[] = [
			'action'      => $action,
			'ts'          => $ts,
			'actor_label' => $actor,
			'summary'     => $summary,
			'age_label'   => $age,
		];
	}

	return $events;
}

function dtb_returns_rest_admin_bulk_action( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$action = sanitize_key( (string) $request->get_param( 'action' ) );
	$ids    = array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) );

	if ( 'delete' !== $action ) {
		return new WP_Error( 'dtb_returns_invalid_bulk_action', __( 'Unsupported bulk return action.', 'drywall-toolbox' ), [ 'status' => 400 ] );
	}

	if ( empty( $ids ) ) {
		return new WP_Error( 'dtb_returns_invalid_bulk_request', __( 'No return IDs provided.', 'drywall-toolbox' ), [ 'status' => 400 ] );
	}

	$processed = [];
	$errors    = [];

	foreach ( array_unique( $ids ) as $return_id ) {
		$post = get_post( $return_id );
		if ( ! $post || 'dtb_return' !== $post->post_type ) {
			$errors[] = $return_id;
			continue;
		}

		if ( function_exists( 'dtb_admin_audit_write' ) ) {
			dtb_admin_audit_write( 'returns', $return_id, 'return.moved_to_trash', [
				'return_id' => $return_id,
				'actor_id'  => get_current_user_id(),
				'source'    => 'admin_bulk_action',
			] );
		}

		$result = wp_trash_post( $return_id );
		if ( ! $result ) {
			$errors[] = $return_id;
			continue;
		}
		$processed[] = $return_id;
	}

	return new WP_REST_Response( [
		'ok'        => empty( $errors ),
		'processed' => $processed,
		'deleted'   => $processed,
		'errors'    => $errors,
	], 200 );
}

// =============================================================================
// ADMIN: PATCH UPDATE — PATCH /dtb/v1/returns/{id}
// =============================================================================

function dtb_returns_rest_admin_patch( WP_REST_Request $request ): WP_REST_Response {
	$id     = (int) $request->get_param( 'id' );
	$entity = dtb_returns_get( $id );

	if ( ! $entity ) {
		return new WP_REST_Response( [ 'success' => false, 'message' => 'Return not found.' ], 404 );
	}

	$body       = (array) ( $request->get_json_params() ?: [] );
	$new_status = isset( $body['status'] ) ? sanitize_key( $body['status'] ) : '';
	$resolution = isset( $body['resolution'] ) ? sanitize_key( $body['resolution'] ) : '';
	$note       = isset( $body['note'] ) ? sanitize_textarea_field( wp_unslash( (string) $body['note'] ) ) : '';
	$changed    = false;

	// ── Status transition ─────────────────────────────────────────────────────
	if ( '' !== $new_status && $new_status !== $entity->status->value() ) {
		$result = dtb_return_transition_status( $id, $new_status );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => $result->get_error_message() ], 400 );
		}
		$changed = true;
	}

	// ── Resolution update ─────────────────────────────────────────────────────
	$valid_resolutions = [ '', 'refund', 'exchange', 'store_credit', 'replacement' ];
	if ( '' !== $resolution && in_array( $resolution, $valid_resolutions, true ) && $resolution !== $entity->resolution ) {
		update_post_meta( $id, '_dtb_return_resolution', $resolution );
		dtb_audit_log_write( 'return.resolution_updated', [
			'return_id'  => $id,
			'resolution' => $resolution,
			'user_id'    => get_current_user_id(),
		] );
		$changed = true;
	}

	// ── Staff note ────────────────────────────────────────────────────────────
	if ( '' !== trim( $note ) ) {
		$raw_notes = get_post_meta( $id, '_dtb_return_staff_notes', true );
		$notes     = is_array( $raw_notes ) ? $raw_notes : ( $raw_notes ? json_decode( $raw_notes, true ) : [] );
		if ( ! is_array( $notes ) ) {
			$notes = [];
		}
		$notes[] = [
			'note'       => $note,
			'user_id'    => get_current_user_id(),
			'user_label' => wp_get_current_user()->display_name ?? 'Admin',
			'created_at' => current_time( 'mysql', true ),
		];
		update_post_meta( $id, '_dtb_return_staff_notes', $notes );
		dtb_audit_log_write( 'return.note_added', [
			'return_id' => $id,
			'user_id'   => get_current_user_id(),
		] );
		$changed = true;
	}

	if ( ! $changed ) {
		return new WP_REST_Response( [ 'success' => false, 'message' => 'No changes provided.' ], 400 );
	}

	$updated = dtb_returns_get( $id );
	$detail_request = new WP_REST_Request( 'GET', '/dtb/v1/returns/' . $id . '/detail' );
	$detail_request->set_param( 'id', $id );
	$detail_response = dtb_returns_rest_admin_detail( $detail_request );
	$detail = $detail_response instanceof WP_REST_Response ? $detail_response->get_data() : [];

	return new WP_REST_Response( [
		'success' => true,
		'return'  => $updated ? $updated->to_array() : [],
		'detail'  => $detail,
	] );
}

// =============================================================================
// ADMIN: SYNC ORDER — POST /dtb/v1/returns/{id}/sync-order
// Pulls fresh WooCommerce order data and overwrites return meta.
// =============================================================================

function dtb_returns_rest_sync_order( WP_REST_Request $request ): WP_REST_Response {
	$id     = (int) $request->get_param( 'id' );
	$entity = dtb_returns_get( $id );

	if ( ! $entity ) {
		return new WP_REST_Response( [ 'success' => false, 'message' => 'Return not found.' ], 404 );
	}
	if ( ! $entity->order_id ) {
		return new WP_REST_Response( [ 'success' => false, 'message' => 'No WooCommerce order is linked to this return.' ], 400 );
	}
	if ( ! function_exists( 'wc_get_order' ) ) {
		return new WP_REST_Response( [ 'success' => false, 'message' => 'WooCommerce is not available.' ], 500 );
	}

	$wc_order = wc_get_order( $entity->order_id );
	if ( ! $wc_order instanceof WC_Order ) {
		return new WP_REST_Response(
			[ 'success' => false, 'message' => 'Order #' . $entity->order_id . ' was not found in WooCommerce.' ],
			404
		);
	}

	// Full sync — overwrite return meta with live WC values.
	$wc_name  = trim( $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name() );
	$wc_email = $wc_order->get_billing_email();
	if ( $wc_name )  { update_post_meta( $id, '_dtb_return_customer_name',  $wc_name ); }
	if ( $wc_email ) { update_post_meta( $id, '_dtb_return_customer_email', $wc_email ); }
	update_post_meta( $id, '_dtb_return_order_number', '#' . $wc_order->get_id() );

	dtb_audit_log_write( 'return.order_synced', [
		'return_id' => $id,
		'order_id'  => $entity->order_id,
		'user_id'   => get_current_user_id(),
	] );

	return new WP_REST_Response( [
		'success' => true,
		'order'   => dtb_returns_format_order_snapshot( $wc_order ),
		'return'  => dtb_returns_get( $id )?->to_array(),
		'detail'  => ( function () use ( $id ) {
			$detail_request = new WP_REST_Request( 'GET', '/dtb/v1/returns/' . $id . '/detail' );
			$detail_request->set_param( 'id', $id );
			$detail_response = dtb_returns_rest_admin_detail( $detail_request );
			return $detail_response instanceof WP_REST_Response ? $detail_response->get_data() : [];
		} )(),
	] );
}

/**
 * Build a standardised order snapshot array from a live WC_Order.
 * Used by both the /detail endpoint and the /sync-order endpoint.
 *
 * @param WC_Order $order
 * @return array
 */
function dtb_returns_format_order_snapshot( WC_Order $order ): array {
	$items = [];
	foreach ( $order->get_items() as $item ) {
		/** @var WC_Order_Item_Product $item */
		$items[] = [
			'name'      => wp_strip_all_tags( $item->get_name() ),
			'quantity'  => (int) $item->get_quantity(),
			'total'     => html_entity_decode( wp_strip_all_tags( wc_price( $item->get_total() ) ), ENT_QUOTES ),
			'total_raw' => (float) $item->get_total(),
		];
	}

	$status = $order->get_status();

	return [
		'id'                   => (int) $order->get_id(),
		'status'               => $status,
		'status_label'         => wc_get_order_status_name( $status ),
		'total'                => html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), [ 'currency' => $order->get_currency() ] ) ), ENT_QUOTES ),
		'total_raw'            => (float) $order->get_total(),
		'subtotal'             => html_entity_decode( wp_strip_all_tags( wc_price( $order->get_subtotal() ) ), ENT_QUOTES ),
		'shipping_total'       => html_entity_decode( wp_strip_all_tags( wc_price( $order->get_shipping_total() ) ), ENT_QUOTES ),
		'total_tax'            => html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total_tax() ) ), ENT_QUOTES ),
		'discount_total'       => html_entity_decode( wp_strip_all_tags( wc_price( $order->get_discount_total() ) ), ENT_QUOTES ),
		'discount_total_raw'   => (float) $order->get_discount_total(),
		'currency'             => $order->get_currency(),
		'payment_method_title' => $order->get_payment_method_title(),
		'date_created'         => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
		'items_count'          => count( $order->get_items() ),
		'items'                => $items,
		'billing'              => [
			'name'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'company' => $order->get_billing_company(),
			'address' => implode( "\n", array_filter( [
				$order->get_billing_address_1(),
				$order->get_billing_address_2(),
				trim( $order->get_billing_city() . ', ' . $order->get_billing_state() . ' ' . $order->get_billing_postcode() ),
				( $order->get_billing_country() && 'US' !== $order->get_billing_country() ) ? $order->get_billing_country() : '',
			] ) ),
			'email'   => $order->get_billing_email(),
			'phone'   => $order->get_billing_phone(),
		],
		'shipping'             => [
			'name'    => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
			'company' => $order->get_shipping_company(),
			'address' => implode( "\n", array_filter( [
				$order->get_shipping_address_1(),
				$order->get_shipping_address_2(),
				trim( $order->get_shipping_city() . ', ' . $order->get_shipping_state() . ' ' . $order->get_shipping_postcode() ),
				( $order->get_shipping_country() && 'US' !== $order->get_shipping_country() ) ? $order->get_shipping_country() : '',
			] ) ),
		],
		'customer_note'        => wp_strip_all_tags( $order->get_customer_note() ),
		'admin_url'            => admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ),
	];
}

// =============================================================================
// ADMIN: CANONICAL WORKBENCH ACTIONS — POST /dtb/v1/admin/returns/{id}/actions
// =============================================================================

/**
 * Canonical workbench action dispatcher for Returns.
 *
 * Accepted action_type values:
 *   approve            — transition to approved
 *   reject             — transition to rejected
 *   mark_awaiting_item — transition to awaiting_item
 *   mark_item_received — transition to item_received
 *   issue_refund       — transition to refund_issued (requires resolution=refund)
 *   send_exchange      — transition to exchange_sent (requires resolution=exchange)
 *   set_resolution     — update resolution field
 *   add_note           — append a staff note
 *   close              — transition to closed
 *
 * Every action: validates capability + nonce (via REST permission_callback +
 * X-WP-Nonce), enforces allowed transitions, writes an audit event, and returns
 * the refreshed canonical workbench detail payload.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function dtb_returns_rest_admin_action( WP_REST_Request $request ): WP_REST_Response {
	$id              = (int) $request->get_param( 'id' );
	$action_type     = sanitize_key( (string) ( $request->get_param( 'action_type' ) ?? '' ) );
	$idempotency_key = sanitize_text_field( (string) ( $request->get_param( 'idempotency_key' ) ?? '' ) );

	if ( ! $id || ! $action_type ) {
		return new WP_REST_Response( [ 'ok' => false, 'message' => 'Missing id or action_type.' ], 400 );
	}

	// Idempotency guard: return the cached success result if this exact request already succeeded.
	if ( $idempotency_key ) {
		$transient_key = 'dtb_ret_idem_' . md5( $idempotency_key );
		$cached        = get_transient( $transient_key );
		if ( $cached ) {
			return new WP_REST_Response( $cached, 200 );
		}
	}

	$entity = dtb_returns_get( $id );
	if ( ! $entity ) {
		return new WP_REST_Response( [ 'ok' => false, 'message' => 'Return not found.' ], 404 );
	}

	$current_status = sanitize_key( (string) $entity->status->value() );

	// Transition map — action_type → target status.
	// Note: this intentionally mirrors the inverse of the client-side actionMap in dtb-returns-page.js.
	// Keep both in sync when adding new actions; a future refactor can source this from
	// dtb_admin_get_workflow_definition('return') once the registry exposes named-action mappings.
	$transition_map = [
		'approve'            => 'approved',
		'reject'             => 'rejected',
		'mark_awaiting_item' => 'awaiting_item',
		'mark_item_received' => 'item_received',
		'issue_refund'       => 'refund_issued',
		'send_exchange'      => 'exchange_sent',
		'close'              => 'closed',
	];

	$changed = false;

	if ( isset( $transition_map[ $action_type ] ) ) {
		$target = $transition_map[ $action_type ];

		// Enforce allowed transitions.
		$allowed = function_exists( 'dtb_return_get_allowed_transitions' )
			? dtb_return_get_allowed_transitions( $current_status )
			: [];

		if ( ! in_array( $target, (array) $allowed, true ) ) {
			return new WP_REST_Response( [
				'ok'      => false,
				'message' => sprintf(
					'Transition %s → %s is not allowed from the current status.',
					$current_status,
					$target
				),
			], 422 );
		}

		$result = dtb_return_transition_status( $id, $target );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'message' => $result->get_error_message() ], 400 );
		}

		dtb_audit_log_write( 'return.status_changed', [
			'return_id' => $id,
			'from'      => $current_status,
			'to'        => $target,
			'user_id'   => get_current_user_id(),
			'via'       => 'workbench_action:' . $action_type,
		] );
		$changed = true;

	} elseif ( 'set_resolution' === $action_type ) {
		$resolution = sanitize_key( (string) ( $request->get_param( 'resolution' ) ?? '' ) );
		$valid_resolutions = [ 'refund', 'exchange', 'store_credit', 'replacement' ];
		if ( ! in_array( $resolution, $valid_resolutions, true ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'message' => 'Invalid resolution value.' ], 400 );
		}
		update_post_meta( $id, '_dtb_return_resolution', $resolution );
		dtb_audit_log_write( 'return.resolution_set', [
			'return_id'  => $id,
			'resolution' => $resolution,
			'user_id'    => get_current_user_id(),
		] );
		$changed = true;

	} elseif ( 'add_note' === $action_type ) {
		$note = sanitize_textarea_field( wp_unslash( (string) ( $request->get_param( 'note' ) ?? '' ) ) );
		if ( '' === trim( $note ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'message' => 'Note cannot be empty.' ], 400 );
		}
		$raw_notes = get_post_meta( $id, '_dtb_return_staff_notes', true );
		$notes     = is_array( $raw_notes ) ? $raw_notes : ( $raw_notes ? json_decode( $raw_notes, true ) : [] );
		if ( ! is_array( $notes ) ) {
			$notes = [];
		}
		$notes[] = [
			'note'       => $note,
			'user_id'    => get_current_user_id(),
			'user_label' => wp_get_current_user()->display_name ?? 'Admin',
			'created_at' => current_time( 'mysql', true ),
		];
		update_post_meta( $id, '_dtb_return_staff_notes', $notes );
		dtb_audit_log_write( 'return.note_added', [
			'return_id' => $id,
			'user_id'   => get_current_user_id(),
		] );
		$changed = true;

	} else {
		return new WP_REST_Response( [ 'ok' => false, 'message' => 'Unknown action_type: ' . $action_type ], 400 );
	}

	if ( ! $changed ) {
		return new WP_REST_Response( [ 'ok' => false, 'message' => 'No changes applied.' ], 400 );
	}

	// Return refreshed canonical workbench detail payload.
	$detail_request = new WP_REST_Request( 'GET', '/dtb/v1/admin/returns/' . $id . '/detail' );
	$detail_request->set_param( 'id', $id );
	$detail_response = dtb_returns_rest_admin_detail( $detail_request );
	$detail = $detail_response instanceof WP_REST_Response ? $detail_response->get_data() : [];

	$result = array_merge(
		[ 'ok' => true, 'message' => 'Action applied.' ],
		is_array( $detail ) ? $detail : []
	);

	// Cache success result for idempotency replay (TTL: 60 s — enough to cover network retries).
	if ( $idempotency_key ) {
		$transient_key = 'dtb_ret_idem_' . md5( $idempotency_key );
		set_transient( $transient_key, $result, 60 );
	}

	return new WP_REST_Response( $result );
}


