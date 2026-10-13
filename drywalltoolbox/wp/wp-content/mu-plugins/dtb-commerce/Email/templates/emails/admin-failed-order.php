<?php
/**
 * DTB branded admin failed order email.
 *
 * Traced against WooCommerce core emails/admin-failed-order.php v10.4.0
 * (wp-content/plugins/woocommerce/templates/emails/admin-failed-order.php).
 * DTB customization: shared branded hero and lifecycle status with concise
 * operator copy; hook sequence preserved unchanged.
 *
 * @package DrywalltoolboxCommerce
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

echo function_exists( 'DTB\\Commerce\\Email\\dtb_email_v4_order_hero' )
	? DTB\Commerce\Email\dtb_email_v4_order_hero( $order, $email, [
		'title' => __( 'Order needs attention', 'drywall-toolbox' ),
		'body' => __( 'An order payment failed. Review the WooCommerce payment state before taking action.', 'drywall-toolbox' ),
		'steps' => [],
	] )
	: ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>

<div class="email-introduction">
	<?php echo function_exists( 'dtb_email_status_badge' ) ? dtb_email_status_badge( __( 'Payment failed', 'drywall-toolbox' ), 'danger' ) : ''; ?>
	<p><?php printf( esc_html__( 'Payment failed for order #%1$s from %2$s. No charge was captured — no fulfillment action is needed unless the customer completes payment.', 'drywall-toolbox' ), esc_html( $order->get_order_number() ), esc_html( $order->get_formatted_billing_full_name() ) ); ?></p>
</div>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo '</td></tr></table>';
}

do_action( 'woocommerce_email_footer', $email );
