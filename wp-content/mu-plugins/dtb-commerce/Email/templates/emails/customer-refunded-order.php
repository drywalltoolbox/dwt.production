<?php
/**
 * DTB branded customer refunded order email.
 *
 * Traced against WooCommerce core emails/customer-refunded-order.php
 * v10.4.0. DTB customization: hero + status badge (no progress tracker — a
 * refund isn't forward progress), distinguishing full vs. partial refunds
 * via WooCommerce's own $partial_refund flag — no independent refund-amount
 * computation (avoids the cumulative-total bug this redesign fixes). Hook
 * sequence preserved.
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
?>

<div class="email-introduction">
	<?php
	echo function_exists( 'dtb_email_status_badge' )
		? dtb_email_status_badge( $partial_refund ? __( 'Partial refund issued', 'drywall-toolbox' ) : __( 'Refund issued', 'drywall-toolbox' ), 'info' )
		: '';
	?>
	<?php if ( $partial_refund ) : ?>
		<p><?php esc_html_e( 'A partial refund has been issued. The remaining order is unaffected.', 'drywall-toolbox' ); ?></p>
	<?php else : ?>
		<p><?php esc_html_e( 'A refund has been issued to the original payment method.', 'drywall-toolbox' ); ?></p>
	<?php endif; ?>
</div>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

echo function_exists( 'dtb_email_support_card' ) ? dtb_email_support_card( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	__( 'Questions about this refund? Our team is here to help.', 'drywall-toolbox' ),
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
