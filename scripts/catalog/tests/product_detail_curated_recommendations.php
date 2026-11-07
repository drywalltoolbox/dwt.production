<?php
declare(strict_types=1);

$path = dirname( __DIR__, 3 ) . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Rest/ProductDetailController.php';
$source = file_get_contents( $path );
if ( false === $source ) {
	throw new RuntimeException( 'Could not read the product detail controller.' );
}
$source = str_replace( "\r\n", "\n", $source );

$start = strpos( $source, 'private static function get_related_products' );
$end   = strpos( $source, "/**\n\t * GET /dtb/v1/catalog/products/:id/variations", $start ?: 0 );
if ( false === $start || false === $end ) {
	throw new RuntimeException( 'Could not isolate the PDP recommendation builder.' );
}

$method = substr( $source, $start, $end - $start );
if ( str_contains( $method, 'wc_get_related_products(' ) ) {
	throw new RuntimeException( 'Generated WooCommerce related products must not enter the curated PDP rail.' );
}
if ( ! str_contains( $method, '$candidate_ids = $source_product->get_upsell_ids();' ) ) {
	throw new RuntimeException( 'Non-toolset PDP recommendations must use explicitly curated WooCommerce upsells.' );
}
if ( ! str_contains( $method, "preg_match( '/^_includes_(\\d+)_sku$/', \$key, \$matches )" ) ) {
	throw new RuntimeException( 'Toolset PDP recommendations must retain exact included-SKU resolution.' );
}
if ( ! str_contains( $source, "'relatedProductsByVariation' => \$related_products_by_variation" )
	|| ! str_contains( $method, 'private static function get_related_products_by_variation' )
	|| ! str_contains( $method, "( \$variation['meta_data'] ?? [] )" ) ) {
	throw new RuntimeException( 'Variable toolsets must expose exact variation-specific included components.' );
}
if ( ! str_contains( $source, "'relatedProductsContext' => \$is_toolset ? 'included_components' : 'related'" ) ) {
	throw new RuntimeException( 'PDP relationship copy must distinguish included components from related products.' );
}

echo "PDP curated and variation-specific relationship source contract passed.\n";
