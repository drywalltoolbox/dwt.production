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
