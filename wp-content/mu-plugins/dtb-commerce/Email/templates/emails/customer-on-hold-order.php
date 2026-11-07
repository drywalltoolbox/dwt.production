<?php
/**
 * DTB branded customer on-hold order email.
 *
 * Traced against WooCommerce core emails/customer-on-hold-order.php v10.4.0.
 * DTB customization: hero + progress tracker (first stage shown as
 * "pending"/warning tone, not "done" — payment isn't confirmed yet, so the
 * step must not claim it is). Hook sequence preserved.
 *
 * @package DrywalltoolboxCommerce
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

echo function_exists( 'dtb_email_hero' ) ? dtb_email_hero( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	$email_heading,
	'',
	/* translators: %s: order number. */
	sprintf( __( 'Order #%s', 'drywall-toolbox' ), $order->get_order_number() )
) : '';

echo function_exists( 'dtb_email_progress_steps' ) ? dtb_email_progress_steps( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	[
		[ 'label' => __( 'Payment pending', 'drywall-toolbox' ), 'state' => 'warning' ],
		[ 'label' => __( 'Being prepared', 'drywall-toolbox' ), 'state' => 'upcoming' ],
		[ 'label' => __( 'Shipping update', 'drywall-toolbox' ), 'state' => 'upcoming' ],
	]
) : '';
?>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

echo function_exists( 'dtb_email_support_card' ) ? dtb_email_support_card( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	__( 'Questions about your payment or order? Our team is here to help.', 'drywall-toolbox' ),
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
