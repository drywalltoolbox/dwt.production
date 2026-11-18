<?php
/**
 * DTB Repair Service — RepairAdminWorkbenchController
 *
 * Focused endpoints for the interactive repair workbench modal. These endpoints
 * persist section-level workbench edits without forcing admins into post.php.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_repair_admin_workbench_register_routes' );

function dtb_repair_admin_workbench_register_routes(): void {
	register_rest_route( 'dtb/v1', '/admin/repairs/technicians', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'dtb_repair_admin_technicians_handler',
		'permission_callback' => static fn() => is_user_logged_in() && current_user_can( 'dtb_manage_repairs' ),
	] );

	register_rest_route( 'dtb/v1', '/admin/repairs/(?P<id>\d+)/workbench', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'dtb_repair_admin_workbench_action_handler',
		'permission_callback' => static fn() => is_user_logged_in() && current_user_can( 'dtb_manage_repairs' ),
		'args'                => [
			'id' => [
				'validate_callback' => static fn( $value ) => is_numeric( $value ) && (int) $value > 0,
				'sanitize_callback' => 'absint',
			],
		],
	] );
}

/**
 * Return assignable repair technicians.
 *
 * @return WP_REST_Response
 */
function dtb_repair_admin_technicians_handler(): WP_REST_Response {
	$role_candidates = [ 'administrator', 'shop_manager', 'editor' ];
	$users = get_users( [
		'role__in' => $role_candidates,
		'fields'   => 'all',
		'number'   => 100,
		'orderby'  => 'display_name',
		'order'    => 'ASC',
	] );

	$current = wp_get_current_user();
	$ids = array_map( static fn( WP_User $user ): int => (int) $user->ID, $users );
	if ( $current instanceof WP_User && $current->ID && ! in_array( (int) $current->ID, $ids, true ) ) {
		$users[] = $current;
	}

	$options = array_values( array_map( static function ( WP_User $user ): array {
		return [
			'id'    => (int) $user->ID,
			'label' => trim( $user->display_name . ' <' . $user->user_email . '>' ),
			'name'  => (string) $user->display_name,
			'email' => (string) $user->user_email,
			'roles' => array_values( (array) $user->roles ),
		];
	}, $users ) );

	return new WP_REST_Response( [ 'ok' => true, 'technicians' => $options ], 200 );
}

/**
 * Generic section-level repair workbench mutation endpoint.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function dtb_repair_admin_workbench_action_handler( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$repair_id = (int) $request->get_param( 'id' );
	$guard = function_exists( '_dtb_repair_action_guard' ) ? _dtb_repair_action_guard( $repair_id ) : get_post( $repair_id );
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}
	if ( ! $guard || 'dtb_repair_request' !== $guard->post_type ) {
		return new WP_Error( 'not_found', __( 'Repair not found.', 'drywall-toolbox' ), [ 'status' => 404 ] );
	}

	$body = $request->get_json_params() ?: [];
	$action_type = sanitize_key( (string) ( $body['action_type'] ?? '' ) );
	if ( '' === $action_type ) {
		return new WP_Error( 'missing_action_type', __( 'action_type is required.', 'drywall-toolbox' ), [ 'status' => 400 ] );
	}

	switch ( $action_type ) {
		case 'quote_save':
			$result = dtb_repair_admin_workbench_save_quote( $repair_id, $body, false );
			break;
		case 'quote_send':
			$result = dtb_repair_admin_workbench_save_quote( $repair_id, $body, true );
			break;
		case 'parts_save':
			$result = dtb_repair_admin_workbench_save_parts( $repair_id, $body, false );
			break;
		case 'parts_allocate':
			$result = dtb_repair_admin_workbench_save_parts( $repair_id, $body, true );
			break;
		case 'technician_assign':
			$result = dtb_repair_admin_workbench_assign_technician( $repair_id, $body );
			break;
		case 'shipping_save':
			$result = dtb_repair_admin_workbench_save_shipping( $repair_id, $body );
			break;
		case 'request_customer_info':
			$result = dtb_repair_admin_workbench_request_customer_info( $repair_id, $body );
			break;
		default:
			return new WP_Error( 'unknown_action_type', __( 'Unknown repair workbench action.', 'drywall-toolbox' ), [ 'status' => 400 ] );
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return function_exists( '_dtb_repair_action_refresh' )
		? _dtb_repair_action_refresh( $repair_id )
		: new WP_REST_Response( [ 'ok' => true ], 200 );
}

function dtb_repair_admin_workbench_save_quote( int $repair_id, array $body, bool $send = false ): bool|WP_Error {
	if ( ! function_exists( 'dtb_repair_save_quote' ) ) {
		return new WP_Error( 'quote_service_missing', __( 'Quote service is not available.', 'drywall-toolbox' ), [ 'status' => 503 ] );
	}

	$quote_input = is_array( $body['quote'] ?? null ) ? $body['quote'] : $body;
	$quote_input['status'] = $send ? 'sent' : sanitize_key( (string) ( $quote_input['status'] ?? 'draft' ) );

	dtb_repair_save_quote( $repair_id, $quote_input, [
		'actor_id' => get_current_user_id(),
		'source'   => $send ? 'admin_repair_workbench_quote_send' : 'admin_repair_workbench_quote_save',
	] );

	$current = (string) get_post_meta( $repair_id, '_repair_status', true );
	if ( $send && 'quoted' !== $current && in_array( $current, [ 'reviewed', 'approved' ], true ) && function_exists( 'dtb_transition_repair_status' ) ) {
		$result = dtb_transition_repair_status( $repair_id, 'quoted', [ 'actor_id' => get_current_user_id() ] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	if ( function_exists( 'dtb_admin_audit_write' ) ) {
		dtb_admin_audit_write( 'repair', $repair_id, $send ? 'repair.quote_sent' : 'repair.quote_saved', [ 'user_id' => get_current_user_id() ] );
	}

	return true;
}

function dtb_repair_admin_workbench_clean_parts( array $parts ): array {
	$clean = [];
	foreach ( $parts as $part ) {
		if ( ! is_array( $part ) ) {
			continue;
		}
		$sku = sanitize_text_field( (string) ( $part['sku'] ?? '' ) );
		if ( '' === $sku ) {
			continue;
		}
		$qty = max( 1, min( 999, absint( $part['qty'] ?? 1 ) ) );
		$clean[] = [
			'sku'  => $sku,
			'qty'  => $qty,
			'note' => sanitize_text_field( (string) ( $part['note'] ?? '' ) ),
		];
	}
	return array_slice( $clean, 0, 100 );
}

function dtb_repair_admin_workbench_save_parts( int $repair_id, array $body, bool $advance_workflow ): bool|WP_Error {
	$parts = dtb_repair_admin_workbench_clean_parts( is_array( $body['parts'] ?? null ) ? $body['parts'] : [] );
	update_post_meta( $repair_id, '_repair_parts_allocated', wp_json_encode( $parts ) );

	$current = (string) get_post_meta( $repair_id, '_repair_status', true );
	if ( $advance_workflow && in_array( $current, [ 'approved', 'quote_accepted' ], true ) && function_exists( 'dtb_transition_repair_status' ) ) {
		$result = dtb_transition_repair_status( $repair_id, 'parts_allocated', [ 'actor_id' => get_current_user_id() ] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	if ( function_exists( 'dtb_admin_audit_write' ) ) {
		dtb_admin_audit_write( 'repair', $repair_id, $advance_workflow ? 'repair.parts_allocated' : 'repair.parts_saved', [
			'parts'   => $parts,
			'user_id' => get_current_user_id(),
		] );
	}

	return true;
}

function dtb_repair_admin_workbench_assign_technician( int $repair_id, array $body ): bool {
	$technician_id = absint( $body['technician_id'] ?? 0 );
	if ( $technician_id > 0 ) {
		update_post_meta( $repair_id, '_repair_technician_id', $technician_id );
	} else {
		delete_post_meta( $repair_id, '_repair_technician_id' );
	}

	$note = sanitize_textarea_field( (string) ( $body['note'] ?? '' ) );
	if ( '' !== $note ) {
		$user = wp_get_current_user();
		$cid = wp_insert_comment( [
			'comment_post_ID'      => $repair_id,
			'comment_content'      => $note,
			'comment_author'       => $user->display_name,
			'comment_author_email' => $user->user_email,
			'comment_approved'     => 1,
			'user_id'              => $user->ID,
		] );
		if ( $cid ) {
			add_comment_meta( $cid, '_dtb_comment_type', 'internal', true );
		}
	}

	if ( function_exists( 'dtb_admin_audit_write' ) ) {
		dtb_admin_audit_write( 'repair', $repair_id, 'repair.technician_assigned', [
			'technician_id' => $technician_id,
			'user_id'       => get_current_user_id(),
		] );
	}

	return true;
}

function dtb_repair_admin_workbench_save_shipping( int $repair_id, array $body ): bool {
	$fields = [
		'tracking_number' => '_repair_veeqo_tracking',
		'veeqo_order_id'  => '_repair_veeqo_order_id',
		'rate_name'       => '_repair_shipping_rate_name',
		'rate_price'      => '_repair_shipping_rate_price',
		'line1'           => '_repair_return_address_1',
		'city'            => '_repair_return_city',
		'state'           => '_repair_return_state',
		'postcode'        => '_repair_return_postcode',
		'country'         => '_repair_return_country',
	];

	foreach ( $fields as $input_key => $meta_key ) {
		if ( ! array_key_exists( $input_key, $body ) ) {
			continue;
		}
		$value = 'rate_price' === $input_key
			? (string) max( 0, round( (float) $body[ $input_key ], 2 ) )
			: sanitize_text_field( (string) $body[ $input_key ] );
		if ( '' === $value ) {
			delete_post_meta( $repair_id, $meta_key );
		} else {
			update_post_meta( $repair_id, $meta_key, $value );
		}
	}

	if ( function_exists( 'dtb_admin_audit_write' ) ) {
		dtb_admin_audit_write( 'repair', $repair_id, 'repair.shipping_saved', [ 'user_id' => get_current_user_id() ] );
	}

	return true;
}

function dtb_repair_admin_workbench_request_customer_info( int $repair_id, array $body ): bool|WP_Error {
	$message = sanitize_textarea_field( (string) ( $body['body'] ?? '' ) );
	if ( '' === $message ) {
		return new WP_Error( 'missing_message', __( 'Message body is required.', 'drywall-toolbox' ), [ 'status' => 400 ] );
	}

	$user = wp_get_current_user();
	$cid = wp_insert_comment( [
		'comment_post_ID'      => $repair_id,
		'comment_content'      => $message,
		'comment_author'       => $user->display_name,
		'comment_author_email' => $user->user_email,
		'comment_approved'     => 1,
		'user_id'              => $user->ID,
	] );
	if ( ! $cid ) {
		return new WP_Error( 'db_error', __( 'Failed to save message.', 'drywall-toolbox' ), [ 'status' => 500 ] );
	}
	add_comment_meta( $cid, '_dtb_comment_type', 'staff', true );

	$current = (string) get_post_meta( $repair_id, '_repair_status', true );
	$allowed = function_exists( 'dtb_get_allowed_transitions' ) ? ( dtb_get_allowed_transitions()[ $current ] ?? [] ) : [];
	if ( in_array( 'awaiting_customer', $allowed, true ) && function_exists( 'dtb_transition_repair_status' ) ) {
		dtb_transition_repair_status( $repair_id, 'awaiting_customer', [ 'actor_id' => get_current_user_id(), 'note' => 'Requested customer information from repair workbench.' ] );
	}

	if ( function_exists( 'dtb_admin_audit_write' ) ) {
		dtb_admin_audit_write( 'repair', $repair_id, 'repair.customer_info_requested', [
			'comment_id' => $cid,
			'user_id'    => get_current_user_id(),
		] );
	}

	return true;
}
