<?php
/** DTB canonical customer fulfillment-updated email. @package DrywalltoolboxCommerce */
defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
echo function_exists( 'DTB\\Commerce\\Email\\dtb_email_v4_order_hero' ) ? DTB\Commerce\Email\dtb_email_v4_order_hero( $order, $email ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

$customer_note_text = is_scalar( $customer_note ?? null ) ? trim( (string) $customer_note ) : '';
if ( '' !== $customer_note_text ) {
	echo function_exists( 'dtb_email_note_box_light' ) ? dtb_email_note_box_light( $customer_note_text ) : '<blockquote>' . esc_html( $customer_note_text ) . '</blockquote>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

do_action( 'woocommerce_email_fulfillment_details', $order, $fulfillment, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_fulfillment_meta', $order, $fulfillment, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

echo function_exists( 'DTB\\Commerce\\Email\\dtb_email_v3_support_panel' ) ? DTB\Commerce\Email\dtb_email_v3_support_panel( __( 'Questions about your shipment?', 'drywall-toolbox' ), __( 'Our support team is here to help.', 'drywall-toolbox' ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

if ( $additional_content ) {
	echo '<table border="0" cellpadding="0" cellspacing="0" width="87%" align="center" role="presentation"><tr><td class="email-additional-content">';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo '</td></tr></table>';
}

do_action( 'woocommerce_email_footer', $email );
