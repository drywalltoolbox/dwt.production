<?php
/**
 * Application — PublishService: atomic promotion of a draft to a new
 * immutable published revision.
 *
 * Publish is idempotent for the same source draft state (re-publishing an
 * unchanged draft against an unchanged pointer is a harmless no-op check via
 * revision_seq) and rejects stale drafts through the same optimistic
 * concurrency token used by draft edits.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Publish the current draft for a scope.
 *
 * @param string $draft_key
 * @param int    $expected_revision_seq Caller's known draft revision_seq.
 * @param int    $author_id
 * @param string $note
 * @return array{success:bool, revision:array|null, conflict:bool}
 */
function dtb_vd_publish_draft( string $draft_key, int $expected_revision_seq, int $author_id, string $note = '' ): array {
	$draft = dtb_vd_get_or_create_draft( $draft_key );

	if ( $draft['revision_seq'] !== $expected_revision_seq ) {
		return [ 'success' => false, 'revision' => null, 'conflict' => true ];
	}

	$parent_revision_id = dtb_vd_get_published_revision_id( $draft_key );

	$revision = dtb_vd_create_revision( [
		'draft_key'                  => $draft_key,
		'payload'                    => $draft['payload'],
		'author_id'                  => $author_id,
		'note'                       => $note,
		'parent_revision_id'         => $parent_revision_id,
		'rollback_origin_id'         => null,
		'source_draft_revision_seq' => $draft['revision_seq'],
	] );

	dtb_vd_mark_revision_published( $revision['id'] );
	dtb_vd_set_published_pointer( $draft_key, $revision['id'] );
	dtb_vd_invalidate_published_cache( $draft_key );

	// Draft now matches published state; base_revision_id tracks it and the
	// dirty flag clears, but the working payload is left untouched — the
	// operator keeps editing on top of what they just published.
	global $wpdb;
	$wpdb->update(
		$wpdb->prefix . 'dtb_design_drafts',
		[ 'base_revision_id' => $revision['id'], 'dirty' => 0 ],
		[ 'draft_key' => $draft_key ],
		[ '%d', '%d' ],
		[ '%s' ]
	);

	dtb_vd_audit( 'design.revision.published', [
		'draft_key'   => $draft_key,
		'revision_id' => $revision['id'],
		'parent_id'   => $parent_revision_id,
		'hash'        => $revision['payload_hash'],
	] );

	return [ 'success' => true, 'revision' => $revision, 'conflict' => false ];
}

/**
 * Invalidate any cache layer sitting in front of the public resolved-config
 * endpoint. Uses a monotonic cache-buster option read by
 * Rest/PublishedConfigController.php rather than a fragile object-cache
 * flush, so it works whether or not a persistent object cache is active.
 *
 * @param string $draft_key
 */
function dtb_vd_invalidate_published_cache( string $draft_key ): void {
	update_option( 'dtb_vd_cache_bust_' . $draft_key, (string) microtime( true ), false );
}
