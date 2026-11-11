<?php
/**
 * Checkout asset cache-version adapter.
 *
 * Replaces the theme's static DTB_VERSION query parameter for checkout-owned
 * local assets with each file's modification time. This keeps browser/CDN/
 * SiteGround cache invalidation deterministic without changing global theme
 * asset policy or loading presentation assets from the MU-plugin layer.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * @param string $src    Enqueued asset URL.
 * @param string $handle WordPress asset handle.
 * @return string
 */
function dtb_checkout_version_local_asset( string $src, string $handle ): string {
	$paths = [
		'dtb-checkout'               => 'assets/checkout/checkout.css',
		'dtb-checkout-desktop'       => 'assets/checkout/checkout-desktop.css',
		'dtb-checkout-order-summary' => 'assets/checkout/checkout-order-summary.js',
	];

	if ( 'dtb-checkout' === $handle && str_ends_with( (string) wp_parse_url( $src, PHP_URL_PATH ), '/checkout.js' ) ) {
		$paths['dtb-checkout'] = 'assets/checkout/checkout.js';
	}

	if ( ! isset( $paths[ $handle ] ) ) {
		return $src;
	}

	$file = get_template_directory() . '/' . $paths[ $handle ];
	if ( ! is_readable( $file ) ) {
		return $src;
	}

	return add_query_arg( 'ver', (string) filemtime( $file ), remove_query_arg( 'ver', $src ) );
}
add_filter( 'style_loader_src', 'dtb_checkout_version_local_asset', 100, 2 );
add_filter( 'script_loader_src', 'dtb_checkout_version_local_asset', 100, 2 );
