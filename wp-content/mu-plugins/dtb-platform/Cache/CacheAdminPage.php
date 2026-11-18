<?php
/**
 * Legacy Cache Tools route compatibility.
 *
 * The former Tools > DTB Cache page duplicated the Tool Library cache UI,
 * capability boundary, POST actions, result rendering, and provider status.
 * It is intentionally retired. Existing bookmarks are redirected to the
 * canonical Drywall Toolbox > Cache Tools page; no menu or mutation handler
 * is registered here.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_init', 'dtb_redirect_legacy_cache_tools_route' );

/** Redirect the retired page slug without preserving a second control plane. */
function dtb_redirect_legacy_cache_tools_route(): void {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	if ( 'dtb-cache-settings' !== $page ) {
		return;
	}

	if ( ! current_user_can( 'dtb_manage_cache_tools' ) ) {
		wp_die(
			esc_html__( 'Unauthorized.', 'drywall-toolbox' ),
			'',
			[ 'response' => 403 ]
		);
	}

	wp_safe_redirect( admin_url( 'admin.php?page=dtb-cache-tools' ), 301 );
	exit;
}
