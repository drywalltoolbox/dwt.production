<?php
/**
 * Coming-soon subscriber launch announcement template and synthetic preview.
 *
 * This renderer does not read subscriber records, schedule a campaign, or send
 * messages. Keep any future sender separately gated to the official launch.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'dtb_register_launch_email_preview' );

/** Return the proposed subject line for a future approved launch send. */
function dtb_launch_announcement_subject(): string {
	return __( 'We’re Live! The New Drywall Toolbox Website Is Now Live', 'dtb' );
}

/** Register the synthetic launch-email preview for administrators. */
function dtb_register_launch_email_preview(): void {
	add_options_page(
		__( 'Launch Email Preview', 'dtb' ),
		__( 'Launch Email Preview', 'dtb' ),
		'manage_options',
		'dtb-launch-email-preview',
		'dtb_render_launch_email_preview'
	);
}

/**
 * Render the launch announcement email.
 *
 * Pass a per-recipient signed unsubscribe URL from a future approved sender.
 * An empty URL uses a non-functional preview-only destination.
 *
 * @param string $unsubscribe_url Recipient-specific unsubscribe URL.
 * @return string
 */
function dtb_render_launch_announcement_email( string $unsubscribe_url = '' ): string {
	if ( ! function_exists( 'dtb_render_branded_email' ) ) {
		return '';
	}

	$template_path = __DIR__ . '/LaunchEmailTemplate.html';
	$template      = is_readable( $template_path ) ? file_get_contents( $template_path ) : false;

	if ( false === $template ) {
		return '';
	}
	$offer_active = class_exists( 'DTB_SignupCouponCampaign' ) && DTB_SignupCouponCampaign::is_active();
	$hero_url = $offer_active
		? apply_filters( 'dtb_launch_email_hero_url', home_url( '/wp/wp-content/uploads/2026/11/dtb-launch-hero.webp' ) )
		: home_url( '/assets/email/account-welcome-hero.jpeg' );
	$hero_html = $offer_active
		? '<img src="' . esc_url( $hero_url ) . '" width="620" alt="We’re live. The new Drywall Toolbox website is now open for professional tools, replacement parts, schematics, and repair service. New account members receive 10% off their first order." style="display:block;width:100%;max-width:620px;height:auto;border:0;outline:0;text-decoration:none">'
		: '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#071827" style="width:100%;background:#071827"><tr><td width="58%" valign="middle" style="width:58%;padding:28px 18px 28px 28px;color:#fff"><p style="margin:0 0 8px;color:#9dbbff;font-size:10px;line-height:15px;font-weight:800;letter-spacing:1.4px">THE NEW WEBSITE IS NOW LIVE</p><h1 style="margin:0;color:#fff;font-family:Arial,sans-serif;font-size:32px;line-height:35px;font-weight:900;font-style:italic">WE’RE LIVE</h1><p style="margin:9px 0 0;color:#f3f6fc;font-size:13px;line-height:19px">Shop tools, find replacement parts, view schematics, and explore repair services.</p></td><td width="42%" style="width:42%"><img src="' . esc_url( $hero_url ) . '" width="260" alt="Drywall Toolbox professional drywall tools" style="display:block;width:100%;height:auto;border:0"></td></tr></table>';
	$account_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account' );

	$values = array(
		'{{shop_url}}'     => esc_url( home_url( '/products' ) ),
		'{{parts_url}}'    => esc_url( home_url( '/parts' ) ),
		'{{schematics_url}}' => esc_url( home_url( '/schematics' ) ),
		'{{repair_url}}'   => esc_url( home_url( '/repairs/packages' ) ),
		'{{home_url}}'     => esc_url( home_url( '/' ) ),
		'{{account_url}}'  => esc_url( $account_url ),
		'{{hero_html}}'    => $hero_html,
		'{{tools_icon}}'   => function_exists( 'dtb_email_icon' ) ? dtb_email_icon( 'package', 44 ) : '',
		'{{parts_icon}}'   => function_exists( 'dtb_email_icon' ) ? dtb_email_icon( 'parts', 44 ) : '',
		'{{schematic_icon}}' => function_exists( 'dtb_email_icon' ) ? dtb_email_icon( 'schematics', 44 ) : '',
		'{{repair_icon}}'  => function_exists( 'dtb_email_icon' ) ? dtb_email_icon( 'repairs', 44 ) : '',
	);

	$content = strtr( $template, $values );
	$unsubscribe_url = esc_url_raw( $unsubscribe_url );
	$footer_note     = '';
	if ( '' !== $unsubscribe_url && wp_http_validate_url( $unsubscribe_url ) ) {
		$footer_note = sprintf(
			/* translators: %s: unsubscribe link. */
			__( 'You are receiving this launch announcement because you signed up for Drywall Toolbox launch updates. %s', 'dtb' ),
			'<a href="' . esc_url( $unsubscribe_url ) . '" style="color:#ffffff;text-decoration:underline">Unsubscribe from launch emails</a>'
		);
	}

	return dtb_render_branded_email(
		array(
			'title'       => __( 'We’re live', 'dtb' ),
			'preheader'   => __( 'The new Drywall Toolbox is live. Shop tools, find parts, view schematics, and explore repair services.', 'dtb' ),
			'theme'       => 'light',
			'hide_heading'  => true,
			'flush_body'    => true,
			'hide_signoff'  => true,
			'greeting'    => '',
			'intro'       => $content,
			'cta_url'     => '',
			'cta_label'   => '',
			'footer_note' => $footer_note,
		)
	);
}

/** Render a synthetic preview without looking up recipients or sending email. */
function dtb_render_launch_email_preview(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'dtb' ) );
	}

	$html = dtb_render_launch_announcement_email();
	// Keep scripts disabled while retaining the parent origin so the synthetic
	// preview can load the same-origin, self-hosted Geist WOFF2 font.
	echo '<div class="wrap"><h1>' . esc_html__( 'Launch Email Preview', 'dtb' ) . '</h1><p><strong>' . esc_html__( 'Subject:', 'dtb' ) . '</strong> ' . esc_html( dtb_launch_announcement_subject() ) . '</p><p>' . esc_html__( 'Synthetic design preview only. No subscriber data is loaded and no email can be sent from this screen. The future launch send must be approved after the site is officially live.', 'dtb' ) . '</p><iframe title="Synthetic launch email preview" sandbox="allow-same-origin" style="display:block;width:min(100%,760px);height:1450px;border:1px solid #c3c4c7;background:#edf2f7" srcdoc="' . esc_attr( $html ) . '"></iframe></div>';
}
