<?php
/**
 * DTB Order Event Stream Controller — REST handler for SSE event stream endpoint.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_order_rest_event_stream( WP_REST_Request $request ): void {
	$order_id = (int) $request->get_param( 'id' );

	header( 'Content-Type: text/event-stream; charset=UTF-8' );
	header( 'Cache-Control: no-cache' );
	header( 'X-Accel-Buffering: no' );

	$projection  = dtb_order_get_tracking_projection( $order_id );
	$status_proj = $projection ? dtb_order_build_status_projection( $order_id ) : null;

	if ( $projection && $status_proj ) {
		$frame = wp_json_encode( [
			'status'      => $status_proj['status'],
			'label'       => $status_proj['label'],
			'occurred_at' => current_time( 'c', true ),
			'is_terminal' => $status_proj['is_terminal'],
			'timeline'    => $projection['timeline'],
		] );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "event: order.status_changed\n";
		echo "data: {$frame}\n\n";
		// phpcs:enable

		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();
	} else {
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "event: error\n";
		echo "data: {\"message\":\"Order not found\"}\n\n";
		// phpcs:enable
		flush();
	}

	wp_die( '', '', [ 'response' => 200 ] );
}
