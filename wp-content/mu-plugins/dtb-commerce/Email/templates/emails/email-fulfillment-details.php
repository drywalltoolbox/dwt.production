<?php
/**
 * DTB branded fulfillment (shipment) details table shown in emails.
 *
 * Traced against WooCommerce core emails/email-fulfillment-details.php
 * v10.7.0 (wp-content/plugins/woocommerce/templates/emails/email-fulfillment-details.php).
 * DTB customization: presentation only (now wrapped in the shared card
 * chrome, `show_sku` now unconditional instead of `$sent_to_admin`-gated).
 * woocommerce_email_before_fulfillment_table /
 * woocommerce_email_after_fulfillment_table preserved unchanged.
 *
 * @package DrywalltoolboxCommerce
 */

defined( 'ABSPATH' ) || exit;

$is_active_fulfillment = null === $fulfillment->get_date_deleted();
$tracking_number       = $is_active_fulfillment ? $fulfillment->get_tracking_number() : '';
$tracking_url          = $is_active_fulfillment ? $fulfillment->get_tracking_url() : '';
$shipment_provider     = $is_active_fulfillment ? $fulfillment->get_shipment_provider() : '';

/**
 * Action hook to add custom content before fulfillment details in email.
 *
 * @param WC_Order    $order         Order object.
 * @param Fulfillment  $fulfillment   Fulfillment object.
 * @param bool         $sent_to_admin Whether it's sent to admin or customer.
 * @param bool         $plain_text    Whether it's a plain text email.
 * @param WC_Email     $email         Email object.
 */
do_action( 'woocommerce_email_before_fulfillment_table', $order, $fulfillment, $sent_to_admin, $plain_text, $email );

/* translators: %s: order number. */
$fulfillment_order_meta = sprintf( __( 'Order #%s', 'drywall-toolbox' ), $order->get_order_number() );

echo function_exists( 'dtb_email_card_open' )
	? dtb_email_card_open( __( 'Shipment summary', 'drywall-toolbox' ), $fulfillment_order_meta ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	: '<div style="margin-bottom:20px;">';

if ( $is_active_fulfillment ) {
	$tracking_rows = [];
	if ( $shipment_provider ) {
		$tracking_rows[] = [ 'label' => __( 'Carrier', 'drywall-toolbox' ), 'value' => $shipment_provider ];
	}
	if ( $tracking_number ) {
		$tracking_rows[] = [ 'label' => __( 'Tracking number', 'drywall-toolbox' ), 'value' => $tracking_number ];
	}

	if ( $tracking_rows ) {
		echo function_exists( 'dtb_email_details_table_light' ) ? dtb_email_details_table_light( $tracking_rows ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} else {
		echo '<p>' . esc_html__( 'Tracking information is not available yet.', 'drywall-toolbox' ) . '</p>';
	}

	if ( $tracking_url ) {
		echo function_exists( 'dtb_email_button' ) ? dtb_email_button( $tracking_url, __( 'Track shipment', 'drywall-toolbox' ) ) : '<p><a class="link" href="' . esc_url( $tracking_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Track shipment', 'drywall-toolbox' ) . '</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
?>

<table class="td font-family email-order-details" cellspacing="0" cellpadding="0" style="width:100%;" border="0" role="presentation">
	<thead>
		<tr class="dtb-order-column-headings">
			<th class="td text-align-left" scope="col"><?php esc_html_e( 'Item', 'drywall-toolbox' ); ?></th>
			<th class="td text-align-right" scope="col"><?php esc_html_e( 'Qty', 'drywall-toolbox' ); ?></th>
			<th class="td text-align-right" scope="col"><?php esc_html_e( 'Price', 'drywall-toolbox' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php
		echo wc_get_email_fulfillment_items( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$order,
			$fulfillment,
			[
				'show_sku'      => true,
				'show_image'    => true,
				'image_size'    => [ 48, 48 ],
				'plain_text'    => $plain_text,
				'sent_to_admin' => $sent_to_admin,
			]
		);
		?>
	</tbody>
</table>

<?php
/**
 * Action hook to add custom content after fulfillment details in email.
 *
 * @param WC_Order    $order         Order object.
 * @param Fulfillment  $fulfillment   Fulfillment object.
 * @param bool         $sent_to_admin Whether it's sent to admin or customer.
 * @param bool         $plain_text    Whether it's a plain text email.
 * @param WC_Email     $email         Email object.
 */
do_action( 'woocommerce_email_after_fulfillment_table', $order, $fulfillment, $sent_to_admin, $plain_text, $email );

echo function_exists( 'dtb_email_card_close' ) ? dtb_email_card_close() : '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>
