<?php
/**
 * DTB branded customer invoice / order details email.
 *
 * Traced against WooCommerce core emails/customer-invoice.php v10.4.0.
 * DTB customization: hero (no progress tracker — this email covers both a
 * failed-payment retry and a pre-payment reference copy, an ambiguous state
 * a forward-progress tracker shouldn't claim); uses WooCommerce's own secure
 * $order->get_checkout_payment_url() for both the failed-payment and
 * payable-invoice cases. Hook sequence preserved.
 *
 * @package DrywalltoolboxCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'woocommerce_email_header', $email_heading, $email );

echo function_exists( 'dtb_email_hero' ) ? dtb_email_hero( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	$email_heading,
	'',
	/* translators: %s: order number. */
	sprintf( __( 'Order #%s', 'drywall-toolbox' ), $order->get_order_number() )
) : '';
?>

<div class="email-introduction">
	<?php if ( $order->needs_payment() ) : ?>
		<?php echo function_exists( 'dtb_email_button' ) ? dtb_email_button( $order->get_checkout_payment_url(), __( 'Pay for this order', 'drywall-toolbox' ) ) : ''; ?>
	<?php endif; ?>
</div>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

echo function_exists( 'dtb_email_support_card' ) ? dtb_email_support_card( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	__( 'Questions about this invoice? Our team is here to help.', 'drywall-toolbox' ),
	function_exists( 'dtb_email_support_url' ) ? dtb_email_support_url() : home_url( '/contact/' ),
	__( 'Contact support', 'drywall-toolbox' ),
	'support',
	__( 'Need help?', 'drywall-toolbox' )
) : '';

if ( $additional_content ) {
	echo '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo '</td></tr></table>';
}

do_action( 'woocommerce_email_footer', $email );
