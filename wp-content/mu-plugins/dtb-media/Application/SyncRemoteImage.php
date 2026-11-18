<?php
defined( 'ABSPATH' ) || exit;

/**
 * Main sync route. Scans uploads/2026/media/ (or the upload_path param),
 * registers image files as WP attachments, and links each to its WooCommerce
 * product resolved by SKU.
 *
 * Image file naming convention: {Slug}-{SKU}-{Seq}.webp
 * Example: columbia-10-24-nyloc-nut-FA271-2.webp
 * CSV Images column uses the full URL, e.g.:
 *   https://drywalltoolbox.com/wp-content/uploads/2026/media/columbia-10-24-nyloc-nut-FA271-2.webp
 *
 * Sync strategy (exact-filename, Images-first):
 *   1. Load all product SKUs from the DB in one indexed query.
 *   2. Build disk index: basename_lower => { path, url } from the scan dir.
 *   3. For each SKU, match exact image basenames from the active import CSV
 *      Images column against the disk index — no fuzzy guessing, no stem scanning.
 *   4. Register any unregistered files found on disk via dtb_register_image_attachment().
 *   5. Link thumbnail + gallery to each product via the WC_Product API.
 *   6. Flush WC product transients so REST responses reflect new images.
 */
function dtb_route_sync_images( WP_REST_Request $request ): WP_REST_Response|WP_Error {

	// ── Acquire sync lock ────────────────────────────────────────────────────
	$lock_token = dtb_image_sync_acquire_lock( 'sync_images' );
	if ( is_wp_error( $lock_token ) ) {
		return $lock_token;
	}

	// ── Raise execution limits (best-effort on shared hosting) ──────────────
	if ( function_exists( 'ini_set' ) ) {
		ini_set( 'memory_limit', '512M' ); // phpcs:ignore
	}
	if ( function_exists( 'set_time_limit' ) ) {
		set_time_limit( 300 ); // phpcs:ignore
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
	$register_only = (bool) $request->get_param( 'register_only' );
	$skip_subsizes_in_register_only = defined( 'DTB_IMAGE_SYNC_SKIP_SUBSIZES_IN_REGISTER_ONLY' )
		? (bool) DTB_IMAGE_SYNC_SKIP_SUBSIZES_IN_REGISTER_ONLY
		: true;
	$generate_subsizes = ! ( $register_only && $skip_subsizes_in_register_only );
	$offset  = (int) $request->get_param( 'offset' );
	$limit   = (int) $request->get_param( 'limit' );

	// wp_upload_dir() used only for the traversal base check below.
	$upload_dir = wp_upload_dir();

	// ── Validate path (prevent directory traversal) ─────────────────────────
	$real_scan = realpath( $scan_dir );
	$real_base = realpath( $upload_dir['basedir'] );
	if ( ! $real_scan || ! $real_base || strncmp( $real_scan, $real_base, strlen( $real_base ) ) !== 0 ) {
		return new WP_Error( 'invalid_path', 'Resolved path is outside the uploads directory.', [ 'status' => 400 ] );
	}

	if ( ! is_dir( $scan_dir ) ) {
		return new WP_Error( 'dir_not_found', "Directory not found: wp-content/uploads/{$relative_directory}", [ 'status' => 404 ] );
	}

	// ── Load WP admin image functions ────────────────────────────────────────
	if ( ! function_exists( 'wp_update_image_subsizes' ) ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}
	if ( ! function_exists( 'wp_read_image_metadata' ) ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
	}

	// ── Build exact catalog filename map from CSV ────────────────────────────
	// csv_sku_files: sku_lower => [basename1, basename2, ...] ordered, first = primary.
	// These are the EXACT basenames from the CSV Images column. No guessing.
	$config        = function_exists( 'dtb_get_config' ) ? dtb_get_config() : [];
	$extensions    = [ 'webp', 'jpg', 'jpeg', 'png', 'avif', 'gif' ];
	$csv_sku_files = dtb_get_catalog_image_filenames_by_sku();

	// ── Build disk index: basename_lower => { path, url } ────────────────────
	$disk_index = [];
	foreach ( dtb_get_image_file_index( $scan_dir, $scan_url, $extensions ) as $file ) {
		$disk_index[ strtolower( $file['filename'] ) ] = [
			'path' => $file['path'],
			'url'  => $file['url'],
		];
	}

	// ── Load WooCommerce product SKU → product_id map ─────────────────────────
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

	$total_skus = count( $sku_map );

	// ── Determine batch strategy ──────────────────────────────────────────────
	// register_only OR no WC products yet → batch by every file found on disk.
	// Normal mode → batch by SKUs whose CSV images have at least one exact disk match.
	$batch_mode = 'sku';
	if ( 0 === $total_skus || $register_only ) {
		// File-first mode: register every image on disk so WooCommerce import
		// can resolve them by URL/GUID without needing products to exist yet.
		$batch_mode  = 'file';
		$disk_files  = array_keys( $disk_index ); // basename_lower keys
		$total       = count( $disk_files );
		$batch       = ( $limit > 0 )
			? array_slice( $disk_files, $offset, $limit )
			: array_slice( $disk_files, $offset );
	} else {
		// SKU mode: only include SKUs that (a) exist in WooCommerce AND
		// (b) have at least one exact-basename CSV file present on disk.
		$active_sku_keys = [];
		foreach ( array_keys( $csv_sku_files ) as $sku_lower ) {
			if ( ! isset( $sku_map[ $sku_lower ] ) ) {
				continue; // SKU is in CSV but not yet in WooCommerce — skip.
			}
			foreach ( $csv_sku_files[ $sku_lower ] as $basename ) {
				if ( isset( $disk_index[ strtolower( $basename ) ] ) ) {
					$active_sku_keys[] = $sku_lower;
					break; // At least one exact file found on disk — include SKU.
				}
			}
		}
		$total = count( $active_sku_keys );
		$batch = ( $limit > 0 )
			? array_slice( $active_sku_keys, $offset, $limit )
			: array_slice( $active_sku_keys, $offset );
	}

	$last_item    = '';
	$last_sku     = '';
	$last_product = 0;
	$run_id       = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'dtb-image-sync-', true );
	$run_started_at = gmdate( 'c' );
	$run_started_ts = microtime( true );

	// ── Initialise all counters referenced by the progress-updater closure ──
	$processed      = 0;
	$registered     = 0;
	$linked         = 0;
	$skipped        = 0;
	$no_file        = 0;
	$gallery_images = 0;
	$errors         = [];
	$missing_files  = [];
	$batch_total    = count( $batch );

	$sync_progress_updater = static function () use (
		&$last_item,
		&$last_sku,
		&$last_product,
		&$processed,
		&$registered,
		&$linked,
		&$skipped,
		&$no_file,
		&$gallery_images,
		&$errors,
		$batch_total,
		$total,
		$offset,
		$limit,
		$batch_mode,
		$dry_run,
		$register_only,
		$run_id,
		$run_started_at,
		$run_started_ts
	): void {
		$elapsed_seconds   = max( 0, microtime( true ) - $run_started_ts );
		$throughput_per_min = ( $elapsed_seconds > 0 && $processed > 0 )
			? round( ( $processed / $elapsed_seconds ) * 60, 2 )
			: 0.0;
		$remaining         = max( 0, $batch_total - $processed );
		$eta_seconds       = ( $throughput_per_min > 0 && $remaining > 0 )
			? (int) round( ( $remaining / $throughput_per_min ) * 60 )
			: null;

		set_transient( DTB_SYNC_PROGRESS_KEY, [
			'run_id'         => $run_id,
			'started_at'     => $run_started_at,
			'last_item'      => $last_item,
			'last_sku'       => $last_sku,
			'last_product'   => $last_product,
			'processed'      => $processed,
			'batch_total'    => $batch_total,
			'total'          => $total,
			'offset'         => $offset,
			'limit'          => $limit,
			'batch_mode'     => $batch_mode,
			'registered'     => $registered,
			'linked'         => $linked,
			'skipped'        => $skipped,
			'no_file'        => $no_file,
			'gallery_images' => $gallery_images,
			'dry_run'        => $dry_run,
			'register_only'  => $register_only,
			'elapsed_seconds'=> (int) round( $elapsed_seconds ),
			'throughput_per_min' => $throughput_per_min,
			'eta_seconds'    => $eta_seconds,
			'updated_at'     => gmdate( 'c' ),
		], DTB_SYNC_LOCK_TTL );
	};

	$sync_progress_updater();

	foreach ( $batch as $batch_item ) {
		// ── File-based batch ──────────────────────────────────────────────────
		// register_only mode or no WC products in DB yet: register every disk file.
		// No SKU matching — just register all images so WooCommerce import can
		// resolve them by URL/GUID.
		if ( 'file' === $batch_mode ) {
			$basename_lower = $batch_item; // already a basename_lower key from disk_index
			$entry          = $disk_index[ $basename_lower ] ?? null;
			$last_item      = $basename_lower;

			if ( ! $entry ) {
				++$processed;
				$sync_progress_updater();
				continue;
			}

			if ( $dry_run ) {
				++$registered;
			} else {
				$existing = attachment_url_to_postid( $entry['url'] );
				if ( ! $existing || $force ) {
					$att = dtb_register_image_attachment( $entry['path'], $entry['url'], 0, $generate_subsizes );
					if ( is_wp_error( $att ) ) {
						$errors[] = $basename_lower . ': ' . $att->get_error_message();
						dtb_image_sync_log( 'image_sync file error [' . $basename_lower . ']: ' . $att->get_error_message() );
					} else {
						++$registered;
					}
				} else {
					++$skipped;
				}
			}
			++$processed;
			$sync_progress_updater();
			continue;
		}

		// ── SKU-based batch: register + link using exact filenames only ───────
		// Source of truth: CSV Images column basenames matched against disk_index
		// by exact case-folded basename. No SKU-stem guessing, no fuzzy matching.
		$sku_lower      = $batch_item;
		$last_sku       = $sku_lower;
		$product_record = $sku_map[ $sku_lower ];
		$product_id     = (int) $product_record['product_id'];
		$last_item      = '';
		$last_product   = $product_id;
		++$processed;

		// Get the exact basenames this SKU expects (direct from the CSV).
		$csv_basenames = $csv_sku_files[ $sku_lower ] ?? [];
		if ( empty( $csv_basenames ) ) {
			++$no_file;
			$sync_progress_updater();
			continue;
		}

		// Match each CSV filename against disk_index using exact basename only.
		$matched         = [];
		$missing_on_disk = [];
		foreach ( $csv_basenames as $basename ) {
			$bl = strtolower( $basename );
			if ( isset( $disk_index[ $bl ] ) ) {
				$matched[] = [
					'basename' => $bl,
					'path'     => $disk_index[ $bl ]['path'],
					'url'      => $disk_index[ $bl ]['url'],
				];
			} else {
				$missing_on_disk[] = $basename;
			}
		}

		if ( empty( $matched ) ) {
			++$no_file;
			$missing_files[] = [ 'sku' => $sku_lower, 'expected' => $csv_basenames ];
			$sync_progress_updater();
			continue;
		}

		if ( ! empty( $missing_on_disk ) ) {
			$missing_files[] = [ 'sku' => $sku_lower, 'expected' => $missing_on_disk ];
		}

		$last_item = $matched[0]['basename'];

		if ( $dry_run ) {
			$exists = attachment_url_to_postid( $matched[0]['url'] );
			$exists ? ++$skipped : ++$registered;
			if ( ! $register_only ) {
				++$linked;
			}
			$gallery_images += max( 0, count( $matched ) - 1 );
			$sync_progress_updater();
			continue;
		}

		// ── Register each matched file ────────────────────────────────────────
		$att_ids = [];
		foreach ( $matched as $idx => $mf ) {
			$existing_att = (int) attachment_url_to_postid( $mf['url'] );
			if ( $existing_att && ! $force ) {
				// Already registered — update post_parent only.
				wp_update_post( [ 'ID' => $existing_att, 'post_parent' => $product_id ] );
				$att_ids[] = $existing_att;
				if ( 0 === $idx ) {
					++$skipped;
				} else {
					++$gallery_images;
				}
			} else {
				$att = dtb_register_image_attachment( $mf['path'], $mf['url'], $product_id, $generate_subsizes );
				if ( is_wp_error( $att ) ) {
					$errors[] = "[{$sku_lower}] {$mf['basename']}: " . $att->get_error_message();
					dtb_image_sync_log( "image_sync error [{$sku_lower}] {$mf['basename']}: " . $att->get_error_message() );
					continue;
				}
				$att_ids[] = (int) $att;
				++$registered;
				if ( $idx > 0 ) {
					++$gallery_images;
				}
			}
		}

		if ( empty( $att_ids ) || $register_only ) {
			$sync_progress_updater();
			continue;
		}

		// ── Link primary + gallery to product via WC_Product API ─────────────
		$primary_att     = $att_ids[0];
		$gallery_att_ids = array_slice( $att_ids, 1 );

		$result = dtb_link_images_to_product( $product_id, $primary_att, $gallery_att_ids );
		if ( is_wp_error( $result ) ) {
			$errors[] = "[{$sku_lower}] link: " . $result->get_error_message();
			dtb_image_sync_log( "image_sync link error [{$sku_lower}]: " . $result->get_error_message() );
			$sync_progress_updater();
			continue;
		}
		++$linked;

		$sync_progress_updater();
	}

	// ── Bump WC product cache version so REST responses reflect new images ──
	if ( ! $dry_run && class_exists( 'WC_Cache_Helper' ) ) {
		WC_Cache_Helper::get_transient_version( 'product', true );
	}

	// Record the completed run if not a dry run and we finished this batch.
	if ( ! $dry_run ) {
		$final_offset = ( $limit > 0 && ( $offset + $limit ) < $total ) ? $offset + $limit : null;
		if ( null === $final_offset ) {
			dtb_image_sync_log_run( $registered + $linked, count( $errors ) );
		}
	}

	return rest_ensure_response( [
		'status'          => $dry_run ? 'dry_run' : 'completed',
		'directory'       => "wp-content/uploads/{$relative_directory}",
		'total_skus'      => $total_skus,
		'total'           => $total,
		'offset'          => $offset,
		'limit'           => $limit,
		'scanned'         => count( $batch ),
		'registered'      => $registered,
		'linked'          => $linked,
		'skipped'         => $skipped,
		'no_file'         => $no_file,
		'gallery_images'  => $gallery_images,
		'active_csv'      => $config['csv_filename'] ?? '',
		'csv_source'      => $config['csv_source'] ?? '',
		'csv_missing'     => $config['csv_missing'] ?? [],
		'missing_files'   => $missing_files,
		'errors'          => $errors,
		'dry_run'         => $dry_run,
		'register_only'   => $register_only,
		'generate_subsizes' => $generate_subsizes,
		'next_offset'     => ( $limit > 0 && ( $offset + $limit ) < $total )
			? $offset + $limit
			: null,
		'file_based_sync' => ( 'file' === $batch_mode ),
		'image_match_mode'=> 'csv_images_exact_basename',
	] );
	} finally {
		dtb_image_sync_release_lock( $lock_token, true );
	}
}

