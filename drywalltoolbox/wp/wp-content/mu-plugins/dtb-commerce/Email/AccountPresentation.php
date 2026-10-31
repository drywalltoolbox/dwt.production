<?php
/**
 * DTB customer-account email presentation (classic HTML only).
 * Uses the canonical shared email-header, which renders the hosted DTB logo.
 * WooCommerce owns recipient, security URLs, lifecycle and email sending.
 *
 * @package DrywalltoolboxCommerce
 */
namespace DTB\Commerce\Email;

defined( 'ABSPATH' ) || exit;

/** Render the live-text welcome hero over the supplied brand artwork. */
function dtb_email_new_account_hero(): string {
	$font = function_exists( 'dtb_email_font_stack' ) ? \dtb_email_font_stack() : "'Geist',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
	$image = home_url( '/assets/email/account-welcome-hero.jpeg' );
	return '<table class="dtb-account-welcome-hero" role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" background="' . esc_url( $image ) . '" bgcolor="#071827" style="width:100%;border-collapse:collapse;background-color:#071827;background-image:linear-gradient(180deg,rgba(255,255,255,0) 0%,rgba(255,255,255,0) 88%,rgba(255,255,255,.18) 94%,#ffffff 100%),url(\'' . esc_url( $image ) . '\');background-position:center center,center center;background-repeat:no-repeat;background-size:100% 100%,cover;">'
		. '<tr><td class="dtb-account-welcome-copy" width="68%" align="left" valign="middle" style="width:68%;padding:38px 34px 40px 42px;background-color:transparent;font-family:' . esc_attr( $font ) . ';">'
		. '<p style="margin:0 0 13px;color:#ffffff;font:700 10px/16px ' . esc_attr( $font ) . ';letter-spacing:1.65px;text-transform:uppercase;">' . esc_html__( 'Your Drywall Toolbox account', 'drywall-toolbox' ) . '</p>'
		. '<h1 style="margin:0;color:#ffffff;font:800 38px/42px ' . esc_attr( $font ) . ';letter-spacing:-.75px;"><span style="display:block;">' . esc_html__( 'WELCOME TO', 'drywall-toolbox' ) . '</span><span style="display:block;color:#2255EE;white-space:nowrap;">' . esc_html__( 'DRYWALL TOOLBOX', 'drywall-toolbox' ) . '</span></h1>'
		. '<p style="max-width:410px;margin:14px 0 0;color:#f2f6fc;font:400 16px/24px ' . esc_attr( $font ) . ';">' . esc_html__( 'Thanks for joining us. Your orders, saved addresses and project essentials are now in one place.', 'drywall-toolbox' ) . '</p>'
		. '</td><td class="dtb-account-welcome-spacer" width="32%" style="width:32%;font-size:1px;line-height:1px;">&nbsp;</td></tr></table>';
}

/** Render the welcome offer using only a verified personal coupon code. */
function dtb_email_new_account_offer( string $coupon_code ): string {
	$font = function_exists( 'dtb_email_font_stack' ) ? \dtb_email_font_stack() : "'Geist',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
	$offer_icon = function_exists( 'dtb_email_icon' ) ? dtb_email_icon( 'package', 30 ) : '';
	return '<table class="dtb-account-offer" role="presentation" border="0" cellpadding="0" cellspacing="0" width="92%" align="center" bgcolor="#f2f7ff" style="width:92%;max-width:600px;margin:0 auto 18px;border-collapse:separate;border:1px solid #d9e5f8;border-radius:14px;background-color:#f2f7ff;font-family:' . esc_attr( $font ) . ';">'
		. '<tr><td class="dtb-account-offer-copy" width="58%" valign="middle" style="width:58%;padding:22px 20px;vertical-align:middle;">'
		. '<table class="dtb-account-offer-copy-inner" role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:collapse;"><tr>'
		. '<td class="dtb-account-offer-icon-cell" width="56" valign="top" style="width:56px;padding:2px 14px 0 0;vertical-align:top;"><table role="presentation" border="0" cellpadding="0" cellspacing="0" width="48" height="48" bgcolor="#e4edff" style="width:48px;height:48px;border-collapse:separate;border-radius:12px;background-color:#e4edff;"><tr><td align="center" valign="middle" style="width:48px;height:48px;">' . $offer_icon . '</td></tr></table></td>'
		. '<td valign="middle" style="padding:0;vertical-align:middle;">'
		. '<p style="margin:0 0 7px;color:#2255EE;font:700 11px/16px ' . esc_attr( $font ) . ';letter-spacing:1.35px;text-transform:uppercase;">' . esc_html__( 'Welcome offer', 'drywall-toolbox' ) . '</p>'
		. '<p style="margin:0;color:#071827;font:800 40px/43px ' . esc_attr( $font ) . ';letter-spacing:-1.3px;">' . esc_html__( '10% Off', 'drywall-toolbox' ) . '</p>'
		. '<p style="margin:0;color:#071827;font:750 20px/26px ' . esc_attr( $font ) . ';letter-spacing:-.25px;">' . esc_html__( 'Your First Order', 'drywall-toolbox' ) . '</p>'
		. '<p style="margin:8px 0 0;color:#263c5c;font:400 13px/19px ' . esc_attr( $font ) . ';">' . esc_html__( 'A thank-you for joining us. Enjoy 10% off your first eligible order.', 'drywall-toolbox' ) . '</p>'
		. '</td></tr></table>'
		. '</td><td class="dtb-account-offer-code" width="42%" align="center" valign="middle" bgcolor="#06245a" style="width:42%;padding:22px 17px;border-left:5px solid #2255EE;border-radius:0 12px 12px 0;background-color:#06245a;vertical-align:middle;">'
		. '<p style="margin:0 0 11px;color:#ffffff;font:700 11px/16px ' . esc_attr( $font ) . ';letter-spacing:1.5px;text-transform:uppercase;">' . esc_html__( 'Your unique code', 'drywall-toolbox' ) . '</p>'
		. '<p style="margin:0;padding:14px 8px;border:2px dashed #2255EE;border-radius:9px;background-color:#ffffff;color:#2255EE;font:800 21px/27px ' . esc_attr( $font ) . ';letter-spacing:.5px;overflow-wrap:anywhere;">' . esc_html( $coupon_code ) . '</p>'
		. '<p style="margin:9px 0 0;color:#ffffff;font:400 12px/17px ' . esc_attr( $font ) . ';">' . esc_html__( 'Use this code at checkout.', 'drywall-toolbox' ) . '</p>'
		. '</td></tr></table>';
}

/** Render truthful, compact account benefits using hosted email-safe icons. */
function dtb_email_new_account_benefits(): string {
	$font = function_exists( 'dtb_email_font_stack' ) ? \dtb_email_font_stack() : "'Geist',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
	$items = [
		[ 'package', __( 'Track orders', 'drywall-toolbox' ), __( 'Follow purchases through delivery.', 'drywall-toolbox' ) ],
		[ 'location', __( 'Save addresses', 'drywall-toolbox' ), __( 'Keep shipping details ready for next time.', 'drywall-toolbox' ) ],
		[ 'clipboard', __( 'Revisit purchases', 'drywall-toolbox' ), __( 'Find order history and details in your account.', 'drywall-toolbox' ) ],
	];
	$cells = '';
	foreach ( $items as $index => [ $icon, $title, $description ] ) {
		$icon_html = function_exists( 'dtb_email_icon' ) ? dtb_email_icon( $icon, 28 ) : '';
		$border = 2 === $index ? '' : 'border-right:1px solid #d7e3f5;';
		$cells .= '<td class="dtb-account-benefit" width="33.33%" align="center" valign="top" style="width:33.33%;padding:15px 12px 16px;' . $border . 'vertical-align:top;">'
			. '<table role="presentation" border="0" cellpadding="0" cellspacing="0" align="center" style="margin:0 auto;border-collapse:collapse;"><tr><td align="center" valign="middle" style="padding:0 0 8px;">' . $icon_html . '</td></tr></table>'
			. '<p style="margin:0;color:#10213a;font:700 14px/19px ' . esc_attr( $font ) . ';letter-spacing:-.1px;">' . esc_html( $title ) . '</p>'
			. '<p style="margin:5px 0 0;color:#52627e;font:400 12px/18px ' . esc_attr( $font ) . ';">' . esc_html( $description ) . '</p>'
			. '</td>';
	}
	return '<table class="dtb-account-benefits" role="presentation" border="0" cellpadding="0" cellspacing="0" width="92%" align="center" bgcolor="#f2f7ff" style="width:92%;max-width:600px;margin:0 auto 14px;border-collapse:separate;border:1px solid #e0e9f7;border-radius:14px;background-color:#f2f7ff;font-family:' . esc_attr( $font ) . ';">'
		. '<tr><td class="dtb-account-benefits-heading" colspan="3" align="center" style="padding:17px 12px 0;color:#173563;font:700 10px/16px ' . esc_attr( $font ) . ';letter-spacing:2.1px;text-transform:uppercase;">' . esc_html__( 'With your account, you can', 'drywall-toolbox' ) . '</td></tr>'
		. '<tr class="dtb-account-benefit-row">' . $cells . '</tr></table>';
}

/** Render campaign terms after the offer and benefits panels. */
function dtb_email_new_account_terms( array $terms ): string {
	$font = function_exists( 'dtb_email_font_stack' ) ? \dtb_email_font_stack() : "'Geist',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
	$term_text = implode( ' ', array_map( 'sanitize_text_field', $terms ) );
	return '<p style="margin:0 20px 22px;color:#52627e;text-align:center;font:400 11px/17px ' . esc_attr( $font ) . ';font-style:italic;">' . esc_html( $term_text ) . '</p>';
}

/** Render one responsive table-based account/security notice. */
function dtb_email_account_notice( string $eyebrow, string $title, string $description, string $greeting, string $username, string $action_url, string $action_label, string $footnote = '' ): string {
	$font = function_exists( 'dtb_email_font_stack' ) ? \dtb_email_font_stack() : "'Geist',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
	$url  = esc_url( $action_url );
	$cta  = '';
	if ( '' !== $url && '' !== trim( $action_label ) ) {
		$cta = '<table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin:24px 0 0;border-collapse:separate;"><tr><td bgcolor="#2255EE" style="border-radius:8px;background-color:#2255EE;">'
			. '<a href="' . $url . '" style="display:inline-block;padding:15px 25px;color:#ffffff!important;font-size:15px;font-weight:700;line-height:21px;text-decoration:none;font-family:' . esc_attr( $font ) . ';">'
			. esc_html( $action_label ) . '</a></td></tr></table>';
	}

	return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" bgcolor="#ffffff" style="width:100%;border-collapse:collapse;background-color:#ffffff;">'
		. '<tr><td align="center" style="padding:34px 24px 0;"><table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;max-width:592px;border-collapse:collapse;">'
		. '<tr><td style="padding:0;color:#2255EE;font:700 11px/17px ' . esc_attr( $font ) . ';letter-spacing:1.55px;text-transform:uppercase;">' . esc_html( $eyebrow ) . '</td></tr>'
		. '<tr><td style="padding:13px 0 0;color:#10213a;font:750 31px/38px ' . esc_attr( $font ) . ';letter-spacing:-.75px;">' . esc_html( $title ) . '</td></tr>'
		. '<tr><td style="padding:12px 0 0;color:#34445e;font:400 16px/25px ' . esc_attr( $font ) . ';">' . esc_html( $description ) . '</td></tr>'
		. '<tr><td style="padding:26px 0 0;"><table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" bgcolor="#f5f8ff" style="border-collapse:separate;background-color:#f5f8ff;border:1px solid #dbe6f7;border-radius:12px;"><tr><td style="padding:23px 25px;color:#34445e;font:400 15px/24px ' . esc_attr( $font ) . ';">'
		. '<p style="margin:0 0 13px;">' . esc_html( $greeting ) . '</p>'
		. '<p style="margin:0;color:#65738f;font-size:13px;line-height:21px;">' . esc_html__( 'Account username:', 'drywall-toolbox' ) . ' <strong style="color:#071b45;">' . esc_html( $username ) . '</strong></p>'
		. $cta
		. '</td></tr></table></td></tr>'
		. ( '' !== $footnote ? '<tr><td style="padding:22px 0 0;color:#65738f;font:400 13px/21px ' . esc_attr( $font ) . ';">' . esc_html( $footnote ) . '</td></tr>' : '' )
		. '<tr><td style="height:31px;font-size:1px;line-height:1px;">&nbsp;</td></tr>'
		. '</table></td></tr></table>';
}
