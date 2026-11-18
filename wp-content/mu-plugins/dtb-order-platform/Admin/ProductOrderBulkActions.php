<?php
/**
 * DTB Product Order Bulk Actions — product-order-specific bulk actions for shop_order list.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'bulk_actions-edit-shop_order', 'dtb_product_order_admin_bulk_actions' );

function dtb_product_order_admin_bulk_actions( array $actions ): array {
	$actions['dtb_retry_veeqo']      = __( 'DTB: Retry Veeqo Sync', 'drywall-toolbox' );
	$actions['dtb_retry_quickbooks'] = __( 'DTB: Retry QuickBooks Sync', 'drywall-toolbox' );
	$actions['dtb_refresh_tracking'] = __( 'DTB: Refresh Tracking Projection', 'drywall-toolbox' );
	return $actions;
}
