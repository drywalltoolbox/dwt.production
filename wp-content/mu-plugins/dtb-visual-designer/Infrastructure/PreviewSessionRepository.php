<?php
/**
 * Infrastructure — PreviewSessionRepository: wp_dtb_design_preview_sessions.
 *
 * Preview tokens are opaque, cryptographically random, short-lived, and
 * stored only as a SHA-256 hash (the raw token is never persisted). Sessions
 * are scoped to one draft/revision and one operator, and expire quickly.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_VD_PREVIEW_TTL_SECONDS = 3600; // 1 hour — matches editor session expectations.

/**
 * Create a new preview session bound to the current draft state.
 *
 * @param string $draft_key
 * @param int    $operator_id
 * @return array{token: string, expires_at: string} Raw token — caller must hand it to the client immediately.
 */
function dtb_vd_create_preview_session( string $draft_key, int $operator_id ): array {
	global $wpdb;
	$table = $wpdb->prefix . 'dtb_design_preview_sessions';

	$draft = dtb_vd_get_or_create_draft( $draft_key );

	$raw_token  = wp_generate_password( 48, false, false );
	$token_hash = hash( 'sha256', $raw_token );
	$now        = current_time( 'mysql', true );
	$expires    = gmdate( 'Y-m-d H:i:s', time() + DTB_VD_PREVIEW_TTL_SECONDS );

	$wpdb->insert(
		$table,
		[
			'token_hash'          => $token_hash,
			'draft_key'           => $draft_key,
			'revision_id'         => null,
			'draft_revision_seq'  => $draft['revision_seq'],
			'operator_id'         => $operator_id,
			'created_at'          => $now,
			'expires_at'          => $expires,
			'revoked'             => 0,
			'used_count'          => 0,
		],
		[ '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%d' ]
	);

	return [ 'token' => $raw_token, 'expires_at' => $expires ];
}

/**
 * Validate a preview token and return its session context, or null if
 * invalid, expired, or revoked (fails closed — no draft data is ever
 * returned for an unauthorized or stale token).
 *
 * @param string $raw_token
 * @return array{draft_key: string, operator_id: int}|null
 */
function dtb_vd_validate_preview_session( string $raw_token ): ?array {
	global $wpdb;
	$table = $wpdb->prefix . 'dtb_design_preview_sessions';

	if ( '' === trim( $raw_token ) ) {
		return null;
	}

	$token_hash = hash( 'sha256', $raw_token );
	$row        = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$table} WHERE token_hash = %s AND revoked = 0 AND expires_at > %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$token_hash,
			current_time( 'mysql', true )
		),
		ARRAY_A
	);

	if ( ! $row ) {
		return null;
	}

	$wpdb->update(
		$table,
		[ 'used_count' => (int) $row['used_count'] + 1 ],
		[ 'id' => (int) $row['id'] ],
		[ '%d' ],
		[ '%d' ]
	);

	return [
		'draft_key'   => (string) $row['draft_key'],
		'operator_id' => (int) $row['operator_id'],
	];
}

/**
 * Revoke every active preview session for a draft scope (used when a draft
 * is discarded or the editor closes explicitly).
 *
 * @param string $draft_key
 */
function dtb_vd_revoke_preview_sessions( string $draft_key ): void {
	global $wpdb;
	$table = $wpdb->prefix . 'dtb_design_preview_sessions';
	$wpdb->update(
		$table,
		[ 'revoked' => 1 ],
		[ 'draft_key' => $draft_key ],
		[ '%d' ],
		[ '%s' ]
	);
}
