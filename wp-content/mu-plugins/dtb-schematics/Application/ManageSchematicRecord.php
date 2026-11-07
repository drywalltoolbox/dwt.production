<?php
/**
 * DTB Schematics — ManageSchematicRecord (application service).
 *
 * All writes to the authoritative `dtb_schematic` domain record must go
 * through this file. Nothing outside this file (and the repository it
 * wraps) is allowed to call `dtb_schematic_record_repo_save()` directly.
 *
 * This service intentionally does not know about REST requests, wp-admin
 * screens, or the reconciliation/migration pipeline (Phase 3) — it exposes
 * plain PHP operations that those layers call into.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create a new schematic record. Starts in the `draft` lifecycle state
 * unless an explicit (valid) lifecycle is supplied.
 *
 * @param array $data See DTB_Schematic_Record_Entity::to_array() shape (id ignored).
 * @return DTB_Schematic_Record_Entity|WP_Error
 */
function dtb_schematic_create( array $data ) {
	unset( $data['id'] );

	$result = dtb_schematic_record_repo_save( $data );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	dtb_schematics_invalidate_domain_cache( (int) $result );

	return dtb_schematic_record_repo_get( (int) $result );
}

/**
 * Update fields on an existing schematic record. The canonical ID cannot be
 * changed through this call (the repository enforces immutability).
 *
 * @return DTB_Schematic_Record_Entity|WP_Error
 */
function dtb_schematic_update( int $schematic_id, array $data ) {
	$data['id'] = $schematic_id;

	$result = dtb_schematic_record_repo_save( $data );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	dtb_schematics_invalidate_domain_cache( $schematic_id );

	return dtb_schematic_record_repo_get( $schematic_id );
}

/**
 * Attach (or replace) a page by its stable page_id. Attachment metadata
 * (width/height/media type/responsive sources) is refreshed from the
 * WordPress attachment repository so callers only need to supply identity
 * and ordering fields.
 *
 * @param array $page_data See dtb_schematic_page_make() shape. `page_id` is required.
 * @return DTB_Schematic_Record_Entity|WP_Error
 */
function dtb_schematic_attach_page( int $schematic_id, array $page_data ) {
	$record = dtb_schematic_record_repo_get( $schematic_id );
	if ( ! $record ) {
		return new WP_Error( 'dtb_schematic_not_found', __( 'Schematic record not found.', 'drywall-toolbox' ) );
	}

	$page_id = sanitize_key( (string) ( $page_data['page_id'] ?? '' ) );
	if ( '' === $page_id ) {
		return new WP_Error( 'dtb_schematic_page_id_required', __( 'A stable page ID is required to attach a page.', 'drywall-toolbox' ) );
	}

	$attachment_id = max( 0, (int) ( $page_data['attachment_id'] ?? 0 ) );
	$described     = $attachment_id > 0 ? dtb_schematic_attachment_repo_describe( $attachment_id ) : null;

	if ( $described && $described['exists'] ) {
		$page_data['media_type'] = $described['media_type'];
		$page_data['width']      = $described['width'];
		$page_data['height']     = $described['height'];
		$page_data['sources']    = $described['sources'];
		$page_data['lifecycle_state'] = DTB_SCHEMATIC_PAGE_STATE_ATTACHED;
	} else {
		$page_data['attachment_id']   = 0;
		$page_data['lifecycle_state'] = DTB_SCHEMATIC_PAGE_STATE_MISSING_ASSET;
	}

	$pages   = $record->pages;
	$replaced = false;
	foreach ( $pages as $index => $existing_page ) {
		if ( $existing_page['page_id'] === $page_id ) {
			$pages[ $index ] = dtb_schematic_page_make( array_merge( $existing_page, $page_data ) );
			$replaced = true;
			break;
		}
	}
	if ( ! $replaced ) {
		$pages[] = dtb_schematic_page_make( $page_data );
	}

	return dtb_schematic_update( $schematic_id, [ 'pages' => $pages ] );
}

/**
 * Replace only the attachment behind an existing page, preserving its
 * page number, label, and hotspot dataset relationship.
 *
 * @return DTB_Schematic_Record_Entity|WP_Error
 */
function dtb_schematic_replace_page_attachment( int $schematic_id, string $page_id, int $new_attachment_id, string $source_checksum = '', string $source_filename = '' ) {
	$page_id = sanitize_key( $page_id );

	return dtb_schematic_attach_page(
		$schematic_id,
		[
			'page_id'         => $page_id,
			'attachment_id'   => $new_attachment_id,
			'source_checksum' => $source_checksum,
			'source_filename' => $source_filename,
		]
	);
}

/**
 * Detach a page's attachment without removing the page definition itself
 * (the page reverts to a missing-asset lifecycle state).
 *
 * @return DTB_Schematic_Record_Entity|WP_Error
 */
function dtb_schematic_detach_page( int $schematic_id, string $page_id ) {
	$record = dtb_schematic_record_repo_get( $schematic_id );
	if ( ! $record ) {
		return new WP_Error( 'dtb_schematic_not_found', __( 'Schematic record not found.', 'drywall-toolbox' ) );
	}

	$page_id = sanitize_key( $page_id );
	$pages   = $record->pages;
	foreach ( $pages as $index => $existing_page ) {
		if ( $existing_page['page_id'] === $page_id ) {
			$pages[ $index ]['attachment_id']   = 0;
			$pages[ $index ]['lifecycle_state'] = DTB_SCHEMATIC_PAGE_STATE_MISSING_ASSET;
			$pages[ $index ]['sources']         = [];
			$pages[ $index ]['width']           = 0;
			$pages[ $index ]['height']          = 0;
		}
	}

	return dtb_schematic_update( $schematic_id, [ 'pages' => $pages ] );
}

/**
 * Reorder pages according to an explicit ordered list of page IDs.
 * Page IDs not present in the list keep their relative order and are
 * appended after the explicitly ordered ones.
 *
 * @param string[] $ordered_page_ids
 * @return DTB_Schematic_Record_Entity|WP_Error
 */
function dtb_schematic_reorder_pages( int $schematic_id, array $ordered_page_ids ) {
	$record = dtb_schematic_record_repo_get( $schematic_id );
	if ( ! $record ) {
		return new WP_Error( 'dtb_schematic_not_found', __( 'Schematic record not found.', 'drywall-toolbox' ) );
	}

	$ordered_page_ids = array_values( array_map( 'sanitize_key', $ordered_page_ids ) );
	$by_id = [];
	foreach ( $record->pages as $page ) {
		$by_id[ $page['page_id'] ] = $page;
	}

	$new_pages   = [];
	$page_number = 1;
	foreach ( $ordered_page_ids as $page_id ) {
		if ( isset( $by_id[ $page_id ] ) ) {
			$page                 = $by_id[ $page_id ];
			$page['page_number']  = $page_number++;
			$new_pages[]          = $page;
			unset( $by_id[ $page_id ] );
		}
	}
	foreach ( $by_id as $remaining ) {
		$remaining['page_number'] = $page_number++;
		$new_pages[]              = $remaining;
	}

	return dtb_schematic_update( $schematic_id, [ 'pages' => $new_pages ] );
}

/**
 * Associate a hotspot dataset relationship with the schematic (default) or
 * a specific page. Does not read or store hotspot coordinate data itself —
 * only the relationship reference (see dtb_schematic_hotspot_dataset_ref_make()).
 *
 * @return DTB_Schematic_Record_Entity|WP_Error
 */
function dtb_schematic_associate_hotspot_dataset( int $schematic_id, array $dataset_ref, ?string $page_id = null ) {
	if ( null === $page_id ) {
		return dtb_schematic_update( $schematic_id, [ 'hotspot_dataset' => $dataset_ref ] );
	}

	$record = dtb_schematic_record_repo_get( $schematic_id );
	if ( ! $record ) {
		return new WP_Error( 'dtb_schematic_not_found', __( 'Schematic record not found.', 'drywall-toolbox' ) );
	}

	$page_id = sanitize_key( $page_id );
	$pages   = $record->pages;
	foreach ( $pages as $index => $existing_page ) {
		if ( $existing_page['page_id'] === $page_id ) {
			$pages[ $index ]['hotspot_dataset'] = dtb_schematic_hotspot_dataset_ref_make( $dataset_ref );
		}
	}

	return dtb_schematic_update( $schematic_id, [ 'pages' => $pages ] );
}

/**
 * Move a record to `ready` once it meets every publication requirement.
 * dtb_schematic_publish() only permits the ready -> published transition
 * (see DTB_Schematic_Lifecycle_Status::allowed_transitions()); nothing
 * elsewhere in this codebase performs the draft/incomplete -> ready step, so
 * without this a record could satisfy every requirement and still be
 * permanently unpublishable. Idempotent: already-ready/published records are
 * returned unchanged.
 *
 * @return DTB_Schematic_Record_Entity|WP_Error
 */
function dtb_schematic_mark_ready( int $schematic_id ) {
	$record = dtb_schematic_record_repo_get( $schematic_id );
	if ( ! $record ) {
		return new WP_Error( 'dtb_schematic_not_found', __( 'Schematic record not found.', 'drywall-toolbox' ) );
	}

	if ( DTB_Schematic_Lifecycle_Status::READY === $record->lifecycle->value() || $record->lifecycle->is_published() ) {
		return $record;
	}

	if ( ! DTB_Schematic_Lifecycle_Status::can_transition( $record->lifecycle->value(), DTB_Schematic_Lifecycle_Status::READY ) ) {
		return new WP_Error( 'dtb_schematic_invalid_transition', __( 'This schematic cannot move to ready from its current lifecycle state.', 'drywall-toolbox' ) );
	}

	$unmet = dtb_schematic_runtime_publication_requirements( $record );
	if ( ! empty( $unmet ) ) {
		return new WP_Error(
			'dtb_schematic_not_publication_eligible',
			__( 'This schematic does not meet the requirements to become ready.', 'drywall-toolbox' ),
			[ 'unmet_requirements' => $unmet ]
		);
	}

	return dtb_schematic_update( $schematic_id, [ 'lifecycle' => DTB_Schematic_Lifecycle_Status::READY ] );
}

/**
 * Publish a schematic. Fails with a WP_Error listing unmet requirements if
 * the record is not publication-eligible, or if the current lifecycle state
 * does not permit the transition.
 *
 * @return DTB_Schematic_Record_Entity|WP_Error
 */
function dtb_schematic_publish( int $schematic_id ) {
	$record = dtb_schematic_record_repo_get( $schematic_id );
	if ( ! $record ) {
		return new WP_Error( 'dtb_schematic_not_found', __( 'Schematic record not found.', 'drywall-toolbox' ) );
	}

	if ( ! DTB_Schematic_Lifecycle_Status::can_transition( $record->lifecycle->value(), DTB_Schematic_Lifecycle_Status::PUBLISHED ) ) {
		return new WP_Error( 'dtb_schematic_invalid_transition', __( 'This schematic cannot be published from its current lifecycle state.', 'drywall-toolbox' ) );
	}

	$unmet = dtb_schematic_runtime_publication_requirements( $record );
	if ( ! empty( $unmet ) ) {
		return new WP_Error(
			'dtb_schematic_not_publication_eligible',
			__( 'This schematic does not meet the requirements for publication.', 'drywall-toolbox' ),
			[ 'unmet_requirements' => $unmet ]
		);
	}

	return dtb_schematic_update(
		$schematic_id,
		[
			'lifecycle'           => DTB_Schematic_Lifecycle_Status::PUBLISHED,
			'publication_version' => $record->publication_version + 1,
		]
	);
}

/**
 * Re-run publication after in-place changes to an already-published
 * schematic (e.g. a page attachment was replaced). Bumps the publication
 * version so downstream caches/consumers can detect the change.
 *
 * @return DTB_Schematic_Record_Entity|WP_Error
 */
function dtb_schematic_update_published_projection( int $schematic_id ) {
	$record = dtb_schematic_record_repo_get( $schematic_id );
	if ( ! $record ) {
		return new WP_Error( 'dtb_schematic_not_found', __( 'Schematic record not found.', 'drywall-toolbox' ) );
	}

	if ( ! $record->lifecycle->is_published() ) {
		return new WP_Error( 'dtb_schematic_not_published', __( 'This schematic is not currently published.', 'drywall-toolbox' ) );
	}

	$unmet = dtb_schematic_runtime_publication_requirements( $record );
	if ( ! empty( $unmet ) ) {
		return new WP_Error(
			'dtb_schematic_not_publication_eligible',
			__( 'This schematic no longer meets the requirements for publication.', 'drywall-toolbox' ),
			[ 'unmet_requirements' => $unmet ]
		);
	}

	return dtb_schematic_update( $schematic_id, [ 'publication_version' => $record->publication_version + 1 ] );
}

/**
 * Retire a schematic. Preserves the administrative record, history, and
 * source provenance — only removes it from public catalog projections
 * (enforced by the application-layer response builder / future REST layer,
 * which must filter on lifecycle === published).
 *
 * @return DTB_Schematic_Record_Entity|WP_Error
 */
function dtb_schematic_retire( int $schematic_id ) {
	$record = dtb_schematic_record_repo_get( $schematic_id );
	if ( ! $record ) {
		return new WP_Error( 'dtb_schematic_not_found', __( 'Schematic record not found.', 'drywall-toolbox' ) );
	}

	if ( ! DTB_Schematic_Lifecycle_Status::can_transition( $record->lifecycle->value(), DTB_Schematic_Lifecycle_Status::RETIRED ) ) {
		return new WP_Error( 'dtb_schematic_invalid_transition', __( 'This schematic cannot be retired from its current lifecycle state.', 'drywall-toolbox' ) );
	}

	return dtb_schematic_update( $schematic_id, [ 'lifecycle' => DTB_Schematic_Lifecycle_Status::RETIRED ] );
}

/**
 * Invalidate schematic-domain caches for a record by bumping the public
 * catalog/publication version consumed by Rest/SchematicPublicApiController.php.
 */
function dtb_schematics_invalidate_domain_cache( int $schematic_id = 0 ): void {
	unset( $schematic_id ); // Reserved for a per-record cache key if a per-schematic cache is introduced later.
	dtb_schematics_bump_public_catalog_version();
}
