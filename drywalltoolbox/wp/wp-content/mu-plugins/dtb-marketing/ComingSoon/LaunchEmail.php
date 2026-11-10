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
	$template_path = __DIR__ . '/LaunchEmailTemplate.html';
	$template      = is_readable( $template_path ) ? file_get_contents( $template_path ) : false;

	if ( false === $template ) {
		return '';
	}

	$values = array(
		'{{home_url}}'     => esc_url( home_url( '/' ) ),
		'{{shop_url}}'     => esc_url( home_url( '/all-products' ) ),
		'{{parts_url}}'    => esc_url( home_url( '/parts' ) ),
		'{{repair_url}}'   => esc_url( home_url( '/repairs/packages' ) ),
		'{{support_url}}'  => esc_url( function_exists( 'dtb_email_support_url' ) ? dtb_email_support_url() : home_url( '/contact' ) ),
		'{{logo_url}}'     => esc_url( function_exists( 'dtb_email_logo_url' ) ? dtb_email_logo_url() : home_url( '/assets/email/dtb-logo.png' ) ),
		'{{hero_url}}'     => esc_url( home_url( '/assets/email/account-welcome-hero.jpeg' ) ),
		'{{unsubscribe}}' => esc_url( '' !== $unsubscribe_url ? $unsubscribe_url : home_url( '/unsubscribe-preview/' ) ),
		'{{year}}'         => esc_html( gmdate( 'Y' ) ),
	);

	return strtr( $template, $values );
}

/** Render a synthetic preview without looking up recipients or sending email. */
function dtb_render_launch_email_preview(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'dtb' ) );
	}

	$html = dtb_render_launch_announcement_email();
	echo '<div class="wrap"><h1>' . esc_html__( 'Launch Email Preview', 'dtb' ) . '</h1><p><strong>' . esc_html__( 'Subject:', 'dtb' ) . '</strong> ' . esc_html( dtb_launch_announcement_subject() ) . '</p><p>' . esc_html__( 'Synthetic design preview only. No subscriber data is loaded and no email can be sent from this screen. The future launch send must be approved after the site is officially live.', 'dtb' ) . '</p><iframe title="Synthetic launch email preview" sandbox="" style="display:block;width:min(100%,760px);height:1100px;border:1px solid #c3c4c7;background:#edf2f7" srcdoc="' . esc_attr( $html ) . '"></iframe></div>';
}
