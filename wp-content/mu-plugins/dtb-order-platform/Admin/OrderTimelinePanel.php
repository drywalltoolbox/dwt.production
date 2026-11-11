<?php
/**
 * DTB Order Timeline Panel — renders the DTB Order Timeline metabox.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_order_admin_metabox_timeline( $post_or_order ): void {
	$order_id = $post_or_order instanceof WC_Order ? (int) $post_or_order->get_id() : (int) $post_or_order->ID;
	$events   = dtb_order_get_events( $order_id, [ 'order' => 'ASC', 'limit' => 200 ] );

	$nonce = wp_create_nonce( 'dtb_order_admin_' . $order_id );
	echo '<input type="hidden" id="dtb-order-nonce" value="' . esc_attr( $nonce ) . '">';
	echo '<input type="hidden" id="dtb-order-id" value="' . esc_attr( (string) $order_id ) . '">';

	if ( empty( $events ) ) {
		echo '<p style="color:#888;">' . esc_html__( 'No events recorded yet.', 'drywall-toolbox' ) . '</p>';
		return;
	}

	$vis_labels = [
		'customer' => '<span style="background:#d1fae5;color:#065f46;padding:1px 6px;border-radius:3px;font-size:10px;">customer</span>',
		'operator' => '<span style="background:#dbeafe;color:#1e3a8a;padding:1px 6px;border-radius:3px;font-size:10px;">operator</span>',
		'internal' => '<span style="background:#f3f4f6;color:#374151;padding:1px 6px;border-radius:3px;font-size:10px;">internal</span>',
	];

	echo '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
	echo '<thead><tr style="border-bottom:1px solid #ddd;">';
	echo '<th style="text-align:left;padding:4px 6px;">' . esc_html__( 'Time (UTC)', 'drywall-toolbox' ) . '</th>';
	echo '<th style="text-align:left;padding:4px 6px;">' . esc_html__( 'Event', 'drywall-toolbox' ) . '</th>';
	echo '<th style="text-align:left;padding:4px 6px;">' . esc_html__( 'Actor', 'drywall-toolbox' ) . '</th>';
	echo '<th style="text-align:left;padding:4px 6px;">' . esc_html__( 'Source', 'drywall-toolbox' ) . '</th>';
	echo '<th style="text-align:left;padding:4px 6px;">' . esc_html__( 'Visibility', 'drywall-toolbox' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $events as $row ) {
		$vis_badge = $vis_labels[ (string) $row->visibility ] ?? esc_html( (string) $row->visibility );
		echo '<tr style="border-bottom:1px solid #f0f0f0;">';
		echo '<td style="padding:4px 6px;white-space:nowrap;">' . esc_html( (string) $row->created_at ) . '</td>';
		echo '<td style="padding:4px 6px;font-family:monospace;">' . esc_html( (string) $row->event_type ) . '</td>';
		echo '<td style="padding:4px 6px;">' . esc_html( (string) $row->actor_type ) . '</td>';
		echo '<td style="padding:4px 6px;">' . esc_html( (string) $row->source ) . '</td>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<td style="padding:4px 6px;">' . $vis_badge . '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';
}
