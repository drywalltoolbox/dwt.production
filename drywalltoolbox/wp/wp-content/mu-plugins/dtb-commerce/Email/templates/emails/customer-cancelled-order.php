<?php
/**
 * DTB branded customer cancelled order email.
 *
 * Traced against WooCommerce core emails/customer-cancelled-order.php
 * v10.4.0. DTB customization: concise hero without a forward-progress
 * tracker or speculative payment/refund copy. Hook sequence preserved.
 *
 * @package DrywalltoolboxCommerce
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

echo function_exists( 'dtb_email_hero' ) ? dtb_email_hero( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	__( 'We’ve cancelled your order', 'drywall-toolbox' ),
	__( 'We’re sorry for the inconvenience. If you have questions or need help with next steps, our team is here to assist.', 'drywall-toolbox' ),
	/* translators: %s: order number. */
	sprintf( __( 'Order #%s', 'drywall-toolbox' ), $order->get_order_number() )
) : '';
?>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

$cancellation_note = trim( (string) $order->get_meta( '_dtb_cancellation_email_note', true ) );
if ( '' !== $cancellation_note ) {
	$note_font = function_exists( 'dtb_email_font_stack' ) ? dtb_email_font_stack() : 'Arial, sans-serif';
	if ( $plain_text ) {
		echo "\n" . esc_html__( 'A message about your order:', 'drywall-toolbox' ) . "\n" . esc_html( $cancellation_note ) . "\n";
	} else {
		echo '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="87%" align="center" bgcolor="#f4f8ff" style="width:87%;max-width:592px;margin:22px auto;border-collapse:separate;border:1px solid #dce7f5;border-left:4px solid #2255ee;border-radius:10px;background-color:#f4f8ff;">';
		echo '<tr><td style="padding:20px 22px;font-family:' . esc_attr( $note_font ) . ';">';
		echo '<p style="margin:0 0 6px;color:#2255ee;font-size:10px;font-weight:700;line-height:15px;letter-spacing:1.2px;text-transform:uppercase;">' . esc_html__( 'From our team', 'drywall-toolbox' ) . '</p>';
		echo '<p style="margin:0 0 8px;color:#10213a;font-size:17px;font-weight:700;line-height:24px;">' . esc_html__( 'A message about your order', 'drywall-toolbox' ) . '</p>';
		echo '<p style="margin:0;color:#52627e;font-size:14px;line-height:22px;">' . nl2br( esc_html( $cancellation_note ) ) . '</p>';
		echo '</td></tr></table>';
	}
}

echo function_exists( 'dtb_email_support_card' ) ? dtb_email_support_card( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	__( 'We’re happy to answer questions about your order.', 'drywall-toolbox' ),
	function_exists( 'dtb_email_support_url' ) ? dtb_email_support_url() : home_url( '/contact/' ),
	__( 'Contact us', 'drywall-toolbox' ),
	'support',
	__( 'Need a hand?', 'drywall-toolbox' )
) : '';

if ( $additional_content ) {
	echo '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo '</td></tr></table>';
}

do_action( 'woocommerce_email_footer', $email );
