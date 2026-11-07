<?php
/**
 * DTB Schematics — MigrateSchematicHotspotDatasets (Phase 5 application service).
 *
 * Bounded batch operation that, for every authoritative schematic record:
 *   1. locates its source hotspot JSON file (reusing the same
 *      Phase 3 locator, dtb_schematic_reconcile_locate_hotspot_dataset_file(),
 *      or an already-associated `_dtb_schematic_hotspot_dataset` reference);
 *   2. reads + normalizes it (Infrastructure/SchematicHotspotDatasetReader.php);
 *   3. persists the normalized dataset (Infrastructure/SchematicHotspotDatasetRepository.php);
 *   4. resolves its parts_catalog against WooCommerce in one bounded batch
 *      (Application/ResolveSchematicPartOccurrences.php);
 *   5. writes both results through the existing authoritative application
 *      service (Application/ManageSchematicRecord.php) — never bypassing it.
 *
 * This is the batch integration point referenced by the spec's "resolve
 * products in bounded backend batches" requirement — nothing here runs
 * per-hotspot-click or per public API request; the public API only reads
 * what this operation already stored.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_SCHEMATIC_HOTSPOT_MIGRATE_MAX_PAGES = 50; // Safety cap: at most 5,000 schematic records (100/page) per invocation.

/**
 * Run the migration across every schematic record (or a single record when
 * `$only_canonical_id` is supplied — used by the WP-CLI `--schematic=` flag).
 *
 * @param array $args {
 *     @type bool   $dry_run           Default true. When false, eligible records are written.
 *     @type int    $per_page          Records per WP_Query page (default 50, capped at 100).
 *     @type string $only_canonical_id Optional: restrict to a single schematic.
 *     @type callable|null $lease_heartbeat Optional commit-lease renewal callback.
 * }
 * @return array{
 *   dry_run:bool, examined:int, migrated:int, changed:int, unchanged:int, skipped:int,
 *   unresolved:int, failed:int,
 *   results: array[]
 * }
 */
function dtb_schematic_migrate_hotspot_datasets( array $args = [] ): array {
	$dry_run   = array_key_exists( 'dry_run', $args ) ? (bool) $args['dry_run'] : true;
	$per_page  = max( 1, min( 100, (int) ( $args['per_page'] ?? 50 ) ) );
	$only_id   = isset( $args['only_canonical_id'] ) ? sanitize_key( (string) $args['only_canonical_id'] ) : '';
	$heartbeat = isset( $args['lease_heartbeat'] ) && is_callable( $args['lease_heartbeat'] ) ? $args['lease_heartbeat'] : null;

	$report = [
		'dry_run'   => $dry_run,
		'examined'  => 0,
		'migrated'  => 0,
		'unchanged' => 0,
		'skipped'   => 0,
		'failed'    => 0,
		'changed'   => 0,
		'unresolved' => 0,
		'results'   => [],
	];

	if ( '' !== $only_id ) {
		$record = dtb_schematic_record_repo_find_by_canonical_id( $only_id );
		if ( ! $record ) {
			$report['results'][] = [ 'canonical_id' => $only_id, 'status' => 'schematic_not_found' ];
			$report['failed']++;
			return $report;
		}
		$result = dtb_schematic_migrate_hotspot_dataset_for_record( $record, $dry_run );
		dtb_schematic_migrate_hotspot_tally( $report, $result );
		$report['changed'] = $report['migrated'];
		if ( ! $dry_run && $report['changed'] > 0 ) {
			dtb_schematics_invalidate_domain_cache();
		}
		return $report;
	}

	$page = 1;
	do {
		$query_result = dtb_schematic_record_repo_query( [ 'page' => $page, 'per_page' => $per_page ] );
		foreach ( $query_result['items'] as $record ) {
			if ( $heartbeat ) {
				$renewed = $heartbeat();
				if ( is_wp_error( $renewed ) ) {
					$report['failed']++;
					$report['fatal_error'] = $renewed->get_error_message();
					break 2;
				}
			}
			$result = dtb_schematic_migrate_hotspot_dataset_for_record( $record, $dry_run );
			dtb_schematic_migrate_hotspot_tally( $report, $result );
		}
		$page++;
	} while ( $page <= $query_result['pages'] && $page <= DTB_SCHEMATIC_HOTSPOT_MIGRATE_MAX_PAGES );

	if ( ! $dry_run ) {
		dtb_schematics_invalidate_domain_cache();
	}
	$report['changed'] = $report['migrated'];

	return $report;
}

function dtb_schematic_migrate_hotspot_tally( array &$report, array $result ): void {
	$report['examined']++;
	$report['results'][] = $result;
	$report['unresolved'] += (int) ( $result['parts_unresolved'] ?? 0 );
	switch ( $result['status'] ) {
		case 'migrated':
			$report['migrated']++;
			break;
		case 'unchanged':
			$report['unchanged']++;
			break;
		case 'no_source_file':
		case 'source_file_missing':
			$report['skipped']++;
			break;
		default:
			$report['failed']++;
			break;
	}
}

/**
 * Migrate a single schematic record's hotspot dataset.
 *
 * @return array{canonical_id:string, status:string, detail:string, source_file:?string, parts_resolved:int, parts_unresolved:int}
 */
function dtb_schematic_migrate_hotspot_dataset_for_record( DTB_Schematic_Record_Entity $record, bool $dry_run ): array {
	$base = [
		'canonical_id'     => $record->canonical_id,
		'status'           => 'skipped',
		'detail'           => '',
		'source_file'      => null,
		'parts_resolved'   => 0,
		'parts_unresolved' => 0,
	];

	$source_entries = function_exists( 'dtb_schematic_reconcile_hotspot_source_group' )
		? dtb_schematic_reconcile_hotspot_source_group( $record->canonical_id )
		: [];
	$relative_reference = $record->hotspot_dataset['reference'] ?? '';
	if ( '' === $relative_reference && function_exists( 'dtb_schematic_reconcile_locate_hotspot_dataset_file' ) ) {
		$relative_reference = (string) dtb_schematic_reconcile_locate_hotspot_dataset_file( $record->canonical_id, $record->brand_name ?: $record->brand_id );
	}
	if ( empty( $source_entries ) && '' !== $relative_reference ) {
		$source_entries = [ [ 'reference' => $relative_reference, 'page' => null ] ];
	}

	if ( '' === $relative_reference ) {
		$base['status'] = 'no_source_file';
		$base['detail'] = 'No hotspot dataset reference is associated and none could be located by canonical ID/brand.';
		return $base;
	}

	$relative_reference = str_replace( '\\', '/', trim( $relative_reference ) );
	if ( ! preg_match( '#^(?:frontend/public/)?brands/(?:[A-Za-z0-9._-]+/)+schematic_data[^/]*\.json$#', $relative_reference ) ) {
		$base['status'] = 'failed';
		$base['detail'] = 'Hotspot dataset reference is outside the supported brand dataset path.';
		return $base;
	}
	$base['source_file'] = implode( ', ', array_column( $source_entries, 'reference' ) );
	$datasets = [];
	foreach ( $source_entries as $source_entry ) {
		$source_reference = str_replace( '\\', '/', trim( (string) ( $source_entry['reference'] ?? '' ) ) );
		if ( ! preg_match( '#^(?:frontend/public/)?brands/(?:[A-Za-z0-9._-]+/)+schematic_data[^/]*\.json$#', $source_reference ) ) {
			$base['status'] = 'failed';
			$base['detail'] = 'Hotspot source group contains an invalid dataset reference.';
			return $base;
		}
		$absolute_path = function_exists( 'dtb_schematics_hotspot_resolve_reference' )
			? dtb_schematics_hotspot_resolve_reference( $source_reference )
			: false;
		if ( false === $absolute_path ) {
			$base['status'] = 'source_file_missing';
			$base['detail'] = 'Hotspot dataset source file does not exist in an approved runtime source root: ' . $source_reference;
			return $base;
		}
		$read = dtb_schematic_hotspot_read_file( $absolute_path );
		if ( DTB_SCHEMATIC_HOTSPOT_READ_OK !== $read['status'] ) {
			$base['status'] = DTB_SCHEMATIC_HOTSPOT_READ_FILE_NOT_FOUND === $read['status'] ? 'source_file_missing' : 'failed';
			$base['detail']  = $source_reference . ': ' . ( $read['error'] ?? $read['status'] ) . ( $read['bom_stripped'] ? ' (BOM stripped)' : '' );
			return $base;
		}
		$datasets[] = [ 'dataset' => $read['dataset'], 'page' => $source_entry['page'] ?? null ];
	}

	$dataset = dtb_schematic_hotspot_merge_source_datasets( $record->canonical_id, $datasets );

	$parts_resolved = dtb_schematic_resolve_part_occurrences_for_record( $record, $dataset );
	$unresolved_count = count(
		array_filter( $parts_resolved, static fn( $p ) => DTB_SCHEMATIC_PART_STATE_UNRESOLVED === $p['resolution_state'] )
	);

	$base['parts_resolved']   = count( $parts_resolved ) - $unresolved_count;
	$base['parts_unresolved'] = $unresolved_count;

	$existing_dataset = dtb_schematic_hotspot_dataset_repo_get( $record->id );
	$dataset_unchanged = $existing_dataset && ( $existing_dataset['checksum'] ?? '' ) === $dataset['checksum']
		&& ( $record->hotspot_dataset['reference'] ?? '' ) === $relative_reference;
	$parts_unchanged = dtb_schematic_hotspot_part_projection_matches( $record->parts, $parts_resolved );

	if ( $dataset_unchanged && $parts_unchanged ) {
		$base['status'] = 'unchanged';
		$base['detail'] = 'Dataset checksum, reference, and resolved-part projection already match the stored state.';
		return $base;
	}

	if ( $dry_run ) {
		$base['status'] = 'migrated';
		$base['detail'] = sprintf(
			'Would persist %s-schema dataset (%d parts, %d hotspot occurrences) and refresh %d part relationship(s).',
			$dataset['source_schema'],
			count( $dataset['parts_catalog'] ),
			count( $dataset['hotspots'] ),
			count( $parts_resolved )
		);
		return $base;
	}

	$record_update = dtb_schematic_update(
		$record->id,
		[
			'hotspot_dataset' => [
				'type'           => 'frontend_json',
				'reference'      => $relative_reference,
				'schema_version' => $dataset['schema_version'],
				'checksum'       => $dataset['checksum'],
			],
			'parts'           => $parts_resolved,
		]
	);
	if ( is_wp_error( $record_update ) ) {
		$base['status'] = 'failed';
		$base['detail'] = $record_update->get_error_message();
		return $base;
	}

	$dataset_saved = dtb_schematic_hotspot_dataset_repo_save( $record->id, $dataset );
	$stored_dataset = dtb_schematic_hotspot_dataset_repo_get( $record->id );
	if ( ! $dataset_saved && ( $stored_dataset['checksum'] ?? '' ) !== $dataset['checksum'] ) {
		$rollback = dtb_schematic_update(
			$record->id,
			[
				'hotspot_dataset' => $record->hotspot_dataset,
				'parts'           => $record->parts,
			]
		);
		$base['status'] = 'failed';
		$base['detail'] = is_wp_error( $rollback )
			? 'Normalized dataset persistence failed and the record projection could not be restored: ' . $rollback->get_error_message()
			: 'Normalized dataset persistence failed; the record projection was restored.';
		return $base;
	}

	$base['status'] = 'migrated';
	$base['detail'] = sprintf(
		'Persisted %s-schema dataset (%d parts, %d hotspot occurrences); %d/%d part relationship(s) resolved.',
		$dataset['source_schema'],
		count( $dataset['parts_catalog'] ),
		count( $dataset['hotspots'] ),
		$base['parts_resolved'],
		count( $parts_resolved )
	);

	return $base;
}

/**
 * Compare persisted and newly resolved part projections deterministically.
 * Product imports and operator linking can change independently of source
 * JSON, so a matching dataset checksum must never suppress a parts refresh.
 */
function dtb_schematic_hotspot_part_projection_matches( array $stored, array $resolved ): bool {
	$normalize = static function ( array $parts ): array {
		$rows = array_map(
			static function ( array $part ): array {
				return [
					'part_ref'          => (string) ( $part['part_ref'] ?? '' ),
					'mpn'               => (string) ( $part['mpn'] ?? '' ),
					'sku'               => (string) ( $part['sku'] ?? '' ),
					'brand'             => (string) ( $part['brand'] ?? '' ),
					'title'             => (string) ( $part['title'] ?? '' ),
					'product_id'        => (int) ( $part['product_id'] ?? 0 ),
					'resolution_method' => (string) ( $part['resolution_method'] ?? '' ),
					'resolution_state'  => (string) ( $part['resolution_state'] ?? '' ),
					'occurrence_count'  => (int) ( $part['occurrence_count'] ?? 0 ),
				];
			},
			$parts
		);
		usort( $rows, static fn( array $a, array $b ): int => strcmp( $a['part_ref'], $b['part_ref'] ) );
		return $rows;
	};

	return $normalize( $stored ) === $normalize( $resolved );
}

/** Merge deterministic per-page source documents without collapsing occurrences. */
function dtb_schematic_hotspot_merge_source_datasets( string $canonical_id, array $sources ): array {
	$parts       = [];
	$seen_parts  = [];
	$hotspots    = [];
	$checksums   = [];
	$all_legacy  = true;

	foreach ( $sources as $source_index => $source ) {
		$dataset    = (array) ( $source['dataset'] ?? [] );
		$page       = isset( $source['page'] ) ? (int) $source['page'] : null;
		$checksums[] = (string) ( $dataset['checksum'] ?? '' );
		$all_legacy = $all_legacy && 'legacy' === ( $dataset['source_schema'] ?? '' );
		foreach ( (array) ( $dataset['parts_catalog'] ?? [] ) as $part ) {
			$part_ref = (string) ( $part['part_ref'] ?? '' );
			if ( '' !== $part_ref && ! isset( $seen_parts[ $part_ref ] ) ) {
				$seen_parts[ $part_ref ] = true;
				$parts[] = $part;
			}
		}
		foreach ( (array) ( $dataset['hotspots'] ?? [] ) as $hotspot_index => $hotspot ) {
			if ( null !== $page ) {
				$hotspot['page'] = $page;
			}
			if ( count( $sources ) > 1 ) {
				$hotspot['hotspot_id'] = sprintf(
					'%s-p%d-s%d-%s',
					$canonical_id,
					(int) ( $hotspot['page'] ?? 1 ),
					$source_index + 1,
					(string) ( $hotspot['hotspot_id'] ?? $hotspot_index )
				);
			}
			$hotspots[] = $hotspot;
		}
	}

	return dtb_schematic_hotspot_dataset_make(
		[
			'source_schema' => $all_legacy ? 'legacy' : 'v2',
			'checksum'      => hash( 'sha256', implode( '|', $checksums ) ),
			'parts_catalog' => $parts,
			'hotspots'      => $hotspots,
		]
	);
}
