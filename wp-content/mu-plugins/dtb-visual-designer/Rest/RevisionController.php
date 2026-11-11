<?php
/**
 * Rest — RevisionController: revision history, publish, and rollback.
 * Operator-only. Publish/rollback both use the draft's optimistic
 * concurrency token to prevent a stale client from silently clobbering a
 * newer server state.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function (): void {
	register_rest_route( DTB_VD_REST_NAMESPACE, '/design/revisions', [
		'methods'             => 'GET',
		'callback'            => 'dtb_vd_rest_get_history',
		'permission_callback' => 'dtb_vd_rest_operator_permission',
		'args'                => [
			'draftKey' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
			'page'     => [ 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ],
			'perPage'  => [ 'type' => 'integer', 'default' => 20, 'sanitize_callback' => 'absint' ],
		],
	] );

	register_rest_route( DTB_VD_REST_NAMESPACE, '/design/revisions/(?P<id>\d+)', [
		'methods'             => 'GET',
		'callback'            => 'dtb_vd_rest_get_revision',
		'permission_callback' => 'dtb_vd_rest_operator_permission',
		'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ] ],
	] );

	register_rest_route( DTB_VD_REST_NAMESPACE, '/design/publish', [
		'methods'             => 'POST',
		'callback'            => 'dtb_vd_rest_publish',
		'permission_callback' => 'dtb_vd_rest_operator_permission',
		'args'                => [
			'draftKey'    => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
			'revisionSeq' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
			'note'        => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
		],
	] );

	register_rest_route( DTB_VD_REST_NAMESPACE, '/design/rollback', [
		'methods'             => 'POST',
		'callback'            => 'dtb_vd_rest_rollback',
		'permission_callback' => 'dtb_vd_rest_operator_permission',
		'args'                => [
			'draftKey'   => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
			'revisionId' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
			'note'       => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
		],
	] );
} );

function dtb_vd_rest_get_history( WP_REST_Request $request ) {
	$draft_key = dtb_vd_rest_draft_key( $request );
	$history   = dtb_vd_get_revision_history( $draft_key, (int) $request->get_param( 'page' ), (int) $request->get_param( 'perPage' ) );

	return rest_ensure_response( array_merge( $history, [
		'publishedRevisionId' => dtb_vd_get_published_revision_id( $draft_key ),
	] ) );
}

function dtb_vd_rest_get_revision( WP_REST_Request $request ) {
	$revision = dtb_vd_get_revision( (int) $request->get_param( 'id' ) );
	if ( ! $revision ) {
		return dtb_vd_rest_error( 'revision_not_found', 'That revision does not exist.', 404 );
	}
	return rest_ensure_response( array_merge( $revision, [ 'config' => dtb_vd_resolve_config( $revision['payload'] ) ] ) );
}

function dtb_vd_rest_publish( WP_REST_Request $request ) {
	$draft_key = dtb_vd_rest_draft_key( $request );
	$result    = dtb_vd_publish_draft( $draft_key, (int) $request->get_param( 'revisionSeq' ), get_current_user_id(), (string) $request->get_param( 'note' ) );

	if ( $result['conflict'] ) {
		return dtb_vd_rest_error( 'stale_draft', 'This draft is out of date. Reload and try again.', 409 );
	}

	return rest_ensure_response( [ 'revision' => $result['revision'] ] );
}

function dtb_vd_rest_rollback( WP_REST_Request $request ) {
	$draft_key = dtb_vd_rest_draft_key( $request );
	$result    = dtb_vd_rollback_to_revision( $draft_key, (int) $request->get_param( 'revisionId' ), get_current_user_id(), (string) $request->get_param( 'note' ) );

	if ( ! $result['success'] ) {
		return dtb_vd_rest_error( $result['error'] ?? 'rollback_failed', 'Unable to roll back to that revision.', 404 );
	}

	return rest_ensure_response( [ 'revision' => $result['revision'] ] );
}
