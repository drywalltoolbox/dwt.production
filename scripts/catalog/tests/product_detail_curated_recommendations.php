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
if ( ! str_contains( $method, "get_post_meta( \$product_id, '_includes_' . \$index . '_sku', true )" ) ) {
	throw new RuntimeException( 'Toolset PDP recommendations must retain exact included-SKU resolution.' );
}

echo "PDP recommendation source contract passed.\n";
