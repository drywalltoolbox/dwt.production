<?php
/**
 * DTB branded billing/shipping addresses shown in emails.
 *
 * Traced against WooCommerce core emails/email-addresses.php v10.6.0
 * (wp-content/plugins/woocommerce/templates/emails/email-addresses.php).
 * DTB customization: presentation only (open, responsive two-column section
 * instead of nested card chrome); the
 * woocommerce_email_customer_address_section hook is preserved unchanged.
 *
 * @package DrywalltoolboxCommerce
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$address      = $order->get_formatted_billing_address();
$shipping     = $order->get_formatted_shipping_address();
$has_shipping = ! wc_ship_to_billing_address_only() && $order->needs_shipping_address() && $shipping;
$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );
unset( $email_improvements_enabled );

// Preserve WooCommerce's section-divider extension point. DTB expresses the
// section boundary through card chrome rather than a separate horizontal rule.
$display_section_divider = (bool) apply_filters( 'woocommerce_email_body_display_section_divider', true );
unset( $display_section_divider );

$card_title = $has_shipping ? __( 'Billing & shipping addresses', 'drywall-toolbox' ) : __( 'Billing address', 'drywall-toolbox' );

echo function_exists( 'dtb_email_card_open' ) ? dtb_email_card_open( $card_title ) : '<div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>
<table id="addresses" cellspacing="0" cellpadding="0" width="100%" style="width:100%;table-layout:fixed;vertical-align:top;border-collapse:collapse;" border="0" role="presentation">
	<tr class="dtb-address-row">
		<td class="font-family text-align-left dtb-address-billing" style="width:50%;border:0;padding:0 18px 0 0;overflow-wrap:anywhere;word-break:break-word;" valign="top" width="50%">
			<b class="address-title"><?php esc_html_e( 'Billing address', 'drywall-toolbox' ); ?></b>
			<address class="address">
				<?php echo wp_kses_post( $address ? $address : esc_html__( 'N/A', 'drywall-toolbox' ) ); ?>
				<?php if ( $order->get_billing_phone() ) : ?>
					<br/><?php echo wc_make_phone_clickable( $order->get_billing_phone() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>
				<?php if ( $order->get_billing_email() ) : ?>
					<br/><?php echo esc_html( $order->get_billing_email() ); ?>
				<?php endif; ?>
				<?php
				/**
				 * Fires after the core address fields in emails.
				 *
				 * @param string   $type          Address type. Either 'billing' or 'shipping'.
				 * @param WC_Order $order         Order instance.
				 * @param bool     $sent_to_admin If this email is being sent to the admin or not.
				 * @param bool     $plain_text    If this email is plain text or not.
				 */
				do_action( 'woocommerce_email_customer_address_section', 'billing', $order, $sent_to_admin, false );
				?>
			</address>
		</td>
		<?php if ( $has_shipping ) : ?>
			<td class="font-family text-align-left dtb-address-shipping" style="width:50%;padding:0 0 0 18px;border-left:1px solid #e4eaf2;overflow-wrap:anywhere;word-break:break-word;" valign="top" width="50%">
				<b class="address-title"><?php esc_html_e( 'Shipping address', 'drywall-toolbox' ); ?></b>
				<address class="address">
					<?php echo wp_kses_post( $shipping ); ?>
					<?php if ( $order->get_shipping_phone() ) : ?>
						<br /><?php echo wc_make_phone_clickable( $order->get_shipping_phone() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endif; ?>
					<?php do_action( 'woocommerce_email_customer_address_section', 'shipping', $order, $sent_to_admin, false ); ?>
				</address>
			</td>
		<?php endif; ?>
	</tr>
</table>
<?php
echo function_exists( 'dtb_email_card_close' ) ? dtb_email_card_close() : '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
