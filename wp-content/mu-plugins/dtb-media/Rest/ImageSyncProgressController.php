<?php
defined( 'ABSPATH' ) || exit;

function dtb_route_sync_images_progress(): WP_REST_Response {
	$lock     = (bool) get_transient( DTB_SYNC_LOCK_KEY );
	$progress = get_transient( DTB_SYNC_PROGRESS_KEY );
	return rest_ensure_response( [
		'locked'   => $lock,
		'progress' => $progress ?: null,
	] );
}

// ============================================================================
// GET /dtb/v1/sync-images/status
// ============================================================================

/**
 * Parse a WooCommerce gallery meta string into normalized attachment IDs.
 *
 * @param string $gallery_meta Raw `_product_image_gallery` meta value.
 * @return int[]
 */

