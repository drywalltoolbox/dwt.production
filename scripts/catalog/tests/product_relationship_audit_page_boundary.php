<?php
/** Regression check for a full final page in the bounded relationship audit. */

define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );

class DTB_ProductMeta {
	public const PRODUCT_KIND = '_dtb_product_kind';
	public const IS_PARTS = '_dtb_is_parts';
	public const COMPATIBLE_TOOL_SKUS = '_dtb_compatible_tool_skus';
	public const REPLACEMENT_PART_FOR = '_dtb_replacement_part_for';
}

class DTB_CatalogProductNormalizer {
	public static function decode_csv_or_array( $value ): array { return is_array( $value ) ? $value : ( '' === (string) $value ? [] : [ $value ] ); }
}

class WPDB_Audit_Page_Fixture {
	public string $posts = 'wp_posts';
	public string $last_query = '';
	public array $last_args = [];
	public array $ids = [];

	public function prepare( string $query, ...$args ): string {
		$this->last_query = $query;
		$this->last_args = count( $args ) === 1 && is_array( $args[0] ) ? $args[0] : $args;
		return $query;
	}

	public function get_var( string $query ): int {
		if ( false !== strpos( $query, 'COUNT(ID)' ) ) {
			$max_id = (int) $this->last_args[ count( $this->last_args ) - 1 ];
			if ( false !== strpos( $query, 'ID > %d' ) ) {
				$cursor = (int) $this->last_args[ count( $this->last_args ) - 2 ];
				return count( array_filter( $this->ids, static fn( $id ) => $id > $cursor && $id <= $max_id ) );
			}
			return count( array_filter( $this->ids, static fn( $id ) => $id <= $max_id ) );
		}
		return 0;
	}

	public function get_col( string $query ): array {
		$args = $this->last_args;
		$cursor = (int) $args[ count( $args ) - 3 ];
		$max_id = (int) $args[ count( $args ) - 2 ];
		$limit = (int) $args[ count( $args ) - 1 ];
		return array_slice( array_values( array_filter( $this->ids, static fn( $id ) => $id > $cursor && $id <= $max_id ) ), 0, $limit );
	}
}

$GLOBALS['wpdb'] = new WPDB_Audit_Page_Fixture();
$GLOBALS['wpdb']->ids = range( 1001, 1050 );

function get_post_stati( array $args = [], string $output = 'names' ): array { return [ 'publish' ]; }
function get_posts( array $args ): array {
	return array_map( static fn( $id ) => (object) [ 'ID' => (int) $id, 'post_type' => 'product', 'post_status' => 'publish', 'post_parent' => 0 ], $args['post__in'] ?? [] );
}
function get_post_meta( int $id, string $key, bool $single = false ) {
	if ( isset( $GLOBALS['audit_meta_fixture'][ $id ][ $key ] ) ) {
		$value = $GLOBALS['audit_meta_fixture'][ $id ][ $key ];
		return $single ? reset( $value ) : $value;
	}
	return $single ? '' : [];
}
function get_post_type( int $id ): string { return 'product'; }
function wp_get_post_parent_id( int $id ): int { return 0; }
function get_the_title( int $id ): string { return 'Fixture product ' . $id; }
function get_post_status( int $id ): string { return 'publish'; }
function absint( $value ): int { return abs( (int) $value ); }
function taxonomy_exists( string $taxonomy ): bool { return 'product_visibility' === $taxonomy; }
function wp_get_object_terms( array $object_ids, string $taxonomy, array $args = [] ): array {
	$terms = [];
	foreach ( $object_ids as $object_id ) {
		foreach ( $GLOBALS['audit_visibility_fixture'][ $object_id ] ?? [] as $slug ) {
			$terms[] = (object) [ 'object_id' => (int) $object_id, 'slug' => $slug ];
		}
	}
	return $terms;
}
function is_wp_error( $value ): bool { return false; }

require dirname( __DIR__, 3 ) . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Infrastructure/ProductRelationshipRepository.php';

$page = dtb_product_mapping_repo_audit_page( 0, 1050 );
if ( 50 !== count( $page['items'] ) || 50 !== $page['total'] || false !== $page['has_more'] ) {
	fwrite( STDERR, "Full final page was not returned with the expected bounds.\n" );
	exit( 1 );
}
$GLOBALS['audit_visibility_fixture'][1001] = [ 'exclude-from-catalog', 'exclude-from-search' ];
$GLOBALS['audit_visibility_fixture'][1002] = [ 'exclude-from-catalog' ];
$page = dtb_product_mapping_repo_audit_page( 0, 1050 );
if ( 'hidden' !== $page['items'][0]['catalog_visibility'] || 'search' !== $page['items'][1]['catalog_visibility'] || 'visible' !== $page['items'][2]['catalog_visibility'] ) {
	fwrite( STDERR, "Relationship audit failed to classify hidden, search-only, or visible catalog products.\n" );
	exit( 1 );
}
$GLOBALS['wpdb']->ids = range( 1001, 1100 );
$page = dtb_product_mapping_repo_audit_page( 0, 1100 );
if ( true !== $page['has_more'] ) {
	fwrite( STDERR, "Full non-final page failed to report remaining records.\n" );
	exit( 1 );
}

$GLOBALS['audit_meta_fixture'][7]['_dtb_compatible_tool_skus'] = [ '[" TOOL-1 ","","TOOL-2"]', 'TOOL-3, TOOL-4,', '["broken"', '{"not":"a list"}' ];
$audit_skus = dtb_product_mapping_audit_skus( 7, '_dtb_compatible_tool_skus' );
if ( [ ' TOOL-1 ', '', 'TOOL-2', 'TOOL-3', ' TOOL-4', '', '[invalid:json]', '[invalid:json_object]' ] !== $audit_skus ) {
	fwrite( STDERR, "Audit decoder normalized raw target SKU spelling.\n" );
	exit( 1 );
}

echo "Product relationship audit page boundary checks passed.\n";
