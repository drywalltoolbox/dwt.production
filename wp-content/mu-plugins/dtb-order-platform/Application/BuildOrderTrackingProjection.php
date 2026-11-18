<?php
/**
 * DTB Build Order Tracking Projection — application command to build and persist tracking projection.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_application_build_tracking_projection( int $order_id ): ?array {
	$projection = dtb_order_build_tracking_projection( $order_id );

	if ( is_array( $projection ) ) {
		update_post_meta( $order_id, '_dtb_tracking_projection', $projection );
		update_post_meta( $order_id, '_dtb_tracking_projection_built_at', current_time( 'mysql', true ) );
	}

	return $projection;
}
