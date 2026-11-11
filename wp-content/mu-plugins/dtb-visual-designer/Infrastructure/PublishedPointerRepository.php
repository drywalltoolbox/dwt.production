<?php
/**
 * Infrastructure — PublishedPointerRepository: wp_dtb_design_published.
 *
 * A bounded, single-row-per-scope pointer table. Publishing atomically
 * repoints this row at a new immutable revision; the previous revision is
 * left untouched in wp_dtb_design_revisions for rollback/history.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Atomically set the published pointer for a draft scope.
 *
 * @param string $draft_key
 * @param int    $revision_id
 */
function dtb_vd_set_published_pointer( string $draft_key, int $revision_id ): void {
	global $wpdb;
	$table = $wpdb->prefix . 'dtb_design_published';
	$now   = current_time( 'mysql', true );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$table} (draft_key, revision_id, updated_at) VALUES (%s, %d, %s)
			 ON DUPLICATE KEY UPDATE revision_id = VALUES(revision_id), updated_at = VALUES(updated_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$draft_key,
			$revision_id,
			$now
		)
	);
}

/**
 * The revision id currently published for a scope, or null if never published.
 *
 * @param string $draft_key
 * @return int|null
 */
function dtb_vd_get_published_revision_id( string $draft_key = DTB_VD_DEFAULT_DRAFT_KEY ): ?int {
	global $wpdb;
	$table = $wpdb->prefix . 'dtb_design_published';
	$id    = $wpdb->get_var(
		$wpdb->prepare( "SELECT revision_id FROM {$table} WHERE draft_key = %s", $draft_key ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);
	return $id ? (int) $id : null;
}

/**
 * The full currently-published revision for a scope, or a safe empty payload
 * if nothing has ever been published (repository defaults apply).
 *
 * @param string $draft_key
 * @return array
 */
function dtb_vd_get_published_revision( string $draft_key = DTB_VD_DEFAULT_DRAFT_KEY ): array {
	$revision_id = dtb_vd_get_published_revision_id( $draft_key );
	if ( ! $revision_id ) {
		return [ 'id' => null, 'payload' => [ 'tokens' => [], 'surfaces' => [] ], 'payload_hash' => null ];
	}

	$revision = dtb_vd_get_revision( $revision_id );
	return $revision ?? [ 'id' => null, 'payload' => [ 'tokens' => [], 'surfaces' => [] ], 'payload_hash' => null ];
}
