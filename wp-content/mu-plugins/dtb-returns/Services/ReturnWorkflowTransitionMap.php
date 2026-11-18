<?php
/**
 * DTB Returns — Workflow Transition Map
 *
 * Authoritative definition of which status transitions are permitted for a
 * returns record. Keep this map aligned with the admin-workbench implementation
 * plan and the customer-impacting returns workflow.
 *
 * @package DTB_Returns
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the allowed next statuses for a given current status.
 *
 * The directed workflow is intentionally narrow:
 *
 *   pending_review → approved | rejected | closed
 *   approved       → awaiting_item | closed
 *   awaiting_item  → item_received | closed
 *   item_received  → refund_issued | exchange_sent | closed
 *   refund_issued  → closed
 *   exchange_sent  → closed
 *   rejected       → closed
 *   closed         → terminal
 *
 * @param  string $current_status  Current return status slug.
 * @return string[]                Allowed next status slugs (empty = terminal).
 */
function dtb_return_get_allowed_transitions( string $current_status ): array {
	static $map = null;

	if ( null === $map ) {
		$map = [
			'pending_review' => [ 'approved', 'rejected', 'closed' ],
			'approved'       => [ 'awaiting_item', 'closed' ],
			'awaiting_item'  => [ 'item_received', 'closed' ],
			'item_received'  => [ 'refund_issued', 'exchange_sent', 'closed' ],
			'refund_issued'  => [ 'closed' ],
			'exchange_sent'  => [ 'closed' ],
			'rejected'       => [ 'closed' ],
			'closed'         => [],
		];
	}

	return $map[ $current_status ] ?? [];
}

/**
 * Validates whether a status transition is permitted.
 *
 * @param  string $from  Current status slug.
 * @param  string $to    Requested next status slug.
 * @return bool          True if the transition is allowed.
 */
function dtb_return_is_valid_transition( string $from, string $to ): bool {
	return in_array( $to, dtb_return_get_allowed_transitions( $from ), true );
}
