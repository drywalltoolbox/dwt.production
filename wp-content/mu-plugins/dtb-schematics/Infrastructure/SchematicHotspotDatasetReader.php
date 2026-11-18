<?php
/**
 * DTB Schematics — SchematicHotspotDatasetReader (Phase 5).
 *
 * Reads and normalizes the source hotspot JSON files under
 * frontend/public/brands/**\/schematic_data.json into the canonical v2
 * dataset shape (Domain/SchematicHotspotDataset.php). Read-only against
 * these files — they are migration INPUT only, never written to or deleted
 * here (Phase 9 owns eventual legacy-file removal).
 *
 * Two source schemas coexist:
 *   - legacy: { id, title, diagramPages, legendPages, parts: [...] } — a
 *     flat parts list with no hotspot coordinates at all. Normalized up to
 *     parts_catalog only; hotspots stays empty (there is nothing to migrate
 *     spatially — fabricating coordinates is explicitly disallowed).
 *   - v2 native: { schema_version: "2.0", diagram, parts_catalog, hotspots }
 *     with real per-occurrence normalized coordinates.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_SCHEMATIC_HOTSPOT_READ_OK               = 'ok';
const DTB_SCHEMATIC_HOTSPOT_READ_FILE_NOT_FOUND    = 'file_not_found';
const DTB_SCHEMATIC_HOTSPOT_READ_UNREADABLE        = 'file_unreadable';
const DTB_SCHEMATIC_HOTSPOT_READ_MALFORMED_JSON    = 'malformed_json';
const DTB_SCHEMATIC_HOTSPOT_READ_EMPTY             = 'empty_document';
const DTB_SCHEMATIC_HOTSPOT_READ_UNRECOGNIZED_SHAPE = 'unrecognized_schema_shape';
const DTB_SCHEMATIC_HOTSPOT_READ_NO_PARTS           = 'no_parts_found';
const DTB_SCHEMATIC_HOTSPOT_READ_RESOURCE_LIMIT     = 'resource_limit_exceeded';
const DTB_SCHEMATIC_HOTSPOT_MAX_FILE_BYTES          = 5 * 1024 * 1024;
const DTB_SCHEMATIC_HOTSPOT_MAX_PARTS               = 5000;
const DTB_SCHEMATIC_HOTSPOT_MAX_OCCURRENCES         = 10000;
const DTB_SCHEMATIC_HOTSPOT_NORMALIZATION_VERSION   = '2026-08-center-geometry-v1';

/**
 * Resolve the repository root the same way
 * Infrastructure/SchematicSourceManifestReader.php does, so this reader
 * works both under a live WordPress runtime and the static verification
 * harness pattern already established for Phase 3.
 */
function dtb_schematics_hotspot_source_roots(): array {
	if ( ! defined( 'ABSPATH' ) || ! function_exists( 'untrailingslashit' ) ) {
		return [];
	}

	$wp_root = untrailingslashit( ABSPATH );
	$public_root = dirname( $wp_root );
	$runtime_root = $public_root . '/brands';

	$roots = [];
	// An absolute server-only override supports nonstandard layouts without
	// deriving filesystem authority from request URLs or caller input.
	if ( defined( 'DTB_SCHEMATIC_HOTSPOT_SOURCE_ROOT' ) && is_string( DTB_SCHEMATIC_HOTSPOT_SOURCE_ROOT ) ) {
		$roots[] = DTB_SCHEMATIC_HOTSPOT_SOURCE_ROOT;
	}
	$roots[] = $runtime_root;
	$roots[] = dirname( dirname( $wp_root ) ) . '/frontend/public/brands'; // Repository checkout.
	$roots = function_exists( 'apply_filters' ) ? apply_filters( 'dtb_schematics_hotspot_source_roots', $roots ) : $roots;

	$resolved = [];
	foreach ( (array) $roots as $root ) {
		$real = realpath( (string) $root );
		if ( false !== $real && is_dir( $real ) ) {
			$resolved[] = str_replace( '\\', '/', $real );
		}
	}
	return array_values( array_unique( $resolved ) );
}

function dtb_schematics_hotspot_source_root(): string {
	$roots = dtb_schematics_hotspot_source_roots();
	return $roots[0] ?? '';
}

/** Resolve a validated logical dataset reference inside an approved source root. */
function dtb_schematics_hotspot_resolve_reference( string $reference ) {
	$reference = str_replace( '\\', '/', trim( $reference ) );
	$relative  = preg_replace( '#^(?:frontend/public/)?brands/#', '', $reference );
	if ( ! is_string( $relative ) || ! preg_match( '#^(?:[A-Za-z0-9._-]+/)+schematic_data[^/]*\.json$#', $relative ) ) {
		return false;
	}

	foreach ( dtb_schematics_hotspot_source_roots() as $root ) {
		$absolute = realpath( trailingslashit( $root ) . $relative );
		if ( false === $absolute ) {
			continue;
		}
		$normalized_root = trailingslashit( str_replace( '\\', '/', $root ) );
		$normalized_path = str_replace( '\\', '/', $absolute );
		if ( 0 === strpos( $normalized_path, $normalized_root ) ) {
			return $absolute;
		}
	}
	return false;
}

/**
 * Read + normalize one hotspot dataset JSON file from an absolute path.
 * Pure/testable: does not touch WordPress state.
 *
 * @return array{
 *   status:string,
 *   dataset:?array,
 *   error:?string,
 *   bom_stripped:bool,
 *   source_schema:?string
 * }
 */
function dtb_schematic_hotspot_read_file( string $absolute_path ): array {
	if ( ! is_file( $absolute_path ) ) {
		return dtb_schematic_hotspot_read_result( DTB_SCHEMATIC_HOTSPOT_READ_FILE_NOT_FOUND, null, 'File does not exist.' );
	}
	$file_size = filesize( $absolute_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_filesize
	if ( false === $file_size || $file_size > DTB_SCHEMATIC_HOTSPOT_MAX_FILE_BYTES ) {
		return dtb_schematic_hotspot_read_result( DTB_SCHEMATIC_HOTSPOT_READ_RESOURCE_LIMIT, null, 'Dataset exceeds the supported file-size limit.' );
	}

	$raw = file_get_contents( $absolute_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents
	if ( false === $raw ) {
		return dtb_schematic_hotspot_read_result( DTB_SCHEMATIC_HOTSPOT_READ_UNREADABLE, null, 'File could not be read.' );
	}

	$bom_stripped = false;
	if ( 0 === strpos( $raw, "\xEF\xBB\xBF" ) ) {
		$raw          = substr( $raw, 3 );
		$bom_stripped = true;
	}

	$trimmed = trim( $raw );
	if ( '' === $trimmed ) {
		return dtb_schematic_hotspot_read_result( DTB_SCHEMATIC_HOTSPOT_READ_EMPTY, null, 'File is empty.', $bom_stripped );
	}

	$decoded = json_decode( $trimmed, true );
	if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
		return dtb_schematic_hotspot_read_result( DTB_SCHEMATIC_HOTSPOT_READ_MALFORMED_JSON, null, 'json_decode error: ' . json_last_error_msg(), $bom_stripped );
	}
	if ( ! is_array( $decoded ) ) {
		return dtb_schematic_hotspot_read_result( DTB_SCHEMATIC_HOTSPOT_READ_MALFORMED_JSON, null, 'Decoded document is not a JSON object/array.', $bom_stripped );
	}

	// Include the normalizer contract in the version so a deployment that
	// changes geometry semantics is not incorrectly treated as unchanged.
	$checksum = hash( 'sha256', DTB_SCHEMATIC_HOTSPOT_NORMALIZATION_VERSION . "\n" . $trimmed );

	if ( isset( $decoded['parts_catalog'] ) || isset( $decoded['hotspots'] ) ) {
		return dtb_schematic_hotspot_normalize_v2( $decoded, $checksum, $bom_stripped );
	}

	if ( isset( $decoded['parts'] ) && is_array( $decoded['parts'] ) ) {
		return dtb_schematic_hotspot_normalize_legacy( $decoded, $checksum, $bom_stripped );
	}

	return dtb_schematic_hotspot_read_result( DTB_SCHEMATIC_HOTSPOT_READ_UNRECOGNIZED_SHAPE, null, 'Document has neither a "parts" (legacy) nor "parts_catalog"/"hotspots" (v2) top-level key.', $bom_stripped );
}

function dtb_schematic_hotspot_read_result( string $status, ?array $dataset, ?string $error, bool $bom_stripped = false, ?string $source_schema = null ): array {
	return [
		'status'        => $status,
		'dataset'       => $dataset,
		'error'         => $error,
		'bom_stripped'  => $bom_stripped,
		'source_schema' => $source_schema,
	];
}

/**
 * Normalize a native v2 document ({ parts_catalog, hotspots, diagram }).
 */
function dtb_schematic_hotspot_normalize_v2( array $decoded, string $checksum, bool $bom_stripped ): array {
	$parts_catalog = $decoded['parts_catalog'] ?? [];
	if ( ! is_array( $parts_catalog ) || empty( $parts_catalog ) ) {
		return dtb_schematic_hotspot_read_result( DTB_SCHEMATIC_HOTSPOT_READ_NO_PARTS, null, 'v2 document has an empty or missing "parts_catalog".', $bom_stripped, 'v2' );
	}
	if ( count( $parts_catalog ) > DTB_SCHEMATIC_HOTSPOT_MAX_PARTS || count( (array) ( $decoded['hotspots'] ?? [] ) ) > DTB_SCHEMATIC_HOTSPOT_MAX_OCCURRENCES ) {
		return dtb_schematic_hotspot_read_result( DTB_SCHEMATIC_HOTSPOT_READ_RESOURCE_LIMIT, null, 'Dataset exceeds the supported part or hotspot count.', $bom_stripped, 'v2' );
	}

	$normalized_parts = [];
	foreach ( $parts_catalog as $part ) {
		if ( ! is_array( $part ) ) {
			continue;
		}
		$normalized_parts[] = dtb_schematic_hotspot_part_make(
			[
				'part_ref'   => (string) ( $part['part_id'] ?? '' ),
				'display_id' => (string) ( $part['display_id'] ?? ( $part['part_id'] ?? '' ) ),
				'name'       => (string) ( $part['name'] ?? '' ),
				'sku'        => (string) ( $part['sku'] ?? '' ),
				'source_sku' => (string) ( $part['source_sku'] ?? '' ),
				'quantity'   => (float) ( $part['quantity'] ?? 0 ),
				'notes'      => (string) ( $part['notes'] ?? '' ),
			]
		);
	}

	$normalized_hotspots = [];
	foreach ( (array) ( $decoded['hotspots'] ?? [] ) as $hotspot ) {
		if ( ! is_array( $hotspot ) ) {
			continue;
		}
		$shape       = is_array( $hotspot['shape'] ?? null ) ? $hotspot['shape'] : [];
		$shape_type  = (string) ( $shape['type'] ?? 'point' );
		$normalized  = is_array( $hotspot['normalized'] ?? null ) ? $hotspot['normalized'] : [];
		$coordinates = dtb_schematic_hotspot_normalize_v2_coordinates( $shape_type, $normalized );

		$normalized_hotspots[] = dtb_schematic_hotspot_occurrence_make(
			[
				'hotspot_id'  => (string) ( $hotspot['hotspot_id'] ?? '' ),
				'part_ref'    => (string) ( $hotspot['part_ref'] ?? '' ),
				'page'        => (int) ( $hotspot['page'] ?? 1 ),
				'shape_type'  => $shape_type,
				'coordinates' => $coordinates,
				'label'       => (string) ( $hotspot['label'] ?? '' ),
			]
		);
	}

	$dataset = dtb_schematic_hotspot_dataset_make(
		[
			'source_schema' => 'v2',
			'checksum'      => $checksum,
			'parts_catalog' => $normalized_parts,
			'hotspots'      => $normalized_hotspots,
		]
	);

	return dtb_schematic_hotspot_read_result( DTB_SCHEMATIC_HOTSPOT_READ_OK, $dataset, null, $bom_stripped, 'v2' );
}

/**
 * Convert v2 shape-specific geometry into the canonical center-anchored
 * percentage contract used by the public viewer. Rectangles arrive as a
 * top-left plus size, circles as a center plus radii, and polygons as points.
 * The persisted domain always exposes x_pct/y_pct as the visual center and
 * width_pct/height_pct as the complete hit-region size.
 */
function dtb_schematic_hotspot_normalize_v2_coordinates( string $shape_type, array $normalized ): array {
	$shape_type = dtb_schematic_hotspot_normalize_shape_type( $shape_type );

	if ( 'circle' === $shape_type ) {
		$cx = $normalized['cx_pct'] ?? $normalized['x_pct'] ?? null;
		$cy = $normalized['cy_pct'] ?? $normalized['y_pct'] ?? null;
		$rx = $normalized['r_pct_w'] ?? null;
		$ry = $normalized['r_pct_h'] ?? $rx;
		if ( is_numeric( $cx ) && is_numeric( $cy ) ) {
			$out = [ 'x_pct' => (float) $cx, 'y_pct' => (float) $cy ];
			if ( is_numeric( $rx ) && is_numeric( $ry ) ) {
				$out['width_pct']  = max( 0, (float) $rx * 2 );
				$out['height_pct'] = max( 0, (float) $ry * 2 );
			}
			return $out;
		}
	}

	if ( 'polygon' === $shape_type && is_array( $normalized['points_pct'] ?? null ) ) {
		$xs = [];
		$ys = [];
		foreach ( $normalized['points_pct'] as $point ) {
			if ( is_array( $point ) && isset( $point[0], $point[1] ) && is_numeric( $point[0] ) && is_numeric( $point[1] ) ) {
				$xs[] = (float) $point[0];
				$ys[] = (float) $point[1];
			}
		}
		if ( $xs && $ys ) {
			return [
				'x_pct'     => ( min( $xs ) + max( $xs ) ) / 2,
				'y_pct'     => ( min( $ys ) + max( $ys ) ) / 2,
				'width_pct' => max( $xs ) - min( $xs ),
				'height_pct'=> max( $ys ) - min( $ys ),
				'points_pct'=> $normalized['points_pct'],
			];
		}
	}

	$x      = $normalized['x_pct'] ?? null;
	$y      = $normalized['y_pct'] ?? null;
	$width  = $normalized['width_pct'] ?? null;
	$height = $normalized['height_pct'] ?? null;
	if ( is_numeric( $x ) && is_numeric( $y ) ) {
		$out = [ 'x_pct' => (float) $x, 'y_pct' => (float) $y ];
		if ( is_numeric( $width ) && is_numeric( $height ) ) {
			$out['width_pct']  = max( 0, (float) $width );
			$out['height_pct'] = max( 0, (float) $height );
			if ( 'rect' === $shape_type ) {
				$out['x_pct'] += $out['width_pct'] / 2;
				$out['y_pct'] += $out['height_pct'] / 2;
			}
		}
		return $out;
	}

	return [];
}

/**
 * Normalize a legacy document ({ id, title, diagramPages, legendPages, parts }).
 *
 * Most legacy files carry no coordinate data, so `hotspots` stays empty for
 * those — fabricating positions is not permitted. However, a majority of the
 * legacy-shaped files (55 of 88, as of this writing) also carry a sibling
 * top-level `coordinates` object keyed by the same `id` used in each
 * `parts[]` entry — e.g. `coordinates.<id> = { x_pct, y_pct, shape,
 * pageNumber, rotation, widthPx, heightPx, ... }`. When that map is present,
 * real hotspot occurrences are built from it (see
 * dtb_schematic_hotspot_normalize_legacy_coordinates()); when it is absent,
 * behavior is unchanged from before — `hotspots` stays empty.
 *
 * `parts_catalog` is built by deduplicating the flat `parts` list on the
 * part `id` field, which is exactly the "collapses repeated physical
 * occurrences" behavior the spec describes for this schema.
 */
function dtb_schematic_hotspot_normalize_legacy( array $decoded, string $checksum, bool $bom_stripped ): array {
	$parts = $decoded['parts'];
	if ( empty( $parts ) ) {
		return dtb_schematic_hotspot_read_result( DTB_SCHEMATIC_HOTSPOT_READ_NO_PARTS, null, 'Legacy document has an empty or missing "parts" list.', $bom_stripped, 'legacy' );
	}
	if ( count( $parts ) > DTB_SCHEMATIC_HOTSPOT_MAX_PARTS ) {
		return dtb_schematic_hotspot_read_result( DTB_SCHEMATIC_HOTSPOT_READ_RESOURCE_LIMIT, null, 'Dataset exceeds the supported part count.', $bom_stripped, 'legacy' );
	}

	$normalized_parts = [];
	$seen             = [];
	foreach ( $parts as $part ) {
		if ( ! is_array( $part ) ) {
			continue;
		}
		$part_ref = (string) ( $part['id'] ?? '' );
		if ( '' === $part_ref || isset( $seen[ $part_ref ] ) ) {
			continue;
		}
		$seen[ $part_ref ] = true;

		$normalized_parts[] = dtb_schematic_hotspot_part_make(
			[
				'part_ref'   => $part_ref,
				'display_id' => $part_ref,
				'name'       => (string) ( $part['name'] ?? '' ),
				'sku'        => (string) ( $part['sku'] ?? '' ),
				'source_sku' => (string) ( $part['source_sku'] ?? '' ),
				'quantity'   => (float) ( $part['quantity'] ?? 0 ),
			]
		);
	}

	$normalized_hotspots = dtb_schematic_hotspot_normalize_legacy_coordinates( $decoded, $normalized_parts );

	if ( count( $normalized_hotspots ) > DTB_SCHEMATIC_HOTSPOT_MAX_OCCURRENCES ) {
		return dtb_schematic_hotspot_read_result( DTB_SCHEMATIC_HOTSPOT_READ_RESOURCE_LIMIT, null, 'Dataset exceeds the supported hotspot count.', $bom_stripped, 'legacy' );
	}

	$dataset = dtb_schematic_hotspot_dataset_make(
		[
			'source_schema' => 'legacy',
			'checksum'      => $checksum,
			'parts_catalog' => $normalized_parts,
			'hotspots'      => $normalized_hotspots,
		]
	);

	return dtb_schematic_hotspot_read_result( DTB_SCHEMATIC_HOTSPOT_READ_OK, $dataset, null, $bom_stripped, 'legacy' );
}

/**
 * Build hotspot occurrences from a legacy document's sibling top-level
 * `coordinates` map, when one is present. Returns an empty array when the
 * document has no `coordinates` map at all, or when it is not shaped as
 * expected — this never fabricates positions for parts the source data
 * genuinely has no coordinates for.
 *
 * Source `coordinates.<id>` entries already carry `x_pct`/`y_pct` as
 * percentage-of-page values (0..100), which is the same normalized
 * percentage scale used by the v2-native `hotspots[].normalized` shape
 * (see dtb_schematic_hotspot_normalize_v2()) and by
 * dtb_schematic_hotspot_normalize_coordinates() — so no unit conversion is
 * needed for x_pct/y_pct, only a key/shape reshape. `width_pct`/`height_pct`
 * are not present in the source directly, but many entries carry pixel-space
 * `widthPx`/`heightPx` alongside document-level `image_natural_width`/
 * `image_natural_height`; when all four are present and non-zero, this
 * derives width_pct/height_pct from them (a direct unit conversion of real
 * data, not a fabrication). Entries missing usable pixel dimensions simply
 * get a point-style occurrence (x_pct/y_pct only).
 *
 * @param array $normalized_parts Already-deduplicated parts_catalog, used only to know which part_refs are legitimate.
 */
function dtb_schematic_hotspot_normalize_legacy_coordinates( array $decoded, array $normalized_parts ): array {
	$coordinates = $decoded['coordinates'] ?? null;
	if ( ! is_array( $coordinates ) || empty( $coordinates ) ) {
		return [];
	}

	$known_parts = [];
	foreach ( $normalized_parts as $part ) {
		$known_parts[ $part['part_ref'] ] = true;
	}

	$image_width  = (float) ( $decoded['image_natural_width'] ?? 0 );
	$image_height = (float) ( $decoded['image_natural_height'] ?? 0 );

	$shape_map = [
		'rectangle' => 'rect',
		'rect'      => 'rect',
		'circle'    => 'circle',
		'polygon'   => 'polygon',
		'point'     => 'point',
	];

	$doc_id    = (string) ( $decoded['id'] ?? '' );
	$occurrences = [];

	foreach ( $coordinates as $part_ref => $entry ) {
		$part_ref = (string) $part_ref;
		if ( ! is_array( $entry ) || '' === $part_ref || ! isset( $known_parts[ $part_ref ] ) ) {
			continue;
		}
		if ( ! is_numeric( $entry['x_pct'] ?? null ) || ! is_numeric( $entry['y_pct'] ?? null ) ) {
			continue; // No usable spatial data for this part — skip, never default to (0,0)/center.
		}
		if ( 0.0 === (float) $entry['x_pct'] && 0.0 === (float) $entry['y_pct'] ) {
			continue; // Legacy authoring placeholder: explicitly unplaced, not the diagram origin.
		}

		$coords = [
			'x_pct' => (float) $entry['x_pct'],
			'y_pct' => (float) $entry['y_pct'],
		];

		$width_px  = (float) ( $entry['widthPx'] ?? 0 );
		$height_px = (float) ( $entry['heightPx'] ?? 0 );
		if ( $width_px > 0 && $height_px > 0 && $image_width > 0 && $image_height > 0 ) {
			$coords['width_pct']  = ( $width_px / $image_width ) * 100;
			$coords['height_pct'] = ( $height_px / $image_height ) * 100;
		}

		$shape_key  = strtolower( (string) ( $entry['shape'] ?? 'point' ) );
		$shape_type = $shape_map[ $shape_key ] ?? 'point';

		$occurrences[] = dtb_schematic_hotspot_occurrence_make(
			[
				'hotspot_id'  => sprintf( 'legacy-%s-%s', '' !== $doc_id ? $doc_id : 'coord', $part_ref ),
				'part_ref'    => $part_ref,
				'page'        => (int) ( $entry['pageNumber'] ?? 1 ),
				'shape_type'  => $shape_type,
				'coordinates' => $coords,
				'label'       => '',
			]
		);
	}

	return $occurrences;
}

/**
 * Enumerate every schematic_data*.json file under the source root
 * (bounded — on the order of ~90 files). Used by the migration/reconciliation
 * batch operation and the static verification harness. Returns paths
 * as deployment-neutral `brands/...` logical references. The resolver also
 * accepts the older `frontend/public/brands/...` stored-reference convention.
 *
 * @return string[] Relative paths (repo-root-relative, forward slashes).
 */
function dtb_schematic_hotspot_enumerate_source_files(): array {
	$roots = dtb_schematics_hotspot_source_roots();
	if ( empty( $roots ) ) {
		return [];
	}

	$files = [];
	foreach ( $roots as $root ) {
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'json' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			$name = $file->getFilename();
			if ( 'schematic_data.schema.json' === $name || 'schematic_data_template.json' === $name ) {
				continue; // Not a dataset — the JSON Schema definition and the authoring template.
			}
			if ( 0 !== strpos( $name, 'schematic_data' ) ) {
				continue;
			}
			$root_prefix = trailingslashit( str_replace( '\\', '/', $root ) );
			$path        = str_replace( '\\', '/', $file->getPathname() );
			$files[]     = 'brands/' . substr( $path, strlen( $root_prefix ) );
		}
	}

	$files = array_values( array_unique( $files ) );
	sort( $files );
	return $files;
}
