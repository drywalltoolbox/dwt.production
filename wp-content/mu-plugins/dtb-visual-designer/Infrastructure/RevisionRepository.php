<?php
/**
 * Infrastructure — RevisionRepository: immutable wp_dtb_design_revisions rows.
 *
 * Revisions are never updated or deleted. Publishing and rollback both create
 * (or point to) an immutable revision; history is preserved indefinitely so
 * old configurations remain readable even after the surfaces/components they
 * describe change or are removed from the registry (ConfigResolver ignores
 * unknown ids at read time rather than requiring revision rewrites).
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create a new immutable revision row.
 *
 * @param array $args {
 *   draft_key, payload (array), author_id, note, parent_revision_id,
 *   rollback_origin_id, source_draft_revision_seq
 * }
 * @return array Hydrated revision row.
 */
function dtb_vd_create_revision( array $args ): array {
	global $wpdb;
	$table = $wpdb->prefix . 'dtb_design_revisions';

	$payload = $args['payload'] ?? [];
	$encoded = wp_json_encode( $payload );
	$hash    = hash( 'sha256', (string) $encoded );
	$uid     = wp_generate_uuid4();

	$affected_surfaces = array_values( array_unique( array_keys( (array) ( $payload['surfaces'] ?? [] ) ) ) );

	$now = current_time( 'mysql', true );

	$wpdb->insert(
		$table,
		[
			'revision_uid'               => $uid,
			'draft_key'                  => (string) ( $args['draft_key'] ?? DTB_VD_DEFAULT_DRAFT_KEY ),
			'schema_version'             => DTB_VD_SCHEMA_VERSION,
			'parent_revision_id'         => $args['parent_revision_id'] ?? null,
			'rollback_origin_id'         => $args['rollback_origin_id'] ?? null,
			'author_id'                  => (int) ( $args['author_id'] ?? get_current_user_id() ),
			'note'                       => $args['note'] ? substr( (string) $args['note'], 0, DTB_VD_MAX_NOTE_LEN ) : null,
			'payload_json'               => $encoded,
			'payload_hash'               => $hash,
			'affected_surfaces_json'     => wp_json_encode( $affected_surfaces ),
			'source_draft_revision_seq' => $args['source_draft_revision_seq'] ?? null,
			'created_at'                 => $now,
			'published_at'               => null,
		],
		[ '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
	);

	return dtb_vd_get_revision( (int) $wpdb->insert_id );
}

/**
 * Mark a revision as published now (does not touch the pointer table).
 *
 * @param int $revision_id
 */
function dtb_vd_mark_revision_published( int $revision_id ): void {
	global $wpdb;
	$table = $wpdb->prefix . 'dtb_design_revisions';
	$wpdb->update(
		$table,
		[ 'published_at' => current_time( 'mysql', true ) ],
		[ 'id' => $revision_id ],
		[ '%s' ],
		[ '%d' ]
	);
}

/**
 * @param int $revision_id
 * @return array|null
 */
function dtb_vd_get_revision( int $revision_id ): ?array {
	global $wpdb;
	$table = $wpdb->prefix . 'dtb_design_revisions';
	$row   = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $revision_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ARRAY_A
	);
	return $row ? dtb_vd_hydrate_revision_row( $row ) : null;
}

/**
 * @param array $row
 * @return array
 */
function dtb_vd_hydrate_revision_row( array $row ): array {
	$payload = json_decode( (string) ( $row['payload_json'] ?? '' ), true );
	return [
		'id'                   => (int) $row['id'],
		'revision_uid'         => (string) $row['revision_uid'],
		'draft_key'            => (string) $row['draft_key'],
		'schema_version'       => (int) $row['schema_version'],
		'parent_revision_id'   => isset( $row['parent_revision_id'] ) && null !== $row['parent_revision_id'] ? (int) $row['parent_revision_id'] : null,
		'rollback_origin_id'   => isset( $row['rollback_origin_id'] ) && null !== $row['rollback_origin_id'] ? (int) $row['rollback_origin_id'] : null,
		'author_id'            => (int) $row['author_id'],
		'note'                 => $row['note'] ?? null,
		'payload'              => is_array( $payload ) ? $payload : [ 'tokens' => [], 'surfaces' => [] ],
		'payload_hash'         => (string) $row['payload_hash'],
		'affected_surfaces'    => (array) json_decode( (string) ( $row['affected_surfaces_json'] ?? '[]' ), true ),
		'created_at'           => (string) $row['created_at'],
		'published_at'         => $row['published_at'] ?? null,
	];
}

/**
 * Paginated revision history for a draft scope, newest first.
 *
 * @param string $draft_key
 * @param int    $page 1-based
 * @param int    $per_page bounded to 50
 * @return array{items: array, total: int, page: int, per_page: int}
 */
function dtb_vd_get_revision_history( string $draft_key, int $page = 1, int $per_page = 20 ): array {
	global $wpdb;
	$table    = $wpdb->prefix . 'dtb_design_revisions';
	$page     = max( 1, $page );
	$per_page = max( 1, min( 50, $per_page ) );
	$offset   = ( $page - 1 ) * $per_page;

	$total = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE draft_key = %s", $draft_key ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, revision_uid, draft_key, schema_version, parent_revision_id, rollback_origin_id, author_id, note, payload_hash, affected_surfaces_json, created_at, published_at FROM {$table} WHERE draft_key = %s ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$draft_key,
			$per_page,
			$offset
		),
		ARRAY_A
	);

	$items = array_map(
		static function ( array $row ): array {
			$author = get_userdata( (int) $row['author_id'] );
			return [
				'id'                 => (int) $row['id'],
				'revision_uid'       => (string) $row['revision_uid'],
				'parent_revision_id' => isset( $row['parent_revision_id'] ) && null !== $row['parent_revision_id'] ? (int) $row['parent_revision_id'] : null,
				'rollback_origin_id' => isset( $row['rollback_origin_id'] ) && null !== $row['rollback_origin_id'] ? (int) $row['rollback_origin_id'] : null,
				'author_id'          => (int) $row['author_id'],
				'author_name'        => $author ? $author->display_name : 'Unknown',
				'note'               => $row['note'] ?? null,
				'payload_hash'       => (string) $row['payload_hash'],
				'affected_surfaces'  => (array) json_decode( (string) ( $row['affected_surfaces_json'] ?? '[]' ), true ),
				'created_at'         => (string) $row['created_at'],
				'published_at'       => $row['published_at'] ?? null,
			];
		},
		(array) $rows
	);

	return [ 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per_page ];
}
