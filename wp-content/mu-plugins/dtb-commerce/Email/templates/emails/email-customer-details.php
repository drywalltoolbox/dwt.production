<?php
/**
 * DTB branded additional customer details shown in emails.
 *
 * Traced against WooCommerce core emails/email-customer-details.php v9.7.0
 * (wp-content/plugins/woocommerce/templates/emails/email-customer-details.php).
 * DTB customization: customer-specific fields use the shared open-section
 * presentation. Billing email and phone are omitted here because the address
 * template already renders them; custom checkout fields remain visible.
 *
 * @package DrywalltoolboxCommerce
 */

defined( 'ABSPATH' ) || exit;

$customer_detail_fields = [];

foreach ( (array) $fields as $field_key => $field ) {
	if ( in_array( (string) $field_key, [ 'billing_email', 'billing_phone' ], true ) ) {
		continue;
	}

	$label = trim( wp_strip_all_tags( (string) ( $field['label'] ?? '' ) ) );
	$value = (string) ( $field['value'] ?? '' );
	if ( '' === $label || '' === trim( wp_strip_all_tags( $value ) ) ) {
		continue;
	}

	$customer_detail_fields[] = [
		'label' => $label,
		'value' => $value,
	];
}

if ( ! empty( $customer_detail_fields ) ) :
	echo function_exists( 'dtb_email_card_open' ) ? dtb_email_card_open( __( 'Additional information', 'drywall-toolbox' ) ) : '<div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
	<table class="dtb-customer-details" cellspacing="0" cellpadding="0" width="100%" border="0" role="presentation">
		<?php foreach ( $customer_detail_fields as $field ) : ?>
			<tr>
				<th class="dtb-customer-detail-label" scope="row" valign="top"><?php echo esc_html( $field['label'] ); ?></th>
				<td class="dtb-customer-detail-value" valign="top"><?php echo wp_kses_post( $field['value'] ); ?></td>
			</tr>
		<?php endforeach; ?>
	</table>
	<?php
	echo function_exists( 'dtb_email_card_close' ) ? dtb_email_card_close() : '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
endif;
