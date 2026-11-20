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

/** Render an Outlook-safe image-led welcome panel with separate copy and account action. */
function dtb_email_new_account_hero( string $action_url = '', string $action_label = '' ): string {
	$font = function_exists( 'dtb_email_font_stack' ) ? \dtb_email_font_stack() : "'Geist',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
	$palette = function_exists( 'dtb_email_palette' ) ? \dtb_email_palette( 'light' ) : [];
	$blue    = esc_attr( $palette['accent'] ?? '#2255ee' );
	$image = home_url( '/assets/email/account-welcome-hero.jpeg' );
	$url  = esc_url( $action_url );
	$cta  = '';
	if ( '' !== $url && '' !== trim( $action_label ) ) {
		$cta = '<table class="dtb-account-welcome-action" role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin:22px 0 0;border-collapse:separate;"><tr><td align="center" bgcolor="#2255EE" style="border:1px solid #9db8ff;border-radius:9px;background-color:#2255EE;box-shadow:0 8px 18px rgba(8,31,74,.32);">'
			. '<a href="' . $url . '" style="display:inline-block;padding:15px 23px;color:#ffffff!important;font:700 15px/21px ' . esc_attr( $font ) . ';text-decoration:none;">' . esc_html( $action_label ) . ' &nbsp;&#8594;</a>'
			. '</td></tr></table>';
	}
	return '<table class="dtb-account-welcome-hero" role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" bgcolor="#edf2f7" style="width:100%;border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt;background-color:#edf2f7;">'
		. '<tr><td class="dtb-account-welcome-image-cell" bgcolor="#071827" style="padding:0;background-color:#071827;"><img class="dtb-account-welcome-image" src="' . esc_url( $image ) . '" width="600" alt="' . esc_attr__( 'Drywall finishing tools and fresh joint compound', 'drywall-toolbox' ) . '" style="display:block;width:100%;max-width:600px;height:auto;border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;"></td></tr>'
		. '<tr><td class="dtb-account-welcome-copy" align="left" bgcolor="#edf2f7" style="padding:32px 38px 36px;background-color:#edf2f7;font-family:' . esc_attr( $font ) . ';">'
		. '<p style="margin:0 0 12px;color:#42536b;font:700 10px/15px ' . esc_attr( $font ) . ';letter-spacing:1.8px;text-transform:uppercase;">' . esc_html__( 'Your account is ready', 'drywall-toolbox' ) . '</p>'
		. '<h1 class="dtb-account-welcome-title" style="margin:0;color:#10213a;font-family:' . esc_attr( $font ) . ';font-size:34px;line-height:37px;font-weight:800;letter-spacing:-.8px;text-transform:uppercase;"><span class="dtb-account-welcome-title-line" style="display:block;color:#10213a;font-family:' . esc_attr( $font ) . ';font-size:34px;line-height:36px;font-weight:800;letter-spacing:-.6px;white-space:nowrap;">' . esc_html__( 'WELCOME TO', 'drywall-toolbox' ) . '</span><span class="dtb-account-welcome-brand dtb-account-welcome-title-brand" style="display:block;margin-top:1px;padding:0;color:#' . ltrim( $blue, '#' ) . ';font-family:' . esc_attr( $font ) . ';font-size:30px;line-height:33px;font-weight:850;letter-spacing:.2px;white-space:nowrap;">' . esc_html__( 'DRYWALL TOOLBOX', 'drywall-toolbox' ) . '</span></h1>'
		. '<p style="max-width:470px;margin:13px 0 0;color:#34445e;font:450 15px/23px ' . esc_attr( $font ) . ';">' . esc_html__( 'Your orders, delivery updates, and project essentials—all in one place.', 'drywall-toolbox' ) . '</p>'
		. $cta
		. '</td></tr></table>';
}

/** Render the welcome offer using only a verified personal coupon code. */
function dtb_email_new_account_offer( string $coupon_code ): string {
	$font = function_exists( 'dtb_email_font_stack' ) ? \dtb_email_font_stack() : "'Geist',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
	$palette = function_exists( 'dtb_email_palette' ) ? \dtb_email_palette( 'light' ) : [];
	$blue    = esc_attr( $palette['accent'] ?? '#2255ee' );
	return '<table class="dtb-account-offer" role="presentation" border="0" cellpadding="0" cellspacing="0" width="92%" align="center" bgcolor="#f2f5f8" style="width:92%;max-width:600px;margin:0 auto 20px;border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt;border-top:3px solid ' . $blue . ';border-bottom:1px solid #d9e1ea;background-color:#f2f5f8;font-family:' . esc_attr( $font ) . ';">'
		. '<tr><td class="dtb-account-offer-copy" align="left" bgcolor="#f2f5f8" style="padding:22px 22px 20px;background-color:#f2f5f8;vertical-align:top;">'
		. '<p style="margin:0 0 8px;color:' . $blue . ';font:700 10px/15px ' . esc_attr( $font ) . ';letter-spacing:1.8px;text-transform:uppercase;">' . esc_html__( 'A welcome benefit', 'drywall-toolbox' ) . '</p>'
		. '<p style="margin:0;color:#071827;font:760 34px/38px ' . esc_attr( $font ) . ';letter-spacing:-.8px;">' . esc_html__( '10% off', 'drywall-toolbox' ) . '</p>'
		. '<p style="margin:1px 0 0;color:#071827;font:650 17px/23px ' . esc_attr( $font ) . ';letter-spacing:-.2px;">' . esc_html__( 'your first order', 'drywall-toolbox' ) . '</p>'
		. '<p style="margin:8px 0 0;color:#45566c;font:400 13px/19px ' . esc_attr( $font ) . ';">' . esc_html__( 'A thank-you for joining us. Enjoy 10% off your first eligible order.', 'drywall-toolbox' ) . '</p>'
		. '</td></tr>'
		. '<tr><td class="dtb-account-offer-code" align="left" bgcolor="#071827" style="padding:18px 22px 20px;border-top:3px solid ' . $blue . ';background-color:#071827;vertical-align:top;">'
		. '<p style="margin:0 0 8px;color:#d7e1ee;font:650 10px/15px ' . esc_attr( $font ) . ';letter-spacing:1.6px;text-transform:uppercase;">' . esc_html__( 'Your unique code', 'drywall-toolbox' ) . '</p>'
		. '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" bgcolor="' . $blue . '" style="width:100%;border-collapse:collapse;background-color:' . $blue . ';"><tr><td style="padding:11px 12px;color:#ffffff;font:700 12px/18px Consolas,\'Courier New\',monospace;letter-spacing:0;word-break:break-all;overflow-wrap:anywhere;">' . esc_html( $coupon_code ) . '</td></tr></table>'
		. '<p style="margin:8px 0 0;color:#e4eaf2;font:400 12px/17px ' . esc_attr( $font ) . ';">' . esc_html__( 'Enter at checkout.', 'drywall-toolbox' ) . '</p>'
		. '</td></tr></table>';
}

/** Render compact account benefits with custom, email-safe numbered marks. */
function dtb_email_new_account_benefits(): string {
	$font = function_exists( 'dtb_email_font_stack' ) ? \dtb_email_font_stack() : "'Geist',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
	$palette = function_exists( 'dtb_email_palette' ) ? \dtb_email_palette( 'light' ) : [];
	$blue    = esc_attr( $palette['accent'] ?? '#2255ee' );
	$items = [
		[ __( '01', 'drywall-toolbox' ), __( 'Follow your orders', 'drywall-toolbox' ), __( 'See order details and delivery progress in one place.', 'drywall-toolbox' ) ],
		[ __( '02', 'drywall-toolbox' ), __( 'Check out faster', 'drywall-toolbox' ), __( 'Keep your checkout details ready in your account.', 'drywall-toolbox' ) ],
		[ __( '03', 'drywall-toolbox' ), __( 'Stay project-ready', 'drywall-toolbox' ), __( 'Keep your account and tool orders organized.', 'drywall-toolbox' ) ],
	];
	$cells = '';
	foreach ( $items as [ $number, $title, $description ] ) {
		$cells .= '<tr>'
			. '<td width="48" valign="top" style="width:48px;padding:14px 12px 14px 0;border-bottom:1px solid #dbe2ea;vertical-align:top;">'
			. '<span style="display:inline-block;padding:5px 6px;background-color:' . $blue . ';color:#ffffff;font:700 10px/14px ' . esc_attr( $font ) . ';letter-spacing:.5px;">' . esc_html( $number ) . '</span>'
			. '</td><td valign="top" style="padding:13px 0 14px;border-bottom:1px solid #dbe2ea;vertical-align:top;">'
			. '<p style="margin:0;color:#10213a;font:700 15px/20px ' . esc_attr( $font ) . ';letter-spacing:-.2px;">' . esc_html( $title ) . '</p>'
			. '<p style="margin:3px 0 0;color:#52627e;font:400 13px/19px ' . esc_attr( $font ) . ';">' . esc_html( $description ) . '</p>'
			. '</td></tr>';
	}
	return '<table class="dtb-account-benefits" role="presentation" border="0" cellpadding="0" cellspacing="0" width="92%" align="center" bgcolor="#ffffff" style="width:92%;max-width:600px;margin:0 auto 22px;border-collapse:collapse;border-top:1px solid #cbd5e1;border-bottom:2px solid #10253c;background-color:#ffffff;font-family:' . esc_attr( $font ) . ';">'
		. '<tr><td colspan="2" style="padding:15px 0 5px;color:' . $blue . ';font:700 10px/15px ' . esc_attr( $font ) . ';letter-spacing:1.7px;text-transform:uppercase;">' . esc_html__( 'Account tools', 'drywall-toolbox' ) . '</td></tr>'
		. $cells
		. '</table>';
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
