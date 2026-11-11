<?php
/**
 * Services — TicketQueryService: rich read-model projections for admin UIs.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the current action due-at timestamp for a ticket.
 */
function dtb_support_action_due_at( object $ticket ): ?string {
	if ( 'pending_customer' === (string) ( $ticket->status ?? '' ) ) {
		return ! empty( $ticket->followup_due_at ) ? (string) $ticket->followup_due_at : null;
	}

	$due_at = $ticket->sla_first_response_due ?? null;

	if ( empty( $due_at ) ) {
		$due_at = dtb_support_compute_first_response_due( (string) $ticket->created_at, (string) $ticket->priority );
	}

	return ! empty( $due_at ) ? (string) $due_at : null;
}

/**
 * Project a raw DB ticket row into a fully-enriched admin view model.
 *
 * v2: includes action_due_at, action_state (not "sla"), priority_score,
 * snooze fields, last-activity timestamps, metadata, and notification health.
 * "action_state" values: ok | due_soon | overdue  (never "warning"/"breach").
 *
 * @param object $ticket  Raw DB row from dtb_support_get_ticket() or dtb_support_query_tickets().
 * @return array
 */
function dtb_support_project_ticket( object $ticket ): array {
	$status   = (string) $ticket->status;
	$priority = (string) $ticket->priority;

	$is_resolved = in_array( $status, [ 'resolved', 'closed', 'spam', 'deleted' ], true );

	// Internal SLA state (ok/warning/breach) mapped to operator-friendly action_state.
	$raw_sla = function_exists( 'dtb_support_compute_sla_state' )
		? dtb_support_compute_sla_state( $ticket )
		: dtb_support_sla_state( (string) $ticket->created_at, $priority, $is_resolved, $ticket->sla_first_response_due ?? null );

	// Map internal values → admin-UI values (no "SLA" language).
	$action_state_map = [
		'ok'      => 'ok',
		'warning' => 'due_soon',
		'breach'  => 'overdue',
		'due_soon' => 'due_soon',
		'overdue'  => 'overdue',
	];
	$action_state = $action_state_map[ $raw_sla ] ?? 'ok';

	// Human label for the action state.
	$action_state_labels = [
		'ok'       => 'On Track',
		'due_soon' => 'Due Soon',
		'overdue'  => 'Overdue',
	];

	// Seconds until the action due time (negative = already overdue).
	$action_due_at = dtb_support_action_due_at( $ticket );
	$seconds_until_due = null;
	if ( $action_due_at && ! $is_resolved ) {
		$seconds_until_due = strtotime( $action_due_at ) - time();
	}

	$age_seconds = time() - strtotime( (string) $ticket->created_at );
	$age_label   = dtb_support_age_label( $age_seconds );

	$priority_score = isset( $ticket->priority_score )
		? (int) $ticket->priority_score
		: ( function_exists( 'dtb_support_compute_priority_score' ) ? dtb_support_compute_priority_score( $ticket ) : 0 );
	$next_action = function_exists( 'dtb_support_compute_next_action' )
		? dtb_support_compute_next_action( $ticket )
		: [ 'action' => 'review', 'label' => __( 'Review Ticket', 'drywall-toolbox' ), 'reason' => '' ];

	$metadata = [];
	if ( ! empty( $ticket->metadata_json ) ) {
		$decoded = json_decode( $ticket->metadata_json, true );
		if ( is_array( $decoded ) ) {
			$metadata = $decoded;
		}
	}
	if ( function_exists( 'dtb_str_normalize_display_mixed' ) ) {
		$metadata = dtb_str_normalize_display_mixed( $metadata );
	}

	$is_snoozed = ! empty( $ticket->snooze_until ) && strtotime( (string) $ticket->snooze_until ) > time();

	$subject = (string) ( $ticket->subject ?? '' );
	$customer_name = (string) ( $ticket->customer_name ?? '' );
	$customer_phone = (string) ( $ticket->customer_phone ?? '' );
	$company = (string) ( $ticket->company ?? '' );
	$message = (string) ( $ticket->message ?? '' );
	$source = (string) ( $ticket->source ?? '' );
	if ( function_exists( 'dtb_str_normalize_display' ) ) {
		$subject = dtb_str_normalize_display( $subject );
		$customer_name = dtb_str_normalize_display( $customer_name );
		$customer_phone = dtb_str_normalize_display( $customer_phone );
		$company = dtb_str_normalize_display( $company );
		$message = dtb_str_normalize_display( $message, true );
		$source = dtb_str_normalize_display( $source );
	}
	$tags = array_values( array_filter( explode( ',', (string) ( $ticket->tags ?? '' ) ) ) );
	if ( function_exists( 'dtb_str_normalize_display' ) ) {
		$tags = array_values(
			array_filter(
				array_map(
					static fn( string $tag ): string => dtb_str_normalize_display( $tag ),
					$tags
				)
			)
		);
	}

	return [
		// Core identity.
		'id'             => (int) $ticket->id,
		'ticket_number'  => $ticket->ticket_number,

		// Status / type / priority.
		'status'         => $status,
		'status_label'   => dtb_support_status_label( $status ),
		'status_css'     => dtb_support_status_css( $status ),
		'ticket_type'    => $ticket->ticket_type,
		'type_label'     => dtb_support_type_label( $ticket->ticket_type ),
		'priority'       => $priority,
		'priority_label' => dtb_support_priority_label( $priority ),
		'priority_score' => $priority_score,
		'next_action'    => (string) ( $next_action['action'] ?? 'review' ),
		'next_action_label' => (string) ( $next_action['label'] ?? __( 'Review Ticket', 'drywall-toolbox' ) ),
		'next_action_reason' => (string) ( $next_action['reason'] ?? '' ),

		// Content.
		'subject'        => $subject,
		'customer_name'  => $customer_name,
		'customer_email' => $ticket->customer_email,
		'customer_phone' => $customer_phone,
		'company'        => $company,
		'message'        => $message,
		'source'         => $source,
		'order_id'       => ! empty( $ticket->order_id ) ? (int) $ticket->order_id : null,
		'tags'           => $tags,
		'metadata'       => $metadata,

		// Operational target (action-due) — admin-facing language, never "SLA".
		'action_state'        => $action_state,
		'action_state_label'  => $action_state_labels[ $action_state ] ?? 'On Track',
		'action_due_at'       => $action_due_at,
		'resolution_target_at' => ! empty( $ticket->sla_resolution_due ) ? (string) $ticket->sla_resolution_due : null,
		'seconds_until_due'   => $seconds_until_due,

		// Activity timestamps.
		'age_label'              => $age_label,
		'first_reply_at'         => $ticket->first_reply_at,
		'last_customer_reply_at' => $ticket->last_customer_reply_at ?? null,
		'last_staff_reply_at'    => $ticket->last_staff_reply_at    ?? null,
		'resolved_at'            => $ticket->resolved_at,
		'closed_at'              => $ticket->closed_at,
		'created_at'             => $ticket->created_at,
		'updated_at'             => $ticket->updated_at,

		// Snooze / follow-up.
		'is_snoozed'     => $is_snoozed,
		'snooze_until'   => ! empty( $ticket->snooze_until )   ? (string) $ticket->snooze_until   : null,
		'snooze_reason'  => ! empty( $ticket->snooze_reason )  ? (string) $ticket->snooze_reason  : null,
		'followup_due_at' => ! empty( $ticket->followup_due_at ) ? (string) $ticket->followup_due_at : null,

		// Notification health.
		'notification_status'      => $ticket->notification_status      ?? '',
		'notification_fail_count'  => (int) ( $ticket->notification_fail_count ?? 0 ),
		'notification_last_sent_at' => $ticket->notification_last_sent_at ?? null,

		// Navigation.
		'edit_url' => admin_url( 'admin.php?page=dtb-support&ticket_id=' . $ticket->id ),
	];
}

/**
 * Return a human-readable age string (e.g. "5m", "2h", "3d").
 *
 * @param int $seconds  Age in seconds.
 * @return string
 */
function dtb_support_age_label( int $seconds ): string {
	if ( $seconds < 60 ) {
		return 'just now';
	}
	if ( $seconds < 3600 ) {
		return round( $seconds / 60 ) . 'm';
	}
	if ( $seconds < 86400 ) {
		return round( $seconds / 3600 ) . 'h';
	}
	return round( $seconds / 86400 ) . 'd';
}

/**
 * Return KPI summary counts for the support dashboard.
 *
 * Keys use "overdue_count" / "due_soon_count" instead of "sla_breach" /
 * "sla_at_risk" — no customer-facing SLA language anywhere in this output.
 *
 * @return array
 */
function dtb_support_get_kpis(): array {
	global $wpdb;
	$table = dtb_support_tickets_table();

	$by_status = dtb_support_count_by_status();
	$total     = array_sum( $by_status );

	// Priority counts (open tickets only).
	$urgent = (int) $wpdb->get_var(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT COUNT(*) FROM {$table} WHERE priority = 'urgent' AND status NOT IN ('resolved','closed','spam','deleted')"
	);
	$high   = (int) $wpdb->get_var(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT COUNT(*) FROM {$table} WHERE priority = 'high' AND status NOT IN ('resolved','closed','spam','deleted')"
	);

	$action_due_hours    = max( 1, (int) dtb_support_action_due_hours() );
	$warning_window_secs = (int) floor( $action_due_hours * HOUR_IN_SECONDS * 0.25 );
	$warning_window_secs = max( HOUR_IN_SECONDS, $warning_window_secs );
	$action_due_expr     = "COALESCE(sla_first_response_due, DATE_ADD(created_at, INTERVAL {$action_due_hours} HOUR))";

	// Overdue and due-soon counts calculated from due timestamp.
	$overdue_count  = (int) $wpdb->get_var(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT COUNT(*) FROM {$table} WHERE {$action_due_expr} < UTC_TIMESTAMP() AND status NOT IN ('resolved','closed','spam','deleted')"
	);
	$due_soon_count = (int) $wpdb->get_var(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT COUNT(*) FROM {$table} WHERE {$action_due_expr} >= UTC_TIMESTAMP() AND TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), {$action_due_expr}) <= {$warning_window_secs} AND status NOT IN ('resolved','closed','spam','deleted')"
	);

	// Needs reply = open/pending_staff/in_progress and not snoozed.
	$needs_reply = (int) $wpdb->get_var(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT COUNT(*) FROM {$table} WHERE status IN ('open','pending_staff','in_progress') AND (snooze_until IS NULL OR snooze_until <= UTC_TIMESTAMP())"
	);

	// Today stats (UTC date).
	$today_start  = gmdate( 'Y-m-d' ) . ' 00:00:00';
	$today_new    = (int) $wpdb->get_var( $wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT COUNT(*) FROM {$table} WHERE created_at >= %s",
		$today_start
	) );
	$today_resolved = (int) $wpdb->get_var( $wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT COUNT(*) FROM {$table} WHERE resolved_at >= %s",
		$today_start
	) );

	// Email failures.
	$email_failures = (int) $wpdb->get_var(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT COUNT(*) FROM {$table} WHERE notification_fail_count > 0 AND status NOT IN ('resolved','closed','spam','deleted')"
	);

	return [
		'total'            => $total,
		'active_total'     => (int) (
			( $by_status['open'] ?? 0 ) +
			( $by_status['pending_customer'] ?? 0 ) +
			( $by_status['pending_staff'] ?? 0 ) +
			( $by_status['in_progress'] ?? 0 )
		),
		'open'             => $by_status['open']             ?? 0,
		'pending_customer' => $by_status['pending_customer'] ?? 0,
		'pending_staff'    => $by_status['pending_staff']    ?? 0,
		'in_progress'      => $by_status['in_progress']      ?? 0,
		'resolved'         => $by_status['resolved']         ?? 0,
		'closed'           => $by_status['closed']           ?? 0,
		'spam'             => $by_status['spam']             ?? 0,
		'urgent'           => $urgent,
		'high'             => $high,
		'needs_reply'      => $needs_reply,
		'overdue_count'    => $overdue_count,
		'sla_breach'       => $overdue_count,
		'due_soon_count'   => $due_soon_count,
		'email_failures'   => $email_failures,
		'today_new'        => $today_new,
		'today_resolved'   => $today_resolved,
		'schema_version'   => function_exists( 'dtb_support_db_version' ) ? dtb_support_db_version() : '0',
	];
}
