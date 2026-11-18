<?php
/**
 * Services — TicketWorkflowService: THE canonical status-transition engine.
 *
 * All status mutations MUST go through dtb_support_transition_ticket().
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Transition a support ticket to a new status.
 *
 * Validates the transition, persists the new status, timestamps, appends an
 * event to the stream, and fires the action hook.
 *
 * @param int    $ticket_id
 * @param string $to_status
 * @param array  $context {
 *   @type string $actor_type 'staff' | 'customer' | 'system'. Default 'system'.
 *   @type int    $actor_id   WP user ID. Default current_user_id.
 *   @type string $source     Origin. Default 'admin'.
 *   @type string $note       Optional internal note to log alongside the transition.
 * }
 * @return true|WP_Error
 */
function dtb_support_transition_ticket( int $ticket_id, string $to_status, array $context = [] ): bool|WP_Error {
	$ticket = dtb_support_get_ticket( $ticket_id );

	if ( ! $ticket ) {
		return new WP_Error(
			'dtb_support_not_found',
			sprintf( __( 'Ticket #%d not found.', 'drywall-toolbox' ), $ticket_id )
		);
	}

	$from_status = $ticket->status;

	if ( ! dtb_support_is_valid_transition( $from_status, $to_status ) ) {
		return new WP_Error(
			'dtb_support_invalid_transition',
			sprintf(
				/* translators: 1: from status, 2: to status */
				__( 'Cannot transition ticket from "%1$s" to "%2$s".', 'drywall-toolbox' ),
				$from_status,
				$to_status
			)
		);
	}

	$actor_type = sanitize_text_field( $context['actor_type'] ?? 'system' );
	$actor_id   = isset( $context['actor_id'] ) ? absint( $context['actor_id'] ) : get_current_user_id();
	$source     = sanitize_text_field( $context['source']     ?? 'admin' );
	$note       = isset( $context['note'] ) ? sanitize_textarea_field( (string) $context['note'] ) : '';

	// Persist new status and bookmarked timestamps.
	$update = [ 'status' => $to_status ];
	if ( 'resolved' === $to_status ) {
		$update['resolved_at'] = gmdate( 'Y-m-d H:i:s' );
	}
	if ( 'closed' === $to_status ) {
		$update['closed_at'] = gmdate( 'Y-m-d H:i:s' );
	}

	$updated = dtb_support_update_ticket( $ticket_id, $update );
	if ( is_wp_error( $updated ) ) {
		return $updated;
	}

	// Append event.
	$event_type_map = [
		'open'             => 'ticket.status_changed',
		'pending_customer' => 'ticket.status_changed',
		'pending_staff'    => 'ticket.status_changed',
		'in_progress'      => 'ticket.status_changed',
		'resolved'         => 'ticket.resolved',
		'closed'           => 'ticket.closed',
		'spam'             => 'ticket.spam_flagged',
	];
	$event_type = $event_type_map[ $to_status ] ?? 'ticket.status_changed';

	dtb_support_append_event( dtb_support_build_event( $ticket_id, $event_type, [
		'from_status' => $from_status,
		'to_status'   => $to_status,
		'actor_type'  => $actor_type,
		'actor_id'    => $actor_id ?: null,
		'source'      => $source,
		'visibility'  => 'customer',
	] ) );

	// Append optional note.
	if ( '' !== $note ) {
		dtb_support_append_event( dtb_support_build_event( $ticket_id, 'ticket.note_added', [
			'actor_type' => $actor_type,
			'actor_id'   => $actor_id ?: null,
			'source'     => $source,
			'visibility' => 'operator',
			'body'       => $note,
		] ) );
	}

	/**
	 * Fires after a support ticket status transition completes.
	 *
	 * @param int    $ticket_id
	 * @param string $from_status
	 * @param string $to_status
	 * @param array  $context
	 */
	do_action( 'dtb_support_ticket_transitioned', $ticket_id, $from_status, $to_status, $context );

	return true;
}

/**
 * Auto-reopen a resolved ticket when a customer submits a new reply.
 *
 * @param int $ticket_id
 */
function dtb_support_maybe_reopen_on_customer_reply( int $ticket_id ): void {
	$ticket = dtb_support_get_ticket( $ticket_id );
	if ( ! $ticket ) {
		return;
	}
	if ( in_array( $ticket->status, [ 'resolved', 'closed', 'deleted' ], true ) ) {
		dtb_support_transition_ticket( $ticket_id, 'open', [
			'actor_type' => 'customer',
			'source'     => 'customer_reply',
			'note'       => 'Auto-reopened by customer reply.',
		] );
	}
}
