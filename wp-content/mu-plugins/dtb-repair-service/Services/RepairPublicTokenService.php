<?php
/**
 * Services — RepairPublicTokenService: public tracking token management.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Generate a new public tracking token for a repair.
 *
 * @return string
 */
function dtb_repair_generate_public_token(): string {
	return wp_generate_password( 32, false, false );
}

/**
 * Ensure a repair has a public tracking token and return it.
 *
 * @param int $repair_id
 * @return string
 */
function dtb_repair_ensure_public_token( int $repair_id ): string {
	$token = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_public_token', true ) );
	if ( '' !== $token ) {
		return $token;
	}

	$token = dtb_repair_generate_public_token();
	update_post_meta( $repair_id, '_repair_public_token', $token );

	return $token;
}
