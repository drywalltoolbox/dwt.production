<?php
/**
 * DTB Schematics — SchematicPageDefinition value object.
 *
 * A single ordered page belonging to an authoritative schematic record.
 * Pages are stored as normalized arrays inside the schematic record's
 * `_dtb_schematic_pages` postmeta (see Infrastructure/SchematicRecordRepository.php).
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Page lifecycle states. Kept intentionally smaller than the schematic-level
 * lifecycle — a page is either not yet backed by a valid attachment, or it is.
 */
const DTB_SCHEMATIC_PAGE_STATE_MISSING_ASSET = 'missing_asset';
const DTB_SCHEMATIC_PAGE_STATE_ATTACHED      = 'attached';
const DTB_SCHEMATIC_PAGE_STATE_PUBLISHED     = 'published';
const DTB_SCHEMATIC_PAGE_STATE_RETIRED       = 'retired';

/**
 * Build a normalized page definition.
 *
 * @param array $data {
 *     @type string $page_id            Stable page ID (immutable once created).
 *     @type int    $page_number        1-based ordering position.
 *     @type string $label              Customer/operator-facing page label.
 *     @type int    $attachment_id      WordPress attachment ID, 0 if unattached.
 *     @type string $source_filename    Canonical source filename.
 *     @type string $source_checksum    sha256 checksum or immutable asset version.
 *     @type string $media_type         MIME type, e.g. image/webp.
 *     @type int    $width              Pixel width, 0 if unknown.
 *     @type int    $height             Pixel height, 0 if unknown.
 *     @type array  $sources            Responsive media sources, e.g. [ [ 'url' => ..., 'width' => ... ], ... ].
 *     @type string[] $part_refs         Explicit page BOM membership, including parts without hotspots.
 *     @type array  $hotspot_dataset    Hotspot dataset relationship for this page (see dtb_schematic_hotspot_dataset_ref_make()).
 *     @type string $lifecycle_state    One of the DTB_SCHEMATIC_PAGE_STATE_* constants.
 * }
 */
function dtb_schematic_page_make( array $data ): array {
	return [
		'page_id'         => sanitize_key( (string) ( $data['page_id'] ?? '' ) ),
		'page_number'     => max( 0, (int) ( $data['page_number'] ?? 0 ) ),
		'label'           => sanitize_text_field( (string) ( $data['label'] ?? '' ) ),
		'attachment_id'   => max( 0, (int) ( $data['attachment_id'] ?? 0 ) ),
		'source_filename' => sanitize_file_name( (string) ( $data['source_filename'] ?? '' ) ),
		'source_checksum' => preg_replace( '/[^a-f0-9]/i', '', (string) ( $data['source_checksum'] ?? '' ) ),
		'media_type'      => sanitize_text_field( (string) ( $data['media_type'] ?? '' ) ),
		'width'           => max( 0, (int) ( $data['width'] ?? 0 ) ),
		'height'          => max( 0, (int) ( $data['height'] ?? 0 ) ),
		'sources'         => dtb_schematic_page_normalize_sources( $data['sources'] ?? [] ),
		'part_refs'       => array_values( array_unique( array_filter( array_map( 'sanitize_key', is_array( $data['part_refs'] ?? null ) ? $data['part_refs'] : [] ) ) ) ),
		'hotspot_dataset' => dtb_schematic_hotspot_dataset_ref_make( is_array( $data['hotspot_dataset'] ?? null ) ? $data['hotspot_dataset'] : [] ),
		'lifecycle_state' => dtb_schematic_page_normalize_state( (string) ( $data['lifecycle_state'] ?? '' ) ),
	];
}

function dtb_schematic_page_normalize_sources( $sources ): array {
	if ( ! is_array( $sources ) ) {
		return [];
	}

	$normalized = [];
	foreach ( $sources as $source ) {
		if ( ! is_array( $source ) || empty( $source['url'] ) ) {
			continue;
		}
		$normalized[] = [
			'url'   => esc_url_raw( (string) $source['url'] ),
			'width' => max( 0, (int) ( $source['width'] ?? 0 ) ),
		];
	}

	return $normalized;
}

function dtb_schematic_page_normalize_state( string $state ): string {
	$valid = [
		DTB_SCHEMATIC_PAGE_STATE_MISSING_ASSET,
		DTB_SCHEMATIC_PAGE_STATE_ATTACHED,
		DTB_SCHEMATIC_PAGE_STATE_PUBLISHED,
		DTB_SCHEMATIC_PAGE_STATE_RETIRED,
	];
	return in_array( $state, $valid, true ) ? $state : DTB_SCHEMATIC_PAGE_STATE_MISSING_ASSET;
}

/**
 * A page is considered structurally valid for publication when it has a
 * stable page ID and a real attachment behind it.
 */
function dtb_schematic_page_is_attached( array $page ): bool {
	return '' !== ( $page['page_id'] ?? '' ) && (int) ( $page['attachment_id'] ?? 0 ) > 0;
}

/**
 * Build a normalized hotspot-dataset relationship reference.
 *
 * This does not read or store hotspot coordinate data (that remains in the
 * frontend JSON datasets through Phase 5) — it only records where the
 * authoritative page/schematic points for its hotspot dataset so Phase 5
 * has a real relationship to wire into instead of another parallel authority.
 *
 * @param array $data {
 *     @type string $type            e.g. 'frontend_json', 'none'.
 *     @type string $reference       Dataset path/identifier.
 *     @type string $schema_version  e.g. 'v2'.
 *     @type string $checksum        Dataset checksum/version, if known.
 * }
 */
function dtb_schematic_hotspot_dataset_ref_make( array $data ): array {
	return [
		'type'           => sanitize_key( (string) ( $data['type'] ?? 'none' ) ),
		'reference'      => sanitize_text_field( (string) ( $data['reference'] ?? '' ) ),
		'schema_version' => sanitize_text_field( (string) ( $data['schema_version'] ?? '' ) ),
		'checksum'       => preg_replace( '/[^a-f0-9]/i', '', (string) ( $data['checksum'] ?? '' ) ),
	];
}
