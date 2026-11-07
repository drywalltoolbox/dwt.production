<?php
/**
 * Services — TicketSnoozeService: snooze and follow-up management.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Snooze a ticket until a specific datetime.
 *
 * @param int    $ticket_id
 * @param string $snooze_until MySQL datetime string (UTC).
 * @param string $reason Optional snooze reason.
 * @param array  $context actor_type, actor_id, source.
 * @return true|WP_Error
 */
function dtb_support_snooze_ticket( int $ticket_id, string $snooze_until, string $reason = '', array $context = [] ): bool|WP_Error {
	$ticket = dtb_support_get_ticket( $ticket_id );
	if ( ! $ticket ) {
		return new WP_Error( 'dtb_support_not_found', __( 'Ticket not found.', 'drywall-toolbox' ) );
	}

	$timestamp = strtotime( $snooze_until );
	if ( false === $timestamp || $timestamp <= time() ) {
		return new WP_Error( 'dtb_support_invalid_snooze', __( 'Snooze time must be a valid future datetime.', 'drywall-toolbox' ) );
	}

	$result = dtb_support_update_ticket( $ticket_id, [
		'snooze_until'  => gmdate( 'Y-m-d H:i:s', $timestamp ),
		'snooze_reason' => sanitize_text_field( $reason ),
	] );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( function_exists( 'dtb_support_append_event' ) ) {
		dtb_support_append_event( dtb_support_build_event( $ticket_id, 'ticket.snoozed', [
			'actor_type' => sanitize_text_field( $context['actor_type'] ?? 'staff' ),
			'actor_id'   => isset( $context['actor_id'] ) ? absint( $context['actor_id'] ) : get_current_user_id(),
			'source'     => sanitize_text_field( $context['source'] ?? 'admin' ),
			'visibility' => 'operator',
			'payload'    => [
				'snooze_until' => gmdate( 'Y-m-d H:i:s', $timestamp ),
				'reason'       => sanitize_text_field( $reason ),
			],
		] ) );
	}

	if ( function_exists( 'dtb_support_update_ticket_priority_score' ) ) {
		dtb_support_update_ticket_priority_score( $ticket_id );
	}

	return true;
}

/**
 * Remove snooze from a ticket.
 *
 * @param int   $ticket_id
 * @param array $context
 * @return true|WP_Error
 */
function dtb_support_unsnooze_ticket( int $ticket_id, array $context = [] ): bool|WP_Error {
	$ticket = dtb_support_get_ticket( $ticket_id );
	if ( ! $ticket ) {
		return new WP_Error( 'dtb_support_not_found', __( 'Ticket not found.', 'drywall-toolbox' ) );
	}

	$previous = (string) ( $ticket->snooze_until ?? '' );
	$result   = dtb_support_update_ticket( $ticket_id, [
		'snooze_until'  => null,
		'snooze_reason' => '',
	] );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( function_exists( 'dtb_support_append_event' ) ) {
		dtb_support_append_event( dtb_support_build_event( $ticket_id, 'ticket.unsnoozed', [
			'actor_type' => sanitize_text_field( $context['actor_type'] ?? 'staff' ),
			'actor_id'   => isset( $context['actor_id'] ) ? absint( $context['actor_id'] ) : get_current_user_id(),
			'source'     => sanitize_text_field( $context['source'] ?? 'admin' ),
			'visibility' => 'operator',
			'payload'    => [ 'was_snooze_until' => $previous ],
		] ) );
	}

	if ( function_exists( 'dtb_support_update_ticket_priority_score' ) ) {
		dtb_support_update_ticket_priority_score( $ticket_id );
	}

	return true;
}

/**
 * Set a follow-up due time for a ticket.
 */
function dtb_support_set_followup( int $ticket_id, string $followup_due_at, array $context = [] ): bool|WP_Error {
	$ticket = dtb_support_get_ticket( $ticket_id );
	if ( ! $ticket ) {
		return new WP_Error( 'dtb_support_not_found', __( 'Ticket not found.', 'drywall-toolbox' ) );
	}

	$timestamp = strtotime( $followup_due_at );
	if ( false === $timestamp ) {
		return new WP_Error( 'dtb_support_invalid_followup', __( 'Follow-up time must be a valid datetime.', 'drywall-toolbox' ) );
	}

	$result = dtb_support_update_ticket( $ticket_id, [ 'followup_due_at' => gmdate( 'Y-m-d H:i:s', $timestamp ) ] );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( function_exists( 'dtb_support_append_event' ) ) {
		dtb_support_append_event( dtb_support_build_event( $ticket_id, 'ticket.followup_set', [
			'actor_type' => sanitize_text_field( $context['actor_type'] ?? 'staff' ),
			'actor_id'   => isset( $context['actor_id'] ) ? absint( $context['actor_id'] ) : get_current_user_id(),
			'source'     => sanitize_text_field( $context['source'] ?? 'admin' ),
			'visibility' => 'operator',
			'payload'    => [ 'followup_due_at' => gmdate( 'Y-m-d H:i:s', $timestamp ) ],
		] ) );
	}

	return true;
}

/**
 * Process expired snoozes: unsnooze tickets whose snooze_until has passed.
 */
function dtb_support_process_expired_snoozes(): int {
	global $wpdb;
	$table = dtb_support_tickets_table();

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$tickets = $wpdb->get_results( "SELECT id FROM {$table} WHERE snooze_until IS NOT NULL AND snooze_until <= UTC_TIMESTAMP() AND status NOT IN ('resolved','closed','spam','deleted')" );
	$count   = 0;

	foreach ( (array) $tickets as $ticket ) {
		$result = dtb_support_unsnooze_ticket( (int) $ticket->id, [
			'actor_type' => 'system',
			'source'     => 'snooze_cron',
		] );
		if ( ! is_wp_error( $result ) ) {
			$count++;
		}
	}

	return $count;
}

/**
 * Register a 15-minute schedule for snooze wake-ups.
 */
function dtb_support_register_snooze_schedule( array $schedules ): array {
	$schedules['dtb_support_every_15_minutes'] = [
		'interval' => 15 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every 15 Minutes (DTB Support)', 'drywall-toolbox' ),
	];

	return $schedules;
}
add_filter( 'cron_schedules', 'dtb_support_register_snooze_schedule' );

/**
 * Schedule snooze processing.
 */
function dtb_support_schedule_snooze_checks(): void {
	if ( ! wp_next_scheduled( 'dtb_support_check_snoozes' ) ) {
		wp_schedule_event( time(), 'dtb_support_every_15_minutes', 'dtb_support_check_snoozes' );
	}
}
add_action( 'plugins_loaded', 'dtb_support_schedule_snooze_checks' );
add_action( 'dtb_support_check_snoozes', 'dtb_support_process_expired_snoozes' );
