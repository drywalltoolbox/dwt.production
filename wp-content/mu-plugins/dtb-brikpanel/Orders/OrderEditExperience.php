<?php
/**
 * Opt-in structural presentation adapter for the WooCommerce order editor.
 *
 * This adapter only relocates existing WooCommerce postboxes inside the same
 * order form. WooCommerce retains field, save, status, note and refund behavior.
 * Keep disabled until rendered and interaction checks pass on staging.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'admin_body_class', 'dtb_brikpanel_order_edit_workspace_body_class' );
add_action( 'admin_enqueue_scripts', 'dtb_brikpanel_order_edit_workspace_assets', 60 );

function dtb_brikpanel_order_edit_workspace_is_screen(): bool {
	if ( ! is_admin() ) {
		return false;
	}

	$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$post   = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( 'wc-orders' === $page && 'edit' === $action ) {
		return true;
	}

	return $post > 0 && function_exists( 'wc_get_order' ) && wc_get_order( $post ) instanceof WC_Order;
}

function dtb_brikpanel_order_edit_workspace_body_class( string $classes ): string {
	if ( function_exists( 'dtb_feature_enabled' )
		&& dtb_feature_enabled( 'DTB_ORDER_EDIT_WORKSPACE_V2', true )
		&& dtb_brikpanel_order_edit_workspace_is_screen() ) {
		$classes .= ' dtb-order-edit-workspace-enabled';
	}

	return $classes;
}

function dtb_brikpanel_order_edit_workspace_assets(): void {
	if ( ! function_exists( 'dtb_feature_enabled' )
		|| ! dtb_feature_enabled( 'DTB_ORDER_EDIT_WORKSPACE_V2', true )
		|| ! dtb_brikpanel_order_edit_workspace_is_screen()
		|| ! current_user_can( 'edit_shop_orders' ) ) {
		return;
	}

	$base = __DIR__ . '/assets/';
	$uri  = content_url( 'mu-plugins/dtb-brikpanel/Orders/assets/' );

	foreach ( [ 'order-edit.css' => 'dtb-order-edit-workspace', 'order-edit.js' => 'dtb-order-edit-workspace' ] as $file => $handle ) {
		if ( ! is_readable( $base . $file ) ) {
			continue;
		}

		$version = (string) filemtime( $base . $file );
		if ( '.css' === substr( $file, -4 ) ) {
			wp_enqueue_style( $handle, $uri . $file, [], $version );
		} else {
			wp_enqueue_script( $handle, $uri . $file, [], $version, true );
		}
	}
}
