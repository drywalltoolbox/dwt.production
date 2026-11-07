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

echo \DTB\Commerce\Email\dtb_email_new_account_hero(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

$account_font = function_exists( 'dtb_email_font_stack' ) ? dtb_email_font_stack() : "'Geist',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
echo '<table class="dtb-account-identity" role="presentation" border="0" cellpadding="0" cellspacing="0" width="92%" align="center" bgcolor="#f2f7ff" style="width:92%;max-width:600px;margin:16px auto 20px;border-collapse:separate;border:1px solid #d9e5f8;border-radius:14px;background-color:#f2f7ff;font-family:' . esc_attr( $account_font ) . ';">';
echo '<tr><td class="dtb-account-identity-copy" width="60%" valign="middle" style="width:60%;padding:22px 16px 22px 24px;vertical-align:middle;">';
echo '<p style="margin:0;color:#10213a;font:750 21px/28px ' . esc_attr( $account_font ) . ';letter-spacing:-.3px;">' . esc_html( sprintf( __( 'Welcome, %s.', 'drywall-toolbox' ), (string) $user_display_name ) ) . '</p>';
echo '<p style="margin:4px 0 13px;color:#34445e;font:400 15px/22px ' . esc_attr( $account_font ) . ';">' . esc_html__( 'You’re all set. Your account is ready whenever you need it.', 'drywall-toolbox' ) . '</p>';
echo '<p style="margin:0;color:#52627e;font:400 13px/19px ' . esc_attr( $account_font ) . ';">' . esc_html__( 'Account username:', 'drywall-toolbox' ) . '<br><strong style="color:#10213a;font-size:16px;font-weight:700;">' . esc_html( (string) $user_login ) . '</strong></p>';
echo '</td><td class="dtb-account-identity-action" width="40%" align="center" valign="middle" style="width:40%;padding:20px 20px 20px 8px;vertical-align:middle;">';
if ( '' !== esc_url( $action_url ) ) {
	 echo '<table role="presentation" border="0" cellpadding="0" cellspacing="0" align="center" style="border-collapse:separate;margin:0 auto;"><tr><td align="center" bgcolor="#2255EE" style="border-radius:8px;background-color:#2255EE;">';
	 echo '<a href="' . esc_url( $action_url ) . '" style="display:inline-block;padding:15px 22px;color:#ffffff!important;font:700 15px/21px ' . esc_attr( $account_font ) . ';text-decoration:none;">' . esc_html( $action_label ) . ' &nbsp;&#8594;</a>';
	echo '</td></tr></table>';
}
echo '</td></tr></table>';

$signup_user      = get_user_by( 'login', (string) $user_login );
$signup_coupon_id = $signup_user instanceof WP_User
	? absint( get_user_meta( $signup_user->ID, '_dtb_signup_coupon_id', true ) )
	: 0;
$welcome_coupon_code  = '';
$welcome_coupon_terms = [];
if ( $signup_coupon_id && class_exists( 'WC_Coupon' ) ) {
	$signup_coupon = new WC_Coupon( $signup_coupon_id );
	$is_personal    = $signup_coupon->get_id()
		&& absint( $signup_coupon->get_meta( '_dtb_signup_coupon_user_id', true ) ) === (int) $signup_user->ID
		&& 'welcome-10-first-order' === (string) $signup_coupon->get_meta( '_dtb_signup_coupon_campaign_id', true );
	$is_welcome_offer = $is_personal
		&& 'percent' === $signup_coupon->get_discount_type()
		&& 10.0 === (float) $signup_coupon->get_amount();
	if ( $is_welcome_offer ) {
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
		$welcome_coupon_code  = (string) $signup_coupon->get_code();
		$welcome_coupon_terms = $coupon_terms;
	}
}

if ( ! empty( $dtb_email_preview ) ) {
	$welcome_coupon_code  = 'PREVIEW10';
	$welcome_coupon_terms = [ __( 'Preview only. Real welcome codes are generated for eligible new accounts.', 'drywall-toolbox' ) ];
}

if ( '' !== $welcome_coupon_code ) {
	echo \DTB\Commerce\Email\dtb_email_new_account_offer( $welcome_coupon_code ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo \DTB\Commerce\Email\dtb_email_new_account_benefits(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo \DTB\Commerce\Email\dtb_email_new_account_terms( $welcome_coupon_terms ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

if ( ! empty( $additional_content ) ) {
	echo '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"><tr><td class="email-additional-content">';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo '</td></tr></table>';
}

do_action( 'woocommerce_email_footer', $email );
