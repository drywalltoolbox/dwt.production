<?php
/** DTB canonical customer completed-order email. @package DrywalltoolboxCommerce */
defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
echo function_exists( 'DTB\\Commerce\\Email\\dtb_email_v4_order_hero' ) ? DTB\Commerce\Email\dtb_email_v4_order_hero( $order, $email ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

echo function_exists( 'DTB\\Commerce\\Email\\dtb_email_v3_support_panel' ) ? DTB\Commerce\Email\dtb_email_v3_support_panel( __( 'Questions about this order?', 'drywall-toolbox' ), __( 'Our support team is here to help.', 'drywall-toolbox' ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

if ( $additional_content ) {
	echo '<table border="0" cellpadding="0" cellspacing="0" width="87%" align="center" role="presentation"><tr><td class="email-additional-content">';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo '</td></tr></table>';
}

do_action( 'woocommerce_email_footer', $email );
