<?php

define( 'ABSPATH', __DIR__ );

final class WP_Error {
	public function __construct( public string $code, public string $message, public array $data = [] ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): array { return $this->data; }
}

function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function add_action( string $hook, $callback ): void { $GLOBALS['actions'][ $hook ] = $callback; }
function wp_register_ability_category( string $name, array $args ): void { $GLOBALS['categories'][ $name ] = $args; }
function wp_register_ability( string $name, array $args ): void { $GLOBALS['abilities'][ $name ] = $args; }
function is_user_logged_in(): bool { return (bool) $GLOBALS['logged_in']; }
function current_user_can( string $capability ): bool { return (bool) $GLOBALS['capabilities'][ $capability ]; }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
function absint( $value ): int { return abs( (int) $value ); }
function wp_json_encode( $value ): string { return json_encode( $value ); }

final class DTB_ProductRelationshipProjectionService {
	public const MAX_ARTIFACT_BYTES = 2097152;
	public static array $calls = [];
	public static string $staged_path = '';
	public static string $report_path = '';

	public static function stage_artifact_payload( string $payload, string $sha256 ): array {
		if ( ! hash_equals( $sha256, hash( 'sha256', $payload ) ) ) {
			throw new RuntimeException( 'Artifact digest mismatch.' );
		}
		$artifact = json_decode( $payload, true );
		self::$staged_path = tempnam( sys_get_temp_dir(), 'dtb-rel-stage-' );
		file_put_contents( self::$staged_path, $payload );
		return [ 'release_id' => $artifact['release_id'], 'batch_index' => $artifact['batch_index'], 'artifact_sha256' => $sha256, 'staged' => true ];
	}

	public static function get_staged_artifact_path( string $release_id, int $batch_index, string $sha256 ): string {
		$artifact = json_decode( (string) file_get_contents( self::$staged_path ), true );
		if ( $artifact['release_id'] !== $release_id || $artifact['batch_index'] !== $batch_index || ! hash_equals( $sha256, (string) hash_file( 'sha256', self::$staged_path ) ) ) {
			throw new RuntimeException( 'Staged artifact identity mismatch.' );
		}
		return self::$staged_path;
	}

	public static function run_artifact( string $path, string $sha256, bool $apply ): array {
		self::$calls[] = [ $sha256, $apply, hash_file( 'sha256', $path ) ];
		return [ 'mode' => $apply ? 'apply' : 'dry-run', 'applied' => $apply, 'report_path' => $path, 'release' => [ 'batches' => [ '1' => [ 'report_path' => $path ] ] ] ];
	}

	public static function read_release_status( string $release_id ): array {
		return [
			'release_id' => $release_id,
			'status' => 'applied',
			'batches' => [
				'1' => [ 'status' => 'applied', 'report_path' => self::$report_path, 'current_report_sha256' => hash_file( 'sha256', self::$report_path ) ],
			],
		];
	}

	public static function rollback_report( string $path, string $sha256 ): array {
		if ( ! hash_equals( $sha256, (string) hash_file( 'sha256', $path ) ) ) {
			throw new RuntimeException( 'Rollback report digest mismatch.' );
		}
		return [ 'rolled_back' => true, 'report_path' => $path, 'report_sha256' => $sha256 ];
	}
}

$GLOBALS['actions'] = [];
$GLOBALS['categories'] = [];
$GLOBALS['abilities'] = [];
$GLOBALS['logged_in'] = true;
$GLOBALS['capabilities'] = [ 'manage_options' => true ];

require __DIR__ . '/../../../drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Rest/ProductRelationshipMcpAbilities.php';

function expect( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

DTB_Product_Relationship_MCP_Abilities::register_category();
DTB_Product_Relationship_MCP_Abilities::register();
expect( isset( $GLOBALS['categories']['dtb-product-relationships'] ), 'relationship ability category registers' );
expect( count( $GLOBALS['abilities'] ) === 5, 'stage, preview, apply, status, and rollback abilities register' );
expect( DTB_ProductRelationshipProjectionService::MAX_ARTIFACT_BYTES === $GLOBALS['abilities']['dtb-product-relationships/projection-stage']['input_schema']['properties']['artifact_json']['maxLength'], 'MCP staging schema uses the projector artifact byte ceiling' );
expect( false === $GLOBALS['abilities']['dtb-product-relationships/projection-stage']['meta']['annotations']['destructive'], 'private staging is a reversible POST operation' );
expect( false === $GLOBALS['abilities']['dtb-product-relationships/projection-apply']['meta']['annotations']['readonly'], 'apply ability is marked as a write' );
expect( true === $GLOBALS['abilities']['dtb-product-relationships/projection-apply']['meta']['annotations']['destructive'], 'product projection is marked destructive' );
expect( true === $GLOBALS['abilities']['dtb-product-relationships/projection-preview']['meta']['annotations']['readonly'], 'preview ability is read-only' );
expect( false === $GLOBALS['abilities']['dtb-product-relationships/projection-rollback']['meta']['annotations']['idempotent'], 'rollback is not advertised as idempotent' );

$release_id = 'abcdef0123456789abcdef01';
$payload = wp_json_encode( [ 'schema_version' => 1, 'artifact_type' => 'dtb_compatibility_projection', 'release_id' => $release_id, 'batch_index' => 1, 'batch_count' => 1 ] );
$sha256 = hash( 'sha256', $payload );
$GLOBALS['logged_in'] = false;
$denied = DTB_Product_Relationship_MCP_Abilities::stage( [ 'artifact_json' => $payload, 'expected_sha256' => $sha256 ] );
expect( is_wp_error( $denied ) && 403 === $denied->get_error_data()['status'], 'unauthenticated artifact staging is denied' );
$GLOBALS['logged_in'] = true;
$stage = DTB_Product_Relationship_MCP_Abilities::stage( [ 'artifact_json' => $payload, 'expected_sha256' => $sha256 ] );
expect( ! is_wp_error( $stage ) && $stage['artifact_sha256'] === $sha256, 'stage stores exact digest-checked artifact bytes' );
expect( ! isset( $stage['path'] ) && ! isset( $stage['report_path'] ), 'stage response does not expose private storage paths' );
$batch_input = [ 'release_id' => $release_id, 'batch_index' => 1, 'artifact_sha256' => $sha256 ];
$preview = DTB_Product_Relationship_MCP_Abilities::preview( $batch_input );
expect( ! is_wp_error( $preview ) && 'dry-run' === ( $preview['mode'] ?? '' ), 'preview resolves the exact private staged batch' . ( is_wp_error( $preview ) ? ': ' . $preview->get_error_message() : '' ) );
expect( ! isset( $preview['report_path'] ), 'preview response does not expose server filesystem paths' );
$calls_before = count( DTB_ProductRelationshipProjectionService::$calls );
$bad_digest = DTB_Product_Relationship_MCP_Abilities::stage( [ 'artifact_json' => $payload, 'expected_sha256' => str_repeat( '0', 64 ) ] );
expect( is_wp_error( $bad_digest ) && 'dtb_relationship_stage_failed' === $bad_digest->get_error_code(), 'incorrect artifact digest is rejected at staging' );
expect( $calls_before === count( DTB_ProductRelationshipProjectionService::$calls ), 'invalid digest never invokes the projector' );

$unconfirmed = DTB_Product_Relationship_MCP_Abilities::apply( $batch_input );
expect( is_wp_error( $unconfirmed ) && 'dtb_relationship_confirmation_required' === $unconfirmed->get_error_code(), 'apply requires explicit confirmation' );
$wrong_release = DTB_Product_Relationship_MCP_Abilities::apply( array_merge( $batch_input, [ 'confirm_release_id' => str_repeat( '0', 24 ), 'confirm_apply' => true ] ) );
expect( is_wp_error( $wrong_release ) && 'dtb_relationship_confirmation_mismatch' === $wrong_release->get_error_code(), 'apply confirmation must match the exact artifact release ID' );
$confirmed = DTB_Product_Relationship_MCP_Abilities::apply( array_merge( $batch_input, [ 'confirm_release_id' => $release_id, 'confirm_apply' => true ] ) );
expect( ! is_wp_error( $confirmed ) && true === $confirmed['applied'], 'confirmed apply delegates the exact release in apply mode' );
expect( ! isset( $confirmed['report_path'] ) && ! isset( $confirmed['release']['batches']['1']['report_path'] ), 'apply response does not expose server filesystem paths' );
expect( file_exists( DTB_ProductRelationshipProjectionService::$staged_path ), 'staged artifact remains available for idempotent retry until expiry' );

$report_path = tempnam( sys_get_temp_dir(), 'dtb-rel-report-' );
file_put_contents( $report_path, 'private rollback report' );
DTB_ProductRelationshipProjectionService::$report_path = $report_path;
$status = DTB_Product_Relationship_MCP_Abilities::status( [ 'release_id' => $release_id ] );
expect( ! is_wp_error( $status ) && ! isset( $status['batches']['1']['report_path'] ), 'status does not expose server filesystem paths' );
$rollback = DTB_Product_Relationship_MCP_Abilities::rollback( [
	'release_id' => $release_id,
	'batch_index' => 1,
	'rollback_report_sha256' => hash_file( 'sha256', $report_path ),
	'confirm_rollback' => true,
] );
expect( ! is_wp_error( $rollback ) && true === $rollback['rolled_back'], 'confirmed rollback delegates the digest-pinned private report' );
expect( ! isset( $rollback['report_path'] ), 'rollback response does not expose server filesystem paths' );
$stale_report = DTB_Product_Relationship_MCP_Abilities::rollback( [
	'release_id' => $release_id,
	'batch_index' => 1,
	'rollback_report_sha256' => str_repeat( '0', 64 ),
	'confirm_rollback' => true,
] );
expect( is_wp_error( $stale_report ) && 'dtb_relationship_rollback_conflict' === $stale_report->get_error_code(), 'rollback refuses a stale report digest' );
unlink( $report_path );
unlink( DTB_ProductRelationshipProjectionService::$staged_path );

echo "PASS: product relationship MCP ability contracts\n";
