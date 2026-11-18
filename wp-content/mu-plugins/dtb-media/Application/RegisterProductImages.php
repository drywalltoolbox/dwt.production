<?php
defined( 'ABSPATH' ) || exit;

function dtb_register_image_attachment( string $file_path, string $file_url, int $product_id = 0, bool $generate_subsizes = true ): int|WP_Error {
	if ( ! file_exists( $file_path ) ) {
		return new WP_Error( 'file_not_found', "File not found: {$file_path}" );
	}

	if ( ! function_exists( 'wp_update_image_subsizes' ) ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	$filename = basename( $file_path );
	$filetype = wp_check_filetype( $filename );

	if ( empty( $filetype['type'] ) ) {
		return new WP_Error( 'invalid_filetype', "Unrecognised file type: {$filename}" );
	}

	// Build a clean Media Library title from the {Slug}-{SKU}-{Seq}.webp naming
	// convention used in dtb catalogs (e.g. columbia-10-24-nyloc-nut-FA271-2.webp).
	// Strip the trailing sequence number suffix (-1, -2, …) so the resulting title
	// reads as "columbia 10-24 nyloc nut FA271" rather than including the index.
	$stem_raw = pathinfo( $filename, PATHINFO_FILENAME );                       // e.g. columbia-10-24-nyloc-nut-FA271-2
	$stem_raw = (string) preg_replace( '/-\d+$/', '', $stem_raw );             // → columbia-10-24-nyloc-nut-FA271
	$title    = sanitize_text_field(
		(string) preg_replace( '/[_]+/', ' ', str_replace( '-', ' ', $stem_raw ) )
	);

	// ── 1. Create the attachment post record ─────────────────────────────────
	$attachment_id = wp_insert_attachment(
		[
			'post_mime_type' => $filetype['type'],
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_parent'    => $product_id,
		],
		$file_path,  // Triggers automatic _wp_attached_file meta write.
		$product_id, // Sets post_parent — "Attached to: [Product]" in Media Library.
		true         // Return WP_Error on failure.
	);

	if ( is_wp_error( $attachment_id ) ) {
		return $attachment_id;
	}

	// ── 2. Write guid directly, bypassing WP's permalink rewriter ────────────
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->update(
		$wpdb->posts,
		[ 'guid' => $file_url ],
		[ 'ID'   => $attachment_id ],
		[ '%s' ],
		[ '%d' ]
	);
	clean_post_cache( $attachment_id );

	// ── 3. Build base metadata with dimensions and EXIF/IPTC data ────────────
	$imagesize = function_exists( 'wp_getimagesize' )
		? wp_getimagesize( $file_path )
		: @getimagesize( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

	$upload_dir = wp_upload_dir();
	$relative   = ltrim( str_replace( $upload_dir['basedir'], '', $file_path ), '/\\' );

	$image_meta = [];
	if ( function_exists( 'wp_read_image_metadata' ) ) {
		$image_meta = wp_read_image_metadata( $file_path ) ?: [];
	}

	$meta = [
		'width'      => isset( $imagesize[0] ) ? (int) $imagesize[0] : 0,
		'height'     => isset( $imagesize[1] ) ? (int) $imagesize[1] : 0,
		'file'       => $relative,
		'filesize'   => (int) filesize( $file_path ),
		'sizes'      => [],
		'image_meta' => $image_meta,
	];

	// Persist base metadata before generating sub-sizes. If sub-size generation
	// fails or times out the attachment record is still valid.
	wp_update_attachment_metadata( $attachment_id, $meta );

	// ── 4. Generate optional sub-sizes via the public API ─────────────────────
	// On shared hosting this can be the most expensive step and may timeout on
	// certain files. Register-only runs can safely skip it.
	if ( $generate_subsizes ) {
		try {
			$subsize_result = wp_update_image_subsizes( $attachment_id );
			if ( is_wp_error( $subsize_result ) ) {
				dtb_image_sync_log( 'image_subsizes warning [' . $filename . ']: ' . $subsize_result->get_error_message() );
			}
		} catch ( Throwable $throwable ) {
			dtb_image_sync_log( 'image_subsizes exception [' . $filename . ']: ' . $throwable->getMessage() );
		}
	}

	// ── 5. Clear attachment cache ─────────────────────────────────────────────
	clean_attachment_cache( $attachment_id );

	return (int) $attachment_id;
}

/**
 * Link a primary image and optional gallery to a WooCommerce product.
 *
 * Uses the WC_Product API (set_image_id / set_gallery_image_ids / save) instead
 * of raw set_post_thumbnail() / update_post_meta(). This fires WooCommerce action
 * hooks (woocommerce_product_set_image, etc.) and updates WC object caches.
 *
 * @param int   $product_id     WooCommerce product post ID.
 * @param int   $attachment_id  Attachment post ID for the featured image.
 * @param int[] $gallery_ids    Ordered array of attachment IDs for the gallery.
 * @return true|WP_Error
 */

