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
	return __( 'We’re live: explore Drywall Toolbox', 'dtb' );
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
	$offer_html = '';
	if ( class_exists( 'DTB_SignupCouponCampaign' ) && DTB_SignupCouponCampaign::is_active() ) {
		$account_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account' );
		$offer_html  = '<table role="presentation" width="100%" bgcolor="#101f37" style="width:100%;margin:22px 0 8px;border:1px solid #4774b0;border-left:5px solid #3472ff;border-collapse:separate"><tr><td style="padding:17px 20px;color:#f5f8ff;font-family:Arial,sans-serif;font-size:16px;line-height:24px"><span style="display:block;color:#9dbbff;font-size:10px;line-height:15px;font-weight:800;letter-spacing:1.5px">A WELCOME OFFER FOR NEW ACCOUNTS</span><strong style="display:inline-block;margin-top:4px;color:#fff;font-size:27px;line-height:32px;font-style:italic">10% OFF</strong><span style="display:inline-block;margin-left:8px;color:#dce7f7">your first order</span><br><a href="' . esc_url( $account_url ) . '" style="display:inline-block;margin-top:11px;padding:10px 15px;border-radius:5px;background:#2255ee;color:#fff;font-size:13px;line-height:17px;font-weight:700;text-decoration:none">Create your account &rarr;</a><span style="display:block;margin-top:9px;color:#b1bfd3;font-size:11px;line-height:16px">One use per new account. The personal coupon is issued with signup. Campaign terms apply.</span></td></tr></table>';
	}

	$values = array(
		'{{shop_url}}'     => esc_url( home_url( '/all-products' ) ),
		'{{parts_url}}'    => esc_url( home_url( '/parts' ) ),
		'{{schematics_url}}' => esc_url( home_url( '/schematics' ) ),
		'{{repair_url}}'   => esc_url( home_url( '/repairs/packages' ) ),
		'{{hero_url}}'     => esc_url( home_url( '/assets/email/account-welcome-hero.jpeg' ) ),
		'{{offer_html}}'   => $offer_html,
	);

	$content = strtr( $template, $values );
	$unsubscribe_url = '' !== $unsubscribe_url ? $unsubscribe_url : home_url( '/unsubscribe-preview/' );
	$footer_note = sprintf(
		/* translators: %s: unsubscribe link. */
		__( 'You are receiving this launch announcement because you signed up for Drywall Toolbox launch updates. %s', 'dtb' ),
		'<a href="' . esc_url( $unsubscribe_url ) . '" style="color:#ffffff;text-decoration:underline">Unsubscribe from launch emails</a>'
	);

	return dtb_render_branded_email(
		array(
			'title'       => __( 'We’re live', 'dtb' ),
			'preheader'   => __( 'The new Drywall Toolbox is live. Shop tools, find parts, view schematics, and explore repair services.', 'dtb' ),
			'greeting'    => '',
			'intro'       => $content,
			'cta_url'     => home_url( '/all-products' ),
			'cta_label'   => __( 'Explore the store', 'dtb' ),
			'signoff'     => __( 'The Drywall Toolbox Team', 'dtb' ),
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
	echo '<div class="wrap"><h1>' . esc_html__( 'Launch Email Preview', 'dtb' ) . '</h1><p><strong>' . esc_html__( 'Subject:', 'dtb' ) . '</strong> ' . esc_html( dtb_launch_announcement_subject() ) . '</p><p>' . esc_html__( 'Synthetic design preview only. No subscriber data is loaded and no email can be sent from this screen. The future launch send must be approved after the site is officially live.', 'dtb' ) . '</p><iframe title="Synthetic launch email preview" sandbox="" style="display:block;width:min(100%,760px);height:1100px;border:1px solid #c3c4c7;background:#edf2f7" srcdoc="' . esc_attr( $html ) . '"></iframe></div>';
}
