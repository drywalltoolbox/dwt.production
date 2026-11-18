<?php
/**
 * Services — TicketSlaService: SLA computation, due-time stamping, and state management.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return default action-due window in hours.
 */
function dtb_support_action_due_hours(): int {
	return (int) apply_filters( 'dtb_support_action_due_hours', 24 );
}

/**
 * Compute first-response due datetime string given created_at and priority.
 */
function dtb_support_compute_first_response_due( string $created_at, string $priority ): string {
	$timestamp = strtotime( $created_at );
	if ( false === $timestamp ) {
		$timestamp = time();
	}

	return gmdate( 'Y-m-d H:i:s', $timestamp + ( dtb_support_action_due_hours() * HOUR_IN_SECONDS ) );
}

/**
 * Compute resolution due datetime string.
 */
function dtb_support_compute_resolution_due( string $created_at, string $priority ): string {
	$timestamp = strtotime( $created_at );
	if ( false === $timestamp ) {
		$timestamp = time();
	}

	return gmdate( 'Y-m-d H:i:s', $timestamp + ( dtb_support_sla_resolution_hours( $priority ) * HOUR_IN_SECONDS ) );
}

/**
 * Return resolution SLA hours for a priority.
 */
function dtb_support_sla_resolution_hours( string $priority ): int {
	$map = [
		'low'    => 168,
		'normal' => 72,
		'high'   => 24,
		'urgent' => 8,
	];

	return (int) ( $map[ $priority ] ?? $map['normal'] );
}

/**
 * Compute SLA state ('ok','warning','breach') for a ticket given current SLA due time.
 */
function dtb_support_compute_sla_state( object $ticket ): string {
	if ( in_array( (string) $ticket->status, [ 'pending_customer', 'resolved', 'closed', 'spam', 'deleted' ], true ) ) {
		return 'ok';
	}

	$due_at = $ticket->sla_first_response_due ?? '';

	if ( empty( $due_at ) ) {
		$due_at = dtb_support_compute_first_response_due( (string) $ticket->created_at, (string) $ticket->priority );
	}

	$remaining = dtb_support_sla_seconds_remaining( $ticket );
	if ( $remaining < 0 ) {
		return 'breach';
	}

	$due_ts    = strtotime( (string) $due_at );
	$start_ts  = strtotime( (string) $ticket->created_at );
	$total     = ( false === $due_ts || false === $start_ts ) ? 0 : max( 1, $due_ts - $start_ts );
	$warning_s = (int) floor( $total * 0.25 );

	if ( $remaining <= $warning_s ) {
		return 'warning';
	}

	return 'ok';
}

/**
 * Return seconds remaining until SLA due (negative means already breached).
 */
function dtb_support_sla_seconds_remaining( object $ticket ): int {
	if ( in_array( (string) $ticket->status, [ 'pending_customer', 'resolved', 'closed', 'spam', 'deleted' ], true ) ) {
		return 0;
	}

	$due_at = $ticket->sla_first_response_due ?? '';

	if ( empty( $due_at ) ) {
		$due_at = dtb_support_compute_first_response_due( (string) $ticket->created_at, (string) $ticket->priority );
	}

	$due_ts = strtotime( (string) $due_at );
	if ( false === $due_ts ) {
		return 0;
	}

	return $due_ts - time();
}

/**
 * Stamp SLA fields on a newly created ticket.
 */
function dtb_support_stamp_ticket_sla( int $ticket_id ): void {
	$ticket = dtb_support_get_ticket( $ticket_id );
	if ( ! $ticket ) {
		return;
	}

	$first_due      = dtb_support_compute_first_response_due( (string) $ticket->created_at, (string) $ticket->priority );
	$resolution_due = dtb_support_compute_resolution_due( (string) $ticket->created_at, (string) $ticket->priority );
	$ticket->sla_first_response_due = $first_due;
	$ticket->sla_resolution_due     = $resolution_due;
	$ticket->sla_state              = dtb_support_compute_sla_state( $ticket );

	dtb_support_update_ticket( $ticket_id, [
		'sla_first_response_due' => $first_due,
		'sla_resolution_due'     => $resolution_due,
		'sla_state'              => $ticket->sla_state,
	] );
}

/**
 * Recompute and update sla_state for a ticket.
 */
function dtb_support_refresh_ticket_sla_state( int $ticket_id ): void {
	$ticket = dtb_support_get_ticket( $ticket_id );
	if ( ! $ticket ) {
		return;
	}

	$update = [];
	if ( empty( $ticket->sla_first_response_due ) ) {
		$update['sla_first_response_due'] = dtb_support_compute_first_response_due( (string) $ticket->created_at, (string) $ticket->priority );
		$ticket->sla_first_response_due   = $update['sla_first_response_due'];
	}
	if ( empty( $ticket->sla_resolution_due ) ) {
		$update['sla_resolution_due'] = dtb_support_compute_resolution_due( (string) $ticket->created_at, (string) $ticket->priority );
		$ticket->sla_resolution_due   = $update['sla_resolution_due'];
	}

	$update['sla_state'] = dtb_support_compute_sla_state( $ticket );
	dtb_support_update_ticket( $ticket_id, $update );
}

/**
 * Run SLA scan across all non-terminal tickets, update sla_state, return count updated.
 */
function dtb_support_run_sla_scan(): int {
	global $wpdb;
	$table   = dtb_support_tickets_table();
	$updated = 0;

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$tickets = $wpdb->get_results( "SELECT * FROM {$table} WHERE status NOT IN ('resolved','closed','spam','deleted')" );
	foreach ( (array) $tickets as $ticket ) {
		$next_state = dtb_support_compute_sla_state( $ticket );
		if ( (string) ( $ticket->sla_state ?? '' ) !== $next_state ) {
			dtb_support_update_ticket( (int) $ticket->id, [ 'sla_state' => $next_state ] );
			$updated++;
		}
	}

	update_option( 'dtb_support_last_sla_scan', gmdate( 'Y-m-d H:i:s' ) );
	return $updated;
}

/**
 * Schedule the SLA cron scan.
 */
function dtb_support_schedule_sla_scan(): void {
	if ( ! wp_next_scheduled( 'dtb_support_hourly_sla_scan' ) ) {
		wp_schedule_event( time(), 'hourly', 'dtb_support_hourly_sla_scan' );
	}
}
add_action( 'plugins_loaded', 'dtb_support_schedule_sla_scan' );
add_action( 'dtb_support_hourly_sla_scan', 'dtb_support_run_sla_scan' );
