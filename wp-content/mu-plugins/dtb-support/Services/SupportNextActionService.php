<?php
/**
 * Services — SupportNextActionService
 *
 * Computes the deterministic "next best action" and risk flags for a support ticket.
 * Used by the intelligence endpoint and the modal command sidebar.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Compute the next best action for a ticket.
 *
 * Returns an array with keys:
 *   action  — machine key (e.g. 'reply', 'escalate', 'resolve', 'follow_up')
 *   label   — human-readable label
 *   reason  — short explanation
 *
 * @param object $ticket Raw DB row.
 * @return array{action: string, label: string, reason: string}
 */
function dtb_support_compute_next_action( object $ticket ): array {
	$status        = (string) ( $ticket->status ?? 'open' );
	$priority      = (string) ( $ticket->priority ?? 'normal' );
	$has_reply     = ! empty( $ticket->last_staff_reply_at );
	$fail_count    = (int) ( $ticket->notification_fail_count ?? 0 );
	$is_snoozed    = ! empty( $ticket->snooze_until ) && strtotime( (string) $ticket->snooze_until ) > time();

	$sla_state = function_exists( 'dtb_support_compute_sla_state' )
		? dtb_support_compute_sla_state( $ticket )
		: 'ok';

	// Delivery failure — recover first.
	if ( $fail_count > 0 ) {
		return [
			'action' => 'retry_email',
			'label'  => __( 'Retry Email Delivery', 'drywall-toolbox' ),
			'reason' => sprintf(
				/* translators: %d: number of delivery failures */
				_n( '%d email delivery failure.', '%d email delivery failures.', $fail_count, 'drywall-toolbox' ),
				$fail_count
			),
		];
	}

	// Already resolved or closed — no action needed.
	if ( in_array( $status, [ 'resolved', 'closed', 'deleted' ], true ) ) {
		return [
			'action' => 'none',
			'label'  => __( 'No Action Required', 'drywall-toolbox' ),
			'reason' => __( 'Ticket is closed or resolved.', 'drywall-toolbox' ),
		];
	}

	// Snoozed — let it rest.
	if ( $is_snoozed ) {
		return [
			'action' => 'wait',
			'label'  => __( 'Snoozed — No Action Yet', 'drywall-toolbox' ),
			'reason' => __( 'Ticket is snoozed. Review at wake time.', 'drywall-toolbox' ),
		];
	}

	// Waiting on customer status with no follow-up scheduled.
	if ( 'pending_customer' === $status && empty( $ticket->followup_due_at ) ) {
		return [
			'action' => 'set_follow_up',
			'label'  => __( 'Set Follow-Up', 'drywall-toolbox' ),
			'reason' => __( 'Waiting on customer — schedule a follow-up to avoid going cold.', 'drywall-toolbox' ),
		];
	}

	// SLA breach or urgent — reply immediately.
	if ( 'breach' === $sla_state || 'urgent' === $priority ) {
		return [
			'action' => 'reply',
			'label'  => __( 'Reply Immediately', 'drywall-toolbox' ),
			'reason' => __( 'Urgent or breached SLA — customer response is overdue.', 'drywall-toolbox' ),
		];
	}

	// SLA warning.
	if ( 'warning' === $sla_state ) {
		return [
			'action' => 'reply',
			'label'  => __( 'Reply Soon', 'drywall-toolbox' ),
			'reason' => __( 'Approaching response target — reply within the hour.', 'drywall-toolbox' ),
		];
	}

	// Needs a first reply.
	if ( ! $has_reply ) {
		return [
			'action' => 'reply',
			'label'  => __( 'Send First Reply', 'drywall-toolbox' ),
			'reason' => __( 'Customer has not yet received a staff response.', 'drywall-toolbox' ),
		];
	}

	// In progress — keep going.
	if ( 'in_progress' === $status ) {
		return [
			'action' => 'update',
			'label'  => __( 'Update Ticket', 'drywall-toolbox' ),
			'reason' => __( 'In progress — log an update or reply to move forward.', 'drywall-toolbox' ),
		];
	}

	// Default: review.
	return [
		'action' => 'review',
		'label'  => __( 'Review Ticket', 'drywall-toolbox' ),
		'reason' => __( 'No immediate urgent signals detected.', 'drywall-toolbox' ),
	];
}

/**
 * Compute risk flags for a ticket.
 *
 * Returns an array of flag strings describing elevated-risk conditions.
 *
 * @param object $ticket Raw DB row.
 * @return string[]
 */
function dtb_support_compute_risk_flags( object $ticket ): array {
	$flags = [];

	$status    = (string) ( $ticket->status ?? 'open' );
	$priority  = (string) ( $ticket->priority ?? 'normal' );
	$fail_count = (int) ( $ticket->notification_fail_count ?? 0 );
	$age_seconds = time() - (int) strtotime( (string) ( $ticket->created_at ?? 'now' ) );

	$sla_state = function_exists( 'dtb_support_compute_sla_state' )
		? dtb_support_compute_sla_state( $ticket )
		: 'ok';

	if ( 'breach' === $sla_state ) {
		$flags[] = __( 'SLA breached — response target exceeded.', 'drywall-toolbox' );
	}

	if ( 'urgent' === $priority ) {
		$flags[] = __( 'Marked urgent — requires immediate attention.', 'drywall-toolbox' );
	}

	if ( $fail_count > 0 ) {
		$flags[] = sprintf(
			/* translators: %d: count */
			_n( '%d email delivery failure on this ticket.', '%d email delivery failures on this ticket.', $fail_count, 'drywall-toolbox' ),
			$fail_count
		);
	}

	if ( $age_seconds > ( 72 * HOUR_IN_SECONDS ) && empty( $ticket->last_staff_reply_at ) ) {
		$flags[] = __( 'No staff reply in over 72 hours.', 'drywall-toolbox' );
	}

	$haystack = strtolower( trim( wp_strip_all_tags(
		(string) ( $ticket->subject ?? '' ) . ' ' . (string) ( $ticket->message ?? '' )
	) ) );
	$hot_words = [ 'refund', 'damaged', 'missing', 'wrong item', 'no response', 'warranty', 'charge', 'cancellation', 'broken' ];
	foreach ( $hot_words as $word ) {
		if ( false !== strpos( $haystack, $word ) ) {
			$flags[] = sprintf(
				/* translators: %s: keyword */
				__( 'Keyword detected: "%s"', 'drywall-toolbox' ),
				$word
			);
			break;
		}
	}

	return $flags;
}
