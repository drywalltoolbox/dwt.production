<?php
/**
 * DTB Order Dashboard Panel — WP dashboard widget for recent DTB order events.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_dashboard_setup', 'dtb_order_admin_register_dashboard_widget' );

function dtb_order_admin_register_dashboard_widget(): void {
	wp_add_dashboard_widget(
		'dtb_order_recent_events',
		__( 'Recent DTB Order Events', 'drywall-toolbox' ),
		'dtb_order_admin_dashboard_widget_render'
	);
}

function dtb_order_admin_dashboard_widget_render(): void {
	global $wpdb;

	$table = $wpdb->prefix . 'dtb_order_events';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$events = $wpdb->get_results(
		"SELECT order_id, event_type, actor_type, created_at FROM {$table} ORDER BY id DESC LIMIT 10"
	);

	$styles = '
		<style>
		.dtb-widget-table { width:100%; border-collapse:collapse; font-size:12px; }
		.dtb-widget-table th { text-align:left; padding:5px 8px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#6b7280; border-bottom:1px solid #e5e7eb; background:#f9fafb; }
		.dtb-widget-table td { padding:7px 8px; border-bottom:1px solid #f3f4f6; vertical-align:middle; color:#111827; }
		.dtb-widget-table tr:last-child td { border-bottom:none; }
		.dtb-widget-table tr:hover td { background:#f5f8ff; }
		.dtb-widget-table a { color:#1d4ed8; font-weight:600; text-decoration:none; }
		.dtb-widget-table a:hover { text-decoration:underline; }
		.dtb-widget-evt { display:inline-block; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:5px; padding:2px 6px; font-size:10px; font-weight:600; color:#1e293b; font-family:monospace; white-space:nowrap; }
		.dtb-widget-time { color:#9ca3af; font-size:11px; }
		</style>
	';
	echo $styles; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	if ( empty( $events ) ) {
		echo '<p style="color:#9ca3af;font-size:12px;">' . esc_html__( 'No events recorded yet.', 'drywall-toolbox' ) . '</p>';
		return;
	}

	echo '<div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">';
	echo '<table class="dtb-widget-table">';
	echo '<thead><tr>'
		. '<th>' . esc_html__( 'Order', 'drywall-toolbox' ) . '</th>'
		. '<th>' . esc_html__( 'Event', 'drywall-toolbox' ) . '</th>'
		. '<th>' . esc_html__( 'Actor', 'drywall-toolbox' ) . '</th>'
		. '<th>' . esc_html__( 'Time (UTC)', 'drywall-toolbox' ) . '</th>'
		. '</tr></thead><tbody>';

	foreach ( $events as $row ) {
		$order_url = admin_url( 'post.php?post=' . absint( $row->order_id ) . '&action=edit' );
		echo '<tr>'
			. '<td><a href="' . esc_url( $order_url ) . '">#' . esc_html( (string) $row->order_id ) . '</a></td>'
			. '<td><span class="dtb-widget-evt">' . esc_html( (string) $row->event_type ) . '</span></td>'
			. '<td style="color:#6b7280;">' . esc_html( (string) $row->actor_type ) . '</td>'
			. '<td><span class="dtb-widget-time">' . esc_html( (string) $row->created_at ) . '</span></td>'
			. '</tr>';
	}

	echo '</tbody></table></div>';
}
