<?php
/**
 * URL helpers — DTB Platform.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Legacy compatibility helper.
 *
 * WooCommerce application passwords and consumer credentials are server-only.
 * They must never be returned to browser code, even when the Origin header is
 * allowlisted. Server-to-server proxy code reads its credentials directly from
 * secured runtime configuration.
 *
 * @deprecated Browser credential delivery is retired. Always returns blanks.
 * @return array{auth_user:string,auth_pass:string}
 */
function dtb_get_wc_credentials(): array {
	return [
		'auth_user' => '',
		'auth_pass' => '',
	];
}
