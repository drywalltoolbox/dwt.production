<?php
/**
 * DTB Repair Service — RepairAdminActionController
 *
 * REST action endpoints consumed by dtb-repairs-page.js.
 *
 * Routes:
 *   PATCH  /dtb/v1/admin/repairs/{id}                — update core fields
 *   POST   /dtb/v1/admin/repairs/{id}/transition      — status transition
 *   POST   /dtb/v1/admin/repairs/{id}/customer-message — send to customer
 *   POST   /dtb/v1/admin/repairs/{id}/internal-note   — private staff note
 *   POST   /dtb/v1/admin/repairs/{id}/mark-customer-read — mark messages read
 *   POST   /dtb/v1/admin/repairs/{id}/quote/save      — save / update quote draft
 *   POST   /dtb/v1/admin/repairs/{id}/quote/send      — send quote to customer
 *   POST   /dtb/v1/admin/repairs/{id}/parts/allocate  — allocate parts list
 *   POST   /dtb/v1/admin/repairs/{id}/ready-to-ship   — mark ready to ship
 *   POST   /dtb/v1/admin/repairs/{id}/close           — close (resolve) repair
 *
 * Every handler:
 *  - Validates nonce + capability
 *  - Sanitizes all inputs
 *  - Enforces idempotency via _dtb_action_lock post meta
 *  - Writes an audit event via dtb_admin_audit_write()
 *  - Returns refreshed detail payload via dtb_repair_admin_detail_handler()
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_repair_admin_action_register_routes' );

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Guard helper: verify post exists, is a repair, and the current user has cap.
 * Returns the WP_Post or a WP_Error.
 */
function _dtb_repair_action_guard( int $repair_id ): WP_Post|WP_Error {
	if ( ! is_user_logged_in() || ! current_user_can( 'dtb_manage_repairs' ) ) {
		return new WP_Error( 'forbidden', __( 'Insufficient permissions.', 'drywall-toolbox' ), [ 'status' => 403 ] );
	}
	$post = get_post( $repair_id );
	if ( ! $post || 'dtb_repair_request' !== $post->post_type ) {
		return new WP_Error( 'not_found', __( 'Repair not found.', 'drywall-toolbox' ), [ 'status' => 404 ] );
	}
	return $post;
}

/**
 * Lock an action to prevent duplicate submissions (simple optimistic locking).
 *
 * @param int    $repair_id
 * @param string $action  Slug e.g. 'transition', 'quote_send'.
 * @return bool  False if already locked (idempotency key still active).
 */
function _dtb_repair_action_lock( int $repair_id, string $action ): bool {
	$meta_key = '_dtb_action_lock_' . sanitize_key( $action );
	$existing = (int) get_post_meta( $repair_id, $meta_key, true );
	if ( $existing && ( time() - $existing ) < 10 ) {
		return false;
	}
	update_post_meta( $repair_id, $meta_key, time() );
	return true;
}

/** Release action lock. */
function _dtb_repair_action_unlock( int $repair_id, string $action ): void {
	delete_post_meta( $repair_id, '_dtb_action_lock_' . sanitize_key( $action ) );
}

/**
 * Return a refreshed detail payload response for the given repair.
 */
function _dtb_repair_action_refresh( int $repair_id ): WP_REST_Response {
	$req = new WP_REST_Request( 'GET', '/dtb/v1/admin/repairs/' . $repair_id . '/detail' );
	$req->set_param( 'id', $repair_id );
	return dtb_repair_admin_detail_handler( $req );
}

// ── Route registration ────────────────────────────────────────────────────────

function dtb_repair_admin_action_register_routes(): void {
	$base = '/admin/repairs/(?P<id>\d+)';
	$id_arg = [
		'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
		'sanitize_callback' => 'absint',
	];

	register_rest_route( 'dtb/v1', '/admin/repairs/bulk', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'dtb_repair_admin_bulk_action_handler',
		'permission_callback' => fn() => is_user_logged_in() && current_user_can( 'dtb_manage_repairs' ),
		'args'                => [
			'action' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ],
			'ids'    => [ 'type' => 'array', 'required' => true ],
		],
	] );

	// PATCH core fields
	register_rest_route( 'dtb/v1', $base, [
		'methods'             => WP_REST_Server::EDITABLE,
		'callback'            => 'dtb_repair_admin_patch_handler',
		'permission_callback' => fn() => is_user_logged_in() && current_user_can( 'dtb_manage_repairs' ),
		'args'                => [ 'id' => $id_arg ],
	] );

	// Action sub-routes
	$sub_routes = [
		'/transition'         => 'dtb_repair_admin_transition_handler',
		'/customer-message'   => 'dtb_repair_admin_customer_message_handler',
		'/internal-note'      => 'dtb_repair_admin_internal_note_handler',
		'/mark-customer-read' => 'dtb_repair_admin_mark_customer_read_handler',
		'/technician/assign'  => 'dtb_repair_admin_technician_assign_handler',
		'/quote/save'         => 'dtb_repair_admin_quote_save_handler',
		'/quote/send'         => 'dtb_repair_admin_quote_send_handler',
		'/parts/allocate'     => 'dtb_repair_admin_parts_allocate_handler',
		'/ready-to-ship'      => 'dtb_repair_admin_ready_to_ship_handler',
		'/close'              => 'dtb_repair_admin_close_handler',
	];

	foreach ( $sub_routes as $sub => $callback ) {
		register_rest_route( 'dtb/v1', $base . $sub, [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => $callback,
			'permission_callback' => fn() => is_user_logged_in() && current_user_can( 'dtb_manage_repairs' ),
			'args'                => [ 'id' => $id_arg ],
		] );
	}
}

function dtb_repair_admin_bulk_action_handler( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$action = sanitize_key( (string) $request->get_param( 'action' ) );
	$ids    = array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) );

	if ( 'delete' !== $action ) {
		return new WP_Error( 'dtb_repair_invalid_bulk_action', __( 'Unsupported bulk repair action.', 'drywall-toolbox' ), [ 'status' => 400 ] );
	}

	if ( empty( $ids ) ) {
		return new WP_Error( 'dtb_repair_invalid_bulk_request', __( 'No repair IDs provided.', 'drywall-toolbox' ), [ 'status' => 400 ] );
	}

	$processed = [];
	$errors    = [];

	foreach ( array_unique( $ids ) as $repair_id ) {
		$guard = _dtb_repair_action_guard( $repair_id );
		if ( is_wp_error( $guard ) ) {
			$errors[] = $repair_id;
			continue;
		}

		if ( function_exists( 'dtb_admin_audit_write' ) ) {
			dtb_admin_audit_write( 'repairs', $repair_id, 'repair.moved_to_trash', [
				'actor_id' => get_current_user_id(),
				'source'   => 'admin_bulk_action',
			] );
		}

		$result = wp_trash_post( $repair_id );
		if ( ! $result ) {
			$errors[] = $repair_id;
			continue;
		}
		$processed[] = $repair_id;
	}

	return new WP_REST_Response( [
		'ok'        => empty( $errors ),
		'processed' => $processed,
		'deleted'   => $processed,
		'errors'    => $errors,
	], 200 );
}

// ── PATCH core fields ─────────────────────────────────────────────────────────

function dtb_repair_admin_patch_handler( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$repair_id = (int) $request->get_param( 'id' );
	$guard     = _dtb_repair_action_guard( $repair_id );
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	$patchable = [
		'_repair_priority'           => 'sanitize_key',
		'_repair_service_tier'       => 'sanitize_key',
		'_repair_technician_id'      => 'absint',
		'_repair_contact_preference' => 'sanitize_key',
		'_repair_tool_model'         => 'sanitize_text_field',
		'_repair_serial'             => 'sanitize_text_field',
	];

	$body = $request->get_json_params() ?: [];
	$updated = [];

	foreach ( $patchable as $meta_key => $sanitizer ) {
		$field = ltrim( str_replace( '_repair_', '', $meta_key ), '_' );
		if ( isset( $body[ $field ] ) ) {
			$value = $sanitizer( $body[ $field ] );
			update_post_meta( $repair_id, $meta_key, $value );
			$updated[ $field ] = $value;
		}
	}

	if ( ! empty( $updated ) && function_exists( 'dtb_admin_audit_write' ) ) {
		dtb_admin_audit_write( 'repair', $repair_id, 'repair.fields_patched', [
			'fields'  => array_keys( $updated ),
			'user_id' => get_current_user_id(),
		] );
	}

	return _dtb_repair_action_refresh( $repair_id );
}

// ── Transition ────────────────────────────────────────────────────────────────

function dtb_repair_admin_transition_handler( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$repair_id = (int) $request->get_param( 'id' );
	$guard     = _dtb_repair_action_guard( $repair_id );
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	$body       = $request->get_json_params() ?: [];
	$to_status  = sanitize_key( $body['to_status'] ?? '' );
	$note       = sanitize_textarea_field( $body['note'] ?? '' );

	if ( '' === $to_status ) {
		return new WP_Error( 'missing_param', __( 'to_status is required.', 'drywall-toolbox' ), [ 'status' => 400 ] );
	}

	// Transition-map validation
	$current = (string) get_post_meta( $repair_id, '_repair_status', true );
	$allowed = function_exists( 'dtb_get_allowed_transitions' )
		? ( dtb_get_allowed_transitions()[ $current ] ?? [] )
		: [];

	if ( ! in_array( $to_status, $allowed, true ) ) {
		return new WP_Error(
			'invalid_transition',
			sprintf(
				/* translators: 1 = from status, 2 = to status */
				__( 'Cannot transition repair from "%1$s" to "%2$s".', 'drywall-toolbox' ),
				$current,
				$to_status
			),
			[ 'status' => 409 ]
		);
	}

	if ( ! _dtb_repair_action_lock( $repair_id, 'transition' ) ) {
		return new WP_Error( 'locked', __( 'A transition is already in progress.', 'drywall-toolbox' ), [ 'status' => 429 ] );
	}

	$result = function_exists( 'dtb_transition_repair_status' )
		? dtb_transition_repair_status( $repair_id, $to_status, [ 'note' => $note, 'actor_id' => get_current_user_id() ] )
		: update_post_meta( $repair_id, '_repair_status', $to_status );

	_dtb_repair_action_unlock( $repair_id, 'transition' );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( function_exists( 'dtb_admin_audit_write' ) ) {
		dtb_admin_audit_write( 'repair', $repair_id, 'repair.status_changed', [
			'from'    => $current,
			'to'      => $to_status,
			'note'    => $note,
			'user_id' => get_current_user_id(),
		] );
	}

	return _dtb_repair_action_refresh( $repair_id );
}

// ── Customer message ──────────────────────────────────────────────────────────

function dtb_repair_admin_customer_message_handler( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$repair_id = (int) $request->get_param( 'id' );
	$guard     = _dtb_repair_action_guard( $repair_id );
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	$body = $request->get_json_params() ?: [];
	$body_text = sanitize_textarea_field( $body['body'] ?? '' );
	if ( '' === $body_text ) {
		return new WP_Error( 'missing_param', __( 'Message body is required.', 'drywall-toolbox' ), [ 'status' => 400 ] );
	}

	$user  = wp_get_current_user();
	$cid   = wp_insert_comment( [
		'comment_post_ID'  => $repair_id,
		'comment_content'  => $body_text,
		'comment_author'   => $user->display_name,
		'comment_author_email' => $user->user_email,
		'comment_approved' => 1,
		'user_id'          => $user->ID,
	] );

	if ( ! $cid ) {
		return new WP_Error( 'db_error', __( 'Failed to save message.', 'drywall-toolbox' ), [ 'status' => 500 ] );
	}

	add_comment_meta( $cid, '_dtb_comment_type', 'staff', true );

	if ( function_exists( 'dtb_admin_audit_write' ) ) {
		dtb_admin_audit_write( 'repair', $repair_id, 'repair.customer_message_sent', [
			'comment_id' => $cid,
			'user_id'    => get_current_user_id(),
		] );
	}

	return _dtb_repair_action_refresh( $repair_id );
}

// ── Internal note ─────────────────────────────────────────────────────────────

function dtb_repair_admin_internal_note_handler( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$repair_id = (int) $request->get_param( 'id' );
	$guard     = _dtb_repair_action_guard( $repair_id );
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	$body = $request->get_json_params() ?: [];
	$note = sanitize_textarea_field( $body['body'] ?? '' );
	if ( '' === $note ) {
		return new WP_Error( 'missing_param', __( 'Note body is required.', 'drywall-toolbox' ), [ 'status' => 400 ] );
	}

	$user = wp_get_current_user();
	$cid  = wp_insert_comment( [
		'comment_post_ID'      => $repair_id,
		'comment_content'      => $note,
		'comment_author'       => $user->display_name,
		'comment_author_email' => $user->user_email,
		'comment_approved'     => 1,
		'user_id'              => $user->ID,
	] );

	if ( ! $cid ) {
		return new WP_Error( 'db_error', __( 'Failed to save note.', 'drywall-toolbox' ), [ 'status' => 500 ] );
	}

	add_comment_meta( $cid, '_dtb_comment_type', 'internal', true );

	if ( function_exists( 'dtb_admin_audit_write' ) ) {
		dtb_admin_audit_write( 'repair', $repair_id, 'repair.internal_note_added', [
			'comment_id' => $cid,
			'user_id'    => get_current_user_id(),
		] );
	}

	return _dtb_repair_action_refresh( $repair_id );
}

// ── Mark customer messages read ────────────────────────────────────────────────

function dtb_repair_admin_mark_customer_read_handler( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$repair_id = (int) $request->get_param( 'id' );
	$guard     = _dtb_repair_action_guard( $repair_id );
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	update_post_meta( $repair_id, '_repair_customer_unread', 0 );

	if ( function_exists( 'dtb_admin_audit_write' ) ) {
		dtb_admin_audit_write( 'repair', $repair_id, 'repair.customer_messages_read', [
			'user_id' => get_current_user_id(),
		] );
	}

	return _dtb_repair_action_refresh( $repair_id );
}

// ── Technician assignment ─────────────────────────────────────────────────────

function dtb_repair_admin_technician_assign_handler( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$repair_id = (int) $request->get_param( 'id' );
	$guard     = _dtb_repair_action_guard( $repair_id );
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	$body          = $request->get_json_params() ?: [];
	$technician_id = absint( $body['technician_id'] ?? 0 );

	if ( $technician_id > 0 ) {
		update_post_meta( $repair_id, '_repair_technician_id', $technician_id );
	} else {
		delete_post_meta( $repair_id, '_repair_technician_id' );
	}

	if ( function_exists( 'dtb_admin_audit_write' ) ) {
		dtb_admin_audit_write( 'repair', $repair_id, 'repair.technician_assigned', [
			'technician_id' => $technician_id,
			'user_id'       => get_current_user_id(),
		] );
	}

	return _dtb_repair_action_refresh( $repair_id );
}

// ── Quote save (draft) ────────────────────────────────────────────────────────

function dtb_repair_admin_quote_save_handler( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$repair_id = (int) $request->get_param( 'id' );
	$guard     = _dtb_repair_action_guard( $repair_id );
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	$body = $request->get_json_params() ?: [];

	if ( ! function_exists( 'dtb_repair_save_quote' ) ) {
		return new WP_Error( 'unavailable', __( 'Quote service not available.', 'drywall-toolbox' ), [ 'status' => 503 ] );
	}

	$result = dtb_repair_save_quote( $repair_id, $body, [
		'actor_id' => get_current_user_id(),
		'send'     => false,
	] );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( function_exists( 'dtb_admin_audit_write' ) ) {
		dtb_admin_audit_write( 'repair', $repair_id, 'repair.quote_saved', [
			'user_id' => get_current_user_id(),
		] );
	}

	return _dtb_repair_action_refresh( $repair_id );
}

// ── Quote send ────────────────────────────────────────────────────────────────

function dtb_repair_admin_quote_send_handler( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$repair_id = (int) $request->get_param( 'id' );
	$guard     = _dtb_repair_action_guard( $repair_id );
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	$current = (string) get_post_meta( $repair_id, '_repair_status', true );
	if ( ! in_array( $current, [ 'reviewed', 'approved', 'quoted' ], true ) ) {
		return new WP_Error( 'invalid_state', __( 'Quote can only be sent from reviewed, approved, or quoted status.', 'drywall-toolbox' ), [ 'status' => 409 ] );
	}

	if ( ! _dtb_repair_action_lock( $repair_id, 'quote_send' ) ) {
		return new WP_Error( 'locked', __( 'Quote send already in progress.', 'drywall-toolbox' ), [ 'status' => 429 ] );
	}

	$body = $request->get_json_params() ?: [];

	if ( function_exists( 'dtb_repair_save_quote' ) ) {
		dtb_repair_save_quote( $repair_id, $body, [
			'actor_id' => get_current_user_id(),
			'send'     => true,
		] );
	}

	// Transition to 'quoted' if not already.
	if ( 'quoted' !== $current && function_exists( 'dtb_transition_repair_status' ) ) {
		dtb_transition_repair_status( $repair_id, 'quoted', [ 'actor_id' => get_current_user_id() ] );
	}

	_dtb_repair_action_unlock( $repair_id, 'quote_send' );

	if ( function_exists( 'dtb_admin_audit_write' ) ) {
		dtb_admin_audit_write( 'repair', $repair_id, 'repair.quote_sent', [
			'user_id' => get_current_user_id(),
		] );
	}

	return _dtb_repair_action_refresh( $repair_id );
}

// ── Parts allocate ────────────────────────────────────────────────────────────

function dtb_repair_admin_parts_allocate_handler( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$repair_id = (int) $request->get_param( 'id' );
	$guard     = _dtb_repair_action_guard( $repair_id );
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	$current = (string) get_post_meta( $repair_id, '_repair_status', true );
	if ( ! in_array( $current, [ 'approved', 'quote_accepted' ], true ) ) {
		return new WP_Error( 'invalid_state', __( 'Parts can only be allocated from approved or quote_accepted status.', 'drywall-toolbox' ), [ 'status' => 409 ] );
	}

	$body  = $request->get_json_params() ?: [];
	$parts = is_array( $body['parts'] ?? null ) ? $body['parts'] : [];

	// Sanitize parts list
	$clean_parts = array_values( array_map( function ( $p ) {
		return [
			'sku'  => sanitize_text_field( $p['sku'] ?? '' ),
			'qty'  => absint( $p['qty'] ?? 1 ),
			'note' => sanitize_text_field( $p['note'] ?? '' ),
		];
	}, $parts ) );

	update_post_meta( $repair_id, '_repair_parts_allocated', wp_json_encode( $clean_parts ) );

	// Transition to parts_allocated
	if ( function_exists( 'dtb_transition_repair_status' ) ) {
		dtb_transition_repair_status( $repair_id, 'parts_allocated', [ 'actor_id' => get_current_user_id() ] );
	}

	if ( function_exists( 'dtb_admin_audit_write' ) ) {
		dtb_admin_audit_write( 'repair', $repair_id, 'repair.parts_allocated', [
			'parts'   => $clean_parts,
			'user_id' => get_current_user_id(),
		] );
	}

	return _dtb_repair_action_refresh( $repair_id );
}

// ── Ready to ship ─────────────────────────────────────────────────────────────

function dtb_repair_admin_ready_to_ship_handler( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$repair_id = (int) $request->get_param( 'id' );
	$guard     = _dtb_repair_action_guard( $repair_id );
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	$current = (string) get_post_meta( $repair_id, '_repair_status', true );
	if ( 'in_progress' !== $current ) {
		return new WP_Error( 'invalid_state', __( 'Ready-to-ship can only be set from in_progress status.', 'drywall-toolbox' ), [ 'status' => 409 ] );
	}

	$body           = $request->get_json_params() ?: [];
	$tracking       = sanitize_text_field( $body['tracking_number'] ?? '' );
	$veeqo_order_id = sanitize_text_field( $body['veeqo_order_id'] ?? '' );

	if ( $tracking ) {
		update_post_meta( $repair_id, '_repair_veeqo_tracking', $tracking );
	}
	if ( $veeqo_order_id ) {
		update_post_meta( $repair_id, '_repair_veeqo_order_id', $veeqo_order_id );
	}

	if ( function_exists( 'dtb_transition_repair_status' ) ) {
		$result = dtb_transition_repair_status( $repair_id, 'ready_to_ship', [ 'actor_id' => get_current_user_id() ] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	if ( function_exists( 'dtb_admin_audit_write' ) ) {
		dtb_admin_audit_write( 'repair', $repair_id, 'repair.ready_to_ship', [
			'tracking_number' => $tracking,
			'user_id'         => get_current_user_id(),
		] );
	}

	return _dtb_repair_action_refresh( $repair_id );
}

// ── Close ─────────────────────────────────────────────────────────────────────

function dtb_repair_admin_close_handler( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$repair_id = (int) $request->get_param( 'id' );
	$guard     = _dtb_repair_action_guard( $repair_id );
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	$current = (string) get_post_meta( $repair_id, '_repair_status', true );
	$allowed = function_exists( 'dtb_get_allowed_transitions' )
		? ( dtb_get_allowed_transitions()[ $current ] ?? [] )
		: [];

	if ( ! in_array( 'closed', $allowed, true ) && 'closed' !== $current ) {
		return new WP_Error(
			'invalid_transition',
			__( 'This repair cannot be closed from its current status.', 'drywall-toolbox' ),
			[ 'status' => 409 ]
		);
	}

	if ( 'closed' === $current ) {
		// Already closed — idempotent success.
		return _dtb_repair_action_refresh( $repair_id );
	}

	$body = $request->get_json_params() ?: [];
	$note = sanitize_textarea_field( $body['note'] ?? '' );

	if ( function_exists( 'dtb_transition_repair_status' ) ) {
		$result = dtb_transition_repair_status( $repair_id, 'closed', [
			'note'     => $note,
			'actor_id' => get_current_user_id(),
		] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	if ( function_exists( 'dtb_admin_audit_write' ) ) {
		dtb_admin_audit_write( 'repair', $repair_id, 'repair.closed', [
			'note'    => $note,
			'user_id' => get_current_user_id(),
		] );
	}

	return _dtb_repair_action_refresh( $repair_id );
}
