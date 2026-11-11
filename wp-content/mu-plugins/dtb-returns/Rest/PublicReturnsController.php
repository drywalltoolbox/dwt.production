<?php
/**
 * DTB Returns — verified public customer endpoints.
 *
 * WooCommerce remains authoritative for order and line-item identity. Public
 * callers must prove possession of both the order number and checkout email
 * before DTB Returns issues a short-lived lookup token.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_returns_public_rest_register_routes(): void {
	register_rest_route( 'dtb/v1', '/returns/lookup', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'dtb_returns_public_rest_lookup',
		'permission_callback' => '__return_true',
		'args'                => [
			'order_number'   => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
			'customer_email' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_email' ],
		],
	] );

	register_rest_route( 'dtb/v1', '/returns/request/verified', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'dtb_returns_public_rest_submit_verified',
		'permission_callback' => '__return_true',
		'args'                => [
			'lookup_token'    => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
			'idempotency_key' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
			'request_type'    => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ],
			'reason'          => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ],
			'notes'           => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ],
			'items'           => [ 'type' => 'array', 'required' => true ],
		],
	] );
}

function dtb_returns_public_rate_limit( string $bucket, int $limit, int $window_seconds ) {
	$ip     = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
	$key    = 'dtb_returns_' . sanitize_key( $bucket ) . '_' . md5( $ip );
	$count  = (int) get_transient( $key );
	$limit  = max( 1, $limit );
	$window = max( MINUTE_IN_SECONDS, $window_seconds );

	if ( $count >= $limit ) {
		return new WP_Error( 'dtb_returns_rate_limited', __( 'Too many attempts. Please wait a few minutes and try again.', 'drywall-toolbox' ), [ 'status' => 429 ] );
	}
	set_transient( $key, $count + 1, $window );
	return true;
}

function dtb_returns_public_rest_lookup( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$rate = dtb_returns_public_rate_limit( 'lookup', 8, 10 * MINUTE_IN_SECONDS );
	if ( is_wp_error( $rate ) ) {
		return $rate;
	}

	$order_number = (string) $request->get_param( 'order_number' );
	$email        = (string) $request->get_param( 'customer_email' );
	$order        = dtb_returns_resolve_verified_order( $order_number, $email );
	if ( is_wp_error( $order ) ) {
		return $order;
	}

	$token = dtb_returns_generate_lookup_token( $order );
	dtb_returns_store_lookup_token( $token, (int) $order->get_id() );

	return new WP_REST_Response( [
		'orders' => [ dtb_returns_project_verified_order( $order, $token ) ],
	], 200 );
}

function dtb_returns_public_request_types(): array {
	return [ 'standard_return', 'order_problem', 'product_problem' ];
}

function dtb_returns_public_reason_map(): array {
	return [
		'standard_return' => [ 'changed_mind', 'ordered_by_mistake', 'better_price_found', 'other' ],
		'order_problem'   => [ 'arrived_damaged', 'wrong_item_received', 'item_not_as_described', 'other' ],
		'product_problem' => [ 'defective_not_working', 'item_not_as_described', 'other' ],
	];
}

function dtb_returns_public_reasons(): array {
	$reasons = [];
	foreach ( dtb_returns_public_reason_map() as $allowed ) {
		$reasons = array_merge( $reasons, $allowed );
	}
	return array_values( array_unique( $reasons ) );
}

function dtb_returns_public_reason_allowed_for_type( string $request_type, string $reason ): bool {
	$map = dtb_returns_public_reason_map();
	return isset( $map[ $request_type ] ) && in_array( $reason, $map[ $request_type ], true );
}

function dtb_returns_public_reason_label( string $reason ): string {
	$labels = [
		'arrived_damaged'       => __( 'Arrived damaged', 'drywall-toolbox' ),
		'wrong_item_received'   => __( 'Wrong item received', 'drywall-toolbox' ),
		'item_not_as_described' => __( 'Item not as described', 'drywall-toolbox' ),
		'defective_not_working' => __( 'Defective / not working', 'drywall-toolbox' ),
		'changed_mind'          => __( 'Changed my mind / no longer needed', 'drywall-toolbox' ),
		'ordered_by_mistake'    => __( 'Ordered by mistake', 'drywall-toolbox' ),
		'better_price_found'    => __( 'Better price found elsewhere', 'drywall-toolbox' ),
		'other'                 => __( 'Other', 'drywall-toolbox' ),
	];
	return $labels[ $reason ] ?? __( 'Other', 'drywall-toolbox' );
}

function dtb_returns_public_rest_submit_verified( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$rate = dtb_returns_public_rate_limit( 'submit', 3, 10 * MINUTE_IN_SECONDS );
	if ( is_wp_error( $rate ) ) {
		return $rate;
	}

	$lookup_token    = (string) $request->get_param( 'lookup_token' );
	$idempotency_key = trim( (string) $request->get_param( 'idempotency_key' ) );
	$request_type    = sanitize_key( (string) $request->get_param( 'request_type' ) );
	$reason          = sanitize_key( (string) $request->get_param( 'reason' ) );
	$notes           = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );
	$items           = (array) $request->get_param( 'items' );

	if ( strlen( $idempotency_key ) < 16 || strlen( $idempotency_key ) > 96 || ! preg_match( '/^[A-Za-z0-9._:-]+$/', $idempotency_key ) ) {
		return new WP_Error( 'dtb_returns_invalid_idempotency_key', __( 'The return request identity is invalid. Refresh the page and try again.', 'drywall-toolbox' ), [ 'status' => 400 ] );
	}
	if ( ! in_array( $request_type, dtb_returns_public_request_types(), true ) || ! in_array( $reason, dtb_returns_public_reasons(), true ) || ! dtb_returns_public_reason_allowed_for_type( $request_type, $reason ) ) {
		return new WP_Error( 'dtb_returns_invalid_reason', __( 'Choose a valid return type and reason.', 'drywall-toolbox' ), [ 'status' => 400 ] );
	}

	$order = dtb_returns_validate_lookup_token( $lookup_token );
	if ( is_wp_error( $order ) ) {
		return $order;
	}

	$order_id = (int) $order->get_id();
	$existing = dtb_returns_find_by_idempotency_key( $order_id, $idempotency_key );
	if ( $existing > 0 ) {
		$entity = dtb_returns_get( $existing );
		if ( $entity ) {
			return new WP_REST_Response( dtb_returns_public_success_payload( $entity ), 200 );
		}
	}

	$lock = dtb_returns_acquire_submission_lock( $order_id, $idempotency_key );
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}

	try {
		// Re-check after lock acquisition so concurrent identical requests converge
		// on the first created return instead of producing duplicate side effects.
		$existing = dtb_returns_find_by_idempotency_key( $order_id, $idempotency_key );
		if ( $existing > 0 ) {
			$entity = dtb_returns_get( $existing );
			if ( $entity ) {
				return new WP_REST_Response( dtb_returns_public_success_payload( $entity ), 200 );
			}
		}

		$validated_items = dtb_returns_validate_requested_items( $order, $items, $request_type );
		if ( is_wp_error( $validated_items ) ) {
			return $validated_items;
		}

		$customer_name = trim( (string) $order->get_formatted_billing_full_name() );
		if ( '' === $customer_name ) {
			$customer_name = __( 'Customer', 'drywall-toolbox' );
		}

		$result = dtb_return_create( [
			'order_id'        => $order_id,
			'order_number'    => (string) $order->get_order_number(),
			'customer_name'   => $customer_name,
			'customer_email'  => sanitize_email( (string) $order->get_billing_email() ),
			'request_type'    => $request_type,
			'reason'          => dtb_returns_public_reason_label( $reason ),
			'notes'           => $notes,
			'items'           => $validated_items,
			'resolution'      => '',
			'idempotency_key' => $idempotency_key,
		] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$entity = dtb_returns_get( (int) $result );
		if ( ! $entity ) {
			return new WP_Error( 'dtb_returns_create_failed', __( 'The return was created but could not be loaded. Please contact support.', 'drywall-toolbox' ), [ 'status' => 500 ] );
		}

		dtb_returns_public_send_notifications( $entity );
		return new WP_REST_Response( dtb_returns_public_success_payload( $entity ), 201 );
	} finally {
		dtb_returns_release_submission_lock( $order_id, $idempotency_key );
	}
}

function dtb_returns_public_success_payload( DTB_Return_Entity $entity ): array {
	$token = dtb_returns_generate_public_status_token( $entity->id, $entity->customer_email );
	return [
		'return_id'    => $entity->id,
		'public_token' => $token,
		'status_url'   => add_query_arg( [ 'token' => $token ], home_url( '/returns/status/' . $entity->id ) ),
		'status'       => $entity->status->value(),
		'message'      => __( 'Return request submitted successfully.', 'drywall-toolbox' ),
	];
}

function dtb_returns_public_send_notifications( DTB_Return_Entity $entity ): void {
	$site_name = get_bloginfo( 'name' ) ?: 'Drywall Toolbox';
	$status     = dtb_returns_public_success_payload( $entity );
	$subject    = sprintf( '[%s] Return request received — #%d', $site_name, $entity->id );
	$plain      = "Hi {$entity->customer_name},\n\n";
	$plain     .= "We received your return request for order {$entity->order_number}.\n\n";
	$plain     .= "Return reference: #{$entity->id}\nCurrent status: Pending Review\n\n";
	$plain     .= "Track your return status here:\n{$status['status_url']}\n\n";
	$plain     .= "Please do not ship anything back until the return is approved and instructions are provided.\n\n{$site_name} Support Team\n";

	if ( is_email( $entity->customer_email ) && ( ! function_exists( 'dtb_account_email_preference' ) || dtb_account_email_preference( $entity->customer_email, 'return_updates' ) ) ) {
		if ( function_exists( 'dtb_send_email' ) ) {
			dtb_send_email( [
				'to'           => $entity->customer_email,
				'subject'      => $subject,
				'message'      => $plain,
				'content_type' => 'text/plain',
				'headers'      => [ 'Reply-To: ' . $site_name . ' Support <info@drywalltoolbox.com>' ],
				'context'      => [ 'module' => 'dtb-returns', 'route' => 'verified-public-submit' ],
			] );
		} else {
			wp_mail( $entity->customer_email, $subject, $plain, [ 'Content-Type: text/plain; charset=UTF-8' ] );
		}
	}

	$admin_email = sanitize_email( (string) get_option( 'admin_email' ) );
	if ( is_email( $admin_email ) ) {
		$admin_subject = sprintf( '[%s] New Return Request — Order %s', $site_name, $entity->order_number );
		$admin_body    = "Return ID: #{$entity->id}\nOrder: {$entity->order_number}\nCustomer: {$entity->customer_name} <{$entity->customer_email}>\nType: {$entity->request_type}\nReason: {$entity->reason}\n";
		if ( $entity->notes ) {
			$admin_body .= "Notes: {$entity->notes}\n";
		}
		$admin_body .= "\nView in admin: " . admin_url( 'admin.php?page=dtb-returns&action=view&return_id=' . $entity->id ) . "\n";
		if ( function_exists( 'dtb_send_email' ) ) {
			dtb_send_email( [
				'to'           => $admin_email,
				'subject'      => $admin_subject,
				'message'      => $admin_body,
				'content_type' => 'text/plain',
				'context'      => [ 'module' => 'dtb-returns', 'route' => 'verified-public-submit-admin' ],
			] );
		} else {
			wp_mail( $admin_email, $admin_subject, $admin_body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
		}
	}
}
