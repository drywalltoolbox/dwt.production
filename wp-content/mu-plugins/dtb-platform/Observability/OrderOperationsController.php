<?php
defined( 'ABSPATH' ) || exit;

/**
 * DTB Order Operations — Actions
 *
 * Operator mutation handlers for the Order Operations dashboard.
 * Every mutation validates nonce + capability, performs the operation, writes
 * an audit event, and returns a result array.
 *
 * All functions in this file are called from dtb-order-operations-ajax.php.
 * They must never be called without prior nonce/capability verification.
 *
 * Provides:
 *   dtb_oo_exec_product_order_action()    — Execute a product-order row action
 *   dtb_oo_exec_repair_order_action()     — Execute a repair-order row action
 *   dtb_oo_exec_bulk_product_action()     — Execute a bulk product-order action
 *   dtb_oo_exec_bulk_repair_action()      — Execute a bulk repair-order action
 *   dtb_oo_exec_queue_action()            — Retry/cancel a local queue job
 *   dtb_oo_save_settings()               — Save dashboard settings
 *
 * @package drywall-toolbox
 */


// =============================================================================
// SECTION 1 — ALLOWED ACTION ALLOWLISTS
// =============================================================================

/**
 * Allowed single-row actions for product orders.
 *
 * @return string[]
 */
function dtb_oo_allowed_product_order_actions(): array {
	return [
		'refresh_order_projection',
		'refresh_tracking_projection',
		'mark_reviewed',
		'add_internal_note',
	];
}

/**
 * Allowed single-row actions for repair orders.
 *
 * @return string[]
 */
function dtb_oo_allowed_repair_order_actions(): array {
	return [
		'assign_technician',
		'request_customer_info',
		'transition_status',
		'add_internal_note',
		'refresh_repair_projection',
		'close_repair',
		'cancel_repair',
	];
}

/**
 * Allowed bulk actions for product orders.
 *
 * @return string[]
 */
function dtb_oo_allowed_bulk_product_actions(): array {
	return [
		'refresh_tracking_projections',
		'refresh_order_projections',
		'mark_reviewed',
		'add_bulk_internal_note',
		'export_selected',
	];
}

/**
 * Allowed bulk actions for repair orders.
 *
 * @return string[]
 */
function dtb_oo_allowed_bulk_repair_actions(): array {
	return [
		'assign_technician',
		'request_customer_info',
		'transition_status',
		'close_repairs',
		'refresh_repair_projections',
		'add_bulk_internal_note',
		'export_selected',
	];
}

/**
 * Allowed queue actions.
 *
 * @return string[]
 */
function dtb_oo_allowed_queue_actions(): array {
	return [ 'retry_local_job', 'cancel_local_job', 'mark_resolved' ];
}

// =============================================================================
// SECTION 2 — PRODUCT ORDER ACTIONS
// =============================================================================

/**
 * Execute a single product-order operator action.
 *
 * Callers MUST verify nonce and capability before calling this function.
 *
 * @param int    $order_id  WooCommerce order ID.
 * @param string $action    One of dtb_oo_allowed_product_order_actions().
 * @param array  $params    Action-specific parameters.
 * @return array{success:bool, message:string, data:array}
 */
function dtb_oo_exec_product_order_action( int $order_id, string $action, array $params = [] ): array {
	// Validate allowlist.
	if ( ! in_array( $action, dtb_oo_allowed_product_order_actions(), true ) ) {
		return dtb_oo_action_error( 'Action not permitted.' );
	}

	// Validate entity exists.
	if ( ! function_exists( 'wc_get_order' ) ) {
		return dtb_oo_action_error( 'WooCommerce is unavailable.' );
	}
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return dtb_oo_action_error( "Order #{$order_id} not found." );
	}

	$actor_id = get_current_user_id();

	switch ( $action ) {

		case 'refresh_order_projection':
			// Enqueue or run projection refresh.
			if ( function_exists( 'dtb_order_enqueue_job' ) ) {
				dtb_order_enqueue_job( 'dtb_order_refresh_tracking_projection', $order_id );
			}
			dtb_oo_audit( 'order.projection_refreshed', [
				'order_id' => $order_id,
				'actor_id' => $actor_id,
				'source'   => 'wp_admin',
			] );
			return dtb_oo_action_ok( "Order #{$order_id} projection refresh queued." );

		case 'refresh_tracking_projection':
			if ( function_exists( 'dtb_order_enqueue_job' ) ) {
				dtb_order_enqueue_job( 'dtb_order_refresh_tracking_projection', $order_id );
			}
			dtb_oo_audit( 'order.tracking_projection_refreshed', [
				'order_id' => $order_id,
				'actor_id' => $actor_id,
				'source'   => 'wp_admin',
			] );
			return dtb_oo_action_ok( "Order #{$order_id} tracking projection refresh queued." );

		case 'mark_reviewed':
			update_post_meta( $order_id, '_dtb_reviewed', '1' );
			update_post_meta( $order_id, '_dtb_reviewed_at', current_time( 'mysql', true ) );
			update_post_meta( $order_id, '_dtb_reviewed_by', $actor_id );

			if ( function_exists( 'dtb_order_append_event' ) ) {
				dtb_order_append_event( $order_id, 'order.reviewed', [
					'actor_type' => 'admin',
					'actor_id'   => $actor_id,
					'source'     => 'wp_admin',
					'visibility' => 'operator',
				] );
			}
			dtb_oo_audit( 'order.marked_reviewed', [
				'order_id' => $order_id,
				'actor_id' => $actor_id,
			] );
			return dtb_oo_action_ok( "Order #{$order_id} marked as reviewed." );

		case 'add_internal_note':
			$note = sanitize_textarea_field( (string) ( $params['note'] ?? '' ) );
			if ( '' === $note ) {
				return dtb_oo_action_error( 'Note text is required.' );
			}
			// Append as WC order note (admin-only).
			if ( method_exists( $order, 'add_order_note' ) ) {
				$order->add_order_note( $note, false, false );
			}
			if ( function_exists( 'dtb_order_append_event' ) ) {
				dtb_order_append_event( $order_id, 'order.internal_note_added', [
					'actor_type' => 'admin',
					'actor_id'   => $actor_id,
					'source'     => 'wp_admin',
					'visibility' => 'operator',
					'payload'    => [ 'note_preview' => substr( $note, 0, 100 ) ],
				] );
			}
			dtb_oo_audit( 'order.note_added', [
				'order_id'     => $order_id,
				'actor_id'     => $actor_id,
				'note_preview' => substr( $note, 0, 100 ),
			] );
			return dtb_oo_action_ok( "Internal note added to order #{$order_id}." );
	}

	return dtb_oo_action_error( 'Unknown action.' );
}

// =============================================================================
// SECTION 3 — REPAIR ORDER ACTIONS
// =============================================================================

/**
 * Execute a single repair-order operator action.
 *
 * Callers MUST verify nonce and capability before calling this function.
 *
 * @param int    $repair_id
 * @param string $action    One of dtb_oo_allowed_repair_order_actions().
 * @param array  $params    Action-specific parameters.
 * @return array{success:bool, message:string, data:array}
 */
function dtb_oo_exec_repair_order_action( int $repair_id, string $action, array $params = [] ): array {
	// Validate allowlist.
	if ( ! in_array( $action, dtb_oo_allowed_repair_order_actions(), true ) ) {
		return dtb_oo_action_error( 'Action not permitted.' );
	}

	// Validate entity exists.
	$post = get_post( $repair_id );
	if ( ! $post || 'dtb_repair_request' !== $post->post_type ) {
		return dtb_oo_action_error( "Repair #{$repair_id} not found." );
	}

	$actor_id = get_current_user_id();

	switch ( $action ) {

		case 'assign_technician':
			$tech_id = (int) ( $params['tech_id'] ?? 0 );
			if ( $tech_id <= 0 ) {
				return dtb_oo_action_error( 'A valid technician user ID is required.' );
			}
			$tech_user = get_userdata( $tech_id );
			if ( ! $tech_user instanceof WP_User ) {
				return dtb_oo_action_error( 'Technician user not found.' );
			}
			update_post_meta( $repair_id, '_repair_assigned_tech_id', $tech_id );

			if ( function_exists( 'dtb_repair_append_event' ) ) {
				dtb_repair_append_event( $repair_id, 'repair.technician_assigned', [
					'actor_type' => 'admin',
					'actor_id'   => $actor_id,
					'source'     => 'wp_admin',
					'visibility' => 'operator',
					'payload'    => [ 'tech_id' => $tech_id, 'tech_login' => $tech_user->user_login ],
				] );
			}
			dtb_oo_audit( 'repair.assigned_technician', [
				'repair_id' => $repair_id,
				'tech_id'   => $tech_id,
				'actor_id'  => $actor_id,
			] );
			return dtb_oo_action_ok( "Technician assigned to repair #{$repair_id}." );

		case 'request_customer_info':
			// Transition to awaiting_customer if currently in a valid state.
			if ( function_exists( 'dtb_transition_repair_status' ) ) {
				$current = (string) get_post_meta( $repair_id, '_repair_status', true );
				$note    = sanitize_textarea_field( (string) ( $params['note'] ?? 'Additional information requested from customer.' ) );
				$result  = dtb_transition_repair_status( $repair_id, 'awaiting_customer', [
					'actor_type' => 'admin',
					'actor_id'   => $actor_id,
					'source'     => 'wp_admin',
					'note'       => $note,
				] );
				if ( is_wp_error( $result ) ) {
					return dtb_oo_action_error( $result->get_error_message() );
				}
			}
			dtb_oo_audit( 'repair.customer_info_requested', [
				'repair_id' => $repair_id,
				'actor_id'  => $actor_id,
			] );
			return dtb_oo_action_ok( "Customer info requested for repair #{$repair_id}." );

		case 'transition_status':
			$to_status = sanitize_key( (string) ( $params['to_status'] ?? '' ) );
			$note      = sanitize_textarea_field( (string) ( $params['note'] ?? '' ) );
			if ( '' === $to_status ) {
				return dtb_oo_action_error( 'Target status is required.' );
			}
			if ( ! function_exists( 'dtb_transition_repair_status' ) ) {
				return dtb_oo_action_error( 'Repair workflow system unavailable.' );
			}
			$result = dtb_transition_repair_status( $repair_id, $to_status, [
				'actor_type' => 'admin',
				'actor_id'   => $actor_id,
				'source'     => 'wp_admin',
				'note'       => $note,
			] );
			if ( is_wp_error( $result ) ) {
				return dtb_oo_action_error( $result->get_error_message() );
			}
			dtb_oo_audit( 'repair.status_transitioned', [
				'repair_id' => $repair_id,
				'to_status' => $to_status,
				'actor_id'  => $actor_id,
			] );
			return dtb_oo_action_ok( "Repair #{$repair_id} transitioned to '{$to_status}'." );

		case 'add_internal_note':
			$note = sanitize_textarea_field( (string) ( $params['note'] ?? '' ) );
			if ( '' === $note ) {
				return dtb_oo_action_error( 'Note text is required.' );
			}
			if ( function_exists( 'dtb_repair_append_event' ) ) {
				dtb_repair_append_event( $repair_id, 'repair.note_added', [
					'actor_type' => 'admin',
					'actor_id'   => $actor_id,
					'source'     => 'wp_admin',
					'visibility' => 'operator',
					'payload'    => [ 'note' => $note ],
				] );
			}
			dtb_oo_audit( 'repair.note_added', [
				'repair_id'    => $repair_id,
				'actor_id'     => $actor_id,
				'note_preview' => substr( $note, 0, 100 ),
			] );
			return dtb_oo_action_ok( "Internal note added to repair #{$repair_id}." );

		case 'refresh_repair_projection':
			if ( function_exists( 'dtb_repair_enqueue_job' ) ) {
				dtb_repair_enqueue_job( 'dtb_repair_refresh_projection', $repair_id );
			}
			dtb_oo_audit( 'repair.projection_refreshed', [
				'repair_id' => $repair_id,
				'actor_id'  => $actor_id,
			] );
			return dtb_oo_action_ok( "Repair #{$repair_id} projection refresh queued." );

		case 'close_repair':
			if ( ! function_exists( 'dtb_transition_repair_status' ) ) {
				return dtb_oo_action_error( 'Repair workflow system unavailable.' );
			}
			$result = dtb_transition_repair_status( $repair_id, 'closed', [
				'actor_type' => 'admin',
				'actor_id'   => $actor_id,
				'source'     => 'wp_admin',
				'note'       => sanitize_textarea_field( (string) ( $params['note'] ?? '' ) ),
			] );
			if ( is_wp_error( $result ) ) {
				return dtb_oo_action_error( $result->get_error_message() );
			}
			dtb_oo_audit( 'repair.closed', [
				'repair_id' => $repair_id,
				'actor_id'  => $actor_id,
			] );
			return dtb_oo_action_ok( "Repair #{$repair_id} closed." );

		case 'cancel_repair':
			if ( ! function_exists( 'dtb_transition_repair_status' ) ) {
				return dtb_oo_action_error( 'Repair workflow system unavailable.' );
			}
			$result = dtb_transition_repair_status( $repair_id, 'cancelled', [
				'actor_type' => 'admin',
				'actor_id'   => $actor_id,
				'source'     => 'wp_admin',
				'note'       => sanitize_textarea_field( (string) ( $params['note'] ?? '' ) ),
			] );
			if ( is_wp_error( $result ) ) {
				return dtb_oo_action_error( $result->get_error_message() );
			}
			dtb_oo_audit( 'repair.cancelled', [
				'repair_id' => $repair_id,
				'actor_id'  => $actor_id,
			] );
			return dtb_oo_action_ok( "Repair #{$repair_id} cancelled." );
	}

	return dtb_oo_action_error( 'Unknown action.' );
}

// =============================================================================
// SECTION 4 — BULK ACTIONS
// =============================================================================

/**
 * Execute a bulk product-order action.
 *
 * @param string $action
 * @param int[]  $order_ids
 * @param array  $params
 * @return array{success:bool, message:string, results:array, failed:int, succeeded:int}
 */
function dtb_oo_exec_bulk_product_action( string $action, array $order_ids, array $params = [] ): array {
	if ( ! in_array( $action, dtb_oo_allowed_bulk_product_actions(), true ) ) {
		return dtb_oo_bulk_error( 'Bulk action not permitted.' );
	}

	$order_ids = array_filter( array_map( 'intval', $order_ids ) );
	if ( empty( $order_ids ) ) {
		return dtb_oo_bulk_error( 'No orders selected.' );
	}

	$succeeded = 0;
	$failed    = 0;
	$results   = [];

	foreach ( $order_ids as $order_id ) {
		switch ( $action ) {
			case 'refresh_tracking_projections':
				$r = dtb_oo_exec_product_order_action( $order_id, 'refresh_tracking_projection' );
				break;
			case 'refresh_order_projections':
				$r = dtb_oo_exec_product_order_action( $order_id, 'refresh_order_projection' );
				break;
			case 'mark_reviewed':
				$r = dtb_oo_exec_product_order_action( $order_id, 'mark_reviewed' );
				break;
			case 'add_bulk_internal_note':
				$r = dtb_oo_exec_product_order_action( $order_id, 'add_internal_note', $params );
				break;
			case 'export_selected':
				$r = dtb_oo_action_ok( "Order #{$order_id} queued for export." );
				break;
			default:
				$r = dtb_oo_action_error( 'Unknown bulk action.' );
		}

		$results[ $order_id ] = $r;
		if ( $r['success'] ) {
			$succeeded++;
		} else {
			$failed++;
		}
	}

	dtb_oo_audit( 'dashboard.bulk_action_run', [
		'action'    => $action,
		'entity_type'=> 'product_order',
		'count'     => count( $order_ids ),
		'succeeded' => $succeeded,
		'failed'    => $failed,
		'actor_id'  => get_current_user_id(),
	] );

	return [
		'success'   => true,
		'message'   => "Bulk action '{$action}' completed: {$succeeded} succeeded, {$failed} failed.",
		'results'   => $results,
		'succeeded' => $succeeded,
		'failed'    => $failed,
	];
}

/**
 * Execute a bulk repair-order action.
 *
 * @param string $action
 * @param int[]  $repair_ids
 * @param array  $params
 * @return array{success:bool, message:string, results:array, failed:int, succeeded:int}
 */
function dtb_oo_exec_bulk_repair_action( string $action, array $repair_ids, array $params = [] ): array {
	if ( ! in_array( $action, dtb_oo_allowed_bulk_repair_actions(), true ) ) {
		return dtb_oo_bulk_error( 'Bulk action not permitted.' );
	}

	$repair_ids = array_filter( array_map( 'intval', $repair_ids ) );
	if ( empty( $repair_ids ) ) {
		return dtb_oo_bulk_error( 'No repairs selected.' );
	}

	$succeeded = 0;
	$failed    = 0;
	$results   = [];

	foreach ( $repair_ids as $repair_id ) {
		switch ( $action ) {
			case 'assign_technician':
				$r = dtb_oo_exec_repair_order_action( $repair_id, 'assign_technician', $params );
				break;
			case 'request_customer_info':
				$r = dtb_oo_exec_repair_order_action( $repair_id, 'request_customer_info', $params );
				break;
			case 'transition_status':
				$r = dtb_oo_exec_repair_order_action( $repair_id, 'transition_status', $params );
				break;
			case 'close_repairs':
				$r = dtb_oo_exec_repair_order_action( $repair_id, 'close_repair', $params );
				break;
			case 'refresh_repair_projections':
				$r = dtb_oo_exec_repair_order_action( $repair_id, 'refresh_repair_projection' );
				break;
			case 'add_bulk_internal_note':
				$r = dtb_oo_exec_repair_order_action( $repair_id, 'add_internal_note', $params );
				break;
			case 'export_selected':
				$r = dtb_oo_action_ok( "Repair #{$repair_id} queued for export." );
				break;
			default:
				$r = dtb_oo_action_error( 'Unknown bulk action.' );
		}

		$results[ $repair_id ] = $r;
		if ( $r['success'] ) {
			$succeeded++;
		} else {
			$failed++;
		}
	}

	dtb_oo_audit( 'dashboard.bulk_action_run', [
		'action'     => $action,
		'entity_type'=> 'repair_order',
		'count'      => count( $repair_ids ),
		'succeeded'  => $succeeded,
		'failed'     => $failed,
		'actor_id'   => get_current_user_id(),
	] );

	return [
		'success'   => true,
		'message'   => "Bulk action '{$action}' completed: {$succeeded} succeeded, {$failed} failed.",
		'results'   => $results,
		'succeeded' => $succeeded,
		'failed'    => $failed,
	];
}

// =============================================================================
// SECTION 5 — QUEUE ACTIONS
// =============================================================================

/**
 * Execute an action on a local queue job.
 *
 * @param string $action  'retry_local_job' | 'cancel_local_job' | 'mark_resolved'
 * @param int    $job_id  Action Scheduler action ID.
 * @return array{success:bool, message:string, data:array}
 */
function dtb_oo_exec_queue_action( string $action, int $job_id ): array {
	if ( ! in_array( $action, dtb_oo_allowed_queue_actions(), true ) ) {
		return dtb_oo_action_error( 'Queue action not permitted.' );
	}

	if ( $job_id <= 0 ) {
		return dtb_oo_action_error( 'Invalid job ID.' );
	}

	$actor_id = get_current_user_id();

	switch ( $action ) {

		case 'retry_local_job':
			if ( function_exists( 'as_get_scheduled_actions' ) ) {
				// Reschedule the failed action via Action Scheduler.
				global $wpdb;
				$table = $wpdb->prefix . 'actionscheduler_actions';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT hook, args, `group` FROM {$table} WHERE action_id = %d AND status IN ('failed','pending')",
						$job_id
					)
				);

				if ( ! $row ) {
					return dtb_oo_action_error( "Job #{$job_id} not found or not in a retryable state." );
				}

				// Only allow retrying our local jobs.
				$allowed_hooks = dtb_oo_local_queue_job_types();
				if ( ! in_array( $row->hook, $allowed_hooks, true ) ) {
					return dtb_oo_action_error( 'This job type cannot be retried from the Order Operations dashboard.' );
				}

				$args = json_decode( (string) $row->args, true );
				if ( ! is_array( $args ) ) {
					$args = [];
				}

				as_schedule_single_action( time() + 5, $row->hook, $args, (string) $row->group );

				dtb_oo_audit( 'queue.local_job_retried', [
					'job_id'   => $job_id,
					'hook'     => $row->hook,
					'actor_id' => $actor_id,
				] );
				return dtb_oo_action_ok( "Job #{$job_id} scheduled for retry." );
			}
			return dtb_oo_action_error( 'Action Scheduler is not available; cannot retry job.' );

		case 'cancel_local_job':
			if ( function_exists( 'as_get_scheduled_actions' ) ) {
				global $wpdb;
				$table = $wpdb->prefix . 'actionscheduler_actions';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT hook FROM {$table} WHERE action_id = %d",
						$job_id
					)
				);

				if ( ! $row ) {
					return dtb_oo_action_error( "Job #{$job_id} not found." );
				}

				$allowed_hooks = dtb_oo_local_queue_job_types();
				if ( ! in_array( $row->hook, $allowed_hooks, true ) ) {
					return dtb_oo_action_error( 'This job type cannot be cancelled from the Order Operations dashboard.' );
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				// Note: Action Scheduler uses American English 'canceled' (not 'cancelled') — intentional for AS API compatibility.
				$wpdb->update(
					$table,
					[ 'status' => 'canceled' ],
					[ 'action_id' => $job_id ],
					[ '%s' ],
					[ '%d' ]
				);

				dtb_oo_audit( 'queue.local_job_cancelled', [
					'job_id'   => $job_id,
					'hook'     => $row->hook,
					'actor_id' => $actor_id,
				] );
				return dtb_oo_action_ok( "Job #{$job_id} cancelled." );
			}
			return dtb_oo_action_error( 'Action Scheduler is not available; cannot cancel job.' );

		case 'mark_resolved':
			dtb_oo_audit( 'queue.local_job_marked_resolved', [
				'job_id'   => $job_id,
				'actor_id' => $actor_id,
			] );
			return dtb_oo_action_ok( "Job #{$job_id} marked as resolved." );
	}

	return dtb_oo_action_error( 'Unknown queue action.' );
}

// =============================================================================
// SECTION 6 — SETTINGS SAVE
// =============================================================================

/**
 * Save dashboard settings.
 *
 * Caller MUST have verified capability manage_options and nonce.
 *
 * @param array $raw  Raw POST data.
 * @return array{success:bool, message:string, data:array}
 */
function dtb_oo_save_settings( array $raw ): array {
	$current  = dtb_oo_get_settings();
	$updated  = [];

	// poll_interval: 180–300 seconds.
	if ( isset( $raw['poll_interval'] ) ) {
		$updated['poll_interval'] = max( 180, min( 300, (int) $raw['poll_interval'] ) );
	}

	// SLA thresholds.
	if ( isset( $raw['sla_warning_hours'] ) ) {
		$updated['sla_warning_hours'] = max( 1, min( 720, (int) $raw['sla_warning_hours'] ) );
	}
	if ( isset( $raw['sla_breach_hours'] ) ) {
		$updated['sla_breach_hours'] = max( 1, min( 720, (int) $raw['sla_breach_hours'] ) );
	}

	// page_size: 10–100.
	if ( isset( $raw['page_size'] ) ) {
		$updated['page_size'] = max( 10, min( 100, (int) $raw['page_size'] ) );
	}

	// audit_retention_days: 7–365.
	if ( isset( $raw['audit_retention_days'] ) ) {
		$updated['audit_retention_days'] = max( 7, min( 365, (int) $raw['audit_retention_days'] ) );
	}

	// display_timezone: PHP timezone string.
	if ( isset( $raw['display_timezone'] ) ) {
		$tz = sanitize_text_field( (string) $raw['display_timezone'] );
		if ( in_array( $tz, timezone_identifiers_list(), true ) || in_array( $tz, [ 'UTC' ], true ) ) {
			$updated['display_timezone'] = $tz;
		}
	}

	// Merge and save.
	$new = array_merge( $current, $updated );
	update_option( DTB_OO_SETTINGS_KEY, $new );

	dtb_oo_audit( 'dashboard.settings_saved', [
		'actor_id' => get_current_user_id(),
		'changed'  => array_keys( $updated ),
	] );

	return dtb_oo_action_ok( 'Settings saved.', $new );
}

// =============================================================================
// SECTION 7 — AUDIT WRITER (thin wrapper)
// =============================================================================

/**
 * Write an order-operations audit event.
 *
 * Delegates to dtb_ops_audit_log() when available, otherwise writes directly.
 *
 * @param string $event    Machine-readable event key.
 * @param array  $context  Safe context data (no secrets, tokens, or stacks).
 */
function dtb_oo_audit( string $event, array $context = [] ): void {
	// Sanitize the event key.
	$event   = sanitize_key( $event );
	$context = dtb_oo_redact_payload( $context );

	if ( function_exists( 'dtb_ops_audit_log' ) ) {
		dtb_ops_audit_log( $event, $context );
		return;
	}

	// Fallback: direct write.
	global $wpdb;
	$table = $wpdb->prefix . 'dtb_audit_log';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert(
		$table,
		[
			'log_timestamp' => current_time( 'mysql', true ),
			'user_id'       => (int) get_current_user_id(),
			'event'         => $event,
			'context'       => wp_json_encode( $context ) ?: '',
			'ip'            => '',
		],
		[ '%s', '%d', '%s', '%s', '%s' ]
	);
}

// =============================================================================
// SECTION 8 — RESULT HELPERS
// =============================================================================

/**
 * Build a successful action result.
 *
 * @param string $message
 * @param array  $data
 * @return array{success:bool, message:string, data:array}
 */
function dtb_oo_action_ok( string $message, array $data = [] ): array {
	return [ 'success' => true, 'message' => $message, 'data' => $data ];
}

/**
 * Build an error action result.
 *
 * @param string $message  Safe operator-facing message (no raw exceptions or stacks).
 * @return array{success:bool, message:string, data:array}
 */
function dtb_oo_action_error( string $message ): array {
	return [ 'success' => false, 'message' => $message, 'data' => [] ];
}

/**
 * Build an error bulk result.
 *
 * @param string $message
 * @return array
 */
function dtb_oo_bulk_error( string $message ): array {
	return [
		'success'   => false,
		'message'   => $message,
		'results'   => [],
		'succeeded' => 0,
		'failed'    => 0,
	];
}
