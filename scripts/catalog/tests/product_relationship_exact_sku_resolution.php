<?php
/** Product relationship inspector must not treat a case-folded SKU lookup as exact identity. */

define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );

class DTB_ProductMeta {
	public const PRODUCT_KIND = '_dtb_product_kind';
	public const IS_PARTS = '_dtb_is_parts';
	public const COMPATIBLE_TOOL_SKUS = '_dtb_compatible_tool_skus';
	public const REPLACEMENT_PART_FOR = '_dtb_replacement_part_for';
}

class DTB_CatalogProductNormalizer {
	public static function decode_csv_or_array( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return [];
		}
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : explode( ',', $value );
	}
}

class Relationship_Product_Fixture {
	public function __construct( public int $id, public string $sku, public string $name, public string $type = 'simple' ) {}
	public function get_id(): int { return $this->id; }
	public function get_sku(): string { return $this->sku; }
	public function get_name(): string { return $this->name; }
	public function get_type(): string { return $this->type; }
}

$GLOBALS['relationship_meta_fixture'] = [
	1 => [
		'_dtb_product_kind' => [ 'part' ],
		'_dtb_compatible_tool_skus' => [ '["TOOL-EXACT","tool-case"]' ],
		'_dtb_replacement_part_for' => [ '' ],
		'_dtb_compatible_tools' => [ [] ],
		'_dtb_compatible_parts' => [ [] ],
	],
];
$GLOBALS['relationship_product_fixture'] = [
	1 => new Relationship_Product_Fixture( 1, 'PART-1', 'Fixture part' ),
	2 => new Relationship_Product_Fixture( 2, 'TOOL-EXACT', 'Exact tool' ),
	3 => new Relationship_Product_Fixture( 3, 'TOOL-CASE', 'Case-conflict tool' ),
];

function get_post_meta( int $id, string $key, bool $single = false ) {
	$values = $GLOBALS['relationship_meta_fixture'][ $id ][ $key ] ?? [];
	return $single ? ( $values[0] ?? '' ) : $values;
}
function wc_get_product( int $id ) { return $GLOBALS['relationship_product_fixture'][ $id ] ?? false; }
function wc_get_product_id_by_sku( string $sku ): int {
	foreach ( $GLOBALS['relationship_product_fixture'] as $product ) {
		if ( 0 === strcasecmp( $product->get_sku(), $sku ) ) {
			return $product->get_id();
		}
	}
	return 0;
}
function get_posts( array $args ): array { return []; }
function absint( $value ): int { return abs( (int) $value ); }
function wp_json_encode( $value ): string|false { return json_encode( $value ); }

require dirname( __DIR__, 3 ) . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Infrastructure/ProductRelationshipRepository.php';

$result = dtb_product_mapping_repo_get_compatibility( 1, 'part' );
$related_skus = array_column( $result['related'], 'sku' );
$mismatches = array_values( array_filter( $result['unresolved'], static fn( $item ) => 'sku_mismatch' === $item['status'] ) );
if ( [ 'TOOL-EXACT' ] !== $related_skus ) {
	fwrite( STDERR, "Only byte-exact stored SKU matches may appear as resolved compatibility.\n" );
	exit( 1 );
}
if ( 1 !== count( $mismatches ) || 'tool-case' !== $mismatches[0]['sku'] || 'TOOL-CASE' !== $mismatches[0]['matched_sku'] ) {
	fwrite( STDERR, "Case-only stored SKU drift must be returned as a reviewable identity conflict.\n" );
	exit( 1 );
}

echo "Product relationship exact SKU resolution checks passed.\n";
