<?php
/**
 * Coming-soon subscriber launch announcement template and controlled test preview.
 *
 * The renderer never reads subscriber records or schedules a campaign. A
 * separate administrator action can send only a marked preview to one fixed
 * test inbox; it is not a campaign sender.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'dtb_register_launch_email_preview' );
add_action( 'admin_post_dtb_send_launch_email_test', 'dtb_send_launch_email_test' );
add_action( 'rest_api_init', 'dtb_register_launch_email_test_route' );

/** Return the current launch announcement subject line. */
function dtb_launch_announcement_subject(): string {
	return __( 'We’re live: meet the new Drywall Toolbox', 'dtb' );
}

/** Deliver one marked preview to the fixed test inbox. */
function dtb_deliver_launch_email_test(): bool {
	$html = dtb_render_launch_announcement_email();
	if ( '' === $html ) {
		return false;
	}

	return wp_mail(
		'elliottthomasmiller@gmail.com',
		'[TEST PREVIEW] ' . dtb_launch_announcement_subject(),
		$html,
		array( 'Content-Type: text/html; charset=UTF-8' )
	);
}

/** Register the administrator-only REST action for sending the fixed preview. */
function dtb_register_launch_email_test_route(): void {
	register_rest_route(
		'dtb/v1',
		'/admin/marketing/launch-email/test-preview',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'dtb_send_launch_email_test_rest',
			'permission_callback' => static function (): bool {
				return current_user_can( 'manage_options' );
			},
		)
	);
}

/** Send a fixed-recipient HTML preview through the authenticated REST API. */
function dtb_send_launch_email_test_rest(): WP_REST_Response|WP_Error {
	if ( ! dtb_deliver_launch_email_test() ) {
		return new WP_Error(
			'dtb_launch_email_test_send_failed',
			__( 'The launch email preview could not be handed to the configured mail transport.', 'dtb' ),
			array( 'status' => 502 )
		);
	}

	return new WP_REST_Response(
		array(
			'sent'      => true,
			'recipient' => 'elliottthomasmiller@gmail.com',
			'subject'   => '[TEST PREVIEW] ' . dtb_launch_announcement_subject(),
			'message'   => __( 'WordPress accepted the preview for the configured mail transport; this does not confirm inbox delivery.', 'dtb' ),
		),
		200
	);
}

/** Send the current launch rendering to the one approved test inbox. */
function dtb_send_launch_email_test(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to send this test email.', 'dtb' ), '', array( 'response' => 403 ) );
	}
	if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
		wp_die( esc_html__( 'This test email action requires a POST request.', 'dtb' ), '', array( 'response' => 405 ) );
	}
	check_admin_referer( 'dtb_send_launch_email_test' );

	$sent = dtb_deliver_launch_email_test();

	$status = $sent ? 'sent' : 'failed';
	wp_safe_redirect(
		add_query_arg(
			array(
				'page'             => 'dtb-launch-email-preview',
				'dtb_test_preview' => $status,
			),
			admin_url( 'options-general.php' )
		)
	);
	exit;
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
		'{{parts_url}}'    => esc_url( home_url( '/parts' ) ),
		'{{schematics_url}}' => esc_url( home_url( '/schematics' ) ),
		'{{repair_url}}'   => esc_url( home_url( '/repairs/packages' ) ),
		'{{home_url}}'     => esc_url( home_url( '/' ) ),
		'{{hero_html}}'    => $hero_html,
		'{{account_url}}'  => esc_url( $account_url ),
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
			'preheader'   => __( 'Professional tools, replacement parts, interactive schematics, and repair services—together in one place.', 'dtb' ),
			'theme'       => 'dark',
			'full_bleed'  => true,
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

/** Render a synthetic preview and its fixed-recipient test action. */
function dtb_render_launch_email_preview(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'dtb' ) );
	}

	$html = dtb_render_launch_announcement_email();
	$notice      = '';
	$test_result = isset( $_GET['dtb_test_preview'] ) && is_string( $_GET['dtb_test_preview'] )
		? sanitize_key( wp_unslash( $_GET['dtb_test_preview'] ) )
		: '';
	if ( 'sent' === $test_result ) {
		$notice = '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'WordPress accepted the test preview for delivery to elliottthomasmiller@gmail.com. Check the inbox and spam folder; acceptance does not guarantee inbox placement.', 'dtb' ) . '</p></div>';
	} elseif ( 'failed' === $test_result ) {
		$notice = '<div class="notice notice-error"><p>' . esc_html__( 'The test preview could not be handed to the configured mail transport. Review the mail transport logs and try again.', 'dtb' ) . '</p></div>';
	}
	// Keep scripts disabled while retaining the parent origin so the synthetic
	// preview can load the same-origin, self-hosted Geist WOFF2 font.
	echo '<div class="wrap"><h1>' . esc_html__( 'Launch Email Preview', 'dtb' ) . '</h1>' . $notice . '<p><strong>' . esc_html__( 'Subject:', 'dtb' ) . '</strong> ' . esc_html( dtb_launch_announcement_subject() ) . '</p><p>' . esc_html__( 'This preview uses the current launch template and shared branded email shell. Test sends go only to the fixed test inbox shown below; subscriber data is never loaded.', 'dtb' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dtb_send_launch_email_test">' . wp_nonce_field( 'dtb_send_launch_email_test', '_wpnonce', true, false ) . '<p><button class="button button-primary" type="submit">' . esc_html__( 'Send test preview to elliottthomasmiller@gmail.com', 'dtb' ) . '</button></p></form><iframe title="Launch email preview" sandbox="allow-same-origin" style="display:block;width:min(100%,760px);height:1900px;border:1px solid #c3c4c7;background:#edf2f7" srcdoc="' . esc_attr( $html ) . '"></iframe></div>';
}
