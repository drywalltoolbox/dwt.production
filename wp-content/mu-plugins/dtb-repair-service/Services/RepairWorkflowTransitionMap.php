<?php
/**
 * Services — RepairWorkflowTransitionMap: allowed status transition map.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the allowed status transition map.
 *
 * @return array<string, string[]>
 */
function dtb_get_allowed_transitions(): array {
static $map = null;
if ( null !== $map ) {
return $map;
}

$map = [
'submitted'         => [ 'reviewed', 'awaiting_customer', 'cancelled' ],
'reviewed'          => [ 'approved', 'quoted', 'awaiting_customer', 'cancelled' ],
'awaiting_customer' => [ 'reviewed', 'cancelled' ],
'approved'          => [ 'quoted', 'parts_allocated', 'cancelled' ],
'quoted'            => [ 'quote_accepted', 'quote_declined', 'cancelled' ],
'quote_accepted'    => [ 'parts_allocated', 'cancelled' ],
'quote_declined'    => [],
'parts_allocated'   => [ 'in_progress', 'cancelled' ],
'in_progress'       => [ 'ready_to_ship', 'cancelled' ],
'ready_to_ship'     => [ 'completed', 'cancelled' ],
'completed'         => [ 'closed' ],
'closed'            => [],
'cancelled'         => [],
];

return $map;
}
