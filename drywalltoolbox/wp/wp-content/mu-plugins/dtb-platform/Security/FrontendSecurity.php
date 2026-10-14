<?php
defined( 'ABSPATH' ) || exit;

/**
 * DTB Frontend Security
 *
 * Theme-independent public hardening and low-risk performance defaults.
 *
 * @package drywall-toolbox
 */


if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
	define( 'DISALLOW_FILE_EDIT', true );
}

if ( ! defined( 'WP_POST_REVISIONS' ) ) {
	define( 'WP_POST_REVISIONS', 5 );
}

if ( ! defined( 'AUTOSAVE_INTERVAL' ) ) {
	define( 'AUTOSAVE_INTERVAL', 300 );
}

add_filter( 'xmlrpc_enabled', '__return_false' );
add_filter( 'wp_headers', 'dtb_frontend_security_strip_unsafe_headers' );
add_filter( 'show_admin_bar', 'dtb_frontend_security_hide_admin_bar' );
add_action( 'template_redirect', 'dtb_frontend_security_block_author_enumeration' );
add_action( 'send_headers', 'dtb_frontend_security_headers' );
add_action( 'init', 'dtb_frontend_security_cleanup_head' );
add_action( 'wp_enqueue_scripts', 'dtb_frontend_security_disable_oembed' );
add_filter( 'heartbeat_settings', 'dtb_frontend_security_heartbeat_settings' );
add_filter( 'wp_revisions_to_keep', 'dtb_frontend_security_revisions_to_keep', 10, 2 );
add_filter( 'woocommerce_allow_marketplace_suggestions', '__return_false' );

function dtb_frontend_security_strip_unsafe_headers( array $headers ): array {
	unset( $headers['X-Pingback'] );
	return $headers;
}

function dtb_frontend_security_hide_admin_bar( bool $show ): bool {
	return is_admin() ? $show : false;
}

function dtb_frontend_security_block_author_enumeration(): void {
	if ( is_admin() || ! isset( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	dtb_security_log( 'author_enumeration_blocked' );
	wp_safe_redirect( home_url( '/' ), 301 );
	exit;
}

function dtb_frontend_security_headers(): void {
	if ( headers_sent() ) {
		return;
	}

	header( 'X-Content-Type-Options: nosniff' );
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	header( 'X-Frame-Options: SAMEORIGIN' );
	header( 'Permissions-Policy: ' . dtb_frontend_security_permissions_policy() );

	// Content-Security-Policy: opt-in via DTB_ENABLE_CSP constant (default off).
	if ( ! is_admin() && dtb_feature_enabled( 'DTB_ENABLE_CSP', false ) ) {
		$connect_origins = array_values(
			array_unique(
				array_filter(
					[
						dtb_normalize_origin( home_url( '/' ) ),
						dtb_normalize_origin( site_url( '/' ) ),
					]
				)
			)
		);
		$csp = "default-src 'self'; "
			. "script-src 'self' 'unsafe-inline'; "
			. "style-src 'self' 'unsafe-inline'; "
			. "img-src 'self' data: https:; "
			. "connect-src 'self' " . implode( ' ', $connect_origins ) . ';';
		header( 'Content-Security-Policy: ' . $csp );
	}

	// Noindex on staging environments (WP_ENVIRONMENT_TYPE preferred; fallback to URL check).
	$env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '';
	if ( 'staging' === $env || ( '' === $env && false !== strpos( (string) get_option( 'siteurl', '' ), '.staging.' ) ) ) {
		header( 'X-Robots-Tag: noindex' );
	}
}

/**
 * Browser capabilities allowed to the storefront and its trusted payment UI.
 *
 * Payment remains denied to every origin except this site and the exact
 * provider origins used by the configured WooPayments runtime and eligible wallets.
 */
function dtb_frontend_security_permissions_policy(): string {
	return 'geolocation=(), microphone=(), camera=(), payment=(self "https://js.stripe.com" "https://hooks.stripe.com" "https://b.stripecdn.com" "https://pay.google.com"), usb=(), browsing-topics=()';
}

function dtb_frontend_security_cleanup_head(): void {
	remove_action( 'wp_head', 'wp_generator' );
	remove_action( 'wp_head', 'wlwmanifest_link' );
	remove_action( 'wp_head', 'rsd_link' );
	remove_action( 'wp_head', 'wp_shortlink_wp_head' );
	remove_action( 'wp_head', 'rest_output_link_wp_head' );
	remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
	remove_action( 'template_redirect', 'rest_output_link_header', 11 );
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
}

function dtb_frontend_security_disable_oembed(): void {
	if ( is_admin() ) {
		return;
	}

	wp_deregister_script( 'wp-embed' );
}

function dtb_frontend_security_heartbeat_settings( array $settings ): array {
	if ( ! is_admin() ) {
		$settings['interval'] = 60;
	}

	return $settings;
}

function dtb_frontend_security_revisions_to_keep( int $num, WP_Post $post ): int {
	return 'product' === $post->post_type ? 10 : $num;
}
