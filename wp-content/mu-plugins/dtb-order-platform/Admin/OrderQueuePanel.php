<?php
/**
 * DTB Order Queue Panel — renders the DTB Integration State metabox.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_order_admin_metabox_integration( $post_or_order ): void {
	$order_id  = $post_or_order instanceof WC_Order ? (int) $post_or_order->get_id() : (int) $post_or_order->ID;
	$int_state = dtb_order_get_integration_state( $order_id );

	$slices = [
		'veeqo'      => __( 'Veeqo Fulfillment', 'drywall-toolbox' ),
		'quickbooks' => __( 'QuickBooks', 'drywall-toolbox' ),
		'rewards'    => __( 'Rewards', 'drywall-toolbox' ),
	];

	foreach ( $slices as $key => $label ) {
		$slice  = $int_state[ $key ] ?? [];
		$status = $slice['status'] ?? 'pending';
		$color  = 'failed' === $status ? '#c00' : ( 'synced' === $status || 'issued' === $status ? '#228B22' : '#888' );
		echo '<strong>' . esc_html( $label ) . ':</strong> ';
		echo '<span style="color:' . esc_attr( $color ) . ';">' . esc_html( ucfirst( $status ) ) . '</span>';
		if ( ! empty( $slice['error'] ) ) {
			echo '<br><small style="color:#c00;">' . esc_html( $slice['error'] ) . '</small>';
		}
		if ( ! empty( $slice['updated_at'] ) ) {
			echo '<br><small style="color:#888;">' . esc_html( $slice['updated_at'] ) . '</small>';
		}
		echo '<br><br>';
	}

	$tracking = dtb_order_get_tracking_projection( $order_id );
	if ( $tracking && ! empty( $tracking['tracking_number'] ) ) {
		echo '<strong>' . esc_html__( 'Tracking:', 'drywall-toolbox' ) . '</strong> ';
		if ( $tracking['tracking_url'] ) {
			echo '<a href="' . esc_url( $tracking['tracking_url'] ) . '" target="_blank">'
				. esc_html( $tracking['tracking_number'] ) . '</a>';
		} else {
			echo esc_html( $tracking['tracking_number'] );
		}
		echo '<br>';
	}
}
