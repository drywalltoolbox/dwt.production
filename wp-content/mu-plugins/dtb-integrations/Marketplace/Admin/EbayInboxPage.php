<?php
/**
 * Marketplace Admin — EbayInboxPage
 *
 * eBay buyer message inbox with reply composer.
 * Page slug: dtb-marketplace-ebay-inbox
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_marketplace_render_ebay_inbox_page(): void {
	if ( ! current_user_can( 'dtb_manage_marketplace' ) ) {
		dtb_admin_shell_access_denied();
		return;
	}

	$msg_status = sanitize_key( $_GET['msg_status'] ?? 'open' );

	dtb_admin_shell_open( [
		'title'    => __( 'eBay Inbox', 'drywall-toolbox' ),
		'subtitle' => __( 'eBay buyer messages with reply composer. Rate-limit protected.', 'drywall-toolbox' ),
		'section'  => 'operations',
		'page'     => 'dtb-marketplace-ebay-inbox',
		'icon'     => 'dashicons-email',
		'tabs'     => dtb_marketplace_admin_tabs( 'messages' ),
		'actions'  => [
			dtb_admin_ui_button( __( 'Sync eBay Messages', 'drywall-toolbox' ), [
				'type' => 'secondary',
				'size' => 'sm',
				'attr' => 'id="dtb-ebay-msg-sync-btn"',
			] ),
		],
	] );

	echo '<div class="dtb-notice dtb-notice--info">';
	echo '<p><strong>' . esc_html__( 'eBay Messaging Policy:', 'drywall-toolbox' ) . '</strong> ' .
		esc_html__( 'Replies must include buyer username, item ID, and order ID where applicable. Rate limit: 5 replies per 60 seconds. Duplicate replies are blocked.', 'drywall-toolbox' ) . '</p>';
	echo '</div>';

	// Rate-limit indicator.
	echo '<div id="dtb-ebay-rate-limit-status" class="dtb-rate-limit-bar" data-channel="ebay" aria-live="polite"></div>';

	// Filter.
	echo '<form method="get" class="dtb-filter-row">';
	echo '<input type="hidden" name="page" value="dtb-marketplace-ebay-inbox">';
	$statuses = [
		'open'        => __( 'Open', 'drywall-toolbox' ),
		'needs_reply' => __( 'Needs Reply', 'drywall-toolbox' ),
		'replied'     => __( 'Replied', 'drywall-toolbox' ),
		''            => __( 'All', 'drywall-toolbox' ),
	];
	echo '<select name="msg_status" class="dtb-filter-select">';
	foreach ( $statuses as $v => $l ) {
		echo '<option value="' . esc_attr( $v ) . '"' . selected( $msg_status, $v, false ) . '>' . esc_html( $l ) . '</option>';
	}
	echo '</select>';
	submit_button( __( 'Filter', 'drywall-toolbox' ), 'secondary small', 'submit', false );
	echo '</form>';

	// Conversations for eBay only.
	$conversations = DTB_MarketplaceReadModels::conversations( [
		'channel_key' => DTB_CHANNEL_EBAY,
		'status'      => $msg_status,
		'needs_reply' => 'needs_reply' === $msg_status ? true : false,
		'limit'       => 100,
	] );

	if ( empty( $conversations ) ) {
		echo '<p class="dtb-empty-state">' . esc_html__( 'No eBay messages found.', 'drywall-toolbox' ) . '</p>';
	} else {
		echo '<table class="widefat striped dtb-table dtb-ebay-inbox-table">';
		echo '<thead><tr>';
		foreach ( [
			__( 'Subject', 'drywall-toolbox' ),
			__( 'Buyer', 'drywall-toolbox' ),
			__( 'Item', 'drywall-toolbox' ),
			__( 'Order', 'drywall-toolbox' ),
			__( 'Status', 'drywall-toolbox' ),
			__( 'SLA', 'drywall-toolbox' ),
			__( 'Last', 'drywall-toolbox' ),
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

			echo '<tr>';
			echo '<td>' . esc_html( $c['subject'] ?? '—' ) . '</td>';
			echo '<td><code>' . esc_html( substr( $c['buyer_ref_hash'] ?? '—', 0, 8 ) . '…' ) . '</code></td>';
			echo '<td>' . esc_html( $c['external_item_id'] ?? '—' ) . '</td>';
			echo '<td>' . esc_html( $c['external_order_id'] ?? '—' ) . '</td>';
			echo '<td>' . esc_html( $c['status'] ?? '' ) . '</td>';
			echo '<td class="' . esc_attr( $sla_class ) . '">' . esc_html( $c['sla_due_at'] ?? '—' ) . '</td>';
			echo '<td>' . esc_html( dtb_marketplace_relative_time( $c['last_inbound_at'] ?? '' ) ) . '</td>';
			echo '<td>';
			echo '<button class="button button-small dtb-thread-open" data-id="' . esc_attr( (string) $c['id'] ) . '" data-channel="ebay">' . esc_html__( 'Reply', 'drywall-toolbox' ) . '</button>';
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	// Thread drawer (same structure as messages page).
	echo '<div id="dtb-thread-drawer" class="dtb-drawer dtb-drawer--closed" aria-hidden="true">';
	echo '<div class="dtb-drawer__header">';
	echo '<h3 class="dtb-drawer__title">' . esc_html__( 'eBay Thread', 'drywall-toolbox' ) . '</h3>';
	echo '<button class="dtb-drawer__close dashicons dashicons-no-alt" aria-label="' . esc_attr__( 'Close', 'drywall-toolbox' ) . '"></button>';
	echo '</div>';
	echo '<div class="dtb-drawer__body" id="dtb-thread-body"></div>';
	echo '<div class="dtb-drawer__footer" id="dtb-ebay-reply-composer">';
	echo '<textarea id="dtb-ebay-reply-body" class="widefat" rows="4" placeholder="' . esc_attr__( 'Write reply…', 'drywall-toolbox' ) . '"></textarea>';
	echo '<input type="hidden" id="dtb-ebay-reply-conversation-id">';
	echo '<p>';
	echo '<button id="dtb-ebay-send-btn" class="button button-primary">' . esc_html__( 'Send Reply', 'drywall-toolbox' ) . '</button> ';
	echo '<span class="dtb-reply-status" aria-live="polite"></span>';
	echo '</p>';
	echo '</div>';
	echo '</div>';
	echo '<div class="dtb-drawer-overlay dtb-drawer-overlay--hidden" id="dtb-drawer-overlay"></div>';

	dtb_admin_shell_close();
}
