<?php
/**
 * Application — PreviewAuthService: scoped, short-lived preview
 * authorization for loading unpublished drafts inside the real React app.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create a preview session for the current operator and draft scope.
 *
 * @param string $draft_key
 * @param int    $operator_id
 * @return array{token:string, expires_at:string}
 */
function dtb_vd_start_preview_session( string $draft_key, int $operator_id ): array {
	$session = dtb_vd_create_preview_session( $draft_key, $operator_id );
	dtb_vd_audit( 'design.preview.session_created', [ 'draft_key' => $draft_key ] );
	return $session;
}

/**
 * Resolve the live preview configuration for a valid preview token. Returns
 * null if the token is invalid/expired/revoked — callers must fail closed to
 * "preview unavailable", never to published or default silently mislabeled
 * as preview.
 *
 * @param string      $raw_token
 * @param string|null $breakpoint
 * @return array{config:array, draftKey:string, revisionSeq:int}|null
 */
function dtb_vd_resolve_preview_config( string $raw_token, ?string $breakpoint = null ): ?array {
	$session = dtb_vd_validate_preview_session( $raw_token );
	if ( ! $session ) {
		return null;
	}

	$draft  = dtb_vd_get_or_create_draft( $session['draft_key'] );
	$config = dtb_vd_resolve_config( $draft['payload'], $breakpoint );

	return [
		'config'      => $config,
		'draftKey'    => $session['draft_key'],
		'revisionSeq' => $draft['revision_seq'],
	];
}
