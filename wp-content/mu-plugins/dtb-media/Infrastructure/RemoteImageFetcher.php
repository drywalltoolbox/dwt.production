<?php
defined( 'ABSPATH' ) || exit;

function dtb_probe_image_hash_variants( string $dir, string $url, string $sku_lower, array $extensions ): array {
	static $cache = [];

	if ( ! is_dir( $dir ) ) {
		return [];
	}

	$cache_key = md5( trailingslashit( $dir ) . '|' . trailingslashit( $url ) . '|' . implode( ',', $extensions ) );
	if ( ! isset( $cache[ $cache_key ] ) ) {
		$index = [];
		foreach ( dtb_get_image_file_index( $dir, $url, $extensions ) as $file ) {
			if ( 1 !== preg_match( '/^(.+)_([0-9a-f]{6,16})$/', $file['stem'], $m ) ) {
				continue;
			}

			$index[ $m[1] ][] = [
				'path' => $file['path'],
				'url'  => $file['url'],
			];
		}

		foreach ( $index as &$matches ) {
			usort( $matches, static fn( $a, $b ) => strcmp( $a['path'], $b['path'] ) );
		}
		unset( $matches );

		$cache[ $cache_key ] = $index;
	}

	return $cache[ $cache_key ][ $sku_lower ] ?? [];
}

/**
 * Register a file that already exists on disk as a WordPress media attachment.
 *
 * Does NOT move or copy the file. The file must be at $file_path before calling.
 *
 * APPROACH:
 *   1. wp_insert_attachment($args, $file_path) — official WP attachment creation.
 *      The $file_path second argument causes WP to write _wp_attached_file meta
 *      with the correct relative path automatically. This is the indexed path used
 *      by attachment_url_to_postid() for future lookups.
 *
 *   2. guid written directly via $wpdb->update() — wp_insert_post() passes any
 *      'guid' value in $args through wp_unique_post_slug() which corrupts the URL
 *      into a permalink slug. Writing directly to the column bypasses that.
 *
 *   3. wp_read_image_metadata($file_path) — extracts EXIF/IPTC data (camera,
 *      copyright, caption, keywords) and stores it in the 'image_meta' key of
 *      the attachment metadata. This is the same data WP Admin populates when
 *      images are uploaded via the UI.
 *
 *   4. wp_update_image_subsizes($attachment_id) [WP 5.3+] — the public API wrapper
 *      around _wp_make_subsizes(). Generates only the four WC-required sub-sizes
 *      (thumbnail, woocommerce_thumbnail, woocommerce_single,
 *      woocommerce_gallery_thumbnail), skips any already generated (idempotent),
 *      and does NOT call wp_unique_filename() on the source file.
 *
 *   5. post_parent set to $product_id — makes WP Admin Media Library show
 *      "Attached to: [Product Name]" instead of "Unattached" for each image.
 *
 * @param string $file_path  Absolute server path to the existing image.
 * @param string $file_url   Full public URL for the image.
 * @param int    $product_id WooCommerce product post ID (used as post_parent).
 * @return int|WP_Error      New attachment post ID on success; WP_Error on failure.
 */

