<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

final class WP_Error {
	public function __construct( public string $code, public string $message ) {}
	public function get_error_code(): string { return $this->code; }
}

final class DTB_ProductMeta {
	public const PRODUCT_KIND = '_dtb_product_kind';
	public const IS_PARTS = '_dtb_is_parts';
	public const COMPATIBLE_TOOL_SKUS = '_dtb_compatible_tool_skus';
	public const REPLACEMENT_PART_FOR = '_dtb_replacement_part_for';
}

final class DTB_Logger {
	public static array $entries = [];
	public static function error( string $message, array $context = [] ): void {
		self::$entries[] = [ $message, $context ];
	}
}

final class FakeWpdb {
	public function prepare( string $query, string $key ): string {
		return $query . '|' . $key;
	}
	public function get_var( string $query ): string {
		return '1';
	}
}

class WC_Product {}

final class FakeProduct extends WC_Product {
	public function __construct( public int $id, public array $upsells, public array $cross_sells ) {}
	public function get_id(): int { return $this->id; }
	public function get_upsell_ids(): array { return $this->upsells; }
	public function get_cross_sell_ids(): array { return $this->cross_sells; }
	public function is_type( string $type ): bool { return false; }
	public function set_upsell_ids( array $ids ): void { $this->upsells = $ids; }
	public function set_cross_sell_ids( array $ids ): void { $this->cross_sells = $ids; }
	public function save(): int {
		if ( 'before' === $GLOBALS['save_mode'] ) {
			throw new RuntimeException( 'provider or database detail must not be returned to the operator' );
		}
		$GLOBALS['product_state'][ $this->id ] = [ $this->upsells, $this->cross_sells ];
		if ( 'after' === $GLOBALS['save_mode'] ) {
			throw new RuntimeException( 'write completed before the exception' );
		}
		return $this->id;
	}
}

function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function absint( $value ): int { return abs( (int) $value ); }
function get_current_blog_id(): int { return 1; }
function get_post_status( int $id ): string { return 'publish'; }
function wp_json_encode( $value ): string|false { return json_encode( $value ); }
function wc_get_product( int $id ): ?FakeProduct {
	if ( ! isset( $GLOBALS['product_state'][ $id ] ) ) {
		return null;
	}
	return new FakeProduct( $id, ...$GLOBALS['product_state'][ $id ] );
}
function wc_get_products( array $args ): array {
	$GLOBALS['product_batch_reads']++;
	$products = array_values( array_filter( array_map( 'wc_get_product', $args['include'] ?? [] ) ) );
	if ( ! empty( $GLOBALS['concurrent_change_on_target_read'] ) ) {
		$GLOBALS['concurrent_change_on_target_read'] = false;
		$GLOBALS['product_state'][1] = [ [ 2, 3 ], [] ];
	}
	return $products;
}

global $wpdb;
$wpdb = new FakeWpdb();
$GLOBALS['product_state'] = [ 1 => [ [ 2 ], [] ], 2 => [ [], [] ], 3 => [ [], [] ] ];
$GLOBALS['save_mode'] = 'after';
$GLOBALS['product_batch_reads'] = 0;
$GLOBALS['concurrent_change_on_target_read'] = false;
require_once __DIR__ . '/../../../drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Infrastructure/ProductRelationshipRepository.php';

$expected = hash( 'sha256', json_encode( [ [ 2 ], [] ] ) );
$saved = dtb_product_mapping_repo_save_relationships( 1, [ 3 ], [], $expected );
if ( is_wp_error( $saved ) || [ [ 3 ], [] ] !== $GLOBALS['product_state'][1] ) {
	fwrite( STDERR, "Expected post-write exception with verified read-back to report success.\n" );
	exit( 1 );
}
if ( 1 !== $GLOBALS['product_batch_reads'] ) {
	fwrite( STDERR, "Expected one bounded WooCommerce CRUD batch read for save-target validation.\n" );
	exit( 1 );
}

$GLOBALS['product_state'][1] = [ [ 2 ], [] ];
$GLOBALS['save_mode'] = 'before';
$expected = hash( 'sha256', json_encode( [ [ 2 ], [] ] ) );
$failed = dtb_product_mapping_repo_save_relationships( 1, [ 3 ], [], $expected );
if ( ! is_wp_error( $failed ) || 'relationship_write_failed' !== $failed->get_error_code() || [ [ 2 ], [] ] !== $GLOBALS['product_state'][1] ) {
	fwrite( STDERR, "Expected pre-write exception with unmatched read-back to return a bounded failure.\n" );
	exit( 1 );
}
if ( 2 !== $GLOBALS['product_batch_reads'] ) {
	fwrite( STDERR, "Expected one additional bounded CRUD batch read for the second save attempt.\n" );
	exit( 1 );
}

$GLOBALS['product_state'][1] = [ [ 2 ], [] ];
$GLOBALS['concurrent_change_on_target_read'] = true;
$expected = hash( 'sha256', json_encode( [ [ 2 ], [] ] ) );
$conflict = dtb_product_mapping_repo_save_relationships( 1, [ 3 ], [], $expected );
if ( ! is_wp_error( $conflict ) || 'relationship_conflict' !== $conflict->get_error_code() || [ [ 2, 3 ], [] ] !== $GLOBALS['product_state'][1] ) {
	fwrite( STDERR, "Expected a concurrent WooCommerce edit during target validation to be detected before the save overwrote it.\n" );
	exit( 1 );
}
if ( 3 !== $GLOBALS['product_batch_reads'] ) {
	fwrite( STDERR, "Expected one bounded target-validation read for the concurrent-write case.\n" );
	exit( 1 );
}
if ( ! DTB_Logger::$entries || isset( DTB_Logger::$entries[0][1]['message'] ) ) {
	fwrite( STDERR, "Expected redacted exception-class logging without the exception message.\n" );
	exit( 1 );
}

fwrite( STDOUT, "Product relationship save exception/read-back checks passed.\n" );
