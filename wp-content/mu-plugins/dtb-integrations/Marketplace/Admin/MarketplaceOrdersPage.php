<?php
/**
 * Marketplace Admin — MarketplaceOrdersPage
 *
 * Renders the Marketplace → Orders grid page.
 * Page slug: dtb-marketplace-orders
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_marketplace_render_orders_page(): void {
	if ( ! current_user_can( 'dtb_view_marketplace' ) ) {
		dtb_admin_shell_access_denied();
		return;
	}

	$channel = sanitize_key( $_GET['channel'] ?? '' );
	$status  = sanitize_key( $_GET['status']  ?? '' );

	dtb_admin_shell_open( [
		'title'    => __( 'Marketplace Orders', 'drywall-toolbox' ),
		'subtitle' => __( 'Unified Amazon & eBay order grid with Woo/Veeqo reconciliation.', 'drywall-toolbox' ),
		'section'  => 'operations',
		'page'     => 'dtb-marketplace-orders',
		'template' => 'list',
		'icon'     => 'dashicons-store',
		'tabs'     => dtb_marketplace_admin_tabs( 'orders' ),
		'actions'  => [
			dtb_admin_ui_button( __( 'Sync Amazon', 'drywall-toolbox' ), [
				'type' => 'secondary',
				'size' => 'sm',
				'attr' => 'data-dtb-sync-channel="amazon"',
			] ),
			dtb_admin_ui_button( __( 'Sync eBay', 'drywall-toolbox' ), [
				'type' => 'secondary',
				'size' => 'sm',
				'attr' => 'data-dtb-sync-channel="ebay"',
			] ),
		],
	] );

	// Filters.
	echo '<form method="get" class="dtb-filter-row" data-dtb-filter-form="marketplace-orders">';
	echo '<input type="hidden" name="page" value="dtb-marketplace-orders">';

	$channels = [ '' => __( 'All channels', 'drywall-toolbox' ), DTB_CHANNEL_AMAZON => 'Amazon', DTB_CHANNEL_EBAY => 'eBay' ];
	echo '<select name="channel" class="dtb-filter-select">';
	foreach ( $channels as $v => $l ) {
		echo '<option value="' . esc_attr( $v ) . '"' . selected( $channel, $v, false ) . '>' . esc_html( $l ) . '</option>';
	}
	echo '</select>';

	$statuses = [
		''          => __( 'All statuses', 'drywall-toolbox' ),
		'pending'   => __( 'Pending', 'drywall-toolbox' ),
		'shipped'   => __( 'Shipped', 'drywall-toolbox' ),
		'unlinked'  => __( 'Unlinked', 'drywall-toolbox' ),
		'exception' => __( 'Has Exceptions', 'drywall-toolbox' ),
	];
	echo '<select name="status" class="dtb-filter-select">';
	foreach ( $statuses as $v => $l ) {
		echo '<option value="' . esc_attr( $v ) . '"' . selected( $status, $v, false ) . '>' . esc_html( $l ) . '</option>';
	}
	echo '</select>';

	submit_button( __( 'Filter', 'drywall-toolbox' ), 'secondary small', 'submit', false );
	echo '</form>';

	// Table.
	$orders = DTB_MarketplaceReadModels::orders( [
		'channel_key' => $channel,
		'status'      => $status,
		'limit'       => 100,
	] );

	if ( empty( $orders ) ) {
		echo '<p class="dtb-empty-state">' . esc_html__( 'No marketplace orders found.', 'drywall-toolbox' ) . '</p>';
	} else {
		echo '<table class="widefat striped dtb-table dtb-marketplace-orders-table">';
		echo '<thead><tr>';
		foreach ( [
			__( 'Channel', 'drywall-toolbox' ),
			__( 'MP Order ID', 'drywall-toolbox' ),
			__( 'Woo Order', 'drywall-toolbox' ),
			__( 'Veeqo', 'drywall-toolbox' ),
			__( 'Buyer', 'drywall-toolbox' ),
			__( 'Age', 'drywall-toolbox' ),
			__( 'Payment', 'drywall-toolbox' ),
			__( 'Fulfillment', 'drywall-toolbox' ),
			__( 'Tracking', 'drywall-toolbox' ),
			__( 'Messages', 'drywall-toolbox' ),
			__( 'Sync', 'drywall-toolbox' ),
			__( 'Exc', 'drywall-toolbox' ),
			__( 'Actions', 'drywall-toolbox' ),
		] as $col ) {
			echo '<th>' . esc_html( $col ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $orders as $o ) {
			$age       = dtb_marketplace_relative_time( $o['order_placed_at'] ?? '' );
			$woo_link  = $o['woo_order_id']   ? '<a href="' . esc_url( get_edit_post_link( (int) $o['woo_order_id'] ) ) . '">#' . esc_html( (string) $o['woo_order_id'] ) . '</a>' : '<em>' . esc_html__( 'unlinked', 'drywall-toolbox' ) . '</em>';
			$veeqo     = $o['veeqo_order_id'] ? '<span class="dtb-badge dtb-badge--info">' . esc_html( (string) $o['veeqo_order_id'] ) . '</span>' : '<span class="dtb-badge dtb-badge--muted">—</span>';
			$ch_badge  = dtb_marketplace_channel_badge( $o['channel_key'] );
			$buyer     = '<span class="dtb-buyer-hash" title="' . esc_attr( $o['buyer_ref_hash'] ?? '' ) . '">' . esc_html( substr( $o['buyer_ref_hash'] ?? '—', 0, 8 ) . '…' ) . '</span>';
			$exc_badge = $o['exception_count'] > 0 ? '<span class="dtb-badge dtb-badge--danger">' . esc_html( (string) $o['exception_count'] ) . '</span>' : '—';

			echo '<tr>';
			echo '<td>' . $ch_badge . '</td>'; // phpcs:ignore
			echo '<td><code>' . esc_html( $o['marketplace_order_id'] ?? '' ) . '</code></td>';
			echo '<td>' . $woo_link . '</td>'; // phpcs:ignore
			echo '<td>' . $veeqo . '</td>'; // phpcs:ignore
			echo '<td>' . $buyer . '</td>'; // phpcs:ignore
			echo '<td>' . esc_html( $age ) . '</td>';
			echo '<td>' . esc_html( $o['payment_state'] ?? '' ) . '</td>';
			echo '<td>' . esc_html( $o['fulfillment_state'] ?? '' ) . '</td>';
			echo '<td>' . esc_html( $o['tracking_state'] ?? '' ) . '</td>';
			echo '<td>' . esc_html( $o['message_state'] ?? '' ) . '</td>';
			echo '<td>' . esc_html( $o['sync_state'] ?? '' ) . '</td>';
			echo '<td>' . $exc_badge . '</td>'; // phpcs:ignore
			echo '<td class="dtb-actions-cell">';

			$mp_id = (int) $o['id'];
			$mp_oid = esc_attr( $o['marketplace_order_id'] );
			echo '<button class="button button-small dtb-order-action" data-action="sync"    data-id="' . esc_attr( (string) $mp_id ) . '" data-mp-id="' . $mp_oid . '">' . esc_html__( 'Sync', 'drywall-toolbox' ) . '</button> ';
			echo '<button class="button button-small dtb-order-action" data-action="messages" data-id="' . esc_attr( (string) $mp_id ) . '">' . esc_html__( 'Msgs', 'drywall-toolbox' ) . '</button> ';
			if ( $o['woo_order_id'] ) {
				echo '<a class="button button-small" href="' . esc_url( get_edit_post_link( (int) $o['woo_order_id'] ) ) . '">' . esc_html__( 'Woo', 'drywall-toolbox' ) . '</a> ';
			} else {
				echo '<button class="button button-small dtb-order-action" data-action="link" data-id="' . esc_attr( (string) $mp_id ) . '">' . esc_html__( 'Link', 'drywall-toolbox' ) . '</button> ';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	dtb_admin_shell_close();
}
