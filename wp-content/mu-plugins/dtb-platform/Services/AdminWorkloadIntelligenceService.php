<?php
/**
 * DTB Platform — AdminWorkloadIntelligenceService
 *
 * Deterministic workload-intelligence helpers consumed by the shared workbench
 * contract.  These functions derive scores, SLA state, sentiment flags, and
 * next-best-action recommendations from record metadata — no external LLM calls.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

// ── Age bucket ────────────────────────────────────────────────────────────────

/**
 * Classify a record by age into a human-readable bucket.
 *
 * @param string $created_at  ISO-8601 or MySQL datetime string.
 * @return string  'new' | 'recent' | 'aging' | 'stale' | 'critical'
 */
function dtb_admin_compute_age_bucket( string $created_at ): string {
	$ts = strtotime( $created_at );
	if ( false === $ts ) {
		return 'unknown';
	}
	$age_hours = ( time() - $ts ) / 3600;

	if ( $age_hours < 2 )   { return 'new'; }
	if ( $age_hours < 24 )  { return 'recent'; }
	if ( $age_hours < 72 )  { return 'aging'; }
	if ( $age_hours < 168 ) { return 'stale'; }  // 7 days
	return 'critical';
}

// ── SLA state ─────────────────────────────────────────────────────────────────

/**
 * Compute SLA state for a record.
 *
 * SLA thresholds (in hours):
 *   support:  warning at 24 h, breach at 48 h
 *   returns:  warning at 48 h, breach at 96 h
 *   repair:   warning at 72 h, breach at 168 h
 *
 * @param string $created_at
 * @param string $status
 * @param string $module  'support' | 'returns' | 'repair'
 * @return string  'ok' | 'warning' | 'breach' | 'exempt'
 */
function dtb_admin_compute_sla_state( string $created_at, string $status, string $module = 'repair' ): string {
	$exempt_statuses = [
		'support' => [ 'closed', 'resolved' ],
		'returns' => [ 'closed', 'refund_issued', 'exchange_sent', 'rejected' ],
		'repair'  => [ 'closed', 'cancelled', 'quote_declined', 'completed' ],
	];

	if ( in_array( $status, $exempt_statuses[ $module ] ?? [], true ) ) {
		return 'exempt';
	}

	$thresholds = [
		'support' => [ 'warning' => 24, 'breach' => 48 ],
		'returns' => [ 'warning' => 48, 'breach' => 96 ],
		'repair'  => [ 'warning' => 72, 'breach' => 168 ],
	];

	$t = $thresholds[ $module ] ?? [ 'warning' => 48, 'breach' => 96 ];

	$ts = strtotime( $created_at );
	if ( false === $ts ) {
		return 'ok';
	}
	$age_hours = ( time() - $ts ) / 3600;

	if ( $age_hours >= $t['breach'] )  { return 'breach'; }
	if ( $age_hours >= $t['warning'] ) { return 'warning'; }
	return 'ok';
}

// ── Sentiment / intent flags ──────────────────────────────────────────────────

/**
 * Detect simple sentiment signals from free-form text.
 *
 * @param string $text  Subject, body, or note content.
 * @return string[]  List of detected flag slugs.
 */
function dtb_admin_detect_customer_sentiment_flags( string $text ): array {
	$text  = strtolower( $text );
	$flags = [];

	// Each category loop breaks on first match — only one flag per category needed.
	$urgent_phrases = [
		'urgent', 'asap', 'immediately', 'right now', 'as soon as possible',
		'frustrated', 'unacceptable', 'ridiculous', 'furious', 'terrible',
	];
	foreach ( $urgent_phrases as $p ) {
		if ( str_contains( $text, $p ) ) {
			$flags[] = 'high_urgency';
			break;
		}
	}

	$negative_phrases = [ 'disappointed', 'unhappy', 'unsatisfied', 'poor', 'bad experience' ];
	foreach ( $negative_phrases as $p ) {
		if ( str_contains( $text, $p ) ) {
			$flags[] = 'negative_sentiment';
			break;
		}
	}

	$escalation_phrases = [ 'refund', 'charge back', 'chargeback', 'dispute', 'bbb', 'attorney', 'legal' ];
	foreach ( $escalation_phrases as $p ) {
		if ( str_contains( $text, $p ) ) {
			$flags[] = 'escalation_risk';
			break;
		}
	}

	return array_unique( $flags );
}

/**
 * Detect issue-type intent signals from free-form text.
 *
 * @param string $text
 * @return string[]  List of detected intent slugs.
 */
function dtb_admin_detect_intent_flags( string $text ): array {
	$text  = strtolower( $text );
	$flags = [];

	$map = [
		'damaged'  => [ 'damaged', 'broken', 'cracked', 'defective', 'not working', 'stopped working' ],
		'missing'  => [ 'missing', 'never arrived', 'did not arrive', 'not received', "didn't receive" ],
		'wrong'    => [ 'wrong item', 'incorrect', 'not what i ordered', 'different from' ],
		'refund'   => [ 'refund', 'money back', 'return my money' ],
		'exchange' => [ 'exchange', 'replacement', 'swap', 'substitute' ],
		'repair'   => [ 'repair', 'fix', 'service', 'broken part', 'replace part' ],
		'tracking' => [ 'tracking', 'where is my order', 'shipping update', 'delivery' ],
	];

	foreach ( $map as $intent => $phrases ) {
		foreach ( $phrases as $p ) {
			if ( str_contains( $text, $p ) ) {
				$flags[] = $intent;
				break;
			}
		}
	}

	return array_unique( $flags );
}

// ── Next best action ──────────────────────────────────────────────────────────

/**
 * Compute the next best action for an operator given module and record context.
 *
 * @param string $module   'support' | 'returns' | 'repair'
 * @param array  $record   Associative record data (status, age, flags, etc.)
 * @return string  Human-readable next-action recommendation.
 */
function dtb_admin_compute_next_best_action( string $module, array $record ): string {
	$status = (string) ( $record['status'] ?? '' );

	$support_map = [
		'open'         => __( 'Reply to customer',   'drywall-toolbox' ),
		'needs_reply'  => __( 'Send reply',           'drywall-toolbox' ),
		'in_progress'  => __( 'Update ticket status', 'drywall-toolbox' ),
		'snoozed'      => __( 'Review snooze',        'drywall-toolbox' ),
		'resolved'     => __( 'Close ticket',         'drywall-toolbox' ),
		'closed'       => __( 'No action required',   'drywall-toolbox' ),
	];

	$returns_map = [
		'pending_review' => __( 'Review and approve or reject', 'drywall-toolbox' ),
		'approved'       => __( 'Send return instructions',     'drywall-toolbox' ),
		'awaiting_item'  => __( 'Await item arrival',           'drywall-toolbox' ),
		'item_received'  => __( 'Process refund or exchange',   'drywall-toolbox' ),
		'refund_issued'  => __( 'Close return',                 'drywall-toolbox' ),
		'exchange_sent'  => __( 'Close return',                 'drywall-toolbox' ),
		'rejected'       => __( 'Close return',                 'drywall-toolbox' ),
		'closed'         => __( 'No action required',           'drywall-toolbox' ),
	];

	$repair_map = [
		'submitted'         => __( 'Review intake',         'drywall-toolbox' ),
		'reviewed'          => __( 'Send quote or approve', 'drywall-toolbox' ),
		'awaiting_customer' => __( 'Await customer reply',  'drywall-toolbox' ),
		'approved'          => __( 'Build quote',           'drywall-toolbox' ),
		'quoted'            => __( 'Await quote approval',  'drywall-toolbox' ),
		'quote_accepted'    => __( 'Allocate parts',        'drywall-toolbox' ),
		'quote_declined'    => __( 'No action required',    'drywall-toolbox' ),
		'parts_allocated'   => __( 'Begin repair',          'drywall-toolbox' ),
		'in_progress'       => __( 'Continue repair',       'drywall-toolbox' ),
		'ready_to_ship'     => __( 'Ship repair',           'drywall-toolbox' ),
		'completed'         => __( 'Close repair',          'drywall-toolbox' ),
		'closed'            => __( 'No action required',    'drywall-toolbox' ),
		'cancelled'         => __( 'No action required',    'drywall-toolbox' ),
	];

	$map = match ( $module ) {
		'support' => $support_map,
		'returns' => $returns_map,
		'repair'  => $repair_map,
		default   => [],
	};

	return $map[ $status ] ?? __( 'Review record', 'drywall-toolbox' );
}

// ── Blockers ─────────────────────────────────────────────────────────────────

/**
 * Compute blocking issues that should surface as warnings in the workbench.
 *
 * @param string $module
 * @param array  $record  Must include status, order_id (optional), email, etc.
 * @return string[]  Human-readable blocker messages.
 */
function dtb_admin_compute_blockers( string $module, array $record ): array {
	$blockers = [];
	$status   = (string) ( $record['status'] ?? '' );

	// Missing order link.
	if ( empty( $record['order_id'] ) && in_array( $status, [ 'approved', 'item_received', 'in_progress', 'quoted' ], true ) ) {
		$blockers[] = __( 'No WooCommerce order linked', 'drywall-toolbox' );
	}

	// Missing customer email.
	if ( empty( $record['customer_email'] ) ) {
		$blockers[] = __( 'No customer email on record', 'drywall-toolbox' );
	}

	// Support: unread customer message when closing.
	if ( 'support' === $module && in_array( $status, [ 'resolved', 'closed' ], true )
		&& ! empty( $record['unread_customer_messages'] )
		&& (int) $record['unread_customer_messages'] > 0
	) {
		$blockers[] = __( 'Customer has unread messages — confirm close', 'drywall-toolbox' );
	}

	// Returns: refund/exchange without resolution.
	if ( 'returns' === $module && in_array( $status, [ 'item_received' ], true ) && empty( $record['resolution'] ) ) {
		$blockers[] = __( 'Resolution not set before refund/exchange action', 'drywall-toolbox' );
	}

	// Repairs: ready-to-ship without tracking.
	if ( 'repair' === $module && 'ready_to_ship' === $status && empty( $record['tracking_number'] ) ) {
		$blockers[] = __( 'No shipping tracking number — complete closeout', 'drywall-toolbox' );
	}

	return $blockers;
}

// ── Workload score ────────────────────────────────────────────────────────────

/**
 * Compute a 0–100 workload urgency score.
 *
 * @param string $module
 * @param array  $record
 * @return int
 */
function dtb_admin_compute_workload_score( string $module, array $record ): int {
	$score = 0;

	$created_at = (string) ( $record['created_at'] ?? '' );
	$status     = (string) ( $record['status'] ?? '' );

	// Age factor (0–30).
	$bucket = dtb_admin_compute_age_bucket( $created_at );
	$age_score = match ( $bucket ) {
		'critical' => 30,
		'stale'    => 20,
		'aging'    => 12,
		'recent'   => 5,
		default    => 0,
	};
	$score += $age_score;

	// SLA factor (0–25).
	$sla = dtb_admin_compute_sla_state( $created_at, $status, $module );
	$score += match ( $sla ) {
		'breach'  => 25,
		'warning' => 15,
		default   => 0,
	};

	// Unread customer messages (+15).
	if ( ! empty( $record['unread_customer_messages'] ) && (int) $record['unread_customer_messages'] > 0 ) {
		$score += 15;
	}

	// Missing order link (+10).
	if ( empty( $record['order_id'] ) ) {
		$score += 10;
	}

	// Failed notifications (+10).
	if ( ! empty( $record['failed_notification_count'] ) && (int) $record['failed_notification_count'] > 0 ) {
		$score += 10;
	}

	// Escalation risk flag (+10).
	$sentiment = (array) ( $record['sentiment_flags'] ?? [] );
	if ( in_array( 'escalation_risk', $sentiment, true ) ) {
		$score += 10;
	}

	return min( 100, $score );
}
