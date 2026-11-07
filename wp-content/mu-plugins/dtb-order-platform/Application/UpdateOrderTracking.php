<?php
/**
 * DTB Update Order Tracking — application command to update tracking info and refresh projection.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_application_update_order_tracking( int $order_id, string $tracking_number, string $carrier ): void {
	$int_state = dtb_order_get_integration_state( $order_id );

	$int_state['veeqo']['tracking'] = sanitize_text_field( $tracking_number );
	$int_state['veeqo']['carrier']  = sanitize_text_field( $carrier );

	dtb_order_update_integration_state( $order_id, 'veeqo', $int_state['veeqo'] );

	dtb_application_refresh_order_projection( $order_id );
}
