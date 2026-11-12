<?php
/**
 * Catalog hook wiring.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', static function (): void {
	DTB_ToolsetData::maybe_seed();
}, 5 );

add_action( 'init', [ DTB_CatalogTaxonomyMigration::class, 'maybe_run' ], 30 );
add_action( 'template_redirect', [ DTB_CatalogTaxonomyMigration::class, 'redirect_legacy_category_archives' ], 1 );
add_action( 'template_redirect', 'dtb_catalog_serve_storefront_route', 2 );

/**
 * Serve the React shell only for registered storefront route shapes. Unknown
 * paths remain WordPress 404s. Canonical PDP paths are checked against
 * WooCommerce so catalog outages are never turned into false product 404s.
 */
function dtb_catalog_serve_storefront_route(): void {
	if ( ! is_404() || is_admin() || wp_doing_ajax() || wp_is_json_request() ) {
		return;
	}

	$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
	$path = '/' . trim( rawurldecode( $path ), '/' );
	$is_staging = str_starts_with( $path, '/staging/' );
	if ( $is_staging ) {
		$path = '/' . ltrim( substr( $path, strlen( '/staging' ) ), '/' );
	}

	$frontend_route = preg_match(
		'~^/(?:products(?:/brands(?:/[^/]+(?:/categories/[^/]+)?)?|/[^/]+(?:/variations/[0-9]+)?)?|parts|category/[^/]+|schematics|repairs(?:/(?:start|packages|track|status/[^/]+))?|help|calculators|shipping-policy|returns(?:/status/[^/]+)?|return-policy|policies|terms-of-service|privacy-policy|preview/technical-specifications|cart|checkout(?:/(?:complete|payment-failed|payment-cancelled|order-received/[^/]+))?|order/[0-9]+|order-tracking/[^/]+|contact|support/status/[^/]+|settings/woocommerce|login|register|forgot-password|reset-password|dashboard(?:/repairs/[^/]+)?|error/[^/]+)$~D',
		$path
	);
	if ( ! $frontend_route ) {
		return;
	}

	if ( ! preg_match( '~^/products/brands(?:/|$)~D', $path )
		&& preg_match( '~^/products/([a-zA-Z0-9_-]+)(?:/variations/[0-9]+)?$~D', $path, $matches ) ) {
		$slug = sanitize_title( $matches[1] );
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

add_action( 'dtb_product_cache_invalidated', static function (): void {
	dtb_catalog_cache_invalidate_facets();
} );

/**
 * Invalidate all catalog caches affected by product changes.
 *
 * @param int|WC_Product|object $subject Post ID, WC product object, or source object.
 */
function dtb_catalog_invalidate_all_caches( object|int $subject = 0 ): void {
	if ( function_exists( 'dtb_is_wc_product_import_request' ) && dtb_is_wc_product_import_request() ) {
		$GLOBALS['dtb_catalog_import_cache_dirty'] = true;
		return;
	}

	dtb_catalog_cache_invalidate_all( $subject );
}

/**
 * Mark the current WooCommerce importer AJAX batch as cache-dirty.
 *
 * WooCommerce fires this action after saving either a new or updated product.
 */
function dtb_catalog_mark_import_cache_dirty( WC_Product $product ): void {
	unset( $product ); // The action subject is not needed; invalidation is global.
	$GLOBALS['dtb_catalog_import_cache_dirty'] = true;
}

/** Flush catalog caches once during shutdown after an importer AJAX batch. */
function dtb_catalog_flush_import_caches(): void {
	if ( empty( $GLOBALS['dtb_catalog_import_cache_dirty'] ) ) {
		return;
	}

	$GLOBALS['dtb_catalog_import_cache_dirty'] = false;
	dtb_catalog_cache_invalidate_all();
}

add_action( 'save_post_product', 'dtb_catalog_invalidate_all_caches', 20 );
add_action( 'save_post_product_variation', 'dtb_catalog_invalidate_all_caches', 20 );

add_action( 'deleted_post', static function ( int $post_id ): void {
	$post_type = get_post_type( $post_id );
	if ( 'product' === $post_type || 'product_variation' === $post_type ) {
		dtb_catalog_invalidate_all_caches( $post_id );
	}
}, 20 );

add_action( 'woocommerce_new_product', 'dtb_catalog_invalidate_all_caches', 20 );
add_action( 'woocommerce_update_product', 'dtb_catalog_invalidate_all_caches', 20 );
add_action( 'woocommerce_delete_product', 'dtb_catalog_invalidate_all_caches', 20 );
add_action( 'woocommerce_new_product_variation', 'dtb_catalog_invalidate_all_caches', 20 );
add_action( 'woocommerce_update_product_variation', 'dtb_catalog_invalidate_all_caches', 20 );
add_action( 'woocommerce_delete_product_variation', 'dtb_catalog_invalidate_all_caches', 20 );
add_action( 'woocommerce_product_import_inserted_product_object', 'dtb_catalog_mark_import_cache_dirty', 20 );
add_action( 'shutdown', 'dtb_catalog_flush_import_caches', 20 );
add_action( 'woocommerce_trash_product', 'dtb_catalog_invalidate_all_caches', 20 );
add_action( 'woocommerce_untrash_product', 'dtb_catalog_invalidate_all_caches', 20 );

foreach ( [ 'created_product_cat', 'edited_product_cat', 'delete_product_cat' ] as $taxonomy_hook ) {
	add_action( $taxonomy_hook, 'dtb_catalog_cache_invalidate_all', 20 );
}
