<?php
/**
 * DTB Order Admin Columns — custom columns on the WooCommerce order list.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'manage_woocommerce_page_wc-orders_columns', 'dtb_order_admin_list_columns', 20 );
add_filter( 'manage_edit-shop_order_columns',            'dtb_order_admin_list_columns', 20 );

function dtb_order_admin_list_columns( array $columns ): array {
	$insert_after = 'order-status';
	$new_columns  = [
		'dtb_fulfillment'  => __( 'Fulfillment', 'drywall-toolbox' ),
		'dtb_tracking'     => __( 'Tracking', 'drywall-toolbox' ),
		'dtb_veeqo'        => __( 'Veeqo', 'drywall-toolbox' ),
		'dtb_quickbooks'   => __( 'QuickBooks', 'drywall-toolbox' ),
		'dtb_rewards'      => __( 'Rewards', 'drywall-toolbox' ),
		'dtb_last_event'   => __( 'Last DTB Event', 'drywall-toolbox' ),
	];

	$result = [];
	foreach ( $columns as $key => $label ) {
		$result[ $key ] = $label;
		if ( $key === $insert_after ) {
			foreach ( $new_columns as $nk => $nl ) {
				$result[ $nk ] = $nl;
			}
		}
	}

	return $result;
}

add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'dtb_order_admin_render_list_column', 10, 2 );
add_action( 'manage_shop_order_posts_custom_column',           'dtb_order_admin_render_list_column', 10, 2 );

function dtb_order_admin_render_list_column( string $column, $order_or_id ): void {
	$order_id = $order_or_id instanceof WC_Order ? (int) $order_or_id->get_id() : (int) $order_or_id;

	if ( 0 === $order_id || ! str_starts_with( $column, 'dtb_' ) ) {
		return;
	}

	$int_state = dtb_order_get_integration_state( $order_id );

	switch ( $column ) {
		case 'dtb_fulfillment':
			$substate = dtb_order_get_fulfillment_substate( $order_id );
			$labels   = dtb_order_fulfillment_substates();
			echo '<small class="dtb-col-label">' . esc_html( $labels[ $substate ] ?? ucwords( $substate ) ) . '</small>';
			break;

		case 'dtb_tracking':
			$tracking_num = $int_state['veeqo']['tracking'] ?? null;
			if ( $tracking_num ) {
				echo '<small>' . esc_html( $tracking_num ) . '</small>';
			} else {
				echo '<span class="dtb-na">—</span>';
			}
			break;

		case 'dtb_veeqo':
			$status = $int_state['veeqo']['status'] ?? 'pending';
			dtb_order_admin_render_badge( $status );
			break;

		case 'dtb_quickbooks':
			$status = $int_state['quickbooks']['status'] ?? 'pending';
			dtb_order_admin_render_badge( $status );
			break;

		case 'dtb_rewards':
			$status = $int_state['rewards']['status'] ?? 'pending';
			dtb_order_admin_render_badge( $status );
			break;

		case 'dtb_last_event':
			$last = dtb_order_get_last_event( $order_id );
			if ( $last ) {
				echo '<small>' . esc_html( $last->event_type ) . '<br>'
					. esc_html( human_time_diff( strtotime( $last->created_at ), time() ) )
					. ' ago</small>';
			} else {
				echo '<span class="dtb-na">—</span>';
			}
			break;
	}
}

function dtb_order_admin_render_badge( string $status ): void {
	$colors = [
		'pending'  => '#888',
		'synced'   => '#228B22',
		'issued'   => '#228B22',
		'failed'   => '#c00',
		'reversed' => '#E07B00',
	];
	$color = $colors[ $status ] ?? '#888';
	echo '<span style="color:' . esc_attr( $color ) . '; font-weight:600; font-size:11px;">'
		. esc_html( ucfirst( $status ) ) . '</span>';
}
