<?php
/**
 * Rest — DraftController: operator-only draft read/patch/discard/reset.
 *
 * PATCH accepts a bounded batch of operations (`ops`) and/or token
 * overrides (`tokens`), validated via DraftService against the live
 * registry, with optimistic concurrency via `revisionSeq`. A mismatch
 * returns 409 with the server's current state so the editor can reconcile
 * deliberately instead of silently overwriting another operator's changes.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function (): void {
	register_rest_route( DTB_VD_REST_NAMESPACE, '/design/draft', [
		[
			'methods'             => 'GET',
			'callback'            => 'dtb_vd_rest_get_draft',
			'permission_callback' => 'dtb_vd_rest_operator_permission',
			'args'                => [ 'draftKey' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ] ],
		],
		[
			'methods'             => 'PATCH',
			'callback'            => 'dtb_vd_rest_patch_draft',
			'permission_callback' => 'dtb_vd_rest_operator_permission',
			'args'                => [
				'draftKey'    => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
				'revisionSeq' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
				'ops'         => [ 'type' => 'array', 'default' => [] ],
				'tokens'      => [ 'type' => 'object', 'default' => [] ],
			],
		],
	] );

	register_rest_route( DTB_VD_REST_NAMESPACE, '/design/draft/discard', [
		'methods'             => 'POST',
		'callback'            => 'dtb_vd_rest_discard_draft',
		'permission_callback' => 'dtb_vd_rest_operator_permission',
		'args'                => [ 'draftKey' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ] ],
	] );

	register_rest_route( DTB_VD_REST_NAMESPACE, '/design/draft/reset', [
		'methods'             => 'POST',
		'callback'            => 'dtb_vd_rest_reset_scope',
		'permission_callback' => 'dtb_vd_rest_operator_permission',
		'args'                => [
			'draftKey'    => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
			'revisionSeq' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
			'level'       => [ 'type' => 'string', 'required' => true, 'enum' => [ 'property', 'component', 'surface', 'token_group', 'all' ] ],
			'target'      => [ 'type' => 'object', 'default' => [] ],
		],
	] );
} );

function dtb_vd_rest_get_draft( WP_REST_Request $request ) {
	$draft_key = dtb_vd_rest_draft_key( $request );
	$draft     = dtb_vd_get_or_create_draft( $draft_key );

	return rest_ensure_response( [
		'draftKey'    => $draft_key,
		'revisionSeq' => $draft['revision_seq'],
		'dirty'       => $draft['dirty'],
		'updatedAt'   => $draft['updated_at'],
		'editorId'    => $draft['editor_id'],
		'config'      => dtb_vd_resolve_config( $draft['payload'] ),
	] );
}

function dtb_vd_rest_patch_draft( WP_REST_Request $request ) {
	$draft_key = dtb_vd_rest_draft_key( $request );
	$ops       = (array) $request->get_param( 'ops' );
	$tokens    = (array) $request->get_param( 'tokens' );
	$seq       = (int) $request->get_param( 'revisionSeq' );

	if ( count( $ops ) > 200 ) {
		return dtb_vd_rest_error( 'too_many_operations', 'A single patch may contain at most 200 operations.', 400 );
	}

	$result = dtb_vd_apply_draft_patch( $draft_key, $ops, $tokens, $seq, get_current_user_id() );

	if ( $result['conflict'] ) {
		$response = rest_ensure_response( [
			'code'        => 'draft_conflict',
			'message'     => 'The draft changed on the server since you last loaded it.',
			'serverDraft' => [
				'revisionSeq' => $result['draft']['revision_seq'] ?? null,
				'updatedAt'   => $result['draft']['updated_at'] ?? null,
				'editorId'    => $result['draft']['editor_id'] ?? null,
			],
		] );
		$response->set_status( 409 );
		return $response;
	}

	return rest_ensure_response( [
		'revisionSeq' => $result['draft']['revision_seq'],
		'rejected'    => $result['rejected'],
		'config'      => dtb_vd_resolve_config( $result['draft']['payload'] ),
	] );
}

function dtb_vd_rest_discard_draft( WP_REST_Request $request ) {
	$draft_key = dtb_vd_rest_draft_key( $request );
	$draft     = dtb_vd_discard_draft( $draft_key, get_current_user_id() );

	return rest_ensure_response( [
		'revisionSeq' => $draft['revision_seq'],
		'config'      => dtb_vd_resolve_config( $draft['payload'] ),
	] );
}

function dtb_vd_rest_reset_scope( WP_REST_Request $request ) {
	$draft_key = dtb_vd_rest_draft_key( $request );
	$level     = (string) $request->get_param( 'level' );
	$target    = (array) $request->get_param( 'target' );
	$seq       = (int) $request->get_param( 'revisionSeq' );

	$result = dtb_vd_reset_scope( $draft_key, $level, $target, $seq, get_current_user_id() );

	if ( $result['conflict'] ) {
		return dtb_vd_rest_error( 'draft_conflict', 'The draft changed on the server since you last loaded it.', 409 );
	}

	return rest_ensure_response( [
		'revisionSeq' => $result['draft']['revision_seq'],
		'config'      => dtb_vd_resolve_config( $result['draft']['payload'] ),
	] );
}
