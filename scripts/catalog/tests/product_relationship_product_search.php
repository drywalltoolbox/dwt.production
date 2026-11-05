<?php
/** Focused registered-product-type checks for Product Relationships search. */

define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );

$GLOBALS['relationship_search_args'] = [];
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ); }
function wc_get_product_types(): array { return [ 'simple' => 'Simple', 'variable' => 'Variable', 'grouped' => 'Grouped', 'external' => 'External', 'subscription' => 'Subscription' ]; }
function wc_get_products( array $args ): array { $GLOBALS['relationship_search_args'][] = $args; return $GLOBALS['relationship_search_products'] ?? []; }
final class Relationship_Search_Product_Fixture {
	public function get_id(): int { return 42; }
	public function get_name(): string { return 'Hidden part'; }
	public function get_sku(): string { return 'PART-42'; }
	public function get_type(): string { return 'simple'; }
	public function get_catalog_visibility(): string { return 'hidden'; }
}

require dirname( __DIR__, 3 ) . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Infrastructure/ProductVariationRepository.php';

$check = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$GLOBALS['relationship_search_products'] = [ new Relationship_Search_Product_Fixture() ];
$search_results = dtb_product_mapping_repo_search_products( 'taper' );
$default_args = $GLOBALS['relationship_search_args'][0] ?? [];
$check( ! array_key_exists( 'type', $default_args ), 'Unfiltered relationship search must include every registered non-variation product type.' );
$check( 'hidden' === ( $search_results[0]['catalog_visibility'] ?? '' ), 'Relationship search must preserve WooCommerce catalog visibility for operator review.' );

dtb_product_mapping_repo_search_products( 'hopper', 'simple,variable,grouped,external,subscription,unknown' );
$filtered_args = $GLOBALS['relationship_search_args'][1] ?? [];
$check( [ 'simple', 'variable', 'grouped', 'external', 'subscription' ] === ( $filtered_args['type'] ?? null ), 'Explicit type filters must be restricted to registered WooCommerce product types.' );

$before_invalid = count( $GLOBALS['relationship_search_args'] );
$result = dtb_product_mapping_repo_search_products( 'anything', 'variation,unknown' );
$check( [] === $result && $before_invalid === count( $GLOBALS['relationship_search_args'] ), 'An explicit filter with no registered product types must fail closed.' );

fwrite( STDOUT, "Product relationship product-type search checks passed.\n" );
