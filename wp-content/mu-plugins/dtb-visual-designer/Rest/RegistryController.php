<?php
/**
 * Rest — RegistryController: operator-only read access to the registered
 * surface/component/property schema and token groups. This is the data the
 * editor UI uses to generate its surface navigator, component tree, and
 * inspector controls — it is never exposed to the public storefront.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function (): void {
	register_rest_route( DTB_VD_REST_NAMESPACE, '/design/registry', [
		'methods'             => 'GET',
		'callback'            => 'dtb_vd_rest_get_registry',
		'permission_callback' => 'dtb_vd_rest_operator_permission',
	] );

	register_rest_route( DTB_VD_REST_NAMESPACE, '/design/defaults', [
		'methods'             => 'GET',
		'callback'            => 'dtb_vd_rest_get_defaults',
		'permission_callback' => 'dtb_vd_rest_operator_permission',
	] );
} );

function dtb_vd_rest_get_registry( WP_REST_Request $request ) {
	unset( $request );
	return rest_ensure_response( [
		'schemaVersion' => DTB_VD_SCHEMA_VERSION,
		'surfaces'      => dtb_vd_get_surfaces(),
		'tokenGroups'   => dtb_vd_get_token_groups(),
		'breakpoints'   => dtb_vd_breakpoints(),
	] );
}

function dtb_vd_rest_get_defaults( WP_REST_Request $request ) {
	unset( $request );
	return rest_ensure_response( dtb_vd_resolve_config( [ 'tokens' => [], 'surfaces' => [] ] ) );
}
