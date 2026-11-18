<?php
/**
 * DTB Schematics — read-only audit of frontend/public/brands hotspot sources.
 *
 * This service evaluates the deployed/repository brand hotspot JSON using the
 * same approved source roots, readers, normalization rules, source grouping,
 * and merge semantics as the migration pipeline. It never writes source
 * files, products, schematic records, or identifiers.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_SCHEMATIC_SOURCE_AUDIT_MAX_RECORDS = 25;
const DTB_SCHEMATIC_SOURCE_AUDIT_MAX_ISSUES  = 100;

/** Audit a bounded record scope against the current frontend/public/brands data. */
function dtb_schematic_hotspot_source_audit_scan( array $args = [] ): array {
	$schematic_id = max( 0, (int) ( $args['schematic_id'] ?? 0 ) );
	$page         = max( 1, (int) ( $args['page'] ?? 1 ) );
	$per_page     = min( DTB_SCHEMATIC_SOURCE_AUDIT_MAX_RECORDS, max( 1, (int) ( $args['per_page'] ?? DTB_SCHEMATIC_SOURCE_AUDIT_MAX_RECORDS ) ) );
	$search       = sanitize_text_field( (string) ( $args['search'] ?? '' ) );

	$records = [];
	if ( $schematic_id > 0 ) {
		$record = dtb_schematic_record_repo_get( $schematic_id );
		$records = $record ? [ $record ] : [];
	} else {
		$query   = dtb_schematic_record_repo_query( [ 'page' => $page, 'per_page' => $per_page, 'search' => $search ] );
		$records = $query['items'];
	}

	$report = [
		'records_examined'       => 0,
		'source_files_examined'  => 0,
		'source_read_errors'     => 0,
		'source_missing'         => 0,
		'source_invalid_records' => 0,
		'source_partial_records' => 0,
		'source_drift_records'   => 0,
		'source_parts'           => 0,
		'source_hotspots'        => 0,
		'source_only_parts'      => 0,
		'stored_only_parts'      => 0,
		'dangling_hotspots'      => 0,
		'invalid_hotspots'       => 0,
		'duplicate_hotspot_ids'  => 0,
		'page_mismatches'        => 0,
		'exactly_resolvable'     => 0,
		'unresolved_at_source'   => 0,
		'items'                  => [],
	];

	foreach ( $records as $record ) {
		$report['records_examined']++;
		$item = dtb_schematic_hotspot_source_audit_record( $record );
		$report['items'][] = $item;
		$report['source_files_examined']  += count( $item['source_files'] );
		$report['source_read_errors']     += count( $item['read_errors'] );
		$report['source_missing']         += 'missing' === $item['status'] ? 1 : 0;
		$report['source_invalid_records'] += 'invalid' === $item['status'] ? 1 : 0;
		$report['source_partial_records'] += 'partial' === $item['status'] ? 1 : 0;
		$report['source_drift_records']   += ! empty( $item['drift'] ) ? 1 : 0;
		$report['source_parts']           += (int) $item['parts_count'];
		$report['source_hotspots']        += (int) $item['hotspot_count'];
		$report['source_only_parts']      += count( $item['source_only_parts'] );
		$report['stored_only_parts']      += count( $item['stored_only_parts'] );
		$report['dangling_hotspots']      += count( $item['dangling_hotspots'] );
		$report['invalid_hotspots']       += count( $item['invalid_hotspots'] );
		$report['duplicate_hotspot_ids']  += count( $item['duplicate_hotspot_ids'] );
		$report['page_mismatches']        += count( $item['page_mismatches'] );
		$report['exactly_resolvable']     += (int) $item['exactly_resolvable'];
		$report['unresolved_at_source']   += (int) $item['unresolved_at_source'];
	}

	return $report;
}

/**
 * Audit one authoritative record against its current brand source JSON.
 *
 * When `$include_projection` is true, the returned `projected_parts` value is
 * the exact deterministic relationship projection the migration service would
 * persist from the source dataset. The normal audit UI leaves it disabled so
 * the bounded report does not carry full relationship arrays unnecessarily.
 */
function dtb_schematic_hotspot_source_audit_record( DTB_Schematic_Record_Entity $record, bool $include_projection = false ): array {
	$source_entries = function_exists( 'dtb_schematic_reconcile_hotspot_source_group' )
		? dtb_schematic_reconcile_hotspot_source_group( $record->canonical_id )
		: [];

	$reference = trim( (string) ( $record->hotspot_dataset['reference'] ?? '' ) );
	if ( empty( $source_entries ) && '' === $reference && function_exists( 'dtb_schematic_reconcile_locate_hotspot_dataset_file' ) ) {
		$reference = (string) dtb_schematic_reconcile_locate_hotspot_dataset_file( $record->canonical_id, $record->brand_name ?: $record->brand_id );
	}
	if ( empty( $source_entries ) && '' !== $reference ) {
		$source_entries = [ [ 'reference' => $reference, 'page' => null ] ];
	}

	$base = [
		'schematic_id'          => $record->id,
		'canonical_id'          => $record->canonical_id,
		'title'                 => $record->title,
		'brand'                 => $record->brand_name ?: $record->brand_id,
		'status'                => 'ok',
		'source_schema'         => '',
		'source_files'          => [],
		'read_errors'           => [],
		'parts_count'           => 0,
		'hotspot_count'         => 0,
		'checksum'              => '',
		'stored_checksum'       => '',
		'drift'                 => false,
		'source_only_parts'     => [],
		'stored_only_parts'     => [],
		'dangling_hotspots'     => [],
		'invalid_hotspots'      => [],
		'duplicate_hotspot_ids' => [],
		'page_mismatches'       => [],
		'exactly_resolvable'    => 0,
		'unresolved_at_source'  => 0,
	];

	if ( empty( $source_entries ) ) {
		$base['status'] = 'missing';
		$base['read_errors'][] = 'No approved frontend/public/brands hotspot source could be associated with this schematic.';
		return $base;
	}

	$sources = [];
	foreach ( $source_entries as $entry ) {
		$source_reference = str_replace( '\\', '/', trim( (string) ( $entry['reference'] ?? '' ) ) );
		if ( '' === $source_reference || ! preg_match( '#^(?:frontend/public/)?brands/(?:[A-Za-z0-9._-]+/)+schematic_data[^/]*\.json$#', $source_reference ) ) {
			$base['read_errors'][] = 'Rejected unsupported hotspot source reference.';
			continue;
		}
		$base['source_files'][] = $source_reference;
		$absolute = dtb_schematics_hotspot_resolve_reference( $source_reference );
		if ( false === $absolute ) {
			$base['read_errors'][] = $source_reference . ': file is not present under an approved runtime source root.';
			continue;
		}
		$read = dtb_schematic_hotspot_read_file( $absolute );
		if ( DTB_SCHEMATIC_HOTSPOT_READ_OK !== ( $read['status'] ?? '' ) || empty( $read['dataset'] ) ) {
			$base['read_errors'][] = $source_reference . ': ' . sanitize_text_field( (string) ( $read['error'] ?? $read['status'] ?? 'read failed' ) );
			continue;
		}
		$sources[] = [
			'dataset' => $read['dataset'],
			'page'    => isset( $entry['page'] ) ? (int) $entry['page'] : null,
		];
	}

	if ( empty( $sources ) ) {
		$base['status'] = 'error';
		return $base;
	}

	$dataset = function_exists( 'dtb_schematic_hotspot_merge_source_datasets' )
		? dtb_schematic_hotspot_merge_source_datasets( $record->canonical_id, $sources )
		: (array) $sources[0]['dataset'];

	$base['source_schema'] = sanitize_key( (string) ( $dataset['source_schema'] ?? '' ) );
	$base['parts_count']   = count( (array) ( $dataset['parts_catalog'] ?? [] ) );
	$base['hotspot_count'] = count( (array) ( $dataset['hotspots'] ?? [] ) );
	$base['checksum']      = sanitize_text_field( (string) ( $dataset['checksum'] ?? '' ) );

	$stored = dtb_schematic_hotspot_dataset_repo_get( $record->id );
	$base['stored_checksum'] = sanitize_text_field( (string) ( $stored['checksum'] ?? '' ) );
	$base['drift'] = ! $stored || '' === $base['stored_checksum'] || $base['checksum'] !== $base['stored_checksum'];

	$source_refs = [];
	foreach ( (array) ( $dataset['parts_catalog'] ?? [] ) as $part ) {
		$ref = sanitize_key( (string) ( $part['part_ref'] ?? '' ) );
		if ( '' !== $ref ) {
			$source_refs[ $ref ] = $part;
		}
	}
	$stored_refs = [];
	foreach ( (array) $record->parts as $part ) {
		$ref = sanitize_key( (string) ( $part['part_ref'] ?? '' ) );
		if ( '' !== $ref ) {
			$stored_refs[ $ref ] = $part;
		}
	}

	$base['source_only_parts'] = array_slice( array_values( array_diff( array_keys( $source_refs ), array_keys( $stored_refs ) ) ), 0, DTB_SCHEMATIC_SOURCE_AUDIT_MAX_ISSUES );
	$base['stored_only_parts'] = array_slice( array_values( array_diff( array_keys( $stored_refs ), array_keys( $source_refs ) ) ), 0, DTB_SCHEMATIC_SOURCE_AUDIT_MAX_ISSUES );

	$seen_hotspot_ids = [];
	$page_numbers = array_map( static fn( $page ) => (int) ( $page['page_number'] ?? 0 ), (array) $record->pages );
	foreach ( (array) ( $dataset['hotspots'] ?? [] ) as $hotspot ) {
		$hotspot_id = sanitize_text_field( (string) ( $hotspot['hotspot_id'] ?? '' ) );
		$part_ref   = sanitize_key( (string) ( $hotspot['part_ref'] ?? '' ) );
		$page       = (int) ( $hotspot['page'] ?? 0 );

		if ( isset( $seen_hotspot_ids[ $hotspot_id ] ) && count( $base['duplicate_hotspot_ids'] ) < DTB_SCHEMATIC_SOURCE_AUDIT_MAX_ISSUES ) {
			$base['duplicate_hotspot_ids'][] = $hotspot_id;
		}
		$seen_hotspot_ids[ $hotspot_id ] = true;

		if ( '' === $part_ref || ! isset( $source_refs[ $part_ref ] ) ) {
			if ( count( $base['dangling_hotspots'] ) < DTB_SCHEMATIC_SOURCE_AUDIT_MAX_ISSUES ) {
				$base['dangling_hotspots'][] = $hotspot_id ?: '(missing id)';
			}
		}
		if ( ! dtb_schematic_hotspot_occurrence_has_valid_coordinates( $hotspot ) ) {
			if ( count( $base['invalid_hotspots'] ) < DTB_SCHEMATIC_SOURCE_AUDIT_MAX_ISSUES ) {
				$base['invalid_hotspots'][] = $hotspot_id ?: '(missing id)';
			}
		}
		if ( $page > 0 && ! empty( $page_numbers ) && ! in_array( $page, $page_numbers, true ) ) {
			if ( count( $base['page_mismatches'] ) < DTB_SCHEMATIC_SOURCE_AUDIT_MAX_ISSUES ) {
				$base['page_mismatches'][] = $hotspot_id ?: '(missing id)';
			}
		}
	}

	$compatible_ids = dtb_schematic_resolve_compatible_part_product_ids( $record->linked_products );
	foreach ( $source_refs as $source_part ) {
		$resolution = dtb_schematic_resolve_single_part( $record, $source_part, $compatible_ids );
		if ( (int) ( $resolution['product_id'] ?? 0 ) > 0 ) {
			$base['exactly_resolvable']++;
		} else {
			$base['unresolved_at_source']++;
		}
	}

	if ( $include_projection ) {
		$base['projected_parts'] = dtb_schematic_resolve_part_occurrences_for_record( $record, $dataset );
	}

	$structural_invalid = ! empty( $base['dangling_hotspots'] )
		|| ! empty( $base['invalid_hotspots'] )
		|| ! empty( $base['duplicate_hotspot_ids'] )
		|| ! empty( $base['page_mismatches'] );

	if ( ! empty( $base['read_errors'] ) ) {
		$base['status'] = 'partial';
	} elseif ( $structural_invalid ) {
		$base['status'] = 'invalid';
	} elseif ( $base['drift'] || $base['source_only_parts'] || $base['stored_only_parts'] ) {
		$base['status'] = 'attention';
	}

	return $base;
}
