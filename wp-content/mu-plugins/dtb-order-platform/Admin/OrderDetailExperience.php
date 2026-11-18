<?php
/**
 * Scoped presentation layer for WooCommerce order-detail screens.
 *
 * WooCommerce retains ownership of order fields, persistence, status, notes,
 * refunds, payment data, and HPOS behavior. This module adds only a body class
 * and a versioned stylesheet for the native order editor and DTB metaboxes.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'admin_body_class', 'dtb_order_detail_admin_body_class' );
add_action( 'admin_enqueue_scripts', 'dtb_order_detail_enqueue_styles', 40 );

/**
 * Determine whether the current request is a WooCommerce order edit screen.
 */
function dtb_order_detail_is_current_screen(): bool {
	if ( ! is_admin() ) {
		return false;
	}

	$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$post   = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( 'wc-orders' === $page && 'edit' === $action ) {
		return true;
	}

	if ( $post > 0 && function_exists( 'wc_get_order' ) ) {
		return wc_get_order( $post ) instanceof WC_Order;
	}

	return false;
}

/**
 * Add a stable scope for repository and BrikPanel custom CSS overrides.
 */
function dtb_order_detail_admin_body_class( string $classes ): string {
	if ( dtb_order_detail_is_current_screen() ) {
		$classes .= ' dtb-order-detail-screen';
		if ( ! function_exists( 'dtb_feature_enabled' ) || dtb_feature_enabled( 'DTB_ORDER_DETAIL_REFINEMENT', true ) ) {
			$classes .= ' dtb-order-detail-refined';
		}
	}

	return $classes;
}

/**
 * Enqueue only on native WooCommerce order-edit requests.
 */
function dtb_order_detail_enqueue_styles(): void {
	if ( ! dtb_order_detail_is_current_screen() || ! current_user_can( 'edit_shop_orders' ) ) {
		return;
	}

	$path = __DIR__ . '/assets/order-detail.css';
	if ( ! is_readable( $path ) ) {
		return;
	}

	wp_enqueue_style(
		'dtb-order-detail-experience',
		content_url( 'mu-plugins/dtb-order-platform/Admin/assets/order-detail.css' ),
		[],
		(string) filemtime( $path )
	);
}
