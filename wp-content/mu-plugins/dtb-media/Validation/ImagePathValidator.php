<?php
defined( 'ABSPATH' ) || exit;

/**
 * Plugin Name: DTB Image Sync
 * Description: Registers pre-placed product images into the WordPress Media
 *              Library and links them to WooCommerce products using exact
 *              basenames from the active CSV Images column.
 *              Uses only public WP/WC APIs. Safe for shared hosting.
 * Version: 2.1.3
 * Author: Drywall Toolbox
 *
 * Must-use plugin: wp/wp-content/mu-plugins/dtb-image-sync.php
 * Loaded by: 00-dtb-loader.php (after dtb-utils.php and dtb-auth.php)
 *
 * ── DESIGN DECISIONS ────────────────────────────────────────────────────────
 *
 * Attachment lookup: attachment_url_to_postid() [official WP API, WP 4.0+]
 *   Uses the indexed _wp_attached_file meta column for lookups.
 *   Faster and more reliable than a guid LIKE query on large catalogs.
 *
 * Sub-size generation: _wp_make_subsizes() via wp_update_image_subsizes()
 *   wp_update_image_subsizes() [WP 5.3+] is the public API that calls
 *   _wp_make_subsizes() internally. It skips sizes already generated
 *   (fully idempotent), does NOT call wp_unique_filename() on the source
 *   file under any code path, and saves metadata after each crop.
 *   Using this over wp_generate_attachment_metadata() avoids the known
 *   Trac #44095 bug where WP renames the source file on disk.
 *
 * Product image linking: WC_Product API
 *   Uses WC_Product->set_image_id() and WC_Product->set_gallery_image_ids()
 *   instead of raw set_post_thumbnail() / update_post_meta(). This fires
 *   all WooCommerce action hooks (woocommerce_product_set_image etc.) and
 *   ensures WC object caches are properly updated.
 *
 * Attachment parent: set to the product post ID
 *   Sets post_parent on each attachment record so WP Admin Media Library
 *   shows "Attached to: [Product Name]" rather than "Unattached".
 *
 * Sync lock: transient-based mutex (dtb_image_sync_lock)
 *   Prevents concurrent sync runs which corrupt gallery meta on shared
 *   hosting where multiple PHP processes can be in flight simultaneously.
 *
 * Product resolution key: SKU
 *   SKU is used only to identify the WooCommerce target product row.
 *
 * Image resolution key: CSV Images basenames
 *   Image files are resolved from exact basenames listed in the active CSV
 *   Images column; the sync does not infer image filenames from SKU.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;


// ============================================================================
// CONSTANTS
// ============================================================================

/** Transient key used as a mutex to prevent concurrent sync runs. */
define( 'DTB_SYNC_LOCK_KEY',    'dtb_image_sync_lock' );

/** Transient key that stores the running sync progress for resumable batches. */
define( 'DTB_SYNC_PROGRESS_KEY', 'dtb_image_sync_progress' );

/** Lock TTL in seconds — auto-expires to prevent a dead lock on crashes. */
define( 'DTB_SYNC_LOCK_TTL', 600 );

/**
 * When true, skip the "fix renamed files" workflow entirely.
 *
 * Set this to true when your image files are already correctly named and
 * you do not want the plugin to attempt renaming files on disk. To re-enable
 * the behavior, set this to false or remove the define and reload.
 */
if ( ! defined( 'DTB_IMAGE_SYNC_DISABLE_RENAME' ) ) {
	define( 'DTB_IMAGE_SYNC_DISABLE_RENAME', false );
}

/**
 * Relative uploads path to scan by default.
 *
 * This should be a path relative to wp-content/uploads/, for example
 * "2026/media".
 */
if ( ! defined( 'DTB_IMAGE_SYNC_DEFAULT_UPLOAD_RELATIVE_PATH' ) ) {
	define( 'DTB_IMAGE_SYNC_DEFAULT_UPLOAD_RELATIVE_PATH', '2026/media' );
}

/**
 * When true, Register Images Only skips generating intermediate image sub-sizes.
 *
 * This keeps attachment registration lightweight on shared hosting and avoids
 * frequent stalls/timeouts inside image editor backends (Imagick/GD).
 */
if ( ! defined( 'DTB_IMAGE_SYNC_SKIP_SUBSIZES_IN_REGISTER_ONLY' ) ) {
	define( 'DTB_IMAGE_SYNC_SKIP_SUBSIZES_IN_REGISTER_ONLY', true );
}

/**
 * Sanitize and validate a relative upload path.
 */
function dtb_image_sync_validate_upload_path( string $value ): bool {
	$value = trim( (string) $value, " \t\n\r\0\x0B/" );
	if ( '' === $value ) {
		return true;
	}
	if ( preg_match( '#(^|/)\.{1,2}(/|$)#', $value ) ) {
		return false;
	}
	return 1 === preg_match( '/^[a-z0-9-]+(?:\/[a-z0-9-]+)*$/', $value );
}

/**
 * Resolve the relative uploads path to scan for this request.
 *
 * Priority:
 *   1. upload_path request parameter
 *   2. legacy year/month request parameters
 *   3. default constant path
 */
function dtb_image_sync_resolve_relative_upload_path( WP_REST_Request $request ) {
	$upload_path = $request->get_param( 'upload_path' );
	if ( is_string( $upload_path ) ) {
		$upload_path = trim( $upload_path, '/' );
		if ( '' !== $upload_path ) {
			if ( ! dtb_image_sync_validate_upload_path( $upload_path ) ) {
				return new WP_Error( 'invalid_upload_path', 'upload_path must be a relative path with allowed characters only.', [ 'status' => 400 ] );
			}
			return $upload_path;
		}
	}

	$year  = trim( (string) $request->get_param( 'year' ) );
	$month = trim( (string) $request->get_param( 'month' ) );

	if ( '' !== $year || '' !== $month ) {
		if ( ! ctype_digit( $year ) || ! ctype_digit( $month ) ) {
			return new WP_Error( 'invalid_params', 'year and month must be numeric when upload_path is not provided.', [ 'status' => 400 ] );
		}
		return sprintf( '%s/%s', $year, $month );
	}

	return DTB_IMAGE_SYNC_DEFAULT_UPLOAD_RELATIVE_PATH;
}

/**
 * Resolve an absolute uploads directory and base URL for a relative path.
 */

