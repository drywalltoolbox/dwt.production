<?php
/**
 * Canonical rules for keeping identity duplicates out of PDP specification rows.
 */
defined( 'ABSPATH' ) || exit;

final class DTB_CatalogSpecificationPolicy {
	private const EXCLUDED_LABELS = [
		'manufacturer sku',
		'official product name',
		'display category',
		'display category key',
	];

	/** Normalize markup/entities and spacing before comparing a spec label. */
	public static function normalize_label( $label ): string {
		if ( ! is_scalar( $label ) ) {
			return '';
		}
		$label = html_entity_decode( wp_strip_all_tags( (string) $label ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$label = strtolower( preg_replace( '/[^a-z0-9]+/i', ' ', $label ) );

		return trim( preg_replace( '/\s+/', ' ', $label ) );
	}

	/** Whether this field duplicates product identity already owned elsewhere. */
	public static function is_excluded_label( $label ): bool {
		return in_array( self::normalize_label( $label ), self::EXCLUDED_LABELS, true );
	}

	/** Filter a canonical list while retaining all other specification records. */
	public static function filter_rows( array $rows ): array {
		return array_values( array_filter( $rows, static function ( $spec ): bool {
			return ! is_array( $spec ) || ! isset( $spec['label'] ) || ! self::is_excluded_label( $spec['label'] );
		} ) );
	}
}
