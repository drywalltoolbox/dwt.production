<?php
/**
 * Marketplace Admin — MarketplaceMessagesPage
 *
 * Unified message inbox with thread drawer.
 * Page slug: dtb-marketplace-messages
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_marketplace_render_messages_page(): void {
	if ( ! current_user_can( 'dtb_view_marketplace' ) ) {
		dtb_admin_shell_access_denied();
		return;
	}

	$channel     = sanitize_key( $_GET['channel'] ?? '' );
	$msg_status  = sanitize_key( $_GET['msg_status'] ?? '' );
	$sla_filter  = sanitize_key( $_GET['sla'] ?? '' );

	dtb_admin_shell_open( [
		'title'    => __( 'Marketplace Messages', 'drywall-toolbox' ),
		'subtitle' => __( 'Unified Amazon & eBay buyer message inbox.', 'drywall-toolbox' ),
		'section'  => 'operations',
		'page'     => 'dtb-marketplace-messages',
		'template' => 'list',
		'icon'     => 'dashicons-email',
		'tabs'     => dtb_marketplace_admin_tabs( 'messages' ),
		'actions'  => current_user_can( 'dtb_manage_marketplace' ) ? [
			dtb_admin_ui_button( __( 'Amazon Composer', 'drywall-toolbox' ), [
				'type' => 'secondary',
				'size' => 'sm',
				'href' => admin_url( 'admin.php?page=dtb-marketplace-amazon-comms' ),
			] ),
			dtb_admin_ui_button( __( 'eBay Inbox', 'drywall-toolbox' ), [
				'type' => 'secondary',
				'size' => 'sm',
				'href' => admin_url( 'admin.php?page=dtb-marketplace-ebay-inbox' ),
			] ),
		] : [],
	] );

	echo '<form method="get" class="dtb-filter-row">';
	echo '<input type="hidden" name="page" value="dtb-marketplace-messages">';

	$ch_opts = [ '' => __( 'All channels', 'drywall-toolbox' ), DTB_CHANNEL_AMAZON => 'Amazon', DTB_CHANNEL_EBAY => 'eBay' ];
	echo '<select name="channel" class="dtb-filter-select">';
	foreach ( $ch_opts as $v => $l ) {
		echo '<option value="' . esc_attr( $v ) . '"' . selected( $channel, $v, false ) . '>' . esc_html( $l ) . '</option>';
	}
	echo '</select>';

	$status_opts = [
		''           => __( 'All', 'drywall-toolbox' ),
		'open'       => __( 'Open', 'drywall-toolbox' ),
		'needs_reply' => __( 'Needs Reply', 'drywall-toolbox' ),
		'replied'    => __( 'Replied', 'drywall-toolbox' ),
		'failed'     => __( 'Failed', 'drywall-toolbox' ),
	];
	echo '<select name="msg_status" class="dtb-filter-select">';
	foreach ( $status_opts as $v => $l ) {
		echo '<option value="' . esc_attr( $v ) . '"' . selected( $msg_status, $v, false ) . '>' . esc_html( $l ) . '</option>';
	}
	echo '</select>';

	$sla_opts = [ '' => __( 'SLA: All', 'drywall-toolbox' ), 'due' => __( 'Due Soon', 'drywall-toolbox' ), 'breached' => __( 'Breached', 'drywall-toolbox' ) ];
	echo '<select name="sla" class="dtb-filter-select">';
	foreach ( $sla_opts as $v => $l ) {
		echo '<option value="' . esc_attr( $v ) . '"' . selected( $sla_filter, $v, false ) . '>' . esc_html( $l ) . '</option>';
	}
	echo '</select>';

	submit_button( __( 'Filter', 'drywall-toolbox' ), 'secondary small', 'submit', false );
	echo '</form>';

	$conversations = DTB_MarketplaceReadModels::conversations( [
		'channel_key' => $channel,
		'status'      => $msg_status,
		'needs_reply' => 'needs_reply' === $msg_status ? true : false,
		'sla_breach'  => 'breached' === $sla_filter ? true : false,
		'limit'       => 100,
	] );

	if ( empty( $conversations ) ) {
		echo '<p class="dtb-empty-state">' . esc_html__( 'No conversations found.', 'drywall-toolbox' ) . '</p>';
	} else {
		echo '<table class="widefat striped dtb-table dtb-marketplace-messages-table">';
		echo '<thead><tr>';
		foreach ( [
			__( 'Channel', 'drywall-toolbox' ),
			__( 'Subject', 'drywall-toolbox' ),
			__( 'Buyer', 'drywall-toolbox' ),
			__( 'Woo Order', 'drywall-toolbox' ),
			__( 'Status', 'drywall-toolbox' ),
			__( 'SLA', 'drywall-toolbox' ),
			__( 'Last Inbound', 'drywall-toolbox' ),
			__( 'Actions', 'drywall-toolbox' ),
		] as $col ) {
			echo '<th>' . esc_html( $col ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $conversations as $c ) {
			$sla_class = '';
			if ( ! empty( $c['sla_due_at'] ) ) {
				$sla_ts = strtotime( $c['sla_due_at'] );
				if ( $sla_ts && $sla_ts < time() ) {
					$sla_class = ' dtb-sla--breached';
				} elseif ( $sla_ts && $sla_ts < time() + HOUR_IN_SECONDS * 4 ) {
					$sla_class = ' dtb-sla--due';
				}
			}

			$woo_link = $c['woo_order_id']
				? '<a href="' . esc_url( get_edit_post_link( (int) $c['woo_order_id'] ) ) . '">#' . esc_html( (string) $c['woo_order_id'] ) . '</a>'
				: '—';

			echo '<tr>';
			echo '<td>' . dtb_marketplace_channel_badge( $c['channel_key'] ) . '</td>'; // phpcs:ignore
			echo '<td>' . esc_html( $c['subject'] ?? '—' ) . '</td>';
			echo '<td><code>' . esc_html( substr( $c['buyer_ref_hash'] ?? '—', 0, 8 ) . '…' ) . '</code></td>';
			echo '<td>' . $woo_link . '</td>'; // phpcs:ignore
			echo '<td>' . esc_html( $c['status'] ?? '' ) . '</td>';
			echo '<td class="' . esc_attr( $sla_class ) . '">' . esc_html( $c['sla_due_at'] ?? '—' ) . '</td>';
			echo '<td>' . esc_html( dtb_marketplace_relative_time( $c['last_inbound_at'] ?? '' ) ) . '</td>';
			echo '<td>';
			echo '<button class="button button-small dtb-thread-open" data-id="' . esc_attr( (string) $c['id'] ) . '" data-channel="' . esc_attr( $c['channel_key'] ) . '">' . esc_html__( 'Open Thread', 'drywall-toolbox' ) . '</button>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	// Thread drawer.
	echo '<div id="dtb-thread-drawer" class="dtb-drawer dtb-drawer--closed" aria-hidden="true">';
	echo '<div class="dtb-drawer__header">';
	echo '<h3 class="dtb-drawer__title">' . esc_html__( 'Message Thread', 'drywall-toolbox' ) . '</h3>';
	echo '<button class="dtb-drawer__close dashicons dashicons-no-alt" aria-label="' . esc_attr__( 'Close', 'drywall-toolbox' ) . '"></button>';
	echo '</div>';
	echo '<div class="dtb-drawer__body" id="dtb-thread-body"></div>';
	echo '<div class="dtb-drawer__footer" id="dtb-thread-footer"></div>';
	echo '</div>';
	echo '<div class="dtb-drawer-overlay dtb-drawer-overlay--hidden" id="dtb-drawer-overlay"></div>';

	dtb_admin_shell_close();
}
