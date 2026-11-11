<?php
/**
 * DTB Refresh Order Projection — application command to invalidate cache and rebuild projection.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_application_refresh_order_projection( int $order_id ): ?array {
	$cache_key = 'dtb_order_tracking_' . $order_id;
	delete_transient( $cache_key );

	return dtb_application_build_tracking_projection( $order_id );
}
