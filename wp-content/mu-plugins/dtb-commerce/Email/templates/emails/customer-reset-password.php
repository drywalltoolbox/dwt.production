<?php
/**
 * DTB branded WooCommerce customer password-reset email.
 * Preserve WooCommerce's native password-reset key, user ID and login contract.
 *
 * @package DrywalltoolboxCommerce
 */
defined( 'ABSPATH' ) || exit;
require_once dirname( __DIR__, 2 ) . '/AccountPresentation.php';

do_action( 'woocommerce_email_header', $email_heading, $email );

$reset_url = add_query_arg(
	[
		'key' => $reset_key,
		'id' => $user_id,
		'login' => rawurlencode( $user_login ),
	],
	wc_get_endpoint_url( 'lost-password', '', wc_get_page_permalink( 'myaccount' ) )
);

echo \DTB\Commerce\Email\dtb_email_account_notice(
	__( 'ACCOUNT SECURITY', 'drywall-toolbox' ),
	__( 'Reset your password.', 'drywall-toolbox' ),
	__( 'A password reset was requested for your Drywall Toolbox account.', 'drywall-toolbox' ),
	sprintf( __( 'Hi %s,', 'drywall-toolbox' ), (string) $user_display_name ),
	(string) $user_login,
	(string) $reset_url,
	__( 'Reset your password', 'drywall-toolbox' ),
	__( 'If you did not request this, no action is needed. Your password will not change unless you use the reset link.', 'drywall-toolbox' )
); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

if ( ! empty( $additional_content ) ) {
	echo '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"><tr><td class="email-additional-content">';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo '</td></tr></table>';
}

do_action( 'woocommerce_email_footer', $email );
