<?php
/**
 * DTB branded WooCommerce customer registration email.
 * Native account URLs and lifecycle remain WooCommerce-owned.
 *
 * @package DrywalltoolboxCommerce
 */
defined( 'ABSPATH' ) || exit;
require_once dirname( __DIR__, 2 ) . '/AccountPresentation.php';

do_action( 'woocommerce_email_header', $email_heading, $email );

$action_url = ! empty( $password_generated ) && ! empty( $set_password_url )
	? $set_password_url
	: wc_get_page_permalink( 'myaccount' );
$action_label = ! empty( $password_generated ) && ! empty( $set_password_url )
	? __( 'Set your password', 'drywall-toolbox' )
	: __( 'Go to your account', 'drywall-toolbox' );

echo \DTB\Commerce\Email\dtb_email_account_notice(
	__( 'YOUR DRYWALL TOOLBOX ACCOUNT', 'drywall-toolbox' ),
	__( 'Welcome to Drywall Toolbox.', 'drywall-toolbox' ),
	__( 'Your account is ready to help you keep orders, saved addresses and project essentials organized.', 'drywall-toolbox' ),
	sprintf( __( 'Hi %s,', 'drywall-toolbox' ), (string) $user_display_name ),
	(string) $user_login,
	(string) $action_url,
	$action_label
); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

if ( ! empty( $additional_content ) ) {
	echo '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"><tr><td class="email-additional-content">';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo '</td></tr></table>';
}

do_action( 'woocommerce_email_footer', $email );
