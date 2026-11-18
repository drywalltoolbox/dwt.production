<?php
/**
 * Application — RollbackService: activate a prior immutable revision as the
 * published state without erasing any intervening history.
 *
 * A rollback creates a new revision row (payload copied verbatim from the
 * target), tagged with `rollback_origin_id`, and repoints the publish
 * pointer at it. The revision being rolled back FROM remains in history.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * @param string $draft_key
 * @param int    $target_revision_id Revision to restore.
 * @param int    $author_id
 * @param string $note
 * @return array{success:bool, revision:array|null, error:string|null}
 */
function dtb_vd_rollback_to_revision( string $draft_key, int $target_revision_id, int $author_id, string $note = '' ): array {
	$target = dtb_vd_get_revision( $target_revision_id );

	if ( ! $target || $target['draft_key'] !== $draft_key ) {
		return [ 'success' => false, 'revision' => null, 'error' => 'revision_not_found' ];
	}

	$current_published_id = dtb_vd_get_published_revision_id( $draft_key );

	$revision = dtb_vd_create_revision( [
		'draft_key'                  => $draft_key,
		'payload'                    => $target['payload'],
		'author_id'                  => $author_id,
		'note'                       => $note ?: "Rollback to revision #{$target_revision_id}",
		'parent_revision_id'         => $current_published_id,
		'rollback_origin_id'         => $target_revision_id,
		'source_draft_revision_seq' => null,
	] );

	dtb_vd_mark_revision_published( $revision['id'] );
	dtb_vd_set_published_pointer( $draft_key, $revision['id'] );
	dtb_vd_invalidate_published_cache( $draft_key );

	dtb_vd_audit( 'design.revision.rollback', [
		'draft_key'         => $draft_key,
		'new_revision_id'   => $revision['id'],
		'restored_from_id'  => $target_revision_id,
		'previous_id'       => $current_published_id,
	] );

	return [ 'success' => true, 'revision' => $revision, 'error' => null ];
}
