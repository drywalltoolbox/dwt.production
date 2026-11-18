<?php
/**
 * Capability-aware DTB quick navigation for the BrikPanel admin top bar.
 *
 * BrikPanel retains ownership of the global admin shell. This module adds one
 * progressively-enhanced navigation region using canonical WordPress admin
 * URLs and does not replace the sidebar, toolbar, search, or profile controls.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_enqueue_scripts', 'dtb_admin_global_navigation_enqueue', 90 );

/**
 * Build the visible quick-navigation items for the current operator.
 *
 * @return array<int, array{label:string,url:string,match:string,group:string}>
 */
function dtb_admin_global_navigation_items(): array {
	$items = [];

	if ( current_user_can( 'dtb_view_command_center' ) ) {
		$items[] = [ 'label' => __( 'Command Center', 'drywall-toolbox' ), 'url' => admin_url( 'admin.php?page=dtb-command-center' ), 'match' => 'dtb-command-center', 'group' => 'operations' ];
	}

	if ( current_user_can( 'manage_woocommerce' ) ) {
		$items[] = [ 'label' => __( 'WooCommerce', 'drywall-toolbox' ), 'url' => admin_url( 'admin.php?page=wc-admin' ), 'match' => 'wc-admin', 'group' => 'commerce' ];
		$items[] = [ 'label' => __( 'Veeqo', 'drywall-toolbox' ), 'url' => admin_url( 'admin.php?page=dtb-veeqo-control-center' ), 'match' => 'dtb-veeqo-control-center', 'group' => 'integrations' ];
	}

	if ( current_user_can( 'manage_options' ) ) {
		$items[] = [ 'label' => __( 'QuickBooks', 'drywall-toolbox' ), 'url' => admin_url( 'admin.php?page=dtb-quickbooks' ), 'match' => 'dtb-quickbooks', 'group' => 'integrations' ];
	}

	if ( current_user_can( 'manage_woocommerce' ) ) {
		$items[] = [ 'label' => __( 'Products', 'drywall-toolbox' ), 'url' => admin_url( 'edit.php?post_type=product' ), 'match' => 'post_type=product', 'group' => 'commerce' ];
	}

	return $items;
}

/**
 * Enqueue the bounded navigation enhancement on wp-admin screens.
 */
function dtb_admin_global_navigation_enqueue(): void {
	if ( ! is_admin() || ! is_user_logged_in() ) {
		return;
	}

	$items = dtb_admin_global_navigation_items();
	if ( empty( $items ) ) {
		return;
	}

	$assets_dir = __DIR__ . '/assets/';
	$assets_url = plugin_dir_url( __FILE__ ) . 'assets/';
	$css_path   = $assets_dir . 'dtb-admin-global-navigation.css';
	$js_path    = $assets_dir . 'dtb-admin-global-navigation.js';

	if ( is_readable( $css_path ) ) {
		wp_enqueue_style( 'dtb-admin-global-navigation', $assets_url . 'dtb-admin-global-navigation.css', [], (string) filemtime( $css_path ) );
	}

	if ( is_readable( $js_path ) ) {
		wp_enqueue_script( 'dtb-admin-global-navigation', $assets_url . 'dtb-admin-global-navigation.js', [], (string) filemtime( $js_path ), true );
		wp_add_inline_script(
			'dtb-admin-global-navigation',
			'window.DTBAdminGlobalNavigation=' . wp_json_encode(
				[
					'items'      => $items,
					'currentUrl' => esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ),
					'labels'     => [
						'navigation' => __( 'Drywall Toolbox quick navigation', 'drywall-toolbox' ),
						'more'       => __( 'More', 'drywall-toolbox' ),
					],
				]
			) . ';',
			'before'
		);
	}
}
