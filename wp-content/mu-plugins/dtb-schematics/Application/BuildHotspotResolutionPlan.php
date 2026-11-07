<?php
/**
 * DTB Schematics — consolidated hotspot resolution plan.
 *
 * Converts the authoritative optimizer result into one complete,
 * operator-reviewable plan. The plan classifies every unresolved identity into
 * one terminal disposition, generates bounded correction artifacts, and never
 * mutates WooCommerce or repository-owned source/catalog identifiers.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_SCHEMATIC_HOTSPOT_PLAN_SCHEMA_VERSION = 3;

/** Build the complete operator plan from an optimizer result. */
function dtb_schematic_hotspot_build_resolution_plan( array $result ): array {
	$metrics        = (array) ( $result['metrics'] ?? [] );
	$groups         = array_values( (array) ( $result['resolution_groups'] ?? [] ) );
	$repairs        = array_values( (array) ( $result['repairs'] ?? [] ) );
	$record_results = array_values( (array) ( $result['results'] ?? [] ) );

	$artifacts = [
		'catalog_corrections' => [],
		'source_corrections'  => [],
		'manual_review'       => [],
	];
	$disposition_counts = [];
	$normalized_groups  = [];

	foreach ( $groups as $group ) {
		$normalized = dtb_schematic_hotspot_plan_normalize_group( $group );
		$normalized_groups[] = $normalized;
		$disposition = sanitize_key( (string) ( $normalized['disposition'] ?? 'manual_review_required' ) );
		$relationships = max( 1, (int) ( $normalized['relationship_count'] ?? 1 ) );
		$disposition_counts[ $disposition ] = (int) ( $disposition_counts[ $disposition ] ?? 0 ) + $relationships;

		$row = dtb_schematic_hotspot_plan_artifact_row( $normalized );
		$bucket = (string) ( $normalized['artifact_bucket'] ?? 'manual_review' );
		if ( ! isset( $artifacts[ $bucket ] ) ) {
			$bucket = 'manual_review';
		}
		$artifacts[ $bucket ][] = $row;
	}
	ksort( $disposition_counts );

	$source_partial_records = 0;
	$source_invalid_records = 0;
	foreach ( $record_results as $record_result ) {
		$source_status = sanitize_key( (string) ( $record_result['source_status'] ?? '' ) );
		if ( 'partial' === $source_status ) {
			$source_partial_records++;
		} elseif ( 'invalid' === $source_status ) {
			$source_invalid_records++;
		}
	}

	$blockers = [];
	if ( ! empty( $result['fatal_error'] ) ) {
		$blockers[] = [ 'code' => 'fatal_error', 'message' => sanitize_text_field( (string) $result['fatal_error'] ) ];
	}
	if ( (int) ( $result['failed'] ?? 0 ) > 0 ) {
		$blockers[] = [ 'code' => 'failed_records', 'message' => sprintf( '%d schematic record(s) failed analysis.', (int) $result['failed'] ) ];
	}
	if ( $source_partial_records > 0 ) {
		$blockers[] = [
			'code'    => 'partial_source_reads',
			'message' => sprintf( '%d schematic source group(s) were only partially readable. Apply is blocked because an incomplete multi-file source cannot be treated as authoritative.', $source_partial_records ),
		];
	}
	if ( $source_invalid_records > 0 ) {
		$blockers[] = [
			'code'    => 'invalid_source_integrity',
			'message' => sprintf( '%d schematic source group(s) contain dangling, invalid, duplicate, or page-mismatched hotspots. Correct source integrity before Apply.', $source_invalid_records ),
		];
	}
	if ( ! empty( $result['groups_truncated'] ) || ! empty( $result['repairs_truncated'] ) ) {
		$blockers[] = [ 'code' => 'truncated_plan', 'message' => 'The generated plan exceeded a bounded reporting limit and must not be approved.' ];
	}

	$projected = (int) ( $metrics['projected_repairs'] ?? $metrics['projected_exact_repairs'] ?? 0 );
	if ( ! empty( $result['dry_run'] ) && count( $repairs ) !== $projected ) {
		$blockers[] = [
			'code'    => 'mapping_plan_count_mismatch',
			'message' => sprintf( 'The optimizer projected %1$d deterministic mapping mutation(s), but the explicit mapping plan contains %2$d.', $projected, count( $repairs ) ),
		];
	}

	$fingerprint = dtb_schematic_hotspot_plan_material_fingerprint( $result, $repairs, $normalized_groups, $record_results );
	$plan = [
		'schema_version' => DTB_SCHEMATIC_HOTSPOT_PLAN_SCHEMA_VERSION,
		'generated_at'   => gmdate( 'c' ),
		'mode'           => ! empty( $result['dry_run'] ) ? 'pre_apply' : 'post_apply',
		'status'         => empty( $blockers ) ? 'reviewable' : 'blocked',
		'can_apply'      => empty( $blockers ) && $projected > 0 && count( $repairs ) === $projected,
		'fingerprint'    => $fingerprint,
		'summary'        => [
			'schematics_examined'        => (int) ( $result['examined'] ?? 0 ),
			'source_files'               => (int) ( $metrics['source_files'] ?? 0 ),
			'source_parts'               => (int) ( $metrics['source_parts'] ?? 0 ),
			'hotspot_occurrences'        => (int) ( $metrics['source_hotspots'] ?? 0 ),
			'exactly_resolvable_signal'  => (int) ( $metrics['exactly_resolvable'] ?? 0 ),
			'projected_new_mappings'     => $projected,
			'applied_new_mappings'       => (int) ( $metrics['applied_repairs'] ?? $metrics['applied_exact_repairs'] ?? 0 ),
			'active_hotspot_unresolved'  => (int) ( $metrics['active_hotspot_unresolved'] ?? 0 ),
			'catalog_only_unresolved'    => (int) ( $metrics['inactive_catalog_unresolved'] ?? 0 ),
			'resolution_groups'          => (int) ( $metrics['resolution_groups'] ?? count( $groups ) ),
			'source_read_errors'         => (int) ( $metrics['source_read_errors'] ?? 0 ),
			'source_unavailable'         => (int) ( $metrics['source_unavailable'] ?? 0 ),
			'source_partial_records'     => $source_partial_records,
			'source_invalid_records'     => $source_invalid_records,
			'source_drift'               => (int) ( $metrics['source_drift_before'] ?? 0 ),
			'catalog_correction_groups'  => count( $artifacts['catalog_corrections'] ),
			'source_correction_groups'   => count( $artifacts['source_corrections'] ),
			'manual_review_groups'       => count( $artifacts['manual_review'] ),
		],
		'proposed_mappings'  => $repairs,
		'blockers'           => $blockers,
		'reason_counts'      => (array) ( $result['reason_counts'] ?? [] ),
		'disposition_counts' => $disposition_counts,
		'resolution_groups'  => $normalized_groups,
		'artifacts'          => $artifacts,
		'source_errors'      => array_values( (array) ( $result['source_errors'] ?? [] ) ),
		'record_results'     => $record_results,
	];

	return $plan;
}

/** Normalize one unresolved group into an explicit terminal disposition. */
function dtb_schematic_hotspot_plan_normalize_group( array $group ): array {
	$code = sanitize_key( (string) ( $group['issue_code'] ?? 'unclassified' ) );
	$sku  = trim( (string) ( $group['sku'] ?? $group['source_sku'] ?? '' ) );
	$name = trim( (string) ( $group['name'] ?? $group['title'] ?? $group['part_name'] ?? '' ) );

	$group['source_sku']        = $sku;
	$group['source_display_id'] = trim( (string) ( $group['display_id'] ?? $group['source_display_id'] ?? $group['mpn'] ?? '' ) );
	$group['part_name']         = $name;
	$group['candidates']        = array_values( (array) ( $group['candidates'] ?? [] ) );

	if ( 'source_unavailable' === $code ) {
		return dtb_schematic_hotspot_plan_set_disposition( $group, 'source_unavailable', 'source_corrections', 'Restore or associate the approved schematic_data JSON source, then rebuild the resolution plan.' );
	}
	if ( 'source_sync_required' === $code ) {
		return dtb_schematic_hotspot_plan_set_disposition( $group, 'source_projection_sync', 'source_corrections', 'Review the reported source drift. Correct unexpected source state before approval.' );
	}
	if ( 'source_reference_only' === $code || dtb_schematic_hotspot_plan_is_reference_only( $sku, $name ) ) {
		return dtb_schematic_hotspot_plan_set_disposition( $group, 'reference_only', 'source_corrections', 'Keep this row as schematic navigation/reference data. Do not create or link a WooCommerce product.' );
	}
	if ( dtb_schematic_hotspot_plan_is_instruction_or_composite( $sku, $name ) ) {
		return dtb_schematic_hotspot_plan_set_disposition( $group, 'source_instruction_not_product', 'source_corrections', 'Correct the schematic source so the part row contains one manufacturer SKU/part identity. Quantities, assembly instructions, Loctite notes, and equivalence lists must not occupy the SKU field.' );
	}
	if ( in_array( $code, [ 'source_identifier_gap', 'source_sku_missing_or_catalog_gap' ], true ) ) {
		return dtb_schematic_hotspot_plan_set_disposition( $group, 'source_identifier_correction', 'source_corrections', 'Populate or correct the authoritative manufacturer SKU/part identity in the schematic source. A callout/index alone is insufficient for product mapping.' );
	}
	if ( in_array( $code, [ 'sku_format_ambiguous', 'operator_review_candidate', 'strong_review_candidate', 'mpn_brand_mismatch' ], true ) ) {
		return dtb_schematic_hotspot_plan_set_disposition( $group, 'manual_review_required', 'manual_review', 'Review the same-brand product evidence and protected identifiers. Create an explicit relationship only after product identity is verified; do not infer from title similarity alone.' );
	}
	if ( in_array( $code, [ 'catalog_identity_gap', 'catalog_product_missing_or_identifier_mismatch', 'catalog_eligibility_mismatch' ], true ) ) {
		return dtb_schematic_hotspot_plan_set_disposition( $group, 'catalog_identity_correction', 'catalog_corrections', 'Verify that this manufacturer part is intentionally sold. If sold, create/correct the WooCommerce product or variation so its protected SKU, brand and MPN identity exactly match authoritative catalog data, then rebuild the plan. If not sold, record an explicit not-sold disposition.' );
	}

	return dtb_schematic_hotspot_plan_set_disposition( $group, 'manual_review_required', 'manual_review', 'Review this unresolved identity and assign an explicit source, catalog, not-sold, or product-mapping disposition before rebuilding the plan.' );
}

function dtb_schematic_hotspot_plan_set_disposition( array $group, string $disposition, string $bucket, string $action ): array {
	$group['disposition']     = sanitize_key( $disposition );
	$group['artifact_bucket'] = sanitize_key( $bucket );
	$group['required_action'] = sanitize_text_field( $action );
	return $group;
}

/** Obvious cross-reference/navigation rows. */
function dtb_schematic_hotspot_plan_is_reference_only( string $sku, string $name ): bool {
	$value = strtoupper( trim( $sku . ' ' . $name ) );
	return (bool) preg_match( '/\bSEE[- _]?[A-Z0-9 ()\/.-]*DETAIL\b/', $value );
}

/** Detect legacy SKU fields that contain notes/instructions rather than one identifier. */
function dtb_schematic_hotspot_plan_is_instruction_or_composite( string $sku, string $name ): bool {
	$value = strtoupper( trim( $sku ) );
	if ( '' === $value ) {
		return false;
	}
	if ( strlen( $value ) > 48 ) {
		return true;
	}
	if ( preg_match( '/\b(?:LOCTITE|SECURED WITH|INSTALL W|INSTALL WITH|INTERIOR OF|APPLY|W\/LOCTITE|W LOCTITE)\b/', $value ) ) {
		return true;
	}
	if ( preg_match( '/^(?:\d+\s*[X×]\s*)?[A-Z0-9.-]+\s*[X×]\s*\d+\b/', $value ) ) {
		return true;
	}
	if ( substr_count( $value, '=' ) >= 1 || substr_count( $value, ',' ) >= 2 || substr_count( $value, ';' ) >= 1 ) {
		return true;
	}
	if ( preg_match_all( '/\b\d{5,}\b/', $value, $matches ) && count( $matches[0] ) >= 2 ) {
		return true;
	}
	return false;
}

/** Normalize one remediation group for CSV/JSON export. */
function dtb_schematic_hotspot_plan_artifact_row( array $group ): array {
	$schematics = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $group['schematics'] ?? [] ) ) ) );
	$candidates = [];
	foreach ( (array) ( $group['candidates'] ?? [] ) as $candidate ) {
		$product_id = (int) ( $candidate['product_id'] ?? $candidate['id'] ?? 0 );
		$sku = sanitize_text_field( (string) ( $candidate['sku'] ?? '' ) );
		$name = sanitize_text_field( (string) ( $candidate['name'] ?? $candidate['title'] ?? '' ) );
		$brand = sanitize_text_field( (string) ( $candidate['brand'] ?? '' ) );
		if ( $product_id <= 0 && '' === $sku && '' === $name ) {
			continue;
		}
		$candidates[] = implode( ':', [ $product_id, $sku, $brand, $name ] );
	}

	$required_action = trim( (string) ( $group['required_action'] ?? $group['resolution'] ?? $group['required_resolution'] ?? '' ) );
	if ( '' === $required_action ) {
		$required_action = 'Review and explicitly resolve this identity before rebuilding the plan.';
	}

	return [
		'disposition'         => sanitize_key( (string) ( $group['disposition'] ?? 'manual_review_required' ) ),
		'issue_code'          => sanitize_key( (string) ( $group['issue_code'] ?? 'unclassified' ) ),
		'issue_label'         => sanitize_text_field( (string) ( $group['issue_label'] ?? '' ) ),
		'brand'               => sanitize_text_field( (string) ( $group['brand'] ?? '' ) ),
		'part_ref'            => sanitize_text_field( (string) ( $group['part_ref'] ?? '' ) ),
		'source_sku'          => sanitize_text_field( (string) ( $group['source_sku'] ?? $group['sku'] ?? '' ) ),
		'source_display_id'   => sanitize_text_field( (string) ( $group['source_display_id'] ?? $group['display_id'] ?? $group['mpn'] ?? '' ) ),
		'part_name'           => sanitize_text_field( (string) ( $group['part_name'] ?? $group['name'] ?? $group['title'] ?? '' ) ),
		'relationship_count'  => max( 0, (int) ( $group['relationship_count'] ?? 0 ) ),
		'hotspot_occurrences' => max( 0, (int) ( $group['occurrences'] ?? 0 ) ),
		'schematics'          => implode( '|', $schematics ),
		'candidate_evidence'  => implode( '|', $candidates ),
		'required_action'     => sanitize_text_field( $required_action ),
	];
}

/** Fingerprint the material reviewed plan, including source-integrity state. */
function dtb_schematic_hotspot_plan_material_fingerprint( array $result, array $repairs, array $groups, array $record_results ): string {
	$repair_material = [];
	foreach ( $repairs as $repair ) {
		$repair_material[] = [
			'canonical_id' => (string) ( $repair['canonical_id'] ?? '' ),
			'part_ref'     => (string) ( $repair['part_ref'] ?? '' ),
			'product_id'   => (int) ( $repair['product_id'] ?? 0 ),
			'method'       => (string) ( $repair['resolution_method'] ?? '' ),
		];
	}
	usort( $repair_material, static fn( $a, $b ) => strcmp( (string) wp_json_encode( $a ), (string) wp_json_encode( $b ) ) );

	$group_material = [];
	foreach ( $groups as $group ) {
		$group_material[] = [
			'group_key'     => (string) ( $group['group_key'] ?? '' ),
			'disposition'   => (string) ( $group['disposition'] ?? '' ),
			'identity'      => (string) ( $group['source_sku'] ?? '' ) . '|' . (string) ( $group['source_display_id'] ?? '' ),
			'relationships' => (int) ( $group['relationship_count'] ?? 0 ),
			'occurrences'   => (int) ( $group['occurrences'] ?? 0 ),
		];
	}
	usort( $group_material, static fn( $a, $b ) => strcmp( (string) wp_json_encode( $a ), (string) wp_json_encode( $b ) ) );

	$source_material = [];
	foreach ( $record_results as $record_result ) {
		$source_material[] = [
			'canonical_id' => (string) ( $record_result['canonical_id'] ?? '' ),
			'status'       => (string) ( $record_result['source_status'] ?? '' ),
			'drift'        => ! empty( $record_result['source_drift'] ),
		];
	}
	usort( $source_material, static fn( $a, $b ) => strcmp( (string) wp_json_encode( $a ), (string) wp_json_encode( $b ) ) );

	$metrics = (array) ( $result['metrics'] ?? [] );
	$material = [
		'repairs'            => $repair_material,
		'groups'             => $group_material,
		'sources'            => $source_material,
		'source_drift'       => (int) ( $metrics['source_drift_before'] ?? 0 ),
		'source_read_errors' => (int) ( $metrics['source_read_errors'] ?? 0 ),
		'source_unavailable' => (int) ( $metrics['source_unavailable'] ?? 0 ),
		'failed'             => (int) ( $result['failed'] ?? 0 ),
	];
	return hash( 'sha256', (string) wp_json_encode( $material ) );
}

/** Stable export payload for the complete plan. */
function dtb_schematic_hotspot_plan_export_payload( array $run ): array {
	$result = (array) ( $run['result'] ?? [] );
	return [
		'report_type' => ! empty( $run['dry_run'] ) ? 'dtb_hotspot_resolution_pre_apply' : 'dtb_hotspot_resolution_post_apply',
		'run' => [
			'id'         => sanitize_text_field( (string) ( $run['id'] ?? '' ) ),
			'status'     => sanitize_key( (string) ( $run['status'] ?? '' ) ),
			'dry_run'    => ! empty( $run['dry_run'] ),
			'created_at' => sanitize_text_field( (string) ( $run['created_at'] ?? '' ) ),
			'error'      => sanitize_text_field( (string) ( $run['error'] ?? '' ) ),
		],
		'plan'                  => dtb_schematic_hotspot_build_resolution_plan( $result ),
		'raw_optimizer_metrics' => (array) ( $result['metrics'] ?? [] ),
	];
}
