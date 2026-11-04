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

final class ProjectionTestWpdb {
	public function prepare( string $query, ...$args ): string { return $query; }
	public function get_var( string $query ) { return 1; }
}
$GLOBALS['wpdb'] = new ProjectionTestWpdb();

require dirname( __DIR__, 3 ) . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Services/ProductRelationshipProjectionService.php';

$record = new ReflectionMethod( 'DTB_ProductRelationshipProjectionService', 'record_release_batch' );
$record->setAccessible( true );
$canonical_hash = new ReflectionMethod( 'DTB_ProductRelationshipProjectionService', 'canonical_sha256' );
$canonical_hash->setAccessible( true );
$check = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
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
