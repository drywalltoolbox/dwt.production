<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

$registered_routes = [];
$meta_values = [];
$products = [];
$product_statuses = [];
$catalog_read_status = 200;

class WC_Product {
	public function __construct( private int $id, private string $sku, private bool $visible = true, private string $type = 'simple', private int $parent_id = 0, private array $variation_attributes = [] ) {}
	public function get_id(): int { return $this->id; }
	public function get_sku(): string { return $this->sku; }
	public function is_visible(): bool { return $this->visible; }
	public function get_parent_id(): int { return $this->parent_id; }
	public function get_variation_attributes(): array { return $this->variation_attributes; }
	public function get_type(): string { return $this->type; }
}

final class WC_Product_Variation extends WC_Product {}

final class WP_REST_Request {
	public function __construct( private string $method, private string $route, private array $params = [] ) {}
	public function get_param( string $key ): mixed { return $this->params[ $key ] ?? null; }
}

final class WP_REST_Response {
	public function __construct( private mixed $data, private int $status = 200 ) {}
	public function get_data(): mixed { return $this->data; }
	public function get_status(): int { return $this->status; }
}

function register_rest_route( string $namespace, string $route, array $args ): void {
	global $registered_routes;
	$registered_routes[ $namespace . $route ] = $args;
}

function absint( mixed $value ): int { return abs( (int) $value ); }

function sanitize_text_field( mixed $value ): string { return trim( (string) $value ); }

function get_posts( array $args ): array {
	unset( $args );
	return array_map( static fn( int $id ): object => (object) [ 'ID' => $id, 'post_type' => 'product', 'post_parent' => 0 ], [ 1, 2, 3, 4, 5 ] );
}

function get_post_meta( int $post_id, string $key, bool $single ): mixed {
	global $meta_values;
	$value = $meta_values[ $post_id ][ $key ] ?? '';
	return $single ? $value : ( is_array( $value ) ? $value : [ $value ] );
}

function wc_get_product( int $product_id ): WC_Product|false {
	global $products;
	return $products[ $product_id ] ?? false;
}

function get_post_status( int $product_id ): string {
	global $product_statuses;
	return $product_statuses[ $product_id ] ?? 'publish';
}

function wc_get_product_id_by_sku( string $sku ): int {
	global $products;
	foreach ( $products as $id => $product ) {
		if ( 0 === strcasecmp( $product->get_sku(), $sku ) ) {
			return (int) $id;
		}
	}
	return 0;
}

function dtb_catalog_wc_get_products_by_ids_response( array $ids ): ?WP_REST_Response {
	global $products, $catalog_read_status;
	if ( 200 !== $catalog_read_status ) {
		return new WP_REST_Response( [ 'error' => 'fixture catalog read failure' ], $catalog_read_status );
	}
	$rows = [];
	foreach ( $ids as $id ) {
		$product = $products[ $id ] ?? null;
		if ( $product ) {
			$rows[] = [ 'id' => $product->get_id(), 'sku' => $product->get_sku(), 'name' => $product->get_sku(), 'type' => $product->get_type(), 'status' => get_post_status( $product->get_id() ) ];
		}
	}
	return new WP_REST_Response( $rows, 200 );
}

function dtb_catalog_normalize_product( array $product ): array {
	return [ 'id' => $product['id'], 'sku' => $product['sku'], 'type' => $product['type'] ];
}

function dtb_error_envelope( string $code, string $message, int $status ): array {
	return [ 'code' => $code, 'message' => $message, 'status' => $status ];
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
$batch_route = $registered_routes['dtb/v1/products/compatible-parts'] ?? null;
assert_contract( is_array( $batch_route ), 'Bounded cart-compatible-parts route was not registered.' );
assert_contract( ( $batch_route['args']['skus']['validate_callback'] )( 'TOOL-1,07TT' ), 'Valid cart SKU list was rejected.' );
assert_contract( ! ( $batch_route['args']['skus']['validate_callback'] )( 'TOOL-1,07TT/PRO' ), 'Malformed cart SKU list passed validation.' );
assert_contract( ! ( $batch_route['args']['skus']['validate_callback'] )( implode( ',', array_fill( 0, 21, 'TOOL-1' ) ) ), 'Over-limit cart SKU list passed validation.' );
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
$products = [
	1 => new WC_Product( 1, 'PART-1' ),
	2 => new WC_Product( 2, 'PART-2' ),
	3 => new WC_Product( 3, 'PART-3' ),
	4 => new WC_Product( 4, 'PART-4' ),
	5 => new WC_Product( 5, 'TOOL-5' ),
	6 => new WC_Product( 6, 'PART-OWNER' ),
	10 => new WC_Product( 10, 'TOOL-SIMPLE' ),
	11 => new WC_Product( 11, 'TOOL-PARENT', true, 'variable' ),
	12 => new WC_Product_Variation( 12, 'TOOL-VAR-A', true, 'variation', 11, [ 'attribute_size' => 'small' ] ),
	13 => new WC_Product_Variation( 13, 'TOOL-VAR-B', true, 'variation', 11, [ 'attribute_size' => 'large' ] ),
	14 => new WC_Product( 14, 'TOOL-HIDDEN', false ),
	15 => new WC_Product( 15, 'TOOL-HIDDEN-PARENT', false, 'variable' ),
	16 => new WC_Product_Variation( 16, 'TOOL-HIDDEN-VAR', true, 'variation', 15 ),
	17 => new WC_Product( 17, 'PART-TARGET' ),
];
$meta_values = [
	1 => [ $key => 'CP-2,CT-9', DTB_ProductMeta::PRODUCT_KIND => 'part' ],
	2 => [ $key => [ 'CP-26', 'CT-10' ], DTB_ProductMeta::PRODUCT_KIND => 'part' ],
	3 => [ $key => '["CP-260","CT-11"]', DTB_ProductMeta::PRODUCT_KIND => 'part' ],
	4 => [ $key => 'cp-27', DTB_ProductMeta::PRODUCT_KIND => 'part' ],
	5 => [ $key => 'CP-26', DTB_ProductMeta::PRODUCT_KIND => 'tool' ],
	6 => [
		$key => '["TOOL-SIMPLE","TOOL-VAR-A","TOOL-VAR-B","TOOL-HIDDEN","TOOL-HIDDEN-VAR","PART-TARGET","TOOL-MISSING","tool-simple"]',
		DTB_ProductMeta::PRODUCT_KIND => 'part',
	],
	17 => [ DTB_ProductMeta::PRODUCT_KIND => 'part' ],
];
$product_statuses = [ 1 => 'publish', 6 => 'publish', 10 => 'publish', 11 => 'publish', 12 => 'publish', 13 => 'publish', 14 => 'publish', 15 => 'publish', 16 => 'publish', 17 => 'publish' ];
$method = new ReflectionMethod( DTB_CompatiblePartsController::class, 'find_products_with_skus_in_meta' );
assert_contract( [ 2 ] === $method->invoke( null, [ 'CP-26' ], [ $key ] ), 'Exact token membership or part-owner filtering failed.' );
assert_contract( [ 1 ] === $method->invoke( null, [ 'CP-2' ], [ $key ] ), 'Short SKU membership failed.' );
assert_contract( [] === $method->invoke( null, [ 'CP-27' ], [ $key ] ), 'Case-only target identity drift must not be returned as compatible.' );

$reverse = DTB_CompatiblePartsController::handle_compatible_tools( new WP_REST_Request( 'GET', '/dtb/v1/parts/PART-OWNER/compatible-tools', [ 'sku' => 'PART-OWNER' ] ) );
$reverse_data = $reverse->get_data();
assert_contract( 200 === $reverse->get_status(), 'Reverse compatibility route did not return success for a published part.' );
assert_contract( 2 === $reverse_data['count'], 'Reverse route did not deduplicate and filter exact visible non-part tool targets.' );
assert_contract( 'TOOL-SIMPLE' === $reverse_data['products'][0]['sku'], 'Reverse route did not return the exact simple tool.' );
assert_contract( 'TOOL-PARENT' === $reverse_data['products'][1]['sku'], 'Reverse route did not group exact tool variations under one parent DTO.' );
assert_contract( [ 'TOOL-VAR-A', 'TOOL-VAR-B' ] === array_column( $reverse_data['products'][1]['compatible_variations'], 'sku' ), 'Reverse route did not preserve exact variation SKU identities.' );

$catalog_read_status = 503;
$catalog_failure = DTB_CompatiblePartsController::handle_compatible_tools( new WP_REST_Request( 'GET', '/dtb/v1/parts/PART-OWNER/compatible-tools', [ 'sku' => 'PART-OWNER' ] ) );
assert_contract( 503 === $catalog_failure->get_status(), 'Reverse route hid a WooCommerce catalog read failure as an empty relationship result.' );
$catalog_read_status = 200;

$not_part = DTB_CompatiblePartsController::handle_compatible_tools( new WP_REST_Request( 'GET', '/dtb/v1/parts/TOOL-SIMPLE/compatible-tools', [ 'sku' => 'TOOL-SIMPLE' ] ) );
assert_contract( 404 === $not_part->get_status(), 'Reverse route accepted a non-part as its relationship owner.' );

echo "Compatible parts route, exact SKU, visibility, part-owner, and variation read-back contracts passed.\n";
