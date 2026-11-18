<?php
/**
 * Services — TicketAutoAssignService: legacy assignment compatibility.
 *
 * Support is currently operated by a single admin, so ticket assignment is
 * intentionally disabled. These functions remain as no-op compatibility
 * shims for older integrations that may still call them.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Legacy option key from the removed assignment workflow.
 */
const DTB_SUPPORT_ASSIGN_POINTER_KEY = 'dtb_support_assign_pointer';

/**
 * Return eligible support agent IDs.
 *
 * @return int[]
 */
function dtb_support_get_agents(): array {
	return [];
}

/**
 * Legacy auto-assignment shim.
 */
function dtb_support_auto_assign( int $ticket_id, string $ticket_type = 'contact' ): int {
	return 0;
}

/**
 * Legacy manual assignment shim.
 */
function dtb_support_assign_ticket( int $ticket_id, int $user_id ): bool|WP_Error {
	return new WP_Error(
		'dtb_support_assignment_disabled',
		__( 'Support ticket assignment is disabled for the current single-admin workflow.', 'drywall-toolbox' )
	);
}
