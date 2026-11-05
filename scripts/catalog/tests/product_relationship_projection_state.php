<?php
/** Focused state-transition checks for the private fitment release ledger. */

$root = sys_get_temp_dir() . '/dtb-relationship-projection-' . bin2hex( random_bytes( 5 ) );
$webroot = $root . '/webroot';
$private = $root . '/private';
mkdir( $webroot, 0700, true );
mkdir( $private, 0700, true );
define( 'ABSPATH', $webroot . DIRECTORY_SEPARATOR );
define( 'DTB_RELATIONSHIP_PRIVATE_DIR', $private );
define( 'YEAR_IN_SECONDS', 31536000 );

function untrailingslashit( string $value ): string { return rtrim( $value, '/\\' ); }
function wp_normalize_path( string $value ): string { return str_replace( '\\', '/', $value ); }
function wp_mkdir_p( string $path ): bool { return mkdir( $path, 0700, true ); }
function wp_json_encode( $value, int $flags = 0 ) { return json_encode( $value, $flags ); }
function absint( $value ): int { return abs( (int) $value ); }
function wc_get_product_id_by_sku( string $sku ): int { return (int) ( $GLOBALS['projection_sku_ids'][ strtoupper( $sku ) ] ?? 0 ); }
function wc_get_product( int $id ) { return $GLOBALS['projection_products'][ $id ] ?? false; }
function get_post_status( int $id ): string { return (string) ( $GLOBALS['projection_statuses'][ $id ] ?? '' ); }
function get_post_meta( int $id, string $key, bool $single = false ) { return $GLOBALS['projection_meta'][ $id ][ $key ] ?? ''; }

class WC_Product {
	public function __construct( private string $sku, private bool $visible = true ) {}
	public function get_sku(): string { return $this->sku; }
	public function is_visible(): bool { return $this->visible; }
}

class WC_Product_Variation extends WC_Product {
	public function __construct( string $sku, private int $parent_id ) { parent::__construct( $sku ); }
	public function get_parent_id(): int { return $this->parent_id; }
}

final class DTB_ProductMeta {
	public const PRODUCT_KIND = '_dtb_product_kind';
	public const IS_PARTS = '_dtb_is_parts';
}

final class ProjectionTestWpdb {
	public function prepare( string $query, ...$args ): string { return $query; }
	public function get_var( string $query ) { return 1; }
}
$GLOBALS['wpdb'] = new ProjectionTestWpdb();

require dirname( __DIR__, 3 ) . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Services/ProductRelationshipProjectionService.php';
require dirname( __DIR__, 3 ) . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Rest/CompatiblePartsController.php';

$record = new ReflectionMethod( 'DTB_ProductRelationshipProjectionService', 'record_release_batch' );
$record->setAccessible( true );
$canonical_hash = new ReflectionMethod( 'DTB_ProductRelationshipProjectionService', 'canonical_sha256' );
$canonical_hash->setAccessible( true );
$expected_release_id = new ReflectionMethod( 'DTB_ProductRelationshipProjectionService', 'expected_release_id' );
$expected_release_id->setAccessible( true );
$compatible_sku_set = new ReflectionMethod( 'DTB_ProductRelationshipProjectionService', 'compatible_tool_sku_set' );
$compatible_sku_set->setAccessible( true );
$validate_runtime_records = new ReflectionMethod( 'DTB_ProductRelationshipProjectionService', 'validate_runtime_records' );
$validate_runtime_records->setAccessible( true );
$get_tool_skus = new ReflectionMethod( 'DTB_CompatiblePartsController', 'get_tool_skus_for_product' );
$get_tool_skus->setAccessible( true );
$append_parent_sku = new ReflectionMethod( 'DTB_CompatiblePartsController', 'append_parent_tool_sku' );
$append_parent_sku->setAccessible( true );
$check = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$check( '7082784e675fe4bc40da89cc' === $expected_release_id->invoke( null, [ 'catalog_sha256' => str_repeat( 'a', 64 ), 'manifest_sha256' => str_repeat( 'b', 64 ), 'catalog_apply_report_sha256' => str_repeat( 'c', 64 ) ], str_repeat( 'd', 64 ) ), 'PHP release identity must match the versioned artifact-builder contract.' );
$artifact = static function ( string $release_id, int $index, int $count ): array {
	return [
		'release_id' => $release_id,
		'batch_index' => $index,
		'batch_count' => $count,
		'catalog_sha256' => str_repeat( 'a', 64 ),
		'manifest_sha256' => str_repeat( 'b', 64 ),
		'catalog_apply_report_sha256' => str_repeat( 'c', 64 ),
		'batch_inventory' => [],
		'batch_inventory_sha256' => str_repeat( 'd', 64 ),
	];
};

$release = str_repeat( '1', 24 );
$storage = DTB_ProductRelationshipProjectionService::verify_private_storage();
$check( true === $storage['writable'] && false === $storage['web_accessible'], 'Private storage preflight did not report the expected access boundary.' );
$stage_release = str_repeat( '2', 24 );
$stage_payload = wp_json_encode( [ 'schema_version' => 1, 'artifact_type' => 'dtb_compatibility_projection', 'release_id' => $stage_release, 'batch_index' => 1, 'batch_count' => 1 ] );
$stage_sha = hash( 'sha256', $stage_payload );
$staged = DTB_ProductRelationshipProjectionService::stage_artifact_payload( $stage_payload, $stage_sha );
$check( $stage_sha === $staged['artifact_sha256'] && $stage_release === $staged['release_id'] && true === $staged['staged'], 'Staging must preserve the exact digest and release identity.' );
$staged_path = DTB_ProductRelationshipProjectionService::get_staged_artifact_path( $stage_release, 1, $stage_sha );
$check( dirname( $staged_path ) === realpath( $private ) && $stage_sha === hash_file( 'sha256', $staged_path ), 'Staged artifact must remain digest-verified inside private storage.' );
$staged_again = DTB_ProductRelationshipProjectionService::stage_artifact_payload( $stage_payload, $stage_sha );
$check( true === $staged_again['already_staged'], 'Staging the same exact artifact must be idempotent.' );
$stage_digest_rejected = false;
try {
	DTB_ProductRelationshipProjectionService::stage_artifact_payload( $stage_payload, str_repeat( '0', 64 ) );
} catch ( RuntimeException $error ) {
	$stage_digest_rejected = str_contains( $error->getMessage(), 'SHA-256' );
}
$check( $stage_digest_rejected, 'Artifact staging must reject a mismatched digest.' );
$stage_identity_rejected = false;
try {
	DTB_ProductRelationshipProjectionService::get_staged_artifact_path( str_repeat( '3', 24 ), 1, $stage_sha );
} catch ( RuntimeException $error ) {
	$stage_identity_rejected = str_contains( $error->getMessage(), 'identity does not match' );
}
$check( $stage_identity_rejected, 'A staged artifact cannot be read under another release identity.' );
touch( $staged_path, time() - 86401 );
$stage_expired = false;
try {
	DTB_ProductRelationshipProjectionService::get_staged_artifact_path( $stage_release, 1, $stage_sha );
} catch ( RuntimeException $error ) {
	$stage_expired = str_contains( $error->getMessage(), 'expired' );
}
$check( $stage_expired && ! file_exists( $staged_path ), 'Expired staged artifacts must be removed and refused.' );
$route_skus = $compatible_sku_set->invoke( null, [
	[ 'sku' => 'TOOL-PARENT', 'compatible_variations' => [ [ 'sku' => '03TT' ], [ 'sku' => '07TT' ] ] ],
	[ 'sku' => 'OTHER-TOOL', 'compatible_variations' => [ [ 'sku' => '' ], null ] ],
] );
$check( [ 'TOOL-PARENT' => true, '03TT' => true, '07TT' => true, 'OTHER-TOOL' => true ] === $route_skus, 'Public compatibility read-back must preserve exact variation SKUs grouped under parent DTOs.' );
$variation_request_skus = $append_parent_sku->invoke( null, [ '8034TT' ], 'tt-flat-box-handle', true );
$check( [ '8034TT', 'tt-flat-box-handle' ] === $variation_request_skus, 'Variation family expansion must preserve the stored family SKU spelling.' );
$check( [ '8034TT' ] === $append_parent_sku->invoke( null, [ '8034TT' ], 'TT-FLAT-BOX-HANDLE', false ), 'Unpublished, invisible, or part parents must not provide inherited family fitment.' );
$GLOBALS['projection_products'] = [ 8034 => new WC_Product_Variation( '8034TT', 8100 ), 8100 => new WC_Product( 'TT-FLAT-BOX-HANDLE' ) ];
$GLOBALS['projection_sku_ids'] = [ '8034TT' => 8034 ];
$GLOBALS['projection_statuses'] = [ 8100 => 'publish' ];
$GLOBALS['projection_meta'] = [ 8100 => [ DTB_ProductMeta::PRODUCT_KIND => 'tool', DTB_ProductMeta::IS_PARTS => '0' ] ];
$check( [ '8034TT', 'TT-FLAT-BOX-HANDLE' ] === $get_tool_skus->invoke( null, '8034TT' ), 'Variation lookup must execute exact variation plus published non-part family SKU resolution.' );
$GLOBALS['projection_statuses'][8100] = 'draft';
$check( [ '8034TT' ] === $get_tool_skus->invoke( null, '8034TT' ), 'Variation lookup must not inherit family relationships from an unpublished parent.' );
$GLOBALS['projection_statuses'][8100] = 'publish';
$GLOBALS['projection_meta'][8100][ DTB_ProductMeta::PRODUCT_KIND ] = 'part';
$check( [ '8034TT' ] === $get_tool_skus->invoke( null, '8034TT' ), 'Variation lookup must not inherit family relationships from a part parent.' );
$runtime_rows = [ [ 'sku' => 'PART-1', 'after' => [ 'Meta: _dtb_compatible_tool_skus' => [ 'TOOL-1' ] ] ] ];
$exact_runtime_map = [
	'PART-1' => [ [ 'sku' => 'PART-1', 'id' => 1, 'post_type' => 'product', 'post_status' => 'publish', 'is_part' => true ] ],
	'TOOL-1' => [ [ 'sku' => 'TOOL-1', 'id' => 2, 'post_type' => 'product', 'post_status' => 'publish', 'is_part' => false ] ],
];
$check( 1 === count( $validate_runtime_records->invoke( null, $runtime_rows, $exact_runtime_map )['parts'] ), 'Exact canonical SKU identities should resolve for fitment projection.' );
$variation_owner_map = $exact_runtime_map;
$variation_owner_map['PART-1'][0] = [
	'sku' => 'PART-1',
	'id' => 11,
	'post_type' => 'product_variation',
	'post_status' => 'publish',
	'parent_id' => 10,
	'parent_is_part' => true,
];
$check( 11 === $validate_runtime_records->invoke( null, $runtime_rows, $variation_owner_map )['parts']['PART-1']['id'], 'An exact published variation SKU may own fitment when its published parent is explicitly classified as a part.' );
$variation_owner_map['PART-1'][0]['parent_is_part'] = false;
$variation_owner_rejected = false;
try {
	$validate_runtime_records->invoke( null, $runtime_rows, $variation_owner_map );
} catch ( RuntimeException $error ) {
	$variation_owner_rejected = str_contains( $error->getMessage(), 'not a part or a variation of a published part parent' );
}
$check( $variation_owner_rejected, 'A variation owner without an exact published part-parent classification must be rejected.' );
$variation_owner_map['PART-1'][0]['parent_is_part'] = true;
$variation_owner_map['PART-1'][0]['post_status'] = 'draft';
$variation_owner_rejected = false;
try {
	$validate_runtime_records->invoke( null, $runtime_rows, $variation_owner_map );
} catch ( RuntimeException $error ) {
	$variation_owner_rejected = str_contains( $error->getMessage(), 'does not resolve exactly to one published relationship owner' );
}
$check( $variation_owner_rejected, 'A non-published relationship-owning variation must be rejected.' );
$case_mismatch_map = $exact_runtime_map;
$case_mismatch_map['TOOL-1'][0]['sku'] = 'tool-1';
$case_mismatch_rejected = false;
try {
	$validate_runtime_records->invoke( null, $runtime_rows, $case_mismatch_map );
} catch ( ReflectionException $error ) {
	throw $error;
} catch ( RuntimeException $error ) {
	$case_mismatch_rejected = str_contains( $error->getMessage(), 'does not resolve exactly' );
}
$check( $case_mismatch_rejected, 'Case-only runtime SKU drift must not satisfy an exact canonical target identity.' );
$case_mismatch_map = $exact_runtime_map;
$case_mismatch_map['PART-1'][0]['sku'] = 'part-1';
$case_mismatch_rejected = false;
try {
	$validate_runtime_records->invoke( null, $runtime_rows, $case_mismatch_map );
} catch ( ReflectionException $error ) {
	throw $error;
} catch ( RuntimeException $error ) {
	$case_mismatch_rejected = str_contains( $error->getMessage(), 'does not resolve exactly' );
}
$check( $case_mismatch_rejected, 'Case-only runtime part SKU drift must not satisfy an exact canonical owner identity.' );
$inventory = [ [ 'batch_index' => 1, 'part_skus' => [ 'PART-1' ], 'rows_sha256' => str_repeat( 'a', 64 ) ] ];
$canonical_inventory = '[{"batch_index":1,"part_skus":["PART-1"],"rows_sha256":"' . str_repeat( 'a', 64 ) . '"}]';
$check( hash( 'sha256', $canonical_inventory ) === $canonical_hash->invoke( null, $inventory ), 'PHP canonical inventory hashing changed.' );
$source_report = $private . '/rollback.json';
file_put_contents( $source_report, '{"rows":[]}' );
$source_sha = hash_file( 'sha256', $source_report );
$record->invoke( null, $artifact( $release, 1, 1 ), str_repeat( 'e', 64 ), 'applying', [ 'PART-1' ], $source_report, $source_sha );
$record->invoke( null, $artifact( $release, 1, 1 ), str_repeat( 'e', 64 ), 'applied', [ 'PART-1' ], $source_report, $source_sha );
$record->invoke( null, $artifact( $release, 1, 1 ), str_repeat( 'e', 64 ), 'already_applied', [], null, null );
$status = DTB_ProductRelationshipProjectionService::read_release_status( $release );
$batch = $status['batches']['1'] ?? [];
$check( 'complete' === $status['status'], 'Applied replay should keep a complete release status.' );
$check( [ 'PART-1' ] === ( $batch['part_skus'] ?? [] ), 'Applied replay erased the batch part list.' );
$check( $source_report === ( $batch['report_path'] ?? '' ), 'Applied replay erased the rollback report pointer.' );
$check( hash_file( 'sha256', $source_report ) === ( $batch['current_report_sha256'] ?? '' ), 'Release status did not calculate the current rollback digest.' );
$check( 3 === count( $batch['attempts'] ?? [] ), 'Release status did not preserve every batch attempt.' );

$mixed = str_repeat( '2', 24 );
$record->invoke( null, $artifact( $mixed, 1, 2 ), str_repeat( 'f', 64 ), 'rolled_back', [ 'PART-1' ], $source_report, $source_sha );
$record->invoke( null, $artifact( $mixed, 2, 2 ), str_repeat( '9', 64 ), 'failed', [ 'PART-2' ], $source_report, $source_sha );
$mixed_status = DTB_ProductRelationshipProjectionService::read_release_status( $mixed );
$check( 'partial_failure' === $mixed_status['status'], 'Mixed rollback and failed batches must report partial_failure.' );

fwrite( STDOUT, "Product relationship projection release-state checks passed.\n" );
