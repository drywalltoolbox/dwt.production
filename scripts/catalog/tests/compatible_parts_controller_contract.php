<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

$registered_routes = [];
$meta_values = [];

function register_rest_route( string $namespace, string $route, array $args ): void {
	global $registered_routes;
	$registered_routes[ $namespace . $route ] = $args;
}

function get_posts( array $args ): array {
	unset( $args );
	return [ 1, 2, 3 ];
}

function get_post_meta( int $post_id, string $key, bool $single ): mixed {
	global $meta_values;
	unset( $single );
	return $meta_values[ $post_id ][ $key ] ?? '';
}

$platform = dirname( __DIR__, 3 ) . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform';
require_once $platform . '/Domain/ProductMeta.php';
require_once $platform . '/Services/CatalogProductNormalizer.php';
require_once $platform . '/Rest/CompatiblePartsController.php';

function assert_contract( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

DTB_CompatiblePartsController::register_routes();
foreach ( [ 'products', 'parts' ] as $resource ) {
	$route = 'dtb/v1/' . $resource . '/CP-26/' . ( 'products' === $resource ? 'compatible-parts' : 'compatible-tools' );
	$matched = false;
	foreach ( $registered_routes as $pattern => $args ) {
		if ( 1 === preg_match( '~^' . $pattern . '$~', $route ) ) {
			$matched = true;
			assert_contract( ( $args['args']['sku']['validate_callback'] )( 'CP-26' ), 'Hyphenated SKU validation failed.' );
			assert_contract( ! ( $args['args']['sku']['validate_callback'] )( 'CP/26' ), 'Invalid SKU passed validation.' );
		}
	}
	assert_contract( $matched, "Route did not match {$route}." );
}

$key = DTB_ProductMeta::COMPATIBLE_TOOL_SKUS;
$meta_values = [
	1 => [ $key => 'CP-2,CT-9' ],
	2 => [ $key => [ 'CP-26', 'CT-10' ] ],
	3 => [ $key => '["CP-260","CT-11"]' ],
];
$method = new ReflectionMethod( DTB_CompatiblePartsController::class, 'find_products_with_sku_in_meta' );
assert_contract( [ 2 ] === $method->invoke( null, 'CP-26', [ $key ] ), 'Exact token membership failed.' );
assert_contract( [ 1 ] === $method->invoke( null, 'CP-2', [ $key ] ), 'Short SKU membership failed.' );

echo "Compatible parts route and exact SKU contract passed.\n";
