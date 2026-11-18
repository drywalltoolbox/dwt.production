<?php
/**
 * Domain — TicketStatus: status labels, transitions, and terminal-state helpers.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// STATUS CONSTANTS
// ---------------------------------------------------------------------------

if ( ! defined( 'DTB_SUPPORT_STATUS_OPEN' ) )             define( 'DTB_SUPPORT_STATUS_OPEN',             'open' );
if ( ! defined( 'DTB_SUPPORT_STATUS_PENDING_CUSTOMER' ) ) define( 'DTB_SUPPORT_STATUS_PENDING_CUSTOMER', 'pending_customer' );
if ( ! defined( 'DTB_SUPPORT_STATUS_PENDING_STAFF' ) )    define( 'DTB_SUPPORT_STATUS_PENDING_STAFF',    'pending_staff' );
if ( ! defined( 'DTB_SUPPORT_STATUS_IN_PROGRESS' ) )      define( 'DTB_SUPPORT_STATUS_IN_PROGRESS',      'in_progress' );
if ( ! defined( 'DTB_SUPPORT_STATUS_RESOLVED' ) )         define( 'DTB_SUPPORT_STATUS_RESOLVED',         'resolved' );
if ( ! defined( 'DTB_SUPPORT_STATUS_CLOSED' ) )           define( 'DTB_SUPPORT_STATUS_CLOSED',           'closed' );
if ( ! defined( 'DTB_SUPPORT_STATUS_SPAM' ) )             define( 'DTB_SUPPORT_STATUS_SPAM',             'spam' );
if ( ! defined( 'DTB_SUPPORT_STATUS_DELETED' ) )          define( 'DTB_SUPPORT_STATUS_DELETED',          'deleted' );

// ---------------------------------------------------------------------------
// STATUS REGISTRY
// ---------------------------------------------------------------------------

/**
 * Return every valid ticket status slug mapped to its customer-facing label.
 *
 * @return array<string,string>
 */
function dtb_support_all_statuses(): array {
	return [
		'open'             => __( 'Open',                'drywall-toolbox' ),
		'pending_customer' => __( 'Waiting on Customer', 'drywall-toolbox' ),
		'pending_staff'    => __( 'Waiting on Staff',    'drywall-toolbox' ),
		'in_progress'      => __( 'In Progress',         'drywall-toolbox' ),
		'resolved'         => __( 'Resolved',            'drywall-toolbox' ),
		'closed'           => __( 'Closed',              'drywall-toolbox' ),
		'spam'             => __( 'Spam',                'drywall-toolbox' ),
		'deleted'          => __( 'Deleted',             'drywall-toolbox' ),
	];
}

/**
 * Return the human-readable label for a status slug.
 *
 * @param string $status
 * @return string
 */
function dtb_support_status_label( string $status ): string {
	$all = dtb_support_all_statuses();
	return $all[ $status ] ?? ucwords( str_replace( '_', ' ', $status ) );
}

/**
 * Return all statuses that block further operator-initiated transitions (terminal).
 *
 * @return string[]
 */
function dtb_support_terminal_statuses(): array {
	return [ 'closed', 'spam', 'deleted' ];
}

/**
 * Return whether a status is terminal.
 *
 * @param string $status
 * @return bool
 */
function dtb_support_is_terminal( string $status ): bool {
	return in_array( $status, dtb_support_terminal_statuses(), true );
}

/**
 * Return the allowed transition map: from_status => string[] of allowed to_statuses.
 *
 * @return array<string,string[]>
 */
function dtb_support_allowed_transitions(): array {
	return [
		'open'             => [ 'pending_customer', 'pending_staff', 'in_progress', 'resolved', 'closed', 'spam' ],
		'pending_customer' => [ 'open', 'pending_staff', 'in_progress', 'resolved', 'closed', 'spam' ],
		'pending_staff'    => [ 'open', 'pending_customer', 'in_progress', 'resolved', 'closed', 'spam' ],
		'in_progress'      => [ 'pending_customer', 'pending_staff', 'resolved', 'closed', 'spam' ],
		'resolved'         => [ 'open', 'closed' ],
		'closed'           => [ 'open' ],   // re-open
		'spam'             => [ 'open' ],   // un-spam
		'deleted'          => [ 'open' ],   // restore
	];
}

/**
 * Return whether a transition is valid.
 *
 * @param string $from
 * @param string $to
 * @return bool
 */
function dtb_support_is_valid_transition( string $from, string $to ): bool {
	if ( $from === $to ) {
		return false;
	}
	$map = dtb_support_allowed_transitions();
	return in_array( $to, $map[ $from ] ?? [], true );
}

/**
 * Return the current status for a ticket post.
 *
 * @param int $ticket_id
 * @return string  Empty string if not found.
 */
function dtb_support_get_ticket_status( int $ticket_id ): string {
	return (string) get_post_meta( $ticket_id, '_dtb_support_status', true );
}

/**
 * Return a CSS class suffix for a status (used in admin badge rendering).
 *
 * @param string $status
 * @return string
 */
function dtb_support_status_css( string $status ): string {
	$map = [
		'open'             => 'open',
		'pending_customer' => 'pending',
		'pending_staff'    => 'pending',
		'in_progress'      => 'progress',
		'resolved'         => 'resolved',
		'closed'           => 'closed',
		'spam'             => 'spam',
		'deleted'          => 'closed',
	];
	return $map[ $status ] ?? 'open';
}
