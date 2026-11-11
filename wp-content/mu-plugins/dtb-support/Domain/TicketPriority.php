<?php
/**
 * Domain — TicketPriority: priority levels, labels, and SLA thresholds.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return all valid priority slugs mapped to their display labels.
 *
 * @return array<string,string>
 */
function dtb_support_all_priorities(): array {
	return [
		'low'    => __( 'Low',    'drywall-toolbox' ),
		'normal' => __( 'Normal', 'drywall-toolbox' ),
		'high'   => __( 'High',   'drywall-toolbox' ),
		'urgent' => __( 'Urgent', 'drywall-toolbox' ),
	];
}

/**
 * Return the display label for a priority slug.
 *
 * @param string $priority
 * @return string
 */
function dtb_support_priority_label( string $priority ): string {
	$all = dtb_support_all_priorities();
	return $all[ $priority ] ?? ucwords( $priority );
}

/**
 * Return whether a given priority slug is valid.
 *
 * @param string $priority
 * @return bool
 */
function dtb_support_is_valid_priority( string $priority ): bool {
	return array_key_exists( $priority, dtb_support_all_priorities() );
}

/**
 * Return the default priority slug for new tickets.
 *
 * @return string
 */
function dtb_support_default_priority(): string {
	return 'normal';
}

/**
 * Return the SLA first-response target in hours for a given priority.
 *
 * @param string $priority
 * @return int  Hours.
 */
function dtb_support_sla_hours( string $priority ): int {
	$default_hours = 24;

	// Support command center target: first response within one business day.
	return (int) apply_filters( 'dtb_support_sla_hours', $default_hours, $priority );
}

/**
 * Compute the SLA state ('ok'|'warning'|'breach') for a ticket.
 *
 * @param string $created_at   ISO-8601 / MySQL datetime.
 * @param string $priority
 * @param bool   $is_resolved  Whether the ticket is already resolved/closed.
 * @return string  'ok' | 'warning' | 'breach'
 */
function dtb_support_sla_state( string $created_at, string $priority, bool $is_resolved = false ): string {
	if ( $is_resolved ) {
		return 'ok';
	}
	$sla_hours = dtb_support_sla_hours( $priority );
	$age_hours = ( time() - strtotime( $created_at ) ) / 3600;

	if ( $age_hours >= $sla_hours ) {
		return 'breach';
	}
	if ( $age_hours >= ( $sla_hours * 0.75 ) ) {
		return 'warning';
	}
	return 'ok';
}
