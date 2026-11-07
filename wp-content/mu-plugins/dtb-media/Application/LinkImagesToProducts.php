<?php
defined( 'ABSPATH' ) || exit;

/**
 * Link already-registered image attachments to WooCommerce products.
 *
 * This mode does not register files. It is intended for the post-import step
 * after products from the active WooCommerce import CSV exist in WooCommerce
 * and image files were already registered in the Media Library.
 *
 * Product targeting is by SKU, but image resolution is by exact basenames from
 * the CSV Images column (no filename inference from SKU).
 */
function dtb_route_link_registered_images( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$lock_token = dtb_image_sync_acquire_lock( 'link_registered_images' );
	if ( is_wp_error( $lock_token ) ) {
		return $lock_token;
	}

	try {
	$relative_path = dtb_image_sync_resolve_relative_upload_path( $request );
	if ( is_wp_error( $relative_path ) ) {
		return $relative_path;
	}

	$upload_path_info = dtb_image_sync_resolve_upload_directory( $relative_path );
	$scan_dir   = trailingslashit( $upload_path_info['basedir'] );
	$scan_url   = trailingslashit( $upload_path_info['baseurl'] );
	$relative_directory = $upload_path_info['relative'];
	$dry_run = (bool) $request->get_param( 'dry_run' );
	$force   = (bool) $request->get_param( 'force' );
	$offset  = (int) $request->get_param( 'offset' );
	$limit   = (int) $request->get_param( 'limit' );

	// wp_upload_dir() used only for the traversal base check below.
	$upload_dir = wp_upload_dir();

	$real_scan = realpath( $scan_dir );
	$real_base = realpath( $upload_dir['basedir'] );
	if ( ! $real_scan || ! $real_base || strncmp( $real_scan, $real_base, strlen( $real_base ) ) !== 0 ) {
		return new WP_Error( 'invalid_path', 'Resolved path is outside the uploads directory.', [ 'status' => 400 ] );
	}
	if ( ! is_dir( $scan_dir ) ) {
		return new WP_Error( 'dir_not_found', "Directory not found: wp-content/uploads/{$relative_directory}", [ 'status' => 404 ] );
	}

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$sku_rows = $wpdb->get_results(
		"SELECT p.ID AS product_id,
		        p.post_type,
		        p.post_parent,
		        pm.meta_value AS sku
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} pm
		         ON pm.post_id  = p.ID
		        AND pm.meta_key = '_sku'
		 WHERE p.post_type   IN ('product', 'product_variation')
		   AND p.post_status != 'trash'
		   AND pm.meta_value != ''
		 ORDER BY p.ID ASC",
		ARRAY_A
	);

	$sku_map = [];
	foreach ( $sku_rows as $row ) {
		$sku_map[ strtolower( trim( $row['sku'] ) ) ] = [
			'product_id' => (int) $row['product_id'],
			'post_type'  => (string) $row['post_type'],
			'parent_id'  => (int) $row['post_parent'],
		];
	}

	// Exact catalog basenames per SKU (from CSV Images column — no guessing).
	$extensions    = [ 'webp', 'jpg', 'jpeg', 'png', 'avif', 'gif' ];
	$csv_sku_files = dtb_get_catalog_image_filenames_by_sku();

	// Disk URL index: basename_lower => { path, url } for resolving attachment IDs.
	$disk_index = [];
	foreach ( dtb_get_image_file_index( $scan_dir, $scan_url, $extensions ) as $file ) {
		$disk_index[ strtolower( $file['filename'] ) ] = [
			'path' => $file['path'],
			'url'  => $file['url'],
		];
	}

	// Collect SKUs that (a) exist in WooCommerce and (b) have at least one
	// exact-basename CSV file present on disk.
	$active_sku_keys = [];
	foreach ( array_keys( $csv_sku_files ) as $sku_lower ) {
		if ( ! isset( $sku_map[ $sku_lower ] ) ) {
			continue;
		}
		foreach ( $csv_sku_files[ $sku_lower ] as $basename ) {
			if ( isset( $disk_index[ strtolower( $basename ) ] ) ) {
				$active_sku_keys[] = $sku_lower;
				break;
			}
		}
	}

	$total = count( $active_sku_keys );
	$batch = ( $limit > 0 )
		? array_slice( $active_sku_keys, $offset, $limit )
		: array_slice( $active_sku_keys, $offset );

	$linked                   = 0;
	$skipped                  = 0;
	$no_file                  = 0;
	$missing_attachment_count = 0;
	$errors                   = [];

	foreach ( $batch as $sku_lower ) {
		$product_record = $sku_map[ $sku_lower ];
		$product_id     = (int) $product_record['product_id'];

		$csv_basenames = $csv_sku_files[ $sku_lower ] ?? [];
		if ( empty( $csv_basenames ) ) {
			++$no_file;
			continue;
		}

		// Resolve attachment IDs using exact basenames only.
		$att_ids = [];
		foreach ( $csv_basenames as $basename ) {
			$bl = strtolower( $basename );
			if ( ! isset( $disk_index[ $bl ] ) ) {
				continue; // File absent from disk — skip.
			}
			$att = (int) attachment_url_to_postid( $disk_index[ $bl ]['url'] );
			if ( $att > 0 ) {
				$att_ids[] = $att;
			} else {
				++$missing_attachment_count;
			}
		}

		if ( empty( $att_ids ) ) {
			++$no_file;
			continue;
		}

		$primary_att     = $att_ids[0];
		$gallery_att_ids = array_slice( $att_ids, 1 );

		if ( $dry_run ) {
			++$linked;
			continue;
		}

		if ( ! $force ) {
			$current_thumb       = (int) get_post_thumbnail_id( $product_id );
			$current_gallery_ids = [];
			$current_gallery     = get_post_meta( $product_id, '_product_image_gallery', true );
			$current_gallery_ids = array_values( array_filter( array_map( 'absint', explode( ',', (string) $current_gallery ) ) ) );
			if ( $current_thumb === $primary_att && $current_gallery_ids === $gallery_att_ids ) {
				++$skipped;
				continue;
			}
		}

		wp_update_post( [ 'ID' => $primary_att, 'post_parent' => $product_id ] );
		foreach ( $gallery_att_ids as $gallery_att_id ) {
			wp_update_post( [ 'ID' => $gallery_att_id, 'post_parent' => $product_id ] );
		}

		$result = dtb_link_images_to_product( $product_id, $primary_att, $gallery_att_ids );
		if ( is_wp_error( $result ) ) {
			$errors[] = "[{$sku_lower}] link: " . $result->get_error_message();
			dtb_image_sync_log( "image_link_only link error [{$sku_lower}]: " . $result->get_error_message() );
			continue;
		}

		++$linked;
	}

	if ( ! $dry_run && class_exists( 'WC_Cache_Helper' ) ) {
		WC_Cache_Helper::get_transient_version( 'product', true );
	}

	return rest_ensure_response( [
		'status'              => $dry_run ? 'dry_run' : 'completed',
		'directory'           => "wp-content/uploads/{$relative_directory}",
		'total'               => $total,
		'offset'              => $offset,
		'limit'               => $limit,
		'scanned'             => count( $batch ),
		'linked'              => $linked,
		'skipped'             => $skipped,
		'no_file'             => $no_file,
		'missing_attachments' => $missing_attachment_count,
		'errors'              => $errors,
		'dry_run'             => $dry_run,
		'next_offset'         => ( $limit > 0 && ( $offset + $limit ) < $total )
			? $offset + $limit
			: null,
		'link_only'           => true,
		'image_match_mode'    => 'csv_images_exact_basename',
	] );
	} finally {
		dtb_image_sync_release_lock( $lock_token, true );
	}
}

// ============================================================================
// GET /dtb/v1/sync-images/progress
// ============================================================================


