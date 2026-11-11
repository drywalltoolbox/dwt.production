<?php
/**
 * Rest — RestHelpers: shared permission callbacks and response helpers for
 * the dtb/v1 design-config REST surface.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_VD_REST_NAMESPACE = 'dtb/v1';
const DTB_VD_CAPABILITY     = 'dtb_manage_visual_designer';

/**
 * Operator permission callback — every editor/draft/publish/rollback/import
 * endpoint requires this capability and an authenticated wp-admin context.
 */
function dtb_vd_rest_operator_permission(): bool {
	return current_user_can( DTB_VD_CAPABILITY );
}

/**
 * Public permission callback for the resolved published configuration —
 * intentionally open (no auth) since this is what the anonymous storefront
 * reads, mirroring how other public storefront read endpoints behave.
 */
function dtb_vd_rest_public_permission(): bool {
	return true;
}

/**
 * Build a safe, generic error response. Never leaks stack traces, DB
 * structure, or file paths to the client.
 *
 * @param string $code
 * @param string $message
 * @param int    $status
 * @return WP_Error
 */
function dtb_vd_rest_error( string $code, string $message, int $status = 400 ): WP_Error {
	return new WP_Error( $code, $message, [ 'status' => $status ] );
}

/**
 * Resolve + bound the `draftKey` request param. v1 ships a single shared
 * scope; this indirection keeps the door open for a future multi-workspace
 * model without changing the REST contract.
 *
 * @param WP_REST_Request $request
 * @return string
 */
function dtb_vd_rest_draft_key( WP_REST_Request $request ): string {
	$key = sanitize_key( (string) $request->get_param( 'draftKey' ) );
	return '' !== $key ? $key : DTB_VD_DEFAULT_DRAFT_KEY;
}

/**
 * Args schema fragment for the optional `breakpoint` query param shared by
 * several read endpoints.
 *
 * @return array
 */
function dtb_vd_breakpoint_arg(): array {
	return [
		'type'              => 'string',
		'enum'              => dtb_vd_breakpoints(),
		'default'           => 'base',
		'sanitize_callback' => 'sanitize_key',
	];
}
