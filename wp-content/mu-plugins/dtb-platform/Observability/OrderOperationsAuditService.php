<?php
defined( 'ABSPATH' ) || exit;

/**
 * DTB Order Operations — AJAX Handlers
 *
 * Thin, security-hardened AJAX endpoint layer for the Order Operations dashboard.
 * Each handler:
 *   1. Verifies nonce (check_ajax_referer)
 *   2. Verifies capability
 *   3. Sanitizes all inputs
 *   4. Delegates to read-models or actions
 *   5. Returns wp_send_json_success / wp_send_json_error
 *
 * AJAX actions:
 *   dtb_ops_order_overview       — Overview tab KPIs
 *   dtb_ops_product_orders       — Product orders list
 *   dtb_ops_repair_orders        — Repair orders list
 *   dtb_ops_order_timeline       — Order event timeline drawer
 *   dtb_ops_repair_timeline      — Repair event timeline drawer
 *   dtb_ops_order_action         — Single product-order operator action
 *   dtb_ops_repair_action        — Single repair-order operator action
 *   dtb_ops_bulk_order_action    — Bulk product-order action
 *   dtb_ops_bulk_repair_action   — Bulk repair-order action
 *   dtb_ops_local_queue          — Local queue tab
 *   dtb_ops_queue_action         — Local queue job action
 *   dtb_ops_oo_audit_log         — Audit log tab
 *   dtb_ops_oo_settings_save     — Settings tab save
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

// Guard: only register AJAX handlers in admin context.
if ( ! is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
	return;
}

// =============================================================================
// SECTION 1 — OVERVIEW KPIs
// =============================================================================

add_action( 'wp_ajax_dtb_ops_order_overview', 'dtb_oo_ajax_order_overview' );

/**
 * AJAX: Return overview KPIs for the Order Operations dashboard.
 */
function dtb_oo_ajax_order_overview(): void {
	check_ajax_referer( DTB_OO_NONCE_ACTION, 'nonce' );

	if ( ! dtb_oo_can_view() ) {
		wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
	}

	$kpis = dtb_oo_get_overview_kpis();
	wp_send_json_success( [ 'kpis' => $kpis ] );
}

// =============================================================================
// SECTION 2 — PRODUCT ORDERS
// =============================================================================

add_action( 'wp_ajax_dtb_ops_product_orders', 'dtb_oo_ajax_product_orders' );

/**
 * AJAX: Return a paged, filtered product-order list.
 */
function dtb_oo_ajax_product_orders(): void {
	check_ajax_referer( DTB_OO_NONCE_ACTION, 'nonce' );

	if ( ! dtb_oo_can_view() ) {
		wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked above via check_ajax_referer
	$args = [
		'woo_status'          => sanitize_key( wp_unslash( $_POST['woo_status'] ?? '' ) ),
		'fulfillment_substate'=> sanitize_key( wp_unslash( $_POST['fulfillment_substate'] ?? '' ) ),
		'tracking_state'      => sanitize_key( wp_unslash( $_POST['tracking_state'] ?? '' ) ),
		'stale'               => sanitize_key( wp_unslash( $_POST['stale'] ?? '' ) ),
		'date_from'           => sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) ),
		'date_to'             => sanitize_text_field( wp_unslash( $_POST['date_to'] ?? '' ) ),
		'customer'            => sanitize_text_field( wp_unslash( $_POST['customer'] ?? '' ) ),
		'email'               => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
		'order_id'            => absint( $_POST['order_id'] ?? 0 ),
		'paged'               => max( 1, (int) ( $_POST['paged'] ?? 1 ) ),
		'per_page'            => max( 10, min( 100, (int) ( $_POST['per_page'] ?? 25 ) ) ),
	];
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	// Remove empty values.
	$args = array_filter( $args, static fn( $v ) => $v !== '' && $v !== 0 );

	$result = dtb_oo_get_product_orders( $args );
	wp_send_json_success( $result );
}

// =============================================================================
// SECTION 3 — REPAIR ORDERS
// =============================================================================

add_action( 'wp_ajax_dtb_ops_repair_orders', 'dtb_oo_ajax_repair_orders' );

/**
 * AJAX: Return a paged, filtered repair-order list.
 */
function dtb_oo_ajax_repair_orders(): void {
	check_ajax_referer( DTB_OO_NONCE_ACTION, 'nonce' );

	if ( ! dtb_oo_can_view() ) {
		wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing
	$args = [
		'repair_status'    => sanitize_key( wp_unslash( $_POST['repair_status'] ?? '' ) ),
		'brand'            => sanitize_text_field( wp_unslash( $_POST['brand'] ?? '' ) ),
		'service_tier'     => sanitize_key( wp_unslash( $_POST['service_tier'] ?? '' ) ),
		'assigned_tech_id' => absint( $_POST['assigned_tech_id'] ?? 0 ),
		'sla_state'        => sanitize_key( wp_unslash( $_POST['sla_state'] ?? '' ) ),
		'date_from'        => sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) ),
		'date_to'          => sanitize_text_field( wp_unslash( $_POST['date_to'] ?? '' ) ),
		'customer'         => sanitize_text_field( wp_unslash( $_POST['customer'] ?? '' ) ),
		'email'            => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
		'repair_id'        => absint( $_POST['repair_id'] ?? 0 ),
		'model'            => sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) ),
		'serial'           => sanitize_text_field( wp_unslash( $_POST['serial'] ?? '' ) ),
		'paged'            => max( 1, (int) ( $_POST['paged'] ?? 1 ) ),
		'per_page'         => max( 10, min( 100, (int) ( $_POST['per_page'] ?? 25 ) ) ),
	];
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	$args = array_filter( $args, static fn( $v ) => $v !== '' && $v !== 0 );

	$result = dtb_oo_get_repair_orders( $args );
	wp_send_json_success( $result );
}

// =============================================================================
// SECTION 4 — TIMELINE DRAWERS
// =============================================================================

add_action( 'wp_ajax_dtb_ops_order_timeline', 'dtb_oo_ajax_order_timeline' );

/**
 * AJAX: Return the full operator event timeline for a product order.
 */
function dtb_oo_ajax_order_timeline(): void {
	check_ajax_referer( DTB_OO_NONCE_ACTION, 'nonce' );

	if ( ! dtb_oo_can_view() ) {
		wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$order_id = absint( $_POST['order_id'] ?? 0 );
	if ( $order_id <= 0 ) {
		wp_send_json_error( [ 'message' => 'Invalid order ID.' ], 400 );
	}

	if ( function_exists( 'wc_get_order' ) && ! wc_get_order( $order_id ) ) {
		wp_send_json_error( [ 'message' => "Order #{$order_id} not found." ], 404 );
	}

	$timeline = dtb_oo_get_order_timeline( $order_id );
	wp_send_json_success( [
		'order_id' => $order_id,
		'timeline' => $timeline,
	] );
}

add_action( 'wp_ajax_dtb_ops_repair_timeline', 'dtb_oo_ajax_repair_timeline' );

/**
 * AJAX: Return the full operator event timeline for a repair order.
 */
function dtb_oo_ajax_repair_timeline(): void {
	check_ajax_referer( DTB_OO_NONCE_ACTION, 'nonce' );

	if ( ! dtb_oo_can_view() ) {
		wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$repair_id = absint( $_POST['repair_id'] ?? 0 );
	if ( $repair_id <= 0 ) {
		wp_send_json_error( [ 'message' => 'Invalid repair ID.' ], 400 );
	}

	$post = get_post( $repair_id );
	if ( ! $post || 'dtb_repair_request' !== $post->post_type ) {
		wp_send_json_error( [ 'message' => "Repair #{$repair_id} not found." ], 404 );
	}

	$timeline = dtb_oo_get_repair_timeline( $repair_id );
	wp_send_json_success( [
		'repair_id' => $repair_id,
		'timeline'  => $timeline,
	] );
}

// =============================================================================
// SECTION 5 — SINGLE ROW ACTIONS
// =============================================================================

add_action( 'wp_ajax_dtb_ops_order_action', 'dtb_oo_ajax_order_action' );

/**
 * AJAX: Execute a single product-order operator action.
 */
function dtb_oo_ajax_order_action(): void {
	check_ajax_referer( DTB_OO_NONCE_ACTION, 'nonce' );

	if ( ! dtb_oo_can_mutate_orders() ) {
		wp_send_json_error( [ 'message' => 'Insufficient permissions to mutate orders.' ], 403 );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing
	$order_id = absint( $_POST['entity_id'] ?? 0 );
	$action   = sanitize_key( wp_unslash( $_POST['action_type'] ?? '' ) );
	$params   = [
		'note'     => sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ),
		'tech_id'  => absint( $_POST['tech_id'] ?? 0 ),
	];
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	if ( $order_id <= 0 || '' === $action ) {
		wp_send_json_error( [ 'message' => 'Missing required parameters.' ], 400 );
	}

	$result = dtb_oo_exec_product_order_action( $order_id, $action, $params );

	if ( $result['success'] ) {
		// Refresh the row projection.
		$row = function_exists( 'dtb_oo_product_order_row_projection' )
			? dtb_oo_product_order_row_projection( $order_id )
			: null;
		$result['row'] = $row;
		wp_send_json_success( $result );
	} else {
		wp_send_json_error( $result, 422 );
	}
}

add_action( 'wp_ajax_dtb_ops_repair_action', 'dtb_oo_ajax_repair_action' );

/**
 * AJAX: Execute a single repair-order operator action.
 */
function dtb_oo_ajax_repair_action(): void {
	check_ajax_referer( DTB_OO_NONCE_ACTION, 'nonce' );

	if ( ! dtb_oo_can_mutate_repairs() ) {
		wp_send_json_error( [ 'message' => 'Insufficient permissions to mutate repairs.' ], 403 );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing
	$repair_id = absint( $_POST['entity_id'] ?? 0 );
	$action    = sanitize_key( wp_unslash( $_POST['action_type'] ?? '' ) );
	$params    = [
		'note'      => sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ),
		'tech_id'   => absint( $_POST['tech_id'] ?? 0 ),
		'to_status' => sanitize_key( wp_unslash( $_POST['to_status'] ?? '' ) ),
	];
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	if ( $repair_id <= 0 || '' === $action ) {
		wp_send_json_error( [ 'message' => 'Missing required parameters.' ], 400 );
	}

	$result = dtb_oo_exec_repair_order_action( $repair_id, $action, $params );

	if ( $result['success'] ) {
		$row = function_exists( 'dtb_oo_repair_order_row_projection' )
			? dtb_oo_repair_order_row_projection( $repair_id )
			: null;
		$result['row'] = $row;
		wp_send_json_success( $result );
	} else {
		wp_send_json_error( $result, 422 );
	}
}

// =============================================================================
// SECTION 6 — BULK ACTIONS
// =============================================================================

add_action( 'wp_ajax_dtb_ops_bulk_order_action', 'dtb_oo_ajax_bulk_order_action' );

/**
 * AJAX: Execute a bulk product-order action.
 */
function dtb_oo_ajax_bulk_order_action(): void {
	check_ajax_referer( DTB_OO_NONCE_ACTION, 'nonce' );

	if ( ! dtb_oo_can_mutate_orders() ) {
		wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing
	$action     = sanitize_key( wp_unslash( $_POST['action_type'] ?? '' ) );
	$raw_ids    = wp_unslash( $_POST['entity_ids'] ?? [] );
	$order_ids  = is_array( $raw_ids ) ? array_filter( array_map( 'absint', $raw_ids ) ) : [];
	$params     = [
		'note'      => sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ),
		'to_status' => sanitize_key( wp_unslash( $_POST['to_status'] ?? '' ) ),
	];
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	if ( '' === $action || empty( $order_ids ) ) {
		wp_send_json_error( [ 'message' => 'Missing required parameters.' ], 400 );
	}

	$result = dtb_oo_exec_bulk_product_action( $action, $order_ids, $params );

	if ( $result['success'] ) {
		wp_send_json_success( $result );
	} else {
		wp_send_json_error( $result, 422 );
	}
}

add_action( 'wp_ajax_dtb_ops_bulk_repair_action', 'dtb_oo_ajax_bulk_repair_action' );

/**
 * AJAX: Execute a bulk repair-order action.
 */
function dtb_oo_ajax_bulk_repair_action(): void {
	check_ajax_referer( DTB_OO_NONCE_ACTION, 'nonce' );

	if ( ! dtb_oo_can_mutate_repairs() ) {
		wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing
	$action     = sanitize_key( wp_unslash( $_POST['action_type'] ?? '' ) );
	$raw_ids    = wp_unslash( $_POST['entity_ids'] ?? [] );
	$repair_ids = is_array( $raw_ids ) ? array_filter( array_map( 'absint', $raw_ids ) ) : [];
	$params     = [
		'note'      => sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ),
		'tech_id'   => absint( $_POST['tech_id'] ?? 0 ),
		'to_status' => sanitize_key( wp_unslash( $_POST['to_status'] ?? '' ) ),
	];
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	if ( '' === $action || empty( $repair_ids ) ) {
		wp_send_json_error( [ 'message' => 'Missing required parameters.' ], 400 );
	}

	$result = dtb_oo_exec_bulk_repair_action( $action, $repair_ids, $params );

	if ( $result['success'] ) {
		wp_send_json_success( $result );
	} else {
		wp_send_json_error( $result, 422 );
	}
}

// =============================================================================
// SECTION 7 — LOCAL QUEUE
// =============================================================================

add_action( 'wp_ajax_dtb_ops_local_queue', 'dtb_oo_ajax_local_queue' );

/**
 * AJAX: Return the local job queue.
 */
function dtb_oo_ajax_local_queue(): void {
	check_ajax_referer( DTB_OO_NONCE_ACTION, 'nonce' );

	if ( ! dtb_oo_can_view() ) {
		wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing
	$args = [
		'status'   => sanitize_key( wp_unslash( $_POST['status'] ?? '' ) ),
		'paged'    => max( 1, (int) ( $_POST['paged'] ?? 1 ) ),
		'per_page' => max( 10, min( 100, (int) ( $_POST['per_page'] ?? 25 ) ) ),
	];
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	$result = dtb_oo_get_local_queue( $args );
	wp_send_json_success( $result );
}

add_action( 'wp_ajax_dtb_ops_queue_action', 'dtb_oo_ajax_queue_action' );

/**
 * AJAX: Execute an action on a local queue job.
 */
function dtb_oo_ajax_queue_action(): void {
	check_ajax_referer( DTB_OO_NONCE_ACTION, 'nonce' );

	if ( ! dtb_oo_can_mutate_orders() && ! dtb_oo_can_mutate_repairs() ) {
		wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing
	$job_id = absint( $_POST['job_id'] ?? 0 );
	$action = sanitize_key( wp_unslash( $_POST['action_type'] ?? '' ) );
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	if ( $job_id <= 0 || '' === $action ) {
		wp_send_json_error( [ 'message' => 'Missing required parameters.' ], 400 );
	}

	$result = dtb_oo_exec_queue_action( $action, $job_id );

	if ( $result['success'] ) {
		wp_send_json_success( $result );
	} else {
		wp_send_json_error( $result, 422 );
	}
}

// =============================================================================
// SECTION 8 — AUDIT LOG
// =============================================================================

add_action( 'wp_ajax_dtb_ops_oo_audit_log', 'dtb_oo_ajax_audit_log' );

/**
 * AJAX: Return the aggregated audit log.
 */
function dtb_oo_ajax_audit_log(): void {
	check_ajax_referer( DTB_OO_NONCE_ACTION, 'nonce' );

	// Audit log requires elevated capability.
	if ( ! current_user_can( 'manage_options' ) && ! dtb_oo_can_view() ) {
		wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing
	$args = [
		'entity_type' => sanitize_key( wp_unslash( $_POST['entity_type'] ?? '' ) ),
		'entity_id'   => absint( $_POST['entity_id'] ?? 0 ),
		'date_from'   => sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) ),
		'date_to'     => sanitize_text_field( wp_unslash( $_POST['date_to'] ?? '' ) ),
		'paged'       => max( 1, (int) ( $_POST['paged'] ?? 1 ) ),
		'per_page'    => max( 10, min( 100, (int) ( $_POST['per_page'] ?? 25 ) ) ),
	];
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	$result = dtb_oo_get_combined_audit_log( $args );
	wp_send_json_success( $result );
}

// =============================================================================
// SECTION 9 — SETTINGS SAVE
// =============================================================================

add_action( 'wp_ajax_dtb_ops_oo_settings_save', 'dtb_oo_ajax_settings_save' );

/**
 * AJAX: Save dashboard settings.
 */
function dtb_oo_ajax_settings_save(): void {
	check_ajax_referer( DTB_OO_NONCE_ACTION, 'nonce' );

	if ( ! dtb_oo_can_manage_settings() ) {
		wp_send_json_error( [ 'message' => 'Only administrators can change dashboard settings.' ], 403 );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing
	$raw = [
		'poll_interval'        => $_POST['poll_interval'] ?? null,
		'sla_warning_hours'    => $_POST['sla_warning_hours'] ?? null,
		'sla_breach_hours'     => $_POST['sla_breach_hours'] ?? null,
		'page_size'            => $_POST['page_size'] ?? null,
		'audit_retention_days' => $_POST['audit_retention_days'] ?? null,
		'display_timezone'     => wp_unslash( $_POST['display_timezone'] ?? '' ),
	];
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	$result = dtb_oo_save_settings( $raw );

	if ( $result['success'] ) {
		wp_send_json_success( $result );
	} else {
		wp_send_json_error( $result, 422 );
	}
}
