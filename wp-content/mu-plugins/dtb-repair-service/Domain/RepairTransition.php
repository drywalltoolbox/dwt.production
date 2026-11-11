<?php
/**
 * Domain — RepairTransition: transition validation.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return true if a transition from $from to $to is valid.
 *
 * @param string $from Current status.
 * @param string $to   Proposed status.
 * @return bool
 */
function dtb_is_valid_repair_transition( string $from, string $to ): bool {
$map = dtb_get_allowed_transitions();

if ( ! isset( $map[ $from ] ) ) {
return false;
}

return in_array( $to, $map[ $from ], true );
}
