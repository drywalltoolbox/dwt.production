<?php
/**
 * DTB processing-order confirmation. Structural design only; WooCommerce
 * owns order data, lifecycle hooks and sending. No fulfillment state inferred.
 *
 * @package DrywalltoolboxCommerce
 */
defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

if ( $order instanceof WC_Order && function_exists( 'DTB\\Commerce\\Email\\dtb_email_order_confirmation_intro' ) ) {
	echo DTB\Commerce\Email\dtb_email_order_confirmation_intro( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/* Official WooCommerce hooks preserve items, totals, structured data and meta. */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

if ( ! empty( $additional_content ) ) {
	echo '<table role="presentation" width="87%" align="center" border="0" cellpadding="0" cellspacing="0"><tr><td class="email-additional-content">';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo '</td></tr></table>';
}

echo function_exists( 'DTB\\Commerce\\Email\\dtb_email_v3_support_panel' )
	? DTB\Commerce\Email\dtb_email_v3_support_panel() : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

do_action( 'woocommerce_email_footer', $email );
