<?php
/**
 * DTB branded customer note email.
 *
 * Traced against WooCommerce core emails/customer-note.php v10.4.0. DTB
 * customization: hero; the note now uses dtb_email_note_box_light() instead
 * of dtb_email_note_box() — the latter is hardcoded to dark-theme colors
 * (built for the separate dtb_render_branded_email() dark shell) and
 * previously rendered as a stray dark box inside this light-themed email.
 * Hook sequence preserved.
 *
 * @package DrywalltoolboxCommerce
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

echo function_exists( 'DTB\\Commerce\\Email\\dtb_email_v4_order_hero' )
	? DTB\Commerce\Email\dtb_email_v4_order_hero( $order, $email, [
		'title' => __( 'A note on your order', 'drywall-toolbox' ),
		'body' => __( 'You have a new update about your order. Details are included below.', 'drywall-toolbox' ),
		'steps' => [],
	] )
	: ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>

<div class="email-introduction">
	<p><?php esc_html_e( 'A note has been added to your order:', 'drywall-toolbox' ); ?></p>
</div>

<?php
$safe_note = wc_wptexturize_order_note( $customer_note );
echo function_exists( 'dtb_email_note_box_light' ) ? dtb_email_note_box_light( wp_strip_all_tags( $safe_note ) ) : '<blockquote>' . wpautop( make_clickable( $safe_note ) ) . '</blockquote>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

echo function_exists( 'dtb_email_support_card' ) ? dtb_email_support_card( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	__( 'Questions about this note or your order? Our team is here to help.', 'drywall-toolbox' ),
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
