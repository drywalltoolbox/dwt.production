<?php
/**
 * Marketplace Admin — AmazonCommunicationPage
 *
 * Order-scoped Amazon buyer messaging.
 * Page slug: dtb-marketplace-amazon-comms
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_marketplace_render_amazon_comms_page(): void {
	if ( ! current_user_can( 'dtb_manage_marketplace' ) ) {
		dtb_admin_shell_access_denied();
		return;
	}

	$mp_order_id = sanitize_text_field( $_GET['order_id'] ?? '' );

	dtb_admin_shell_open( [
		'title'    => __( 'Amazon Buyer Communication', 'drywall-toolbox' ),
		'subtitle' => __( 'Order-scoped messaging via Amazon Selling Partner API.', 'drywall-toolbox' ),
		'section'  => 'operations',
		'page'     => 'dtb-marketplace-amazon-comms',
		'icon'     => 'dashicons-email',
		'tabs'     => dtb_marketplace_admin_tabs( 'messages' ),
	] );

	echo '<div class="dtb-notice dtb-notice--info">';
	echo '<p><strong>' . esc_html__( 'Amazon Message Policy:', 'drywall-toolbox' ) . '</strong> ' .
		esc_html__( 'Only officially supported message actions are enabled. Available actions are fetched per order from the Amazon SP-API.', 'drywall-toolbox' ) . '</p>';
	echo '</div>';

	// Order picker.
	echo '<div class="dtb-amazon-comms-picker">';
	echo '<form method="get" class="dtb-filter-row">';
	echo '<input type="hidden" name="page" value="dtb-marketplace-amazon-comms">';
	echo '<input type="text" name="order_id" class="regular-text" placeholder="' . esc_attr__( 'Amazon Order ID (e.g. 123-4567890-1234567)', 'drywall-toolbox' ) . '" value="' . esc_attr( $mp_order_id ) . '">';
	submit_button( __( 'Load Order', 'drywall-toolbox' ), 'primary small', 'submit', false );
	echo '</form>';
	echo '</div>';

	if ( '' !== $mp_order_id ) {
		$order_row = DTB_MarketplaceReadModels::find_order( DTB_CHANNEL_AMAZON, $mp_order_id );

		if ( ! $order_row ) {
			echo '<div class="dtb-notice dtb-notice--warning"><p>' .
				esc_html__( 'Order not found in local database. Sync Amazon orders first.', 'drywall-toolbox' ) .
				'</p></div>';
		} else {
			echo '<div id="dtb-amazon-order-comms" class="dtb-amazon-comms-panel" data-mp-order-id="' . esc_attr( $mp_order_id ) . '">';

			// Order context.
			$woo_link = $order_row['woo_order_id']
				? '<a href="' . esc_url( get_edit_post_link( (int) $order_row['woo_order_id'] ) ) . '">#' . esc_html( (string) $order_row['woo_order_id'] ) . '</a>'
				: '<em>' . esc_html__( 'unlinked', 'drywall-toolbox' ) . '</em>';

			echo '<table class="dtb-detail-table"><tbody>';
			echo '<tr><th>' . esc_html__( 'Amazon Order ID', 'drywall-toolbox' ) . '</th><td><code>' . esc_html( $mp_order_id ) . '</code></td></tr>';
			echo '<tr><th>' . esc_html__( 'Woo Order', 'drywall-toolbox' ) . '</th><td>' . $woo_link . '</td></tr>'; // phpcs:ignore
			echo '<tr><th>' . esc_html__( 'Fulfillment', 'drywall-toolbox' ) . '</th><td>' . esc_html( $order_row['fulfillment_state'] ?? '—' ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Payment', 'drywall-toolbox' ) . '</th><td>' . esc_html( $order_row['payment_state'] ?? '—' ) . '</td></tr>';
			echo '</tbody></table>';

			// Available actions placeholder — JS fetches live.
			echo '<h3>' . esc_html__( 'Available Message Actions', 'drywall-toolbox' ) . '</h3>';
			echo '<div id="dtb-amazon-actions-list" class="dtb-spinner-target" data-loading="true">';
			echo '<p class="dtb-loading-msg">' . esc_html__( 'Fetching available actions from Amazon…', 'drywall-toolbox' ) . '</p>';
			echo '</div>';

			// Reply composer (hidden until action is selected).
			echo '<div id="dtb-amazon-reply-composer" class="dtb-reply-composer dtb-reply-composer--hidden">';
			echo '<h3>' . esc_html__( 'Compose Message', 'drywall-toolbox' ) . '</h3>';
			echo '<p class="dtb-selected-action-label"></p>';
			echo '<textarea id="dtb-amazon-reply-body" class="widefat" rows="6" placeholder="' . esc_attr__( 'Enter your message…', 'drywall-toolbox' ) . '"></textarea>';
			echo '<p class="dtb-reply-composer__actions">';
			echo '<button id="dtb-amazon-send-btn" class="button button-primary" data-mp-order-id="' . esc_attr( $mp_order_id ) . '">' . esc_html__( 'Send Message', 'drywall-toolbox' ) . '</button>';
			echo '<button id="dtb-amazon-cancel-btn" class="button button-secondary">' . esc_html__( 'Cancel', 'drywall-toolbox' ) . '</button>';
			echo '</p>';
			echo '<div id="dtb-amazon-send-result" class="dtb-send-result" aria-live="polite"></div>';
			echo '</div>';

			echo '</div>'; // .dtb-amazon-comms-panel
		}
	}

	dtb_admin_shell_close();
}
