<?php
/**
 * Infrastructure — DraftRepository: CRUD for wp_dtb_design_drafts.
 *
 * v1 uses a single shared editing scope, `draft_key = 'default'`, covering
 * the whole resolved configuration (global tokens + every surface/component/
 * responsive override). This keeps precedence fully deterministic — there is
 * exactly one draft document to merge against published state — while still
 * supporting concurrent operators through `revision_seq` optimistic
 * concurrency (see Application/DraftService.php).
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_VD_DEFAULT_DRAFT_KEY = 'default';

/**
 * Fetch a draft row by key, creating an empty one if it does not exist yet.
 *
 * @param string $draft_key
 * @return array{id:int, draft_key:string, schema_version:int, base_revision_id:?int,
 *               revision_seq:int, payload:array, dirty:bool, editor_id:?int,
 *               updated_at:string}
 */
function dtb_vd_get_or_create_draft( string $draft_key = DTB_VD_DEFAULT_DRAFT_KEY ): array {
	global $wpdb;
	$table = $wpdb->prefix . 'dtb_design_drafts';

	$row = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$table} WHERE draft_key = %s LIMIT 1", $draft_key ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ARRAY_A
	);

	if ( ! $row ) {
		$now      = current_time( 'mysql', true );
		$empty    = wp_json_encode( [ 'tokens' => [], 'surfaces' => [] ] );
		$inserted = $wpdb->insert(
			$table,
			[
				'draft_key'        => $draft_key,
				'schema_version'   => DTB_VD_SCHEMA_VERSION,
				'base_revision_id' => null,
				'revision_seq'     => 1,
				'payload_json'     => $empty,
				'dirty'            => 0,
				'editor_id'        => null,
				'created_at'       => $now,
				'updated_at'       => $now,
			],
			[ '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			// Concurrent creation race — re-read.
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE draft_key = %s LIMIT 1", $draft_key ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
		} else {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $wpdb->insert_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
		}
	}

	return dtb_vd_hydrate_draft_row( $row );
}

/**
 * @param array $row Raw $wpdb row.
 * @return array Hydrated draft.
 */
function dtb_vd_hydrate_draft_row( array $row ): array {
	$payload = json_decode( (string) ( $row['payload_json'] ?? '' ), true );
	return [
		'id'               => (int) $row['id'],
		'draft_key'        => (string) $row['draft_key'],
		'schema_version'   => (int) $row['schema_version'],
		'base_revision_id' => isset( $row['base_revision_id'] ) && null !== $row['base_revision_id'] ? (int) $row['base_revision_id'] : null,
		'revision_seq'     => (int) $row['revision_seq'],
		'payload'          => is_array( $payload ) ? $payload : [ 'tokens' => [], 'surfaces' => [] ],
		'dirty'            => (bool) $row['dirty'],
		'editor_id'        => isset( $row['editor_id'] ) && null !== $row['editor_id'] ? (int) $row['editor_id'] : null,
		'updated_at'       => (string) $row['updated_at'],
	];
}

/**
 * Update a draft's payload with optimistic concurrency control.
 *
 * @param string $draft_key
 * @param array  $payload            Fully-formed new payload (already sanitized upstream).
 * @param int    $expected_revision_seq Caller's known revision_seq; rejected if it does not match.
 * @param int    $editor_id
 * @return array{0: bool, 1: array|null} [ success, updated_draft_or_null ]
 */
function dtb_vd_update_draft( string $draft_key, array $payload, int $expected_revision_seq, int $editor_id ): array {
	global $wpdb;
	$table = $wpdb->prefix . 'dtb_design_drafts';

	$current = dtb_vd_get_or_create_draft( $draft_key );
	if ( $current['revision_seq'] !== $expected_revision_seq ) {
		return [ false, $current ];
	}

	$encoded = wp_json_encode( $payload );
	if ( false === $encoded || strlen( $encoded ) > DTB_VD_MAX_PAYLOAD_BYTES ) {
		return [ false, $current ];
	}

	$updated = $wpdb->update(
		$table,
		[
			'payload_json' => $encoded,
			'revision_seq' => $expected_revision_seq + 1,
			'dirty'        => 1,
			'editor_id'    => $editor_id,
			'updated_at'   => current_time( 'mysql', true ),
		],
		[ 'draft_key' => $draft_key, 'revision_seq' => $expected_revision_seq ],
		[ '%s', '%d', '%d', '%d', '%s' ],
		[ '%s', '%d' ]
	);

	if ( ! $updated ) {
		// Someone else updated between our read and write.
		return [ false, dtb_vd_get_or_create_draft( $draft_key ) ];
	}

	return [ true, dtb_vd_get_or_create_draft( $draft_key ) ];
}

/**
 * Reset the draft back to the currently published revision (or empty if none).
 *
 * @param string $draft_key
 * @param int    $editor_id
 * @return array Updated draft.
 */
function dtb_vd_reset_draft_to_published( string $draft_key, int $editor_id ): array {
	global $wpdb;
	$table = $wpdb->prefix . 'dtb_design_drafts';

	$published = dtb_vd_get_published_revision( $draft_key );
	$payload   = $published['payload'] ?? [ 'tokens' => [], 'surfaces' => [] ];

	$wpdb->update(
		$table,
		[
			'payload_json'     => wp_json_encode( $payload ),
			'base_revision_id' => $published['id'] ?? null,
			'dirty'            => 0,
			'editor_id'        => $editor_id,
			'updated_at'       => current_time( 'mysql', true ),
		],
		[ 'draft_key' => $draft_key ],
		[ '%s', '%d', '%d', '%d', '%s' ],
		[ '%s' ]
	);

	// revision_seq is intentionally bumped separately so optimistic-concurrency
	// checks in flight still see a consistent, monotonically increasing value.
	$wpdb->query(
		$wpdb->prepare( "UPDATE {$table} SET revision_seq = revision_seq + 1 WHERE draft_key = %s", $draft_key ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);

	return dtb_vd_get_or_create_draft( $draft_key );
}
