<?php
defined( 'ABSPATH' ) || exit;

function dtb_route_sync_images_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$relative_path = dtb_image_sync_resolve_relative_upload_path( $request );
	if ( is_wp_error( $relative_path ) ) {
		return $relative_path;
	}

	return rest_ensure_response( dtb_build_image_sync_snapshot( $relative_path ) );
}

// ============================================================================
// POST /dtb/v1/sync-images/reset — DESTRUCTIVE
// ============================================================================

/**
 * Full clean-slate reset.
 *   1. Delete every attachment whose _wp_attached_file points to uploads/<year>/<month>/.
 *      wp_delete_attachment(force=true) also removes generated sub-size files from disk.
 *   2. Clear _thumbnail_id and _product_image_gallery from every product.
 *   3. Bump WC product cache version.
 *
 * dry_run=true (default) — reports what would be done without executing.
 */

