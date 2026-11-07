<?php
/**
 * DTB Order Bulk Actions — AJAX operator action handler.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_ajax_dtb_order_operator_action', 'dtb_order_admin_ajax_operator_action' );

function dtb_order_admin_ajax_operator_action(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'drywall-toolbox' ) ], 403 );
		return;
	}

	$order_id   = (int) ( $_POST['order_id'] ?? 0 );
	$nonce      = sanitize_text_field( (string) ( $_POST['nonce'] ?? '' ) );
	$dtb_action = sanitize_key( (string) ( $_POST['dtb_action'] ?? '' ) );

	if ( ! wp_verify_nonce( $nonce, 'dtb_order_admin_' . $order_id ) ) {
		wp_send_json_error( [ 'message' => __( 'Security check failed.', 'drywall-toolbox' ) ], 403 );
		return;
	}

	if ( $order_id <= 0 ) {
		wp_send_json_error( [ 'message' => __( 'Invalid order ID.', 'drywall-toolbox' ) ] );
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		wp_send_json_error( [ 'message' => __( 'Order not found.', 'drywall-toolbox' ) ] );
		return;
	}

	$actor_id = get_current_user_id();

	switch ( $dtb_action ) {
		case 'retry_veeqo':
			dtb_order_enqueue_job( 'dtb_order_sync_veeqo', $order_id );
			dtb_order_append_event( $order_id, 'integration.veeqo.queued', [
				'source'     => 'wp_admin',
				'actor_type' => 'admin',
				'actor_id'   => $actor_id,
				'visibility' => 'operator',
			] );
			wp_send_json_success( [ 'message' => __( 'Veeqo sync re-queued.', 'drywall-toolbox' ) ] );
			break;

		case 'retry_quickbooks':
			dtb_order_enqueue_job( 'dtb_order_sync_quickbooks', $order_id, [ 'action' => 'create' ] );
			dtb_order_append_event( $order_id, 'integration.quickbooks.queued', [
				'source'     => 'wp_admin',
				'actor_type' => 'admin',
				'actor_id'   => $actor_id,
				'visibility' => 'operator',
			] );
			wp_send_json_success( [ 'message' => __( 'QuickBooks sync re-queued.', 'drywall-toolbox' ) ] );
			break;

		case 'refresh_tracking':
			dtb_order_enqueue_job( 'dtb_order_refresh_tracking_projection', $order_id );
			wp_send_json_success( [ 'message' => __( 'Tracking projection refresh queued.', 'drywall-toolbox' ) ] );
			break;

		case 'resend_confirm':
			dtb_order_release_notification_send( $order_id, 'order-confirmation' );
			dtb_order_enqueue_job( 'dtb_order_send_notification', $order_id, [ 'template' => 'order-confirmation' ] );
			wp_send_json_success( [ 'message' => __( 'Order confirmation re-queued.', 'drywall-toolbox' ) ] );
			break;

		case 'resend_shipped':
			dtb_order_enqueue_job( 'dtb_order_send_notification', $order_id, [ 'template' => 'order-shipped' ] );
			wp_send_json_success( [ 'message' => __( 'Shipping email re-queued.', 'drywall-toolbox' ) ] );
			break;

		case 'recalc_rewards':
			wp_send_json_error( [ 'message' => __( 'Rewards workflows are disabled for the current launch.', 'drywall-toolbox' ) ], 409 );
			break;

		default:
			wp_send_json_error( [ 'message' => __( 'Unknown action.', 'drywall-toolbox' ) ] );
	}
}
