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

$signup_user      = get_user_by( 'login', (string) $user_login );
$signup_coupon_id = $signup_user instanceof WP_User
	? absint( get_user_meta( $signup_user->ID, '_dtb_signup_coupon_id', true ) )
	: 0;
if ( $signup_coupon_id && class_exists( 'WC_Coupon' ) ) {
	$signup_coupon = new WC_Coupon( $signup_coupon_id );
	$is_personal    = $signup_coupon->get_id()
		&& absint( $signup_coupon->get_meta( '_dtb_signup_coupon_user_id', true ) ) === (int) $signup_user->ID
		&& 'welcome-10-first-order' === (string) $signup_coupon->get_meta( '_dtb_signup_coupon_campaign_id', true );
	if ( $is_personal ) {
		$coupon_terms = [ __( 'One use on your first eligible order. This personal code is restricted to your account email.', 'drywall-toolbox' ) ];
		if ( $signup_coupon->get_exclude_sale_items() ) {
			$coupon_terms[] = __( 'Sale items are excluded.', 'drywall-toolbox' );
		}
		if ( $signup_coupon->get_individual_use() ) {
			$coupon_terms[] = __( 'It cannot be combined with other coupons.', 'drywall-toolbox' );
		}
		$minimum_amount = (float) $signup_coupon->get_minimum_amount();
		if ( $minimum_amount > 0 ) {
			$coupon_terms[] = sprintf( __( 'Minimum spend: %s.', 'drywall-toolbox' ), wp_strip_all_tags( wc_price( $minimum_amount ) ) );
		}
		$expires = $signup_coupon->get_date_expires();
		if ( $expires instanceof WC_DateTime ) {
			$coupon_terms[] = sprintf( __( 'Expires %s.', 'drywall-toolbox' ), wp_date( get_option( 'date_format' ), $expires->getTimestamp() ) );
		}

		echo '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"><tr><td style="padding:20px 24px;background:#eef4ff;border:1px solid #d7e5ff;border-radius:10px;text-align:center">';
		echo '<p style="margin:0 0 8px;color:#12213a;font-size:18px;font-weight:700">' . esc_html__( 'Your 10% first-order coupon', 'drywall-toolbox' ) . '</p>';
		echo '<p style="margin:0 0 8px;color:#1769ff;font-size:23px;font-weight:800;letter-spacing:2px">' . esc_html( $signup_coupon->get_code() ) . '</p>';
		echo '<p style="margin:0;color:#596274;font-size:13px;line-height:1.5">' . esc_html( implode( ' ', $coupon_terms ) ) . '</p>';
		echo '</td></tr></table>';
	}
}

if ( ! empty( $additional_content ) ) {
	echo '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"><tr><td class="email-additional-content">';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo '</td></tr></table>';
}

do_action( 'woocommerce_email_footer', $email );
