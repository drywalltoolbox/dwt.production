<?php
/**
 * DTB Order Admin Menu — metabox registration for WooCommerce order detail screens.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'add_meta_boxes', 'dtb_order_admin_register_metaboxes', 30 );

function dtb_order_admin_register_metaboxes(): void {
	$screens = [ 'shop_order', 'woocommerce_page_wc-orders' ];

	foreach ( $screens as $screen ) {
		add_meta_box(
			'dtb-order-timeline',
			__( 'DTB Order Timeline', 'drywall-toolbox' ),
			'dtb_order_admin_metabox_timeline',
			$screen,
			'normal',
			'high'
		);

		add_meta_box(
			'dtb-integration-state',
			__( 'DTB Integration State', 'drywall-toolbox' ),
			'dtb_order_admin_metabox_integration',
			$screen,
			'side',
			'default'
		);

		add_meta_box(
			'dtb-operator-actions',
			__( 'DTB Operator Actions', 'drywall-toolbox' ),
			'dtb_order_admin_metabox_actions',
			$screen,
			'side',
			'low'
		);
	}
}
