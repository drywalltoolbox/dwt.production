<?php
/**
 * Rest — PublishedConfigController: the ONE public endpoint the React
 * storefront calls in normal (non-preview) operation. Returns a compact,
 * deterministic, resolved configuration plus a stable ETag so the frontend
 * can cache it and skip re-fetching when nothing has changed.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function (): void {
	register_rest_route( DTB_VD_REST_NAMESPACE, '/design/config', [
		'methods'             => 'GET',
		'callback'            => 'dtb_vd_rest_get_published_config',
		'permission_callback' => 'dtb_vd_rest_public_permission',
		'args'                => [
			'breakpoint' => dtb_vd_breakpoint_arg(),
			'draftKey'   => [ 'type' => 'string', 'default' => DTB_VD_DEFAULT_DRAFT_KEY, 'sanitize_callback' => 'sanitize_key' ],
		],
	] );
} );

function dtb_vd_rest_get_published_config( WP_REST_Request $request ) {
	$draft_key  = dtb_vd_rest_draft_key( $request );
	$breakpoint = (string) $request->get_param( 'breakpoint' );

	$published = dtb_vd_get_published_revision( $draft_key );
	$etag      = dtb_vd_compute_etag( $published['payload_hash'] ?? null );

	$if_none_match = $request->get_header( 'if_none_match' );
	if ( $if_none_match && trim( $if_none_match ) === $etag ) {
		$response = rest_ensure_response( null );
		$response->set_status( 304 );
		$response->header( 'ETag', $etag );
		$response->header( 'Cache-Control', 'public, max-age=60' );
		return $response;
	}

	$config = dtb_vd_resolve_config( $published['payload'] ?? [ 'tokens' => [], 'surfaces' => [] ], $breakpoint );

	$response = rest_ensure_response( [
		'schemaVersion' => DTB_VD_SCHEMA_VERSION,
		'revisionId'    => $published['id'],
		'breakpoint'    => $breakpoint,
		'config'        => $config,
	] );
	$response->header( 'ETag', $etag );
	$response->header( 'Cache-Control', 'public, max-age=60' );

	return $response;
}
