<?php
/**
 * Register the bounded WooCommerce check for canonical storefront product URLs.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/Infrastructure/WooProductRepository.php';

add_action( 'template_redirect', 'dtb_catalog_serve_storefront_product_route', 2 );

/**
 * Serve the React shell for an existing product at its canonical storefront path.
 * WordPress may resolve the same slug using WooCommerce's retired permalink base;
 * intercept the explicit public route before redirect_canonical runs.
 */
function dtb_catalog_serve_storefront_product_route(): void {
	if ( is_admin() || wp_doing_ajax() || wp_is_json_request() ) {
		return;
	}

	$path       = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
	$path       = '/' . trim( rawurldecode( $path ), '/' );
	$is_staging = str_starts_with( $path, '/staging/' );
	if ( $is_staging ) {
		$path = '/' . ltrim( substr( $path, strlen( '/staging' ) ), '/' );
	}

	if ( ! preg_match( '~^/products/(?!brands(?:/|$))([a-zA-Z0-9_-]+)(?:/variations/[0-9]+)?$~D', $path, $matches ) ) {
		return;
	}

	$slug     = sanitize_title( $matches[1] );
	$response = dtb_catalog_wc_get_response( 'wc/v3/products', [
		'slug'    => $slug,
		'status'  => 'publish',
		'_fields' => 'id,slug,status',
	] );

	if ( ! $response instanceof WP_REST_Response || 200 !== $response->get_status() ) {
		status_header( 503 );
		exit;
	}

	$products = $response->get_data();
	if ( ! is_array( $products ) || empty( $products[0]['id'] ) || $slug !== (string) ( $products[0]['slug'] ?? '' ) ) {
		status_header( 404 );
		exit;
	}

	$shell = dirname( ABSPATH ) . ( $is_staging ? '/staging/index.html' : '/index.html' );
	if ( ! is_readable( $shell ) ) {
		status_header( 503 );
		exit;
	}

	status_header( 200 );
	header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
	readfile( $shell );
	exit;
}
