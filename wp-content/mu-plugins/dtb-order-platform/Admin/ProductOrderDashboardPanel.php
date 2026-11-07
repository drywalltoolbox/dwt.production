<?php
/**
 * DTB Product Order Dashboard Panel — product-order metabox on product screens.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'add_meta_boxes', 'dtb_product_order_admin_register_metabox' );

function dtb_product_order_admin_register_metabox(): void {
	add_meta_box(
		'dtb-product-orders',
		__( 'DTB Orders for this Product', 'drywall-toolbox' ),
		'dtb_product_order_admin_metabox_render',
		'product',
		'normal',
		'default'
	);
}

function dtb_product_order_admin_metabox_render( $post ): void {
	$product_id = (int) $post->ID;

	$orders = wc_get_orders( [
		'limit'      => 20,
		'orderby'    => 'date',
		'order'      => 'DESC',
		'return'     => 'objects',
		'meta_query' => [
			[
				'key'     => '_product_id',
				'value'   => $product_id,
				'compare' => '=',
				'type'    => 'NUMERIC',
			],
		],
	] );

	if ( empty( $orders ) ) {
		echo '<p style="color:#9ca3af;font-size:12px;">' . esc_html__( 'No orders found for this product.', 'drywall-toolbox' ) . '</p>';
		return;
	}

	$wc_status_colors = [
		'pending'    => '#fef3c7;color:#92400e',
		'processing' => '#dbeafe;color:#1e40af',
		'on-hold'    => '#fef3c7;color:#92400e',
		'completed'  => '#dcfce7;color:#166534',
		'cancelled'  => '#fee2e2;color:#991b1b',
		'refunded'   => '#f3f4f6;color:#6b7280',
		'failed'     => '#fee2e2;color:#991b1b',
	];

	echo '<style>
		.dtb-po-table { width:100%; border-collapse:collapse; font-size:12px; }
		.dtb-po-table th { text-align:left; padding:6px 8px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#6b7280; border-bottom:1px solid #e5e7eb; background:#f9fafb; }
		.dtb-po-table td { padding:8px 8px; border-bottom:1px solid #f3f4f6; vertical-align:middle; color:#111827; }
		.dtb-po-table tr:last-child td { border-bottom:none; }
		.dtb-po-table tr:hover td { background:#f5f8ff; }
		.dtb-po-table a { color:#1d4ed8; font-weight:600; text-decoration:none; }
		.dtb-po-table a:hover { text-decoration:underline; }
		.dtb-po-badge { display:inline-flex; align-items:center; padding:2px 8px; border-radius:10px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; }
		</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	echo '<div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">';
	echo '<table class="dtb-po-table">';
	echo '<thead><tr>'
		. '<th>' . esc_html__( 'Order', 'drywall-toolbox' ) . '</th>'
		. '<th>' . esc_html__( 'Status', 'drywall-toolbox' ) . '</th>'
		. '<th>' . esc_html__( 'Total', 'drywall-toolbox' ) . '</th>'
		. '<th>' . esc_html__( 'Date', 'drywall-toolbox' ) . '</th>'
		. '</tr></thead><tbody>';

	foreach ( $orders as $order ) {
		$edit_url   = get_edit_post_link( $order->get_id() );
		$status     = $order->get_status();
		$color_str  = $wc_status_colors[ $status ] ?? '#f3f4f6;color:#6b7280';
		$total      = wp_strip_all_tags( wc_price( $order->get_total() ) );
		$date       = $order->get_date_created() ? $order->get_date_created()->format( 'M j, Y' ) : '—';

		echo '<tr>'
			. '<td><a href="' . esc_url( (string) $edit_url ) . '">#' . esc_html( (string) $order->get_id() ) . '</a></td>'
			. '<td><span class="dtb-po-badge" style="background:' . esc_attr( $color_str ) . '">' . esc_html( ucfirst( $status ) ) . '</span></td>'
			. '<td style="font-weight:600;">' . esc_html( $total ) . '</td>'
			. '<td style="color:#6b7280;">' . esc_html( $date ) . '</td>'
			. '</tr>';
	}

	echo '</tbody></table></div>';
}
