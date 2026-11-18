<?php
/**
 * Rest — PreviewController: preview-session issuance (operator-only) and
 * preview configuration retrieval (token-scoped, not capability-scoped —
 * the short-lived token itself is the authorization for the read).
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function (): void {
	register_rest_route( DTB_VD_REST_NAMESPACE, '/design/preview/session', [
		'methods'             => 'POST',
		'callback'            => 'dtb_vd_rest_create_preview_session',
		'permission_callback' => 'dtb_vd_rest_operator_permission',
		'args'                => [ 'draftKey' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ] ],
	] );

	register_rest_route( DTB_VD_REST_NAMESPACE, '/design/preview/config', [
		'methods'             => 'GET',
		'callback'            => 'dtb_vd_rest_get_preview_config',
		// Public permission callback: the preview token itself (validated inside
		// the callback, hashed + expiring + revocable) is the real authorization
		// boundary here, matching the "scoped preview authorization" requirement
		// rather than requiring the storefront's anonymous preview iframe to also
		// hold an admin session.
		'permission_callback' => 'dtb_vd_rest_public_permission',
		'args'                => [
			'token'      => [ 'type' => 'string', 'required' => true ],
			'breakpoint' => dtb_vd_breakpoint_arg(),
		],
	] );
} );

function dtb_vd_rest_create_preview_session( WP_REST_Request $request ) {
	$draft_key = dtb_vd_rest_draft_key( $request );
	$session   = dtb_vd_start_preview_session( $draft_key, get_current_user_id() );

	return rest_ensure_response( $session );
}

function dtb_vd_rest_get_preview_config( WP_REST_Request $request ) {
	$token      = (string) $request->get_param( 'token' );
	$breakpoint = (string) $request->get_param( 'breakpoint' );

	$resolved = dtb_vd_resolve_preview_config( $token, $breakpoint );
	if ( ! $resolved ) {
		return dtb_vd_rest_error( 'invalid_preview_session', 'This preview link has expired or is no longer valid.', 403 );
	}

	$response = rest_ensure_response( [
		'schemaVersion' => DTB_VD_SCHEMA_VERSION,
		'draftKey'      => $resolved['draftKey'],
		'revisionSeq'   => $resolved['revisionSeq'],
		'breakpoint'    => $breakpoint,
		'config'        => $resolved['config'],
	] );
	// Preview payloads must never be cached publicly.
	$response->header( 'Cache-Control', 'no-store, private' );
	return $response;
}
