<?php
/**
 * Rest — ImportExportController: sanitized configuration portability.
 * Operator-only. Import always lands in the draft, never published state.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function (): void {
	register_rest_route( DTB_VD_REST_NAMESPACE, '/design/export', [
		'methods'             => 'GET',
		'callback'            => 'dtb_vd_rest_export',
		'permission_callback' => 'dtb_vd_rest_operator_permission',
		'args'                => [ 'draftKey' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ] ],
	] );

	register_rest_route( DTB_VD_REST_NAMESPACE, '/design/import', [
		'methods'             => 'POST',
		'callback'            => 'dtb_vd_rest_import',
		'permission_callback' => 'dtb_vd_rest_operator_permission',
		'args'                => [
			'draftKey' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
			'document' => [ 'type' => 'object', 'required' => true ],
		],
	] );
} );

function dtb_vd_rest_export( WP_REST_Request $request ) {
	$draft_key = dtb_vd_rest_draft_key( $request );
	dtb_vd_audit( 'design.export.generated', [ 'draft_key' => $draft_key ] );
	return rest_ensure_response( dtb_vd_export_published( $draft_key ) );
}

function dtb_vd_rest_import( WP_REST_Request $request ) {
	$draft_key = dtb_vd_rest_draft_key( $request );
	$document  = (array) $request->get_param( 'document' );

	if ( ! dtb_vd_structurally_bounded( $document ) ) {
		dtb_vd_audit( 'design.import.rejected', [ 'draft_key' => $draft_key, 'reason' => 'oversized_or_malformed' ] );
		return dtb_vd_rest_error( 'oversized_or_malformed_document', 'The import document is malformed or too large.', 400 );
	}

	$result = dtb_vd_import_document( $document, $draft_key, get_current_user_id() );

	if ( ! $result['success'] ) {
		return dtb_vd_rest_error( 'import_failed', 'The draft changed on the server while importing; reload and try again.', 409 );
	}

	return rest_ensure_response( [
		'errors' => $result['errors'],
		'config' => dtb_vd_resolve_config( $result['draft']['payload'] ),
	] );
}
