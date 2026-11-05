<?php
/** Parts Manager must use the canonical universal projection service. */

define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );
$root = dirname( __DIR__, 3 );
require $root . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Domain/ProductMeta.php';

$GLOBALS['test_sku_id']       = 0;
$GLOBALS['test_post_type']    = [];
$GLOBALS['test_product_meta'] = [];
$GLOBALS['test_query_ids']    = [];
$GLOBALS['test_success']      = null;
$GLOBALS['test_last_query']   = null;
$GLOBALS['test_wc_products']  = [];
$GLOBALS['test_meta_writes']  = [];

function add_action( string $hook, string $callback ): void {}
function dtb_is_admin_or_ajax_request(): bool { return true; }
function check_ajax_referer( string $action, string $field ): void {}
function current_user_can( string $capability ): bool { return 'dtb_manage_parts' === $capability; }
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', $value ) ); }
function sanitize_text_field( string $value ): string { return trim( $value ); }
function absint( $value ): int { return abs( (int) $value ); }
function wp_unslash( $value ) { return $value; }
function wp_kses_post( string $value ): string { return $value; }
function wc_format_decimal( $value ): string { return (string) $value; }
function wc_get_product_id_by_sku( string $sku ): int { return (int) $GLOBALS['test_sku_id']; }
function wc_get_product( int $id ) { return $GLOBALS['test_wc_products'][ $id ] ?? false; }
function get_post_type( int $post_id ): string { return (string) ( $GLOBALS['test_post_type'][ $post_id ] ?? '' ); }
function get_post_meta( int $post_id, string $key, bool $single = false ): string { return (string) ( $GLOBALS['test_product_meta'][ $post_id ][ $key ] ?? '' ); }
function update_post_meta( int $post_id, string $key, $value ): void { $GLOBALS['test_product_meta'][ $post_id ][ $key ] = (string) $value; $GLOBALS['test_meta_writes'][] = [ $post_id, $key, $value ]; }
function get_posts( array $args ): array { $GLOBALS['test_last_query'] = $args; return $GLOBALS['test_query_ids']; }
function current_time( string $type, bool $gmt = false ): string { return '2026-11-04 00:00:00'; }
function wp_send_json_success( array $data ): void { $GLOBALS['test_success'] = $data; }

class WC_Product {
	public array $meta = [];
	public int $save_count = 0;
	public function __construct( private string $sku ) {}
	public function get_sku(): string { return $this->sku; }
	public function delete_meta_data( string $key ): void { unset( $this->meta[ $key ] ); }
	public function update_meta_data( string $key, $value ): void { $this->meta[ $key ] = $value; }
	public function set_name( string $value ): void {}
	public function set_description( string $value ): void {}
	public function set_status( string $value ): void {}
	public function set_regular_price( string $value ): void {}
	public function save(): int { ++$this->save_count; return 90; }
}
class TestPartsManagerProduct extends WC_Product {}
class WC_Product_Variation extends TestPartsManagerProduct {}

require $root . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Services/UniversalPartsProjectionService.php';
require $root . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Admin/PartsManagerActions.php';

$service = new DTB_UniversalPartsProjectionService();
$reflect = new ReflectionClass( $service );
$resolve = $reflect->getMethod( 'resolve_member_product_id' );
$sku_lookup = $reflect->getMethod( 'find_part_id_by_sku' );
$mpn_lookup = $reflect->getMethod( 'find_part_id_by_manufacturer_sku' );

$GLOBALS['test_sku_id']                  = 90;
$GLOBALS['test_post_type'][90]           = 'product';
$GLOBALS['test_product_meta'][90][ DTB_ProductMeta::PRODUCT_KIND ] = 'part';
if ( 90 !== $sku_lookup->invoke( $service, 'PART-90' )['product_id'] ) {
	throw new RuntimeException( 'Product-kind classified parts must resolve through exact SKU identity.' );
}

$GLOBALS['test_post_type'][90] = 'product_variation';
if ( 0 !== $sku_lookup->invoke( $service, 'PART-90' )['product_id'] ) {
	throw new RuntimeException( 'Variation SKU matches must not resolve as catalog parts.' );
}

$GLOBALS['test_query_ids'] = [ 92 ];
if ( 92 !== $mpn_lookup->invoke( $service, 'MPN-92' )['product_id'] ) {
	throw new RuntimeException( 'A unique manufacturer part number should resolve to its part.' );
}
$fallback = $resolve->invoke( $service, [ 'brand_sku' => '', 'manufacturer_sku' => 'MPN-92' ] );
if ( 92 !== $fallback['product_id'] || 'resolved_by_manufacturer_sku' !== $fallback['reason'] ) {
	throw new RuntimeException( 'A missing brand SKU should allow unique manufacturer-SKU fallback.' );
}
$query = $GLOBALS['test_last_query'];
if ( 2 !== $query['posts_per_page'] || 'OR' !== $query['meta_query'][0]['relation'] ) {
	throw new RuntimeException( 'Manufacturer part lookup must include both supported part classifications and detect ambiguity.' );
}
$GLOBALS['test_query_ids'] = [ 92, 93 ];
if ( 'manufacturer_sku_ambiguous' !== $mpn_lookup->invoke( $service, 'MPN-DUPLICATE' )['reason'] ) {
	throw new RuntimeException( 'Ambiguous manufacturer part numbers must fail closed.' );
}
$ambiguous = $resolve->invoke( $service, [ 'brand_sku' => '', 'manufacturer_sku' => 'MPN-DUPLICATE' ] );
if ( 0 !== $ambiguous['product_id'] || 2 !== count( $ambiguous['candidate_ids'] ) ) {
	throw new RuntimeException( 'Ambiguous manufacturer-SKU candidates must be returned for operator review.' );
}

$GLOBALS['test_sku_id']        = 90;
$GLOBALS['test_post_type'][90] = 'product_variation';
$GLOBALS['test_query_ids']     = [ 92 ];
$wrong_type = $resolve->invoke( $service, [ 'brand_sku' => 'VAR-90', 'manufacturer_sku' => 'MPN-92' ] );
if ( 'sku_resolves_to_non_product' !== $wrong_type['reason'] || 0 !== $wrong_type['product_id'] ) {
	throw new RuntimeException( 'An exact SKU resolving to a variation must not fall through to a different MPN match.' );
}

$GLOBALS['test_post_type'][90] = 'product';
$GLOBALS['test_product_meta'][90][ DTB_ProductMeta::IS_PARTS ] = '1';
$GLOBALS['test_product_meta'][90][ DTB_ProductMeta::PRODUCT_KIND ] = 'part';
$GLOBALS['test_product_meta'][90][ DTB_ProductMeta::MANUFACTURER_SKU ] = 'MPN-90';
$GLOBALS['test_product_meta'][90][ DTB_ProductMeta::UNIVERSAL_PART_ID ] = 'UP-EXISTING';
$GLOBALS['test_product_meta'][90][ DTB_ProductMeta::UNIVERSAL_PART_STATUS ] = 'active';
$GLOBALS['test_wc_products'][90] = new TestPartsManagerProduct( 'PART-90' );
$service->apply_universal_meta( 90, [
	'universal_part_id' => 'UP-CANONICAL',
	'universal_part_status' => 'active',
	'universal_part_confidence' => 'verified',
	'universal_part_family' => 'blade',
	'universal_part_signature' => 'sha256:source',
] );
if ( 'UP-CANONICAL' !== $GLOBALS['test_wc_products'][90]->meta[ DTB_ProductMeta::UNIVERSAL_PART_ID ] || 1 !== $GLOBALS['test_wc_products'][90]->save_count ) {
	throw new RuntimeException( 'Canonical universal projection must persist through WooCommerce CRUD.' );
}
$_POST = [
	'id' => '90',
	'title' => 'Edited part',
	'sku' => 'PART-90',
	'brand_label' => 'Brand',
	'manufacturer_sku' => 'MPN-90',
	'price' => '1.00',
	'description' => '',
	'status' => 'publish',
	'universal_part_id' => 'UP-STALE-EDIT',
	'universal_part_status' => 'quarantine',
];
dtb_ajax_parts_save();
if ( 'UP-EXISTING' !== $GLOBALS['test_product_meta'][90][ DTB_ProductMeta::UNIVERSAL_PART_ID ] || 'active' !== $GLOBALS['test_product_meta'][90][ DTB_ProductMeta::UNIVERSAL_PART_STATUS ] ) {
	throw new RuntimeException( 'Generic Parts Manager saves must not overwrite canonical universal-part projections, even when stale universal fields are submitted.' );
}
foreach ( $GLOBALS['test_meta_writes'] as $write ) {
	if ( in_array( $write[1], [ DTB_ProductMeta::UNIVERSAL_PART_ID, DTB_ProductMeta::UNIVERSAL_PART_STATUS, DTB_ProductMeta::UNIVERSAL_PART_CONFIDENCE, DTB_ProductMeta::UNIVERSAL_PART_FAMILY, DTB_ProductMeta::UNIVERSAL_PART_SIGNATURE, DTB_ProductMeta::UNIVERSAL_PART_SYNCED_AT ], true ) ) {
		throw new RuntimeException( 'Generic part save wrote a universal-part projection meta key.' );
	}
}

$_POST = [ 'mode' => 'dry_run' ];
dtb_ajax_parts_universal_sync();
if ( ! is_array( $GLOBALS['test_success'] ) || 'dry_run' !== ( $GLOBALS['test_success']['mode'] ?? '' ) || ! str_starts_with( (string) ( $GLOBALS['test_success']['message'] ?? '' ), 'Universal seed dry run complete.' ) ) {
	throw new RuntimeException( 'Parts Manager universal sync did not delegate to the shared projection service.' );
}

echo "Universal projection owner, exact identity, part eligibility, and dry-run delegation checks passed.\n";

$temp_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dtb-universal-projection-' . bin2hex( random_bytes( 6 ) );
if ( ! mkdir( $temp_dir, 0700 ) ) {
	throw new RuntimeException( 'Unable to create isolated seed-validation test directory.' );
}
try {
	$missing = ( new DTB_UniversalPartsProjectionService( $temp_dir ) )->sync_members( 'apply' );
	if ( 'seed_files_unavailable' !== ( $missing['error'] ?? '' ) || 0 !== ( $missing['updated'] ?? -1 ) ) {
		throw new RuntimeException( 'Missing seed files must stop projection before writes.' );
	}

	file_put_contents( $temp_dir . '/parts.csv', "universal_part_id,status\n" );
	file_put_contents( $temp_dir . '/members.csv', "universal_part_id,brand_sku\n" );
	$empty = ( new DTB_UniversalPartsProjectionService( $temp_dir ) )->sync_members( 'apply' );
	if ( 'seed_data_empty' !== ( $empty['error'] ?? '' ) || 0 !== ( $empty['updated'] ?? -1 ) ) {
		throw new RuntimeException( 'Empty seed files must stop projection before writes.' );
	}
} finally {
	foreach ( [ 'parts.csv', 'members.csv' ] as $filename ) {
		if ( is_file( $temp_dir . '/' . $filename ) ) {
			unlink( $temp_dir . '/' . $filename );
		}
	}
	rmdir( $temp_dir );
}

echo "Universal projection missing/empty seed guards passed.\n";
