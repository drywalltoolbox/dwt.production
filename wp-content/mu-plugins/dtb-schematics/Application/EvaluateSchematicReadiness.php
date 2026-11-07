<?php
/**
 * Runtime readiness evaluation for public schematic projections.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return runtime requirements that remain unmet for a schematic record.
 *
 * Publication readiness is intentionally limited to the data required to
 * render a truthful schematic projection. Exact WooCommerce part/product
 * resolution is a separate catalog-completeness concern: unresolved parts
 * remain visible to operators and are projected without a commerce target,
 * but they do not prevent an otherwise valid diagram/hotspot projection from
 * reaching the storefront.
 */
function dtb_schematic_runtime_publication_requirements( DTB_Schematic_Record_Entity $record ): array {
	$unmet = dtb_schematic_publication_requirements( $record->to_array() );

	if ( '' === trim( $record->brand_name ) ) {
		$unmet[] = 'brand_name_required';
	}
	if ( '' === trim( $record->category_name ) ) {
		$unmet[] = 'category_name_required';
	}

	foreach ( $record->pages as $page ) {
		$page_id       = sanitize_key( (string) ( $page['page_id'] ?? '' ) );
		$attachment_id = (int) ( $page['attachment_id'] ?? 0 );
		$described     = $attachment_id > 0 ? dtb_schematic_attachment_repo_describe( $attachment_id ) : null;
		if ( ! $described || empty( $described['exists'] ) || '' === trim( (string) ( $described['url'] ?? '' ) ) ) {
			$unmet[] = 'page_attachment_unavailable:' . ( $page_id ?: 'unknown' );
		}
	}

	$expected_hotspot_sources = DTB_SCHEMATIC_HOTSPOT_SOURCE_MAP[ $record->canonical_id ] ?? [];
	if ( ! empty( $expected_hotspot_sources ) ) {
		$dataset = dtb_schematic_hotspot_dataset_repo_get( $record->id );
		if ( ! $dataset || '' === trim( (string) ( $dataset['checksum'] ?? '' ) ) ) {
			$unmet[] = 'normalized_hotspot_dataset_required';
		}
	}

	return array_values( array_unique( $unmet ) );
}

/** Move an unhealthy non-retired record to incomplete, including published records. */
function dtb_schematic_mark_incomplete( int $schematic_id, array $unmet = [] ) {
	$record = dtb_schematic_record_repo_get( $schematic_id );
	if ( ! $record ) {
		return new WP_Error( 'dtb_schematic_not_found', __( 'Schematic record not found.', 'drywall-toolbox' ) );
	}
	if ( $record->lifecycle->is_retired() || DTB_Schematic_Lifecycle_Status::INCOMPLETE === $record->lifecycle->value() ) {
		return $record;
	}
	if ( ! DTB_Schematic_Lifecycle_Status::can_transition( $record->lifecycle->value(), DTB_Schematic_Lifecycle_Status::INCOMPLETE ) ) {
		return new WP_Error( 'dtb_schematic_invalid_transition', __( 'This schematic cannot move to incomplete from its current lifecycle state.', 'drywall-toolbox' ) );
	}

	$updated = dtb_schematic_update( $schematic_id, [ 'lifecycle' => DTB_Schematic_Lifecycle_Status::INCOMPLETE ] );
	if ( ! is_wp_error( $updated ) && ! empty( $unmet ) && function_exists( 'dtb_schematic_activity_log' ) ) {
		dtb_schematic_activity_log(
			[
				'operation_type' => 'readiness_failed',
				'dry_run'        => false,
				'result'         => 'partial',
				'examined'       => 1,
				'changed'        => 1,
				'unresolved'     => count( $unmet ),
				'summary'        => sprintf( 'Schematic %s moved to incomplete after readiness evaluation.', $record->canonical_id ),
				'detail'         => [ 'schematic_id' => $record->canonical_id, 'requirements' => array_slice( $unmet, 0, 50 ) ],
			]
		);
	}
	return $updated;
}
