<?php
/**
 * Application — TransitionTicketStatus: orchestrates a status change with notifications.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Transition a ticket's status and trigger appropriate notifications.
 *
 * @param int    $ticket_id
 * @param string $new_status   Target status slug.
 * @param string $note         Optional operator note to record alongside the transition.
 * @param int    $actor_id     WP user ID performing the action; 0 = system.
 * @return true|WP_Error
 */
function dtb_support_do_transition( int $ticket_id, string $new_status, string $note = '', int $actor_id = 0 ): bool|WP_Error {
	$ticket = dtb_support_get_ticket( $ticket_id );
	if ( ! $ticket ) {
		return new WP_Error( 'dtb_support_not_found', __( 'Ticket not found.', 'drywall-toolbox' ) );
	}

	$result = dtb_support_transition_ticket(
		$ticket_id,
		$new_status,
		[
			'actor_type' => $actor_id ? 'staff' : 'system',
			'actor_id'   => $actor_id,
			'source'     => 'admin',
		]
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// Append an internal note if provided.
	if ( '' !== trim( $note ) ) {
		dtb_support_append_event( dtb_support_build_event( $ticket_id, 'ticket.note_added', [
			'actor_type' => $actor_id ? 'staff' : 'system',
			'actor_id'   => $actor_id ?: null,
			'source'     => 'status_transition',
			'visibility' => 'operator',
			'body'       => sanitize_textarea_field( $note ),
			'payload'    => [ 'transition_to' => $new_status ],
		] ) );
	}

	// Notification triggers.
	if ( DTB_SUPPORT_STATUS_RESOLVED === $new_status ) {
		$fresh = dtb_support_get_ticket( $ticket_id );
		if ( $fresh && is_email( $fresh->customer_email ) ) {
			dtb_support_send_email(
				$fresh->customer_email,
				'ticket-resolved-customer',
				[
					'ticket_number' => $fresh->ticket_number,
					'subject'       => $fresh->subject,
					'customer_name' => $fresh->customer_name,
					'status_url'    => function_exists( 'dtb_support_public_status_url' ) ? dtb_support_public_status_url( $fresh ) : '',
				]
			);
		}
	}

	if ( DTB_SUPPORT_STATUS_OPEN === $new_status && 'resolved' === $ticket->status ) {
		// Ticket re-opened.
		$fresh = dtb_support_get_ticket( $ticket_id );
		if ( $fresh && is_email( $fresh->customer_email ) ) {
			dtb_support_send_email(
				$fresh->customer_email,
				'ticket-reopened-customer',
				[
					'ticket_number' => $fresh->ticket_number,
					'subject'       => $fresh->subject,
					'customer_name' => $fresh->customer_name,
					'status_url'    => function_exists( 'dtb_support_public_status_url' ) ? dtb_support_public_status_url( $fresh ) : '',
				]
			);
		}
	}

	return true;
}
