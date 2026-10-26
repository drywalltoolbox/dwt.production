<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

final class WP_Term {
	public int $term_id;
	public int $parent;
	public int $count;
	public string $name;
	public string $slug;
	public string $description = '';

	public function __construct( int $term_id, int $parent, int $count, string $name, string $slug ) {
		$this->term_id = $term_id;
		$this->parent  = $parent;
		$this->count   = $count;
		$this->name    = $name;
		$this->slug    = $slug;
	}
}

function sanitize_key( string $value ): string {
	return trim( preg_replace( '/[^a-z0-9_]+/', '_', strtolower( $value ) ) ?? '', '_' );
}

function sanitize_title( string $value ): string {
	return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ) ?? '', '-' );
}

function sanitize_text_field( string $value ): string {
	return trim( strip_tags( $value ) );
}

function wp_specialchars_decode( string $value, int $flags ): string {
	return htmlspecialchars_decode( $value, $flags );
}

function wp_strip_all_tags( string $value ): string {
	return strip_tags( $value );
}

function absint( mixed $value ): int {
	return abs( (int) $value );
}

function get_term_meta( int $term_id, string $key, bool $single ): string {
	unset( $term_id, $key, $single );
	return '';
}

function wp_get_attachment_image_url( int $attachment_id, string $size ): false {
	unset( $attachment_id, $size );
	return false;
}

function esc_url_raw( string $url ): string {
	return $url;
}

function get_terms( array $args ): array {
	$registry_path = dirname( __DIR__, 3 ) . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Resources/catalog-taxonomy.json';
	$registry      = json_decode( (string) file_get_contents( $registry_path ), true, 512, JSON_THROW_ON_ERROR );
	$ids           = [];
	foreach ( $registry['taxa'] as $index => $taxon ) {
		$ids[ $taxon['key'] ] = $index + 1;
	}

	$allowed = array_flip( (array) ( $args['slug'] ?? [] ) );
	$terms   = [];
	foreach ( $registry['taxa'] as $taxon ) {
		if ( ! isset( $allowed[ $taxon['slug'] ] ) ) {
			continue;
		}
		$is_empty = 'automatic_continuous_flow_tools' === $taxon['key'] || ( null === $taxon['parent_key'] && 'stilts' !== $taxon['key'] );
		$terms[]  = new WP_Term(
			$ids[ $taxon['key'] ],
			null === $taxon['parent_key'] ? 0 : $ids[ $taxon['parent_key'] ],
			$is_empty ? 0 : 1,
			$taxon['label'],
			$taxon['slug']
		);
	}
	return $terms;
}

require_once dirname( __DIR__, 3 ) . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Services/CatalogNavigationService.php';

$groups = DTB_CatalogNavigationService::get_groups();
if ( '2.1' !== DTB_CatalogNavigationService::CONTRACT_VERSION ) {
	throw new RuntimeException( 'Unexpected navigation contract version.' );
}
if ( [ 'automatic-taping-tools', 'taping-finishing-tools', 'corner-bead-tools', 'hanging-tools', 'drywall-stilts', 'accessories', 'materials-consumables' ] !== array_column( $groups, 'slug' ) ) {
	throw new RuntimeException( 'Canonical root order was not preserved.' );
}

$children = $groups[0]['children'];
if ( 'automatic-tapers' !== $children[0]['slug'] || 'flat-boxes' !== $children[1]['slug'] ) {
	throw new RuntimeException( 'Canonical child sort order was not preserved.' );
}
if ( in_array( 'continuous-flow-tools', array_column( $children, 'slug' ), true ) ) {
	throw new RuntimeException( 'Empty non-publishable taxonomy leaf was exposed.' );
}
if ( ! in_array( 'powered-compound-applicators', array_column( $children, 'slug' ), true ) ) {
	throw new RuntimeException( 'Populated canonical taxonomy leaf was omitted.' );
}

$goosenecks = array_values( array_filter( $children, static fn ( array $child ): bool => 'goosenecks-box-fillers-adapters' === $child['slug'] ) );
if ( 1 !== count( $goosenecks ) || 'Goosenecks, Box Fillers & Adapters' !== $goosenecks[0]['label'] ) {
	throw new RuntimeException( 'Canonical Goosenecks term identity was not preserved.' );
}

echo "catalog navigation service contract passed\n";
