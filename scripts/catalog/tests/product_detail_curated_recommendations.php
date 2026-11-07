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

if ( ! str_contains( $source, "'familyRelatedProducts' => \$family_related_products" )
	|| ! str_contains( $source, 'dtb_product_mapping_repo_get_family_related_products( $family_source )' ) ) {
	throw new RuntimeException( 'Similar-product discovery must use exact family metadata for tools/toolsets and remain separate from fitment.' );
}
if ( ! str_contains( $source, "'categoryRelatedProducts' => \$category_related_products" )
	|| ! str_contains( $source, 'dtb_product_mapping_repo_get_category_related_products( $family_source )' ) ) {
	throw new RuntimeException( 'Category discovery must be a separate read-only product-detail relationship.' );
}

$repository_path = dirname( __DIR__, 3 ) . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Infrastructure/ProductRelationshipRepository.php';
$repository_source = file_get_contents( $repository_path );
if ( false === $repository_source
	|| ! str_contains( $repository_source, "'family_related' => array_map(" )
	|| ! str_contains( $repository_source, "'product_family' => trim( (string) \$source->get_meta( 'product_family', true ) )" )
	|| ! str_contains( $repository_source, "! in_array( \$kind, [ 'tool', 'toolset' ], true )" )
	|| str_contains( $repository_source, "'series' => trim( (string) \$source->get_meta( 'series', true ) )" )
	|| str_contains( $repository_source, '$categories !== $source_categories' ) ) {
	throw new RuntimeException( 'Family discovery must use exact tool/toolset brand and product-family metadata while allowing series and category differences.' );
}
if ( ! str_contains( $repository_source, "'category_related' => array_map(" )
	|| ! str_contains( $repository_source, "'_dtb_display_category_key' => trim( (string) \$source->get_meta( '_dtb_display_category_key', true ) )" )
	|| ! str_contains( $repository_source, "! in_array( \$kind, [ 'tool', 'toolset', 'stilt', 'accessory' ], true )" ) ) {
	throw new RuntimeException( 'Category discovery must use exact brand/kind/display-category metadata and exclude parts.' );
}

echo "PDP curated and variation-specific relationship source contract passed.\n";
