<?php
/**
 * Opt-in presentation adapter for WooCommerce's native order preview modal.
 *
 * WooCommerce remains the data and modal-lifecycle authority. This module only
 * composes its existing preview markup and read-only order data.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/QuickViewRenderer.php';

/** Whether the independently gated quick-view layout is enabled. */
function dtb_order_quick_view_v2_enabled(): bool {
	return function_exists( 'dtb_feature_enabled' )
		&& dtb_feature_enabled( 'DTB_ORDER_QUICK_VIEW_V2', true );
}

/** Restrict the presentation scope to WooCommerce's order-list screen. */
function dtb_order_quick_view_is_orders_list_screen(): bool {
	if ( ! is_admin() || ! current_user_can( 'edit_shop_orders' ) ) {
		return false;
	}

	$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'wc-orders' === $page && '' === $action ) {
		return true;
	}

	if ( function_exists( 'get_current_screen' ) ) {
		$screen = get_current_screen();
		return $screen && 'edit-shop_order' === $screen->id;
	}

	return false;
}

/** Add a stable body scope only to the orders list where the modal is opened. */
add_filter( 'admin_body_class', static function ( string $classes ): string {
	if ( dtb_order_quick_view_v2_enabled() && dtb_order_quick_view_is_orders_list_screen() ) {
		$classes .= ' dtb-order-quick-view-v2';
	}

	return $classes;
} );

/** Load the modal presentation layer only on the authorized order-list screen. */
add_action( 'admin_enqueue_scripts', static function (): void {
	if ( ! dtb_order_quick_view_v2_enabled() || ! dtb_order_quick_view_is_orders_list_screen() ) {
		return;
	}

	$base = __DIR__ . '/assets/';
	$uri  = content_url( 'mu-plugins/dtb-brikpanel/Orders/assets/' );
	$css  = $base . 'order-quick-view.css';
	$js   = $base . 'order-quick-view.js';

	if ( is_readable( $css ) ) {
		wp_enqueue_style( 'dtb-order-quick-view-v2', $uri . 'order-quick-view.css', [], (string) filemtime( $css ) );
	}
	if ( is_readable( $js ) ) {
		wp_enqueue_script( 'dtb-order-quick-view-v2', $uri . 'order-quick-view.js', [], (string) filemtime( $js ), true );
	}
}, 50 );
