<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

final class WP_REST_Request {
	public function __construct( private array $params ) {}
	public function get_param( string $key ): mixed { return $this->params[ $key ] ?? null; }
}

final class WP_REST_Response {
	public function __construct( private array $data, private int $status = 200 ) {}
	public function get_data(): array { return $this->data; }
	public function get_status(): int { return $this->status; }
}

class WC_Product {
	public function __construct( protected int $id, protected string $sku, protected bool $visible = true ) {}
	public function get_id(): int { return $this->id; }
	public function get_sku(): string { return $this->sku; }
	public function is_visible(): bool { return $this->visible; }
	public function is_type( string $type ): bool { return false; }
	public function get_type(): string { return 'simple'; }
	public function get_variation_attributes(): array { return []; }
	public function get_price(): string { return '10.00'; }
	public function get_regular_price(): string { return '10.00'; }
	public function get_sale_price(): string { return ''; }
	public function is_purchasable(): bool { return true; }
	public function is_in_stock(): bool { return true; }
}

final class WC_Product_Variation extends WC_Product {
	public function __construct( int $id, string $sku, private int $parent_id, bool $visible = true ) {
		parent::__construct( $id, $sku, $visible );
	}
	public function get_parent_id(): int { return $this->parent_id; }
	public function get_variation_attributes(): array { return [ 'attribute_pa_size' => '3' ]; }
}

function get_permalink( int $product_id ): string { return 'https://drywalltoolbox.com/product/' . $product_id . '/'; }
function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }

$products = [];
$sku_ids = [];
$statuses = [];
$meta_values = [];
$candidate_ids = [];
$registered_routes = [];

function register_rest_route( string $namespace, string $route, array $args ): void {
	global $registered_routes;
	$registered_routes[ $namespace . $route ] = $args;
}

function absint( mixed $value ): int { return abs( (int) $value ); }

function get_posts( array $args ): array {
	global $candidate_ids;
	unset( $args );
	return array_map( static fn( int $id ): object => (object) [
		'ID' => $id,
		'post_type' => 106 === $id ? 'product_variation' : 'product',
		'post_parent' => 106 === $id ? 105 : 0,
	], $candidate_ids );
}

function get_post_meta( int $product_id, string $key, bool $single = false ): mixed {
	global $meta_values;
	$value = $meta_values[ $product_id ][ $key ] ?? '';
	return $single ? $value : ( '' === $value ? [] : [ $value ] );
}

function get_post_status( int $product_id ): string {
	global $statuses;
	return $statuses[ $product_id ] ?? 'publish';
}

function wc_get_product_id_by_sku( string $sku ): int {
	global $sku_ids;
	return $sku_ids[ strtoupper( $sku ) ] ?? 0;
}

function wc_get_product( int $product_id ): WC_Product|false {
	global $products;
	return $products[ $product_id ] ?? false;
}

function dtb_catalog_wc_fetch_products_by_ids( array $ids ): array {
	global $products;
	return array_map( static function ( int $id ) use ( $products ): array {
		$product = $products[ $id ];
		return [
			'id'          => $id,
			'sku'         => $product->get_sku(),
			'name'        => 'Product ' . $product->get_sku(),
			'type'        => 'simple',
			'status'      => 'publish',
			'stock_status'=> 'instock',
			'purchasable' => true,
			'price'       => [ 'value' => 10 ],
		];
	}, $ids );
}

function dtb_catalog_normalize_product( array $product ): array { return $product; }

$platform = dirname( __DIR__, 3 ) . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform';
require_once $platform . '/Domain/ProductMeta.php';
require_once $platform . '/Services/CatalogProductNormalizer.php';
require_once $platform . '/Rest/CompatiblePartsController.php';

$products = [
	10  => new WC_Product( 10, 'TOOL-A' ),
	20  => new WC_Product( 20, 'TOOL-FAMILY' ),
	21  => new WC_Product_Variation( 21, 'TOOL-VAR', 20 ),
	30  => new WC_Product( 30, 'PART-CART' ),
	40  => new WC_Product( 40, 'TOOL-HIDDEN', false ),
	101 => new WC_Product( 101, 'PART-A' ),
	102 => new WC_Product( 102, 'PART-VAR' ),
	103 => new WC_Product( 103, 'NOT-A-PART' ),
	104 => new WC_Product( 104, 'PART-HIDDEN-TOOL' ),
	105 => new WC_Product( 105, 'PART-FAMILY' ),
	106 => new WC_Product_Variation( 106, 'PART-CHILD-3', 105 ),
];
$sku_ids = [
	'TOOL-A' => 10,
	'TOOL-FAMILY' => 20,
	'TOOL-VAR' => 21,
	'PART-CART' => 30,
	'TOOL-HIDDEN' => 40,
	'PART-A' => 101,
	'PART-VAR' => 102,
	'NOT-A-PART' => 103,
	'PART-HIDDEN-TOOL' => 104,
	'PART-FAMILY' => 105,
	'PART-CHILD-3' => 106,
];
$statuses = array_fill_keys( array_keys( $products ), 'publish' );
$candidate_ids = [ 101, 102, 103, 104, 106 ];
$meta_values = [
	10 => [ DTB_ProductMeta::PRODUCT_KIND => 'tool' ],
	20 => [ DTB_ProductMeta::PRODUCT_KIND => 'tool' ],
	21 => [ DTB_ProductMeta::PRODUCT_KIND => 'tool' ],
	30 => [ DTB_ProductMeta::PRODUCT_KIND => 'part' ],
	40 => [ DTB_ProductMeta::PRODUCT_KIND => 'tool' ],
	101 => [ DTB_ProductMeta::PRODUCT_KIND => 'part', DTB_ProductMeta::COMPATIBLE_TOOL_SKUS => [ 'TOOL-A' ] ],
	102 => [ DTB_ProductMeta::PRODUCT_KIND => 'part', DTB_ProductMeta::REPLACEMENT_PART_FOR => [ 'TOOL-VAR' ] ],
	103 => [ DTB_ProductMeta::PRODUCT_KIND => 'tool', DTB_ProductMeta::COMPATIBLE_TOOL_SKUS => [ 'TOOL-A' ] ],
	104 => [ DTB_ProductMeta::PRODUCT_KIND => 'part', DTB_ProductMeta::COMPATIBLE_TOOL_SKUS => [ 'TOOL-HIDDEN' ] ],
	105 => [ DTB_ProductMeta::PRODUCT_KIND => 'part' ],
	106 => [ DTB_ProductMeta::COMPATIBLE_TOOL_SKUS => [ 'TOOL-VAR' ] ],
];

$response = DTB_CompatiblePartsController::handle_cart_compatible_parts(
	new WP_REST_Request( [ 'skus' => 'TOOL-A,TOOL-VAR,PART-CART,TOOL-HIDDEN,tool-a', 'limit' => 12 ] )
);
$data = $response->get_data();
$rows = $data['products'] ?? [];

if ( 200 !== $response->get_status() || 3 !== count( $rows ) ) {
	throw new RuntimeException( 'Batch cart compatibility did not return the eligible part products, including the exact variable-part owner.' );
}
if ( [ 'PART-A', 'PART-VAR', 'PART-FAMILY' ] !== array_column( $rows, 'sku' ) ) {
	throw new RuntimeException( 'Batch cart compatibility returned a non-exact or non-part product.' );
}
if ( [ [ 'TOOL-A' ], [ 'TOOL-VAR' ], [] ] !== array_column( $rows, 'compatible_with_skus' ) ) {
	throw new RuntimeException( 'Batch cart compatibility did not retain the exact matching cart SKU identities.' );
}
if ( 'PART-CHILD-3' !== ( $rows[2]['compatible_part_variations'][0]['sku'] ?? '' ) || [ 'TOOL-VAR' ] !== ( $rows[2]['compatible_part_variations'][0]['compatible_with_skus'] ?? [] ) ) {
	throw new RuntimeException( 'Exact variation-owned fitment was not retained and attributed to its exact cart SKU: ' . json_encode( $rows[2] ) );
}
if ( ! str_contains( (string) ( $rows[2]['compatible_part_variations'][0]['permalink'] ?? '' ), 'attribute_pa_size=3' ) ) {
	throw new RuntimeException( 'The variation recommendation did not carry its exact selection URL.' );
}

echo "Batch cart compatibility endpoint returns exact, eligible part recommendations.\n";
