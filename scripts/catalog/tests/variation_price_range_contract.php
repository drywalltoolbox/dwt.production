<?php
define( 'ABSPATH', __DIR__ );
function absint( $value ) { return abs( (int) $value ); }
class WC_Product_Variable {
    public function get_variation_prices( $display = false ) { return [ 'price' => [ 1 => '900.00', 2 => '1099.00' ] ]; }
}
function wc_get_product( $id ) { return new WC_Product_Variable(); }
require_once dirname( __DIR__, 3 ) . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Services/CatalogProductNormalizer.php';
require_once dirname( __DIR__, 3 ) . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Services/DefaultVariationResolver.php';
$method = new ReflectionMethod( DTB_CatalogProductNormalizer::class, 'extract_price' );
$price = $method->invoke( null, [ 'id' => 21717, 'type' => 'variable', 'price' => '900' ] );
if ( 900.0 !== $price['min'] || 1099.0 !== $price['max'] ) { throw new RuntimeException( 'Variation range incorrect' ); }
$parent = [ 'id' => 21717, 'sku' => 'PARENT', 'price' => $price, 'media' => [ 'image' => '' ] ];
$variation = [ 'id' => 1, 'sku' => 'CHILD', 'name' => 'Test', 'price' => [ 'value' => 900, 'regular' => 990, 'sale' => 900, 'onSale' => true ], 'media' => [ 'image' => '' ], 'inventory' => [ 'stockStatus' => 'instock' ], 'variation' => [ 'label' => 'Test' ] ];
$result = DTB_DefaultVariationResolver::apply_to_card( $parent, $variation );
if ( 900.0 !== $result['price']['min'] || 1099.0 !== $result['price']['max'] || 900 !== $result['price']['value'] ) { throw new RuntimeException( 'Default selection discarded range' ); }
echo "Variation price range contract passed\n";
