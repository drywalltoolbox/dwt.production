<?php
/**
 * Services — TicketPriorityScoreService: deterministic priority score computation.
 *
 * Score range: 0–1000. Higher = more urgent.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Compute and return the priority score for a ticket.
 *
 * @param object $ticket Raw DB row.
 * @return int
 */
function dtb_support_compute_priority_score( object $ticket ): int {
	$score  = 0;
	$status = (string) ( $ticket->status ?? '' );

	if ( in_array( $status, [ 'open', 'pending_staff' ], true ) ) {
		$score += 200;
	} elseif ( 'in_progress' === $status ) {
		$score += 100;
	}

	$priority_weights = [
		'urgent' => 250,
		'high'   => 150,
		'normal' => 50,
		'low'    => 0,
	];
	$score += $priority_weights[ (string) ( $ticket->priority ?? 'normal' ) ] ?? 50;

	$sla_state = function_exists( 'dtb_support_compute_sla_state' )
		? dtb_support_compute_sla_state( $ticket )
		: (string) ( $ticket->sla_state ?? 'ok' );
	if ( 'breach' === $sla_state ) {
		$score += 300;
	} elseif ( 'warning' === $sla_state ) {
		$score += 150;
	}

	$age_seconds = time() - strtotime( (string) ( $ticket->created_at ?? 'now' ) );
	$has_staff_reply = ! empty( $ticket->last_staff_reply_at ) || ! empty( $ticket->first_reply_at );
	if ( ! $has_staff_reply && $age_seconds > ( 48 * HOUR_IN_SECONDS ) ) {
		$score += 100;
	} elseif ( ! $has_staff_reply && $age_seconds > DAY_IN_SECONDS ) {
		$score += 50;
	}

	if ( ! empty( $ticket->order_id ) ) {
		$score += 25;
	}

	$haystack = strtolower( trim( wp_strip_all_tags( (string) ( $ticket->subject ?? '' ) . ' ' . (string) ( $ticket->message ?? '' ) ) ) );
	$keywords = [ 'refund', 'damaged', 'missing', 'urgent', 'broken', 'cancellation', 'warranty', 'charge', 'no response', 'wrong item' ];
	foreach ( $keywords as $keyword ) {
		if ( false !== strpos( $haystack, $keyword ) ) {
			$score += 50;
			break;
		}
	}

	if ( (int) ( $ticket->notification_fail_count ?? 0 ) > 0 ) {
		$score += 25;
	}

	return max( 0, min( 1000, $score ) );
}

/**
 * Compute and persist the priority score for a ticket.
 *
 * @param int $ticket_id
 * @return int
 */
function dtb_support_update_ticket_priority_score( int $ticket_id ): int {
	$ticket = dtb_support_get_ticket( $ticket_id );
	if ( ! $ticket ) {
		return 0;
	}

	$old_score = isset( $ticket->priority_score ) ? (int) $ticket->priority_score : 0;
	$new_score = dtb_support_compute_priority_score( $ticket );
	dtb_support_update_ticket( $ticket_id, [ 'priority_score' => $new_score ] );

	if ( $old_score !== $new_score && function_exists( 'dtb_support_append_event' ) ) {
		dtb_support_append_event( dtb_support_build_event( $ticket_id, 'ticket.score_updated', [
			'actor_type' => 'system',
			'source'     => 'priority_score',
			'visibility' => 'operator',
			'payload'    => [
				'old_score' => $old_score,
				'new_score' => $new_score,
			],
		] ) );
	}

	return $new_score;
}
