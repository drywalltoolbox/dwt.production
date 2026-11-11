<?php
/**
 * DTB Integrations — Veeqo Webhook Echo Guard.
 *
 * Prevents inbound Veeqo webhook-driven WooCommerce status updates from queuing
 * an immediate outbound status update back to Veeqo.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

remove_action( 'woocommerce_order_status_changed', 'dtb_operational_pipeline_route_veeqo_status_change', 20 );
add_action( 'woocommerce_order_status_changed', 'dtb_operational_pipeline_route_veeqo_status_change_guarded', 20, 4 );

if ( ! function_exists( 'dtb_operational_pipeline_route_veeqo_status_change_guarded' ) ) {
	/**
	 * Guarded Veeqo status-change router.
	 *
	 * @param int    $order_id   Woo order ID.
	 * @param string $old_status Previous status.
	 * @param string $new_status New status.
	 * @param mixed  $order      Woo order object.
	 */
	function dtb_operational_pipeline_route_veeqo_status_change_guarded( int $order_id, string $old_status, string $new_status, $order ): void {
		if ( get_transient( 'dtb_veeqo_webhook_updating_order_' . $order_id ) ) {
			return;
		}

		if ( function_exists( 'dtb_operational_pipeline_route_veeqo_status_change' ) ) {
			dtb_operational_pipeline_route_veeqo_status_change( $order_id, $old_status, $new_status, $order );
		}
	}
}
