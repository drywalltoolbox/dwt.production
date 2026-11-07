<?php
/**
 * Validation — RepairAccessValidator: gate REST repair access via public token or WP auth.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validate REST access to a specific repair by ID.
 *
 * Checks:
 *  1. Post exists and is the correct type.
 *  2. Either WP user is authenticated, OR a valid public token is supplied.
 *  3. Origin is allowed (delegates to dtb_check_origin).
 *
 * @param int    $repair_id
 * @param string $public_token Optional public tracking token from query string.
 * @return true|WP_Error
 */
function dtb_validate_repair_access( int $repair_id, string $public_token = '' ): bool|WP_Error {
$post = get_post( $repair_id );

if ( ! $post || 'dtb_repair_request' !== $post->post_type ) {
return new WP_Error(
'dtb_repair_not_found',
__( 'Repair not found.', 'drywall-toolbox' ),
[ 'status' => 404 ]
);
}

if ( is_user_logged_in() && current_user_can( 'dtb_manage_repairs' ) ) {
return true;
}

if ( '' !== $public_token ) {
$stored = (string) get_post_meta( $repair_id, '_repair_public_token', true );
if ( $stored !== '' && hash_equals( $stored, $public_token ) ) {
return true;
}
}

if ( function_exists( 'dtb_check_origin' ) ) {
$origin_ok = dtb_check_origin();
if ( is_wp_error( $origin_ok ) ) {
return $origin_ok;
}
}

return new WP_Error(
'dtb_repair_access_denied',
__( 'Access denied.', 'drywall-toolbox' ),
[ 'status' => 403 ]
);
}
