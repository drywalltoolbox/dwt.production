<?php
defined( 'ABSPATH' ) || exit;

function dtb_route_fix_renamed_files( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	if ( function_exists( 'ini_set' ) ) {
		ini_set( 'memory_limit', '256M' ); // phpcs:ignore
	}
	if ( function_exists( 'set_time_limit' ) ) {
		set_time_limit( 120 ); // phpcs:ignore
	}

	$relative_path = dtb_image_sync_resolve_relative_upload_path( $request );
	if ( is_wp_error( $relative_path ) ) {
		return $relative_path;
	}

	$upload_path_info = dtb_image_sync_resolve_upload_directory( $relative_path );
	$scan_dir         = trailingslashit( $upload_path_info['basedir'] );
	$relative_dir     = $upload_path_info['relative'];
	$dry_run = (bool) $request->get_param( 'dry_run' );

	if ( get_transient( DTB_SYNC_LOCK_KEY ) ) {
		return new WP_Error(
			'sync_locked',
			'A sync is already in progress. Use /release-lock if the previous run crashed.',
			[ 'status' => 423 ]
		);
	}

	// Early-exit when renaming is globally disabled via constant.
	if ( defined( 'DTB_IMAGE_SYNC_DISABLE_RENAME' ) && DTB_IMAGE_SYNC_DISABLE_RENAME ) {
		return rest_ensure_response( [
			'status'    => 'disabled',
			'directory' => "wp-content/uploads/$relative_dir",
			'dry_run'   => $dry_run,
			'renamed'   => 0,
			'skipped'   => 0,
			'preview'   => [],
			'errors'    => [],
		] );
	}

	if ( ! is_dir( $scan_dir ) ) {
		return new WP_Error( 'dir_not_found', "Directory not found: wp-content/uploads/$relative_dir", [ 'status' => 404 ] );
	}

	$expected_by_sku = dtb_get_catalog_image_filenames_by_sku();
	$expected_exact  = [];
	$expected_by_norm = [];
	foreach ( $expected_by_sku as $filenames ) {
		if ( ! is_array( $filenames ) ) {
			continue;
		}
		foreach ( $filenames as $filename ) {
			$basename = strtolower( basename( (string) $filename ) );
			if ( '' === $basename ) {
				continue;
			}
			$expected_exact[ $basename ] = $basename;
			foreach ( dtb_image_sync_basename_match_keys( $basename ) as $match_key ) {
				if ( ! isset( $expected_by_norm[ $match_key ] ) ) {
					$expected_by_norm[ $match_key ] = $basename;
				}
			}
		}
	}

	if ( empty( $expected_exact ) ) {
		return rest_ensure_response( [
			'status'    => 'no_expected_catalog_images',
			'directory' => "wp-content/uploads/$relative_dir",
			'dry_run'   => $dry_run,
			'renamed'   => 0,
			'skipped'   => 0,
			'preview'   => [],
			'errors'    => [],
		] );
	}

	$extensions = [ 'webp', 'jpg', 'jpeg', 'png', 'avif', 'gif' ];
	$renamed    = 0;
	$skipped    = 0;
	$preview    = [];
	$errors     = [];

	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $scan_dir, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $it as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}

		$ext = strtolower( $file->getExtension() );
		if ( ! in_array( $ext, $extensions, true ) ) {
			continue;
		}

		$current_file  = strtolower( $file->getFilename() );
		if ( isset( $expected_exact[ $current_file ] ) ) {
			++$skipped;
			continue;
		}

		$canonical_file = '';
		foreach ( dtb_image_sync_basename_match_keys( $current_file ) as $match_key ) {
			if ( isset( $expected_by_norm[ $match_key ] ) ) {
				$canonical_file = $expected_by_norm[ $match_key ];
				break;
			}
		}

		if ( '' === $canonical_file ) {
			continue;
		}
		$canonical_path = trailingslashit( $file->getPath() ) . $canonical_file;
		$current_path   = $file->getPathname();

		if ( $canonical_file === $current_file ) {
			++$skipped;
			continue;
		}

		if ( file_exists( $canonical_path ) ) {
			++$skipped;
			continue;
		}

		$preview[] = $file->getFilename() . ' → ' . $canonical_file;

		if ( ! $dry_run ) {
			if ( rename( $current_path, $canonical_path ) ) {
				// Update _wp_attached_file meta so existing attachment records
				// point to the corrected filename.
				global $wpdb;
				$base_scan   = trailingslashit( str_replace( '\\', '/', $scan_dir ) );
				$old_rel_dir = ltrim( str_replace( $base_scan, '', str_replace( '\\', '/', $file->getPathname() ) ), '/' );
				$new_rel_dir = ltrim( str_replace( $base_scan, '', str_replace( '\\', '/', $canonical_path ) ), '/' );
				$relative_old = trailingslashit( $relative_dir ) . $old_rel_dir;
				$relative_new = trailingslashit( $relative_dir ) . $new_rel_dir;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$wpdb->postmeta}
					 SET meta_value = %s
					 WHERE meta_key  = '_wp_attached_file'
					   AND meta_value = %s",
					$relative_new,
					$relative_old
				) );
				dtb_image_sync_log( "image_sync fix-renamed: {$file->getFilename()} → {$canonical_file}" );
				++$renamed;
			} else {
				$errors[] = 'Failed to rename: ' . $file->getFilename();
			}
		} else {
			++$renamed;
		}
	}

	return rest_ensure_response( [
		'status'    => $dry_run ? 'dry_run' : 'completed',
		'directory' => "wp-content/uploads/$relative_dir",
		'dry_run'   => $dry_run,
		'renamed'   => $renamed,
		'skipped'   => $skipped,
		'preview'   => $dry_run ? $preview : [],
		'errors'    => $errors,
	] );
}

/**
 * Build candidate match keys for filename comparisons.
 *
 * Handles separator/casing drift and optional brand-token differences
 * such as `columbia_tools_10ffb_01.webp` vs `columbia-10ffb-01.webp`
 * and `columbia-tools-10ffb-01.webp`.
 *
 * @return string[]
 */
function dtb_image_sync_basename_match_keys( string $basename ): array {
	$basename = strtolower( basename( trim( $basename ) ) );
	if ( '' === $basename ) {
		return [];
	}

	$ext  = strtolower( pathinfo( $basename, PATHINFO_EXTENSION ) );
	$stem = strtolower( pathinfo( $basename, PATHINFO_FILENAME ) );
	$stem = preg_replace( '/[^a-z0-9]+/', '-', $stem ) ?: '';
	$stem = trim( $stem, '-' );

	if ( '' === $stem || '' === $ext ) {
		return [];
	}

	$keys = [ $stem . '.' . $ext ];

	if ( preg_match( '/^([a-z0-9]+)-tools-(.+)$/', $stem, $match ) ) {
		$keys[] = $match[1] . '-' . $match[2] . '.' . $ext;
	}

	if ( preg_match( '/^([a-z0-9]+)-(.+)$/', $stem, $match ) ) {
		$keys[] = $match[1] . '-tools-' . $match[2] . '.' . $ext;
	}

	return array_values( array_unique( $keys ) );
}

// ============================================================================
// HELPERS
// ============================================================================

/**
 * Probe for an image file on disk, checking all supported extensions.
 *
 * @param string   $dir        Absolute path to the upload directory.
 * @param string   $url        Public base URL for the directory.
 * @param string   $stem       Filename without extension (lower-cased).
 * @param string[] $extensions Ordered list of extensions to try.
 * @return array{0: string|null, 1: string|null} [absolute_path, public_url] or [null, null].
 */

