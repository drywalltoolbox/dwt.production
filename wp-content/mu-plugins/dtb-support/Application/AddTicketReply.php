<?php
/**
 * Application — AddTicketReply: handles customer and staff reply submission.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add a reply to an existing support ticket.
 *
 * Handles:
 *  - Sanitisation of body content.
 *  - Appending the reply event to the event stream.
 *  - Auto-reopening closed/resolved tickets on customer reply.
 *  - Dispatching notifications to the appropriate party.
 *
 * @param int    $ticket_id
 * @param string $body        Raw reply message text.
 * @param string $actor_type  'customer' | 'staff'.
 * @param int    $actor_id    WP user ID (0 for unauthenticated customer).
 * @param bool   $is_internal Mark as internal note (staff only; not visible to customer).
 * @return int|WP_Error  Event row ID on success.
 */
function dtb_support_add_reply(
	int $ticket_id,
	string $body,
	string $actor_type = 'customer',
	int $actor_id = 0,
	bool $is_internal = false
): int|WP_Error {
	$ticket = dtb_support_get_ticket( $ticket_id );
	if ( ! $ticket ) {
		return new WP_Error( 'dtb_support_not_found', __( 'Ticket not found.', 'drywall-toolbox' ) );
	}

	$sanitised_body = wp_kses_post( trim( $body ) );
	if ( '' === $sanitised_body ) {
		return new WP_Error( 'dtb_support_empty_reply', __( 'Reply body cannot be empty.', 'drywall-toolbox' ) );
	}

	// Visibility: internal notes are operator-only; customer replies are public.
	$visibility = 'customer' === $actor_type ? 'all' : ( $is_internal ? 'operator' : 'all' );

	// Use canonical event names: customer reply goes to staff; staff reply goes to customer.
	$event_type = 'customer' === $actor_type ? 'ticket.reply_staff' : ( $is_internal ? 'ticket.note_added' : 'ticket.reply_customer' );

	$event = dtb_support_build_event( $ticket_id, $event_type, [
		'actor_type' => $actor_type,
		'actor_id'   => $actor_id ?: null,
		'source'     => 'web',
		'visibility' => $visibility,
		'body'       => $sanitised_body,
	] );

	$event_id = dtb_support_append_event( $event );

	if ( 'customer' === $actor_type ) {
		dtb_support_maybe_reopen_on_customer_reply( $ticket_id );
		$ticket = dtb_support_get_ticket( $ticket_id );
	}

	$now = gmdate( 'Y-m-d H:i:s' );
	if ( 'staff' === $actor_type && ! $is_internal && function_exists( 'dtb_support_update_ticket' ) ) {
		$update = [ 'last_staff_reply_at' => $now ];
		if ( empty( $ticket->first_reply_at ) ) {
			$update['first_reply_at'] = $now;
		}
		dtb_support_update_ticket( $ticket_id, $update );
		dtb_support_auto_transition_after_reply( $ticket_id, 'staff' );
		if ( function_exists( 'dtb_support_refresh_ticket_sla_state' ) ) {
			dtb_support_refresh_ticket_sla_state( $ticket_id );
		}
		$ticket = dtb_support_get_ticket( $ticket_id );
	}

	if ( 'customer' === $actor_type && function_exists( 'dtb_support_update_ticket' ) ) {
		dtb_support_update_ticket( $ticket_id, [ 'last_customer_reply_at' => $now ] );
		$ticket = dtb_support_get_ticket( $ticket_id );
		if ( ! empty( $ticket->snooze_until ) && strtotime( (string) $ticket->snooze_until ) > time() && function_exists( 'dtb_support_unsnooze_ticket' ) ) {
			dtb_support_unsnooze_ticket( $ticket_id, [
				'actor_type' => 'system',
				'source'     => 'customer_reply',
			] );
			$ticket = dtb_support_get_ticket( $ticket_id );
		}
		dtb_support_auto_transition_after_reply( $ticket_id, 'customer' );
		$ticket = dtb_support_get_ticket( $ticket_id );
	}

	// Dispatch notifications (skip internal notes — those never go to customer).
	if ( ! $is_internal ) {
		if ( 'customer' === $actor_type ) {
			dtb_support_notify_customer_reply( $ticket, $sanitised_body );
		} else {
			dtb_support_notify_staff_reply( $ticket, $sanitised_body );
		}
	}

	if ( function_exists( 'dtb_support_update_ticket_priority_score' ) ) {
		dtb_support_update_ticket_priority_score( $ticket_id );
	}

	/**
	 * Fires after a reply is successfully added to a ticket.
	 *
	 * @param int    $ticket_id
	 * @param int    $event_id
	 * @param string $actor_type
	 * @param string $event_type
	 */
	do_action( 'dtb_support_reply_added', $ticket_id, $event_id, $actor_type, $event_type );

	return $event_id;
}

/**
 * Move tickets to the correct waiting state after public replies.
 */
function dtb_support_auto_transition_after_reply( int $ticket_id, string $actor_type ): void {
	if ( ! function_exists( 'dtb_support_transition_ticket' ) || ! function_exists( 'dtb_support_is_valid_transition' ) ) {
		return;
	}

	$ticket = dtb_support_get_ticket( $ticket_id );
	if ( ! $ticket ) {
		return;
	}

	$current = (string) ( $ticket->status ?? '' );
	$target  = 'staff' === $actor_type ? 'pending_customer' : 'pending_staff';

	if ( $current === $target || in_array( $current, [ 'closed', 'spam' ], true ) ) {
		return;
	}

	if ( dtb_support_is_valid_transition( $current, $target ) ) {
		dtb_support_transition_ticket( $ticket_id, $target, [
			'actor_type' => 'staff' === $actor_type ? 'staff' : 'customer',
			'actor_id'   => 'staff' === $actor_type ? get_current_user_id() : 0,
			'source'     => 'reply_auto_status',
			'note'       => 'Auto-updated after public reply.',
		] );
	}
}
