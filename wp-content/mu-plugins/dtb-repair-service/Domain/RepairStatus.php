<?php
/**
 * Domain — RepairStatus: status labels and read helper.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the customer-facing label for a repair status.
 *
 * @param string $status Internal status slug.
 * @return string Human-readable label.
 */
function dtb_get_repair_status_label( string $status ): string {
$labels = [
'submitted'        => __( 'Submitted', 'drywall-toolbox' ),
'reviewed'         => __( 'Under Review', 'drywall-toolbox' ),
'awaiting_customer'=> __( 'Waiting on Customer', 'drywall-toolbox' ),
'approved'         => __( 'Approved', 'drywall-toolbox' ),
'quoted'           => __( 'Quote Sent', 'drywall-toolbox' ),
'quote_accepted'   => __( 'Quote Accepted', 'drywall-toolbox' ),
'quote_declined'   => __( 'Quote Declined', 'drywall-toolbox' ),
'parts_allocated'  => __( 'Parts Allocated', 'drywall-toolbox' ),
'in_progress'      => __( 'Repair In Progress', 'drywall-toolbox' ),
'ready_to_ship'    => __( 'Ready to Ship', 'drywall-toolbox' ),
'completed'        => __( 'Completed', 'drywall-toolbox' ),
'closed'           => __( 'Closed', 'drywall-toolbox' ),
'cancelled'        => __( 'Cancelled', 'drywall-toolbox' ),
];

return $labels[ $status ] ?? ucwords( str_replace( '_', ' ', $status ) );
}

/**
 * Return all valid repair status slugs.
 *
 * @return string[]
 */
function dtb_get_all_repair_statuses(): array {
return array_values( array_unique(
array_merge( array_keys( dtb_get_allowed_transitions() ), [ 'closed', 'cancelled', 'quote_declined' ] )
) );
}

/**
 * Return the terminal statuses (no further transitions allowed).
 *
 * @return string[]
 */
function dtb_get_terminal_repair_statuses(): array {
return [ 'closed', 'cancelled', 'quote_declined' ];
}

/**
 * Return the current status slug for a repair.
 *
 * @param int $repair_id
 * @return string  Empty string if the repair doesn't exist or has no status yet.
 */
function dtb_get_repair_status( int $repair_id ): string {
return (string) get_post_meta( $repair_id, '_repair_status', true );
}
