<?php
/**
 * DTB Schematics — one-time hotspot synchronization and identity optimizer.
 *
 * One bounded operator-triggered service audits authoritative schematic
 * sources, runs the existing hotspot migration/resolution pipeline, records
 * every deterministic repair that Preview would apply / Apply did apply, and
 * classifies every remaining unresolved identity into an actionable group.
 *
 * WooCommerce remains authoritative for products. This service never creates
 * products or rewrites protected SKU/MPN/GTIN/brand identifiers. Automatic
 * relationship writes are limited to the resolver contract in
 * ResolveSchematicPartOccurrences.php; title/fuzzy evidence is review-only.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_SCHEMATIC_HOTSPOT_OPTIMIZER_MAX_PAGES   = 50;
const DTB_SCHEMATIC_HOTSPOT_OPTIMIZER_MAX_GROUPS  = 1500;
const DTB_SCHEMATIC_HOTSPOT_OPTIMIZER_MAX_REPAIRS = 1500;
const DTB_SCHEMATIC_HOTSPOT_OPTIMIZER_PER_PAGE    = 25;

/** Run a full optimizer pass across authoritative schematic records. */
function dtb_schematic_hotspot_optimizer_run( array $args = [] ): array {
	$dry_run   = array_key_exists( 'dry_run', $args ) ? (bool) $args['dry_run'] : true;
	$per_page  = max( 1, min( 50, (int) ( $args['per_page'] ?? DTB_SCHEMATIC_HOTSPOT_OPTIMIZER_PER_PAGE ) ) );
	$heartbeat = isset( $args['lease_heartbeat'] ) && is_callable( $args['lease_heartbeat'] ) ? $args['lease_heartbeat'] : null;

	$report = [
		'dry_run'      => $dry_run,
		'examined'     => 0,
		'changed'      => 0,
		'would_change' => 0,
		'skipped'      => 0,
		'failed'       => 0,
		'unresolved'   => 0,
		'fatal_error'  => '',
		'metrics'      => [
			'source_files'                    => 0,
			'source_parts'                    => 0,
			'source_hotspots'                 => 0,
			'source_drift_before'             => 0,
			'source_read_errors'              => 0,
			'source_unavailable'              => 0,
			'exactly_resolvable'              => 0,
			'unresolved_at_source'            => 0,
			'projected_exact_repairs'         => 0, // Back-compat: all deterministic projected relationship repairs.
			'applied_exact_repairs'           => 0, // Back-compat: all deterministic applied relationship repairs.
			'projected_repairs'               => 0,
			'applied_repairs'                 => 0,
			'projected_normalized_sku_repairs'=> 0,
			'applied_normalized_sku_repairs'  => 0,
			'remaining_unresolved'            => 0,
			'active_hotspot_unresolved'       => 0,
			'inactive_catalog_unresolved'     => 0,
			'resolution_groups'               => 0,
		],
		'reason_counts'     => [],
		'resolution_groups' => [],
		'repairs'           => [],
		'source_errors'     => [],
		'results'           => [],
		'groups_truncated'  => false,
		'repairs_truncated' => false,
		'plan_fingerprint'  => '',
	];

	$groups = [];
	$page   = 1;

	do {
		$query = dtb_schematic_record_repo_query( [ 'page' => $page, 'per_page' => $per_page ] );
		foreach ( (array) $query['items'] as $record ) {
			if ( $heartbeat ) {
				$renewed = $heartbeat();
				if ( is_wp_error( $renewed ) ) {
					$report['failed']++;
					$report['fatal_error'] = $renewed->get_error_message();
					break 2;
				}
			}

			$report['examined']++;
			$audit = dtb_schematic_hotspot_source_audit_record( $record );
			$report['metrics']['source_files']         += count( (array) ( $audit['source_files'] ?? [] ) );
			$report['metrics']['source_parts']         += (int) ( $audit['parts_count'] ?? 0 );
			$report['metrics']['source_hotspots']      += (int) ( $audit['hotspot_count'] ?? 0 );
			$report['metrics']['source_drift_before']  += ! empty( $audit['drift'] ) ? 1 : 0;
			$report['metrics']['source_read_errors']   += count( (array) ( $audit['read_errors'] ?? [] ) );
			$report['metrics']['source_unavailable']   += in_array( (string) ( $audit['status'] ?? '' ), [ 'missing', 'error' ], true ) ? 1 : 0;
			$report['metrics']['exactly_resolvable']   += (int) ( $audit['exactly_resolvable'] ?? 0 );
			$report['metrics']['unresolved_at_source'] += (int) ( $audit['unresolved_at_source'] ?? 0 );

			foreach ( (array) ( $audit['read_errors'] ?? [] ) as $error ) {
				if ( count( $report['source_errors'] ) >= 100 ) {
					break;
				}
				$report['source_errors'][] = [
					'canonical_id' => $record->canonical_id,
					'message'      => sanitize_text_field( (string) $error ),
				];
			}

			$before_unresolved = [];
			foreach ( (array) $record->parts as $part ) {
				if ( dtb_schematic_hotspot_part_is_unresolved( $part ) ) {
					$before_unresolved[ (string) ( $part['part_ref'] ?? '' ) ] = true;
				}
			}

			$migration = dtb_schematic_migrate_hotspot_dataset_for_record( $record, $dry_run );
			$status    = (string) ( $migration['status'] ?? 'failed' );
			if ( 'migrated' === $status ) {
				if ( $dry_run ) {
					$report['would_change']++;
				} else {
					$report['changed']++;
				}
			} elseif ( in_array( $status, [ 'no_source_file', 'source_file_missing' ], true ) ) {
				$report['skipped']++;
			} elseif ( 'unchanged' !== $status ) {
				$report['failed']++;
			}

			$record_result = [
				'schematic_id'     => $record->id,
				'canonical_id'     => $record->canonical_id,
				'source_status'    => sanitize_key( (string) ( $audit['status'] ?? '' ) ),
				'source_drift'     => ! empty( $audit['drift'] ),
				'migration_status' => sanitize_key( $status ),
				'parts_resolved'   => (int) ( $migration['parts_resolved'] ?? 0 ),
				'parts_unresolved' => (int) ( $migration['parts_unresolved'] ?? 0 ),
			];
			if ( count( $report['results'] ) < 100 ) {
				$report['results'][] = $record_result;
			}

			if ( in_array( (string) ( $audit['status'] ?? '' ), [ 'missing', 'error' ], true ) ) {
				dtb_schematic_hotspot_optimizer_add_group(
					$groups,
					$record,
					[ 'part_ref' => '', 'display_id' => '', 'name' => __( 'Hotspot source unavailable', 'drywall-toolbox' ), 'sku' => '' ],
					[
						'code'       => 'source_unavailable',
						'label'      => __( 'Source unavailable', 'drywall-toolbox' ),
						'resolution' => __( 'Restore or associate the authoritative schematic_data JSON under the approved brand source root, then rerun Preview.', 'drywall-toolbox' ),
						'candidates' => [],
					],
					0
				);
				continue;
			}

			if ( $dry_run && ! empty( $audit['drift'] ) ) {
				dtb_schematic_hotspot_optimizer_add_group(
					$groups,
					$record,
					[ 'part_ref' => '', 'display_id' => '', 'name' => __( 'Source projection drift', 'drywall-toolbox' ), 'sku' => '' ],
					[
						'code'       => 'source_sync_required',
						'label'      => __( 'Source synchronization required', 'drywall-toolbox' ),
						'resolution' => __( 'Apply will synchronize the normalized hotspot projection before resolving part relationships.', 'drywall-toolbox' ),
						'candidates' => [],
					],
					0
				);
				continue;
			}

			$fresh_record = $dry_run ? $record : dtb_schematic_record_repo_get( $record->id );
			$dataset      = dtb_schematic_hotspot_dataset_repo_get( $record->id );
			if ( ! $fresh_record || ! $dataset ) {
				continue;
			}

			$projection    = dtb_schematic_resolve_part_occurrences_for_record( $fresh_record, $dataset );
			$source_by_ref = [];
			foreach ( (array) ( $dataset['parts_catalog'] ?? [] ) as $source_part ) {
				$source_by_ref[ (string) ( $source_part['part_ref'] ?? '' ) ] = $source_part;
			}

			$repairs = 0;
			foreach ( $projection as $part ) {
				$ref         = (string) ( $part['part_ref'] ?? '' );
				$product_id  = (int) ( $part['product_id'] ?? 0 );
				$method      = sanitize_key( (string) ( $part['resolution_method'] ?? '' ) );
				$occurrences = max( 0, (int) ( $part['occurrence_count'] ?? 0 ) );
				$source_part = (array) ( $source_by_ref[ $ref ] ?? [
					'part_ref'   => $ref,
					'display_id' => (string) ( $part['mpn'] ?? '' ),
					'name'       => (string) ( $part['title'] ?? '' ),
					'sku'        => (string) ( $part['sku'] ?? '' ),
				] );

				if ( isset( $before_unresolved[ $ref ] ) && $product_id > 0 ) {
					$repairs++;
					dtb_schematic_hotspot_optimizer_add_repair( $report, $fresh_record, $source_part, $part, $dry_run );
				}

				if ( ! dtb_schematic_hotspot_part_is_unresolved( $part ) ) {
					continue;
				}

				$classification = dtb_schematic_hotspot_optimizer_classify_unresolved( $fresh_record, $source_part );
				dtb_schematic_hotspot_optimizer_add_group( $groups, $fresh_record, $source_part, $classification, $occurrences );
			}

			if ( $dry_run ) {
				$report['metrics']['projected_exact_repairs'] += $repairs;
				$report['metrics']['projected_repairs']       += $repairs;
			} else {
				$report['metrics']['applied_exact_repairs'] += $repairs;
				$report['metrics']['applied_repairs']       += $repairs;
			}
		}

		$page++;
	} while ( $page <= (int) ( $query['pages'] ?? 1 ) && $page <= DTB_SCHEMATIC_HOTSPOT_OPTIMIZER_MAX_PAGES );

	$groups = array_values( $groups );
	usort(
		$groups,
		static function ( array $a, array $b ): int {
			$active_a = (int) ( $a['occurrences'] ?? 0 ) > 0 ? 1 : 0;
			$active_b = (int) ( $b['occurrences'] ?? 0 ) > 0 ? 1 : 0;
			if ( $active_a !== $active_b ) {
				return $active_b <=> $active_a;
			}
			$impact_a = (int) ( $a['occurrences'] ?? 0 ) + (int) ( $a['relationship_count'] ?? 0 );
			$impact_b = (int) ( $b['occurrences'] ?? 0 ) + (int) ( $b['relationship_count'] ?? 0 );
			return $impact_b <=> $impact_a;
		}
	);
	if ( count( $groups ) > DTB_SCHEMATIC_HOTSPOT_OPTIMIZER_MAX_GROUPS ) {
		$report['groups_truncated'] = true;
		$groups = array_slice( $groups, 0, DTB_SCHEMATIC_HOTSPOT_OPTIMIZER_MAX_GROUPS );
	}

	foreach ( $groups as $group ) {
		$relationships = max( 1, (int) ( $group['relationship_count'] ?? 1 ) );
		$code          = sanitize_key( (string) ( $group['issue_code'] ?? 'unclassified' ) );
		$report['reason_counts'][ $code ] = (int) ( $report['reason_counts'][ $code ] ?? 0 ) + $relationships;
		if ( (int) ( $group['occurrences'] ?? 0 ) > 0 ) {
			$report['metrics']['active_hotspot_unresolved'] += $relationships;
		} else {
			$report['metrics']['inactive_catalog_unresolved'] += $relationships;
		}
	}
	ksort( $report['reason_counts'] );

	$report['resolution_groups'] = $groups;
	$report['metrics']['resolution_groups']    = count( $groups );
	$report['metrics']['remaining_unresolved'] = array_sum( array_map( static fn( $group ) => (int) ( $group['relationship_count'] ?? 0 ), $groups ) );
	$report['unresolved'] = $report['metrics']['remaining_unresolved'];
	$report['plan_fingerprint'] = dtb_schematic_hotspot_optimizer_plan_fingerprint( $report );

	if ( ! $dry_run && $report['changed'] > 0 ) {
		dtb_schematics_invalidate_domain_cache();
	}

	return $report;
}

/** Record a newly resolvable relationship so Preview truthfully shows Apply's plan. */
function dtb_schematic_hotspot_optimizer_add_repair( array &$report, DTB_Schematic_Record_Entity $record, array $source_part, array $relationship, bool $dry_run ): void {
	$method = sanitize_key( (string) ( $relationship['resolution_method'] ?? '' ) );
	if ( DTB_SCHEMATIC_PART_RESOLUTION_NORMALIZED_SKU === $method ) {
		$key = $dry_run ? 'projected_normalized_sku_repairs' : 'applied_normalized_sku_repairs';
		$report['metrics'][ $key ]++;
	}
	if ( count( $report['repairs'] ) >= DTB_SCHEMATIC_HOTSPOT_OPTIMIZER_MAX_REPAIRS ) {
		$report['repairs_truncated'] = true;
		return;
	}

	$product_id = (int) ( $relationship['product_id'] ?? 0 );
	$product    = function_exists( 'dtb_schematic_hotspot_describe_product' ) ? dtb_schematic_hotspot_describe_product( $product_id ) : null;
	$report['repairs'][] = [
		'action'        => $dry_run ? 'would_link' : 'linked',
		'schematic_id'  => $record->id,
		'canonical_id'  => sanitize_text_field( $record->canonical_id ),
		'brand'         => sanitize_text_field( (string) ( $record->brand_name ?: $record->brand_id ) ),
		'part_ref'      => sanitize_text_field( (string) ( $source_part['part_ref'] ?? $relationship['part_ref'] ?? '' ) ),
		'title'         => sanitize_text_field( (string) ( $source_part['name'] ?? $relationship['title'] ?? '' ) ),
		'source_sku'    => sanitize_text_field( (string) ( $source_part['sku'] ?? $relationship['sku'] ?? '' ) ),
		'display_id'    => sanitize_text_field( (string) ( $source_part['display_id'] ?? $relationship['mpn'] ?? '' ) ),
		'occurrences'   => max( 0, (int) ( $relationship['occurrence_count'] ?? 0 ) ),
		'product_id'    => $product_id,
		'resolution_method' => $method,
		'product'       => $product ? [
			'id'     => (int) ( $product['id'] ?? $product_id ),
			'name'   => sanitize_text_field( (string) ( $product['name'] ?? '' ) ),
			'sku'    => sanitize_text_field( (string) ( $product['sku'] ?? '' ) ),
			'mpn'    => sanitize_text_field( (string) ( $product['mpn'] ?? '' ) ),
			'brand'  => sanitize_text_field( (string) ( $product['brand'] ?? '' ) ),
			'type'   => sanitize_key( (string) ( $product['type'] ?? '' ) ),
			'status' => sanitize_key( (string) ( $product['status'] ?? '' ) ),
		] : null,
	];
}

/** Classify one unresolved current-source identity without mutating catalog data. */
function dtb_schematic_hotspot_optimizer_classify_unresolved( DTB_Schematic_Record_Entity $record, array $source_part ): array {
	$sku      = trim( (string) ( $source_part['sku'] ?? '' ) );
	$mpn      = trim( (string) ( $source_part['display_id'] ?? '' ) );
	$part_ref = trim( (string) ( $source_part['part_ref'] ?? '' ) );
	$title    = trim( (string) ( $source_part['name'] ?? '' ) );

	if ( dtb_schematic_hotspot_optimizer_is_reference_only( $sku, $title ) ) {
		return [
			'code'       => 'source_reference_only',
			'label'      => __( 'Diagram reference, not a product identity', 'drywall-toolbox' ),
			'resolution' => __( 'Treat this source row as schematic navigation/reference data. Do not create or link a WooCommerce product unless the source is corrected to a real manufacturer part identity.', 'drywall-toolbox' ),
			'candidates' => [],
		];
	}

	// Weak legacy numeric values are commonly diagram callout numbers. They
	// must not be promoted to MPN/product identity merely because they exist.
	$strong_sku = dtb_schematic_normalized_sku_is_strong( $sku );
	if ( ! $strong_sku ) {
		return [
			'code'       => 'source_identifier_gap',
			'label'      => __( 'Source identifier is missing or too weak', 'drywall-toolbox' ),
			'resolution' => __( 'Verify the manufacturer/source part identity. A diagram callout number alone is not a safe SKU/MPN mapping key.', 'drywall-toolbox' ),
			'candidates' => [],
		];
	}

	$candidates = dtb_schematic_hotspot_review_candidates( $record, $source_part );
	$normalized_sku = dtb_schematic_hotspot_optimizer_normalize_identifier( $sku );
	foreach ( $candidates as $candidate ) {
		$candidate_sku = trim( (string) ( $candidate['sku'] ?? '' ) );
		if ( '' !== $candidate_sku
			&& 0 !== strcasecmp( $sku, $candidate_sku )
			&& $normalized_sku === dtb_schematic_hotspot_optimizer_normalize_identifier( $candidate_sku ) ) {
			return [
				'code'       => 'sku_format_ambiguous',
				'label'      => __( 'SKU formatting evidence is ambiguous', 'drywall-toolbox' ),
				'resolution' => __( 'A formatting-similar product exists but did not satisfy the unique same-brand automatic resolver. Inspect the candidate identities before making an explicit mapping.', 'drywall-toolbox' ),
				'candidates' => array_slice( $candidates, 0, 3 ),
			];
		}
	}

	if ( ! empty( $candidates ) ) {
		return [
			'code'       => 'operator_review_candidate',
			'label'      => __( 'Review evidence available', 'drywall-toolbox' ),
			'resolution' => __( 'The evidence is insufficient for an automatic mapping. Compare source SKU, brand and product identity; explicitly link only after verification.', 'drywall-toolbox' ),
			'candidates' => array_slice( $candidates, 0, 3 ),
		];
	}

	return [
		'code'       => 'catalog_product_missing_or_identifier_mismatch',
		'label'      => __( 'Catalog identity gap', 'drywall-toolbox' ),
		'resolution' => __( 'The source provides a strong SKU but no deterministic WooCommerce identity exists. Verify catalog completeness and protected product metadata, then rerun Preview.', 'drywall-toolbox' ),
		'candidates' => [],
	];
}

/** True for obvious schematic-detail navigation rows rather than sellable parts. */
function dtb_schematic_hotspot_optimizer_is_reference_only( string $sku, string $title ): bool {
	$value = strtoupper( trim( $sku . ' ' . $title ) );
	return (bool) preg_match( '/\bSEE[- _]?[A-Z0-9 -]*DETAIL\b/', $value );
}

/** Normalize identifier punctuation only for diagnostic comparison, never writes. */
function dtb_schematic_hotspot_optimizer_normalize_identifier( string $value ): string {
	return function_exists( 'dtb_schematic_normalize_product_identifier' )
		? dtb_schematic_normalize_product_identifier( $value )
		: ( preg_replace( '/[^a-z0-9]+/', '', strtolower( trim( $value ) ) ) ?: '' );
}

/** Aggregate repeated unresolved identities across schematics into one work item. */
function dtb_schematic_hotspot_optimizer_add_group( array &$groups, DTB_Schematic_Record_Entity $record, array $source_part, array $classification, int $occurrences ): void {
	$brand    = sanitize_text_field( (string) ( $record->brand_name ?: $record->brand_id ) );
	$sku      = sanitize_text_field( (string) ( $source_part['sku'] ?? '' ) );
	$mpn      = sanitize_text_field( (string) ( $source_part['display_id'] ?? '' ) );
	$part_ref = sanitize_text_field( (string) ( $source_part['part_ref'] ?? '' ) );
	$title    = sanitize_text_field( (string) ( $source_part['name'] ?? '' ) );
	$identity = '' !== $sku ? $sku : ( '' !== $mpn ? $mpn : ( '' !== $title ? $title : $part_ref ) );
	$key      = sanitize_key( $brand . '-' . dtb_schematic_hotspot_optimizer_normalize_identifier( $identity ) . '-' . (string) ( $classification['code'] ?? '' ) );
	if ( '' === $key ) {
		$key = 'unclassified-' . $record->id . '-' . count( $groups );
	}

	if ( ! isset( $groups[ $key ] ) ) {
		$groups[ $key ] = [
			'group_key'          => $key,
			'issue_code'         => sanitize_key( (string) ( $classification['code'] ?? 'unclassified' ) ),
			'issue_label'        => sanitize_text_field( (string) ( $classification['label'] ?? __( 'Unclassified', 'drywall-toolbox' ) ) ),
			'recommended_fix'    => sanitize_text_field( (string) ( $classification['resolution'] ?? '' ) ),
			'brand'              => $brand,
			'sku'                => $sku,
			'mpn'                => $mpn,
			'part_ref'           => $part_ref,
			'title'              => $title,
			'relationship_count' => 0,
			'occurrences'        => 0,
			'schematics'         => [],
			'candidates'         => [],
		];
		foreach ( array_slice( (array) ( $classification['candidates'] ?? [] ), 0, 3 ) as $candidate ) {
			$groups[ $key ]['candidates'][] = [
				'id'      => (int) ( $candidate['id'] ?? 0 ),
				'name'    => sanitize_text_field( (string) ( $candidate['name'] ?? '' ) ),
				'sku'     => sanitize_text_field( (string) ( $candidate['sku'] ?? '' ) ),
				'mpn'     => sanitize_text_field( (string) ( $candidate['mpn'] ?? '' ) ),
				'brand'   => sanitize_text_field( (string) ( $candidate['brand'] ?? '' ) ),
				'status'  => sanitize_key( (string) ( $candidate['status'] ?? '' ) ),
				'type'    => sanitize_key( (string) ( $candidate['type'] ?? '' ) ),
			];
		}
	}

	$groups[ $key ]['relationship_count']++;
	$groups[ $key ]['occurrences'] += max( 0, $occurrences );
	$schematic_label = sanitize_text_field( $record->canonical_id );
	if ( ! in_array( $schematic_label, $groups[ $key ]['schematics'], true ) && count( $groups[ $key ]['schematics'] ) < 20 ) {
		$groups[ $key ]['schematics'][] = $schematic_label;
	}
}

/** Stable approval fingerprint for the material Preview plan. */
function dtb_schematic_hotspot_optimizer_plan_fingerprint( array $report ): string {
	$repairs = [];
	foreach ( (array) ( $report['repairs'] ?? [] ) as $repair ) {
		$repairs[] = [
			'canonical_id' => (string) ( $repair['canonical_id'] ?? '' ),
			'part_ref'     => (string) ( $repair['part_ref'] ?? '' ),
			'product_id'   => (int) ( $repair['product_id'] ?? 0 ),
			'method'       => (string) ( $repair['resolution_method'] ?? '' ),
		];
	}
	usort( $repairs, static fn( $a, $b ) => strcmp( implode( '|', $a ), implode( '|', $b ) ) );

	$material = [
		'repairs'             => $repairs,
		'source_drift_before' => (int) ( $report['metrics']['source_drift_before'] ?? 0 ),
		'source_read_errors'  => (int) ( $report['metrics']['source_read_errors'] ?? 0 ),
		'source_unavailable'  => (int) ( $report['metrics']['source_unavailable'] ?? 0 ),
		'failed'              => (int) ( $report['failed'] ?? 0 ),
	];
	return hash( 'sha256', (string) wp_json_encode( $material ) );
}
