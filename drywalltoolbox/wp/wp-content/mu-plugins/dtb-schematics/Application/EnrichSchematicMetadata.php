<?php
/**
 * Canonical customer-facing metadata enrichment for schematic records.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/** Return generated canonical identity/display metadata for one schematic. */
function dtb_schematic_canonical_metadata( string $canonical_id ): array {
	$canonical_id = sanitize_key( $canonical_id );
	$identity     = DTB_SCHEMATIC_BRAND_CATEGORY_MAP[ $canonical_id ] ?? [];
	$display      = DTB_SCHEMATIC_DISPLAY_MAP[ $canonical_id ] ?? [];
	$brand_map    = DTB_SCHEMATIC_BRAND_DISPLAY_MAP[ $canonical_id ] ?? DTB_LEVEL5_SCHEMATIC_DISPLAY_MAP[ $canonical_id ] ?? [];
	$identity     = array_merge( $identity, array_intersect_key( $brand_map, array_flip( [ 'brand_id', 'category_id' ] ) ) );
	$display      = array_merge( $display, array_intersect_key( $brand_map, array_flip( [ 'brand_name', 'category_name', 'title' ] ) ) );
	if ( function_exists( 'dtb_asgard_schematic_metadata' ) ) {
		$asgard = dtb_asgard_schematic_metadata( $canonical_id );
		if ( ! empty( $asgard ) ) {
			$identity = [ 'brand_id' => $asgard['brand_id'] ?? '', 'category_id' => $asgard['category_id'] ?? '' ];
			$display = [ 'brand_name' => $asgard['brand_name'] ?? '', 'category_name' => $asgard['category_name'] ?? '', 'title' => $asgard['title'] ?? '' ];
		}
	}
	if ( 'tape-tech' === ( $identity['brand_id'] ?? '' ) && function_exists( 'dtb_tapetech_schematic_category_name' ) ) {
		$category_name = dtb_tapetech_schematic_category_name( (string) ( $identity['category_id'] ?? '' ) );
		if ( '' !== $category_name ) {
			$display['category_name'] = $category_name;
		}
	}

	return [
		'brand_id'     => sanitize_key( (string) ( $identity['brand_id'] ?? '' ) ),
		'brand_name'   => sanitize_text_field( (string) ( $display['brand_name'] ?? '' ) ),
		'category_id'  => sanitize_key( (string) ( $identity['category_id'] ?? '' ) ),
		'category_name'=> sanitize_text_field( (string) ( $display['category_name'] ?? '' ) ),
		'title'         => sanitize_text_field( (string) ( $display['title'] ?? '' ) ),
	];
}

/** Machine-derived titles may be replaced by the generated display title. */
function dtb_schematic_title_is_machine_derived( string $title, string $canonical_id ): bool {
	$normalize = static fn( string $value ): string => strtolower( preg_replace( '/[^a-z0-9]+/', '', $value ) ?: '' );
	$canonical_id = sanitize_key( $canonical_id );
	$brand_map = DTB_SCHEMATIC_BRAND_DISPLAY_MAP[ $canonical_id ] ?? DTB_LEVEL5_SCHEMATIC_DISPLAY_MAP[ $canonical_id ] ?? [];
	$legacy_title = (string) ( $brand_map['legacy_title'] ?? DTB_SCHEMATIC_DISPLAY_MAP[ $canonical_id ]['legacy_title'] ?? '' );
	$generated_title = (string) ( DTB_SCHEMATIC_DISPLAY_MAP[ $canonical_id ]['title'] ?? '' );
	return '' === trim( $title ) || $normalize( $title ) === $normalize( $canonical_id )
		|| ( '' !== $legacy_title && $normalize( $title ) === $normalize( $legacy_title ) )
		|| ( '' !== $generated_title && $normalize( $title ) === $normalize( $generated_title ) );
}

/** Resolve public display metadata while older records await reconciliation. */
function dtb_schematic_resolve_display_metadata( DTB_Schematic_Record_Entity $record ): array {
	$canonical = dtb_schematic_canonical_metadata( $record->canonical_id );
	$title     = dtb_schematic_title_is_machine_derived( $record->title, $record->canonical_id )
		? ( $canonical['title'] ?: $record->title )
		: $record->title;

	return [
		'brand_id'      => $canonical['brand_id'] ?: $record->brand_id,
		'brand_name'    => $record->brand_name ?: $canonical['brand_name'],
		'category_id'   => $canonical['category_id'] ?: $record->category_id,
		'category_name' => $record->category_name ?: $canonical['category_name'],
		'title'         => $title,
	];
}

/**
 * Persist canonical IDs and customer-facing labels without overwriting an
 * operator-authored title that is more specific than the machine ID.
 *
 * @return array{record:DTB_Schematic_Record_Entity,changed:bool}|WP_Error
 */
function dtb_schematic_enrich_metadata( DTB_Schematic_Record_Entity $record ) {
	$canonical = dtb_schematic_canonical_metadata( $record->canonical_id );
	if ( '' === $canonical['brand_id'] || '' === $canonical['category_id'] ) {
		return new WP_Error(
			'dtb_schematic_canonical_metadata_missing',
			__( 'Canonical brand/category metadata is unavailable for this schematic.', 'drywall-toolbox' )
		);
	}

	$updates = [];
	foreach ( [ 'brand_id', 'brand_name', 'category_id', 'category_name' ] as $field ) {
		if ( '' !== $canonical[ $field ] && $record->{$field} !== $canonical[ $field ] ) {
			$updates[ $field ] = $canonical[ $field ];
		}
	}
	if ( '' !== $canonical['title'] && dtb_schematic_title_is_machine_derived( $record->title, $record->canonical_id ) && $record->title !== $canonical['title'] ) {
		$updates['title'] = $canonical['title'];
	}

	if ( empty( $updates ) ) {
		return [ 'record' => $record, 'changed' => false ];
	}

	$updated = dtb_schematic_update( $record->id, $updates );
	if ( is_wp_error( $updated ) ) {
		return $updated;
	}

	return [ 'record' => $updated, 'changed' => true ];
}

