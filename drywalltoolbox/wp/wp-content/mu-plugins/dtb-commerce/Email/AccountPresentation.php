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

/** Render one responsive table-based account/security notice. */
function dtb_email_account_notice( string $eyebrow, string $title, string $description, string $greeting, string $username, string $action_url, string $action_label, string $footnote = '' ): string {
	$font = function_exists( 'dtb_email_font_stack' ) ? \dtb_email_font_stack() : 'Arial, sans-serif';
	$url  = esc_url( $action_url );
	$cta  = '';
	if ( '' !== $url && '' !== trim( $action_label ) ) {
		$cta = '<table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin:23px 0 0;border-collapse:separate;"><tr><td bgcolor="#1769ff" style="border-radius:6px;background-color:#1769ff;">'
			. '<a href="' . $url . '" style="display:inline-block;padding:14px 25px;color:#ffffff!important;font-size:14px;font-weight:700;line-height:20px;text-decoration:none;font-family:' . esc_attr( $font ) . ';">'
			. esc_html( $action_label ) . '</a></td></tr></table>';
	}

	return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" bgcolor="#ffffff" style="width:100%;border-collapse:collapse;background-color:#ffffff;">'
		. '<tr><td align="center" style="padding:0 24px;"><table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;max-width:592px;border-collapse:collapse;">'
		. '<tr><td style="padding:31px 0 0;color:#1769ff;font:700 11px/16px ' . esc_attr( $font ) . ';letter-spacing:1.6px;text-transform:uppercase;">' . esc_html( $eyebrow ) . '</td></tr>'
		. '<tr><td style="padding:12px 0 0;color:#071b45;font:750 28px/35px ' . esc_attr( $font ) . ';">' . esc_html( $title ) . '</td></tr>'
		. '<tr><td style="padding:11px 0 0;color:#52627e;font:400 15px/24px ' . esc_attr( $font ) . ';">' . esc_html( $description ) . '</td></tr>'
		. '<tr><td style="padding:25px 0 0;"><table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" bgcolor="#f4f7fa" style="border-collapse:separate;background-color:#f4f7fa;border:1px solid #dce3ed;border-radius:8px;"><tr><td style="padding:22px 24px;color:#263c5c;font:400 14px/23px ' . esc_attr( $font ) . ';">'
		. '<p style="margin:0 0 13px;">' . esc_html( $greeting ) . '</p>'
		. '<p style="margin:0;color:#65738f;font-size:13px;line-height:21px;">' . esc_html__( 'Account username:', 'drywall-toolbox' ) . ' <strong style="color:#071b45;">' . esc_html( $username ) . '</strong></p>'
		. $cta
		. '</td></tr></table></td></tr>'
		. ( '' !== $footnote ? '<tr><td style="padding:22px 0 0;color:#65738f;font:400 13px/21px ' . esc_attr( $font ) . ';">' . esc_html( $footnote ) . '</td></tr>' : '' )
		. '<tr><td style="height:31px;font-size:1px;line-height:1px;">&nbsp;</td></tr>'
		. '</table></td></tr></table>';
}
