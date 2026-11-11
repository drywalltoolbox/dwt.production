<?php
/**
 * DTB Platform — Cache Tools toolbar link.
 *
 * Cache execution is intentionally owned by the canonical Tool Library page.
 * The toolbar provides navigation only; it does not register another purge
 * endpoint, nonce contract, result transient, or competing provider control.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_bar_menu', 'dtb_admin_cache_toolbar_register', 10000 );

/**
 * Register a navigation link to the single cache operations surface.
 *
 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
 */
function dtb_admin_cache_toolbar_register( WP_Admin_Bar $wp_admin_bar ): void {
	if ( ! current_user_can( 'dtb_manage_cache_tools' ) ) {
		return;
	}

	$wp_admin_bar->add_node(
		[
			'id'    => 'dtb-cache-tools',
			'title' => __( 'Cache Tools', 'drywall-toolbox' ),
			'href'  => admin_url( 'admin.php?page=dtb-cache-tools' ),
			'meta'  => [
				'title' => __( 'Open the DTB Cache Tools control surface.', 'drywall-toolbox' ),
			],
		]
	);
}
