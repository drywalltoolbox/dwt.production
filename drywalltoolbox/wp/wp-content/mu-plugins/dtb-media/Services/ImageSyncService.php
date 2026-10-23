<?php
defined( 'ABSPATH' ) || exit;

function dtb_build_image_sync_snapshot( string $relative_path ): array {
	$upload_path_info    = dtb_image_sync_resolve_upload_directory( $relative_path );
	$scan_dir            = trailingslashit( $upload_path_info['basedir'] );
	$base_url            = trailingslashit( $upload_path_info['baseurl'] );
	$relative_directory  = $upload_path_info['relative'];
	$dir_exists          = is_dir( $scan_dir );
	$image_exts          = [ 'webp', 'jpg', 'jpeg', 'png', 'avif', 'gif', 'svg' ];
	$file_index          = $dir_exists ? dtb_get_image_file_index( $scan_dir, $base_url, $image_exts ) : [];
	$files_on_disk       = count( $file_index );
	$config              = function_exists( 'dtb_get_config' ) ? dtb_get_config() : [];
	$expected_by_sku     = dtb_get_catalog_image_filenames_by_sku();
	$missing_disk_by_sku = $dir_exists ? dtb_get_catalog_missing_image_filenames_by_sku( $scan_dir, $base_url, $image_exts ) : $expected_by_sku;
	$progress            = get_transient( DTB_SYNC_PROGRESS_KEY ) ?: null;
	$sync_locked         = (bool) get_transient( DTB_SYNC_LOCK_KEY );

	global $wpdb;

	$disk_by_basename          = [];
	$disk_basenames_present    = [];
	$expected_unique_basenames = [];
	$expected_image_refs_total = 0;

	foreach ( $file_index as $file ) {
		$basename_lower = strtolower( $file['filename'] );
		$disk_by_basename[ $basename_lower ] = $file;
		$disk_basenames_present[ $basename_lower ] = true;
	}

	foreach ( $expected_by_sku as $sku => $filenames ) {
		$expected_image_refs_total += count( $filenames );
		foreach ( $filenames as $filename ) {
			$expected_unique_basenames[ strtolower( $filename ) ] = $filename;
		}
	}

	$relative_prefix = $wpdb->esc_like( $relative_directory . '/' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$attachment_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT pm.post_id AS attachment_id,
			        pm.meta_value AS attached_file,
			        p.post_parent AS post_parent
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p
			         ON p.ID = pm.post_id
			        AND p.post_type = 'attachment'
			 WHERE pm.meta_key = '_wp_attached_file'
			   AND pm.meta_value LIKE %s",
			$relative_prefix . '%'
		),
		ARRAY_A
	);

	$attachments_by_basename = [];
	$attachment_id_lookup    = [];
	foreach ( $attachment_rows as $row ) {
		$attachment_id  = (int) $row['attachment_id'];
		$attached_file  = (string) $row['attached_file'];
		$basename_lower = strtolower( basename( $attached_file ) );
		$record         = [
			'attachment_id' => $attachment_id,
			'attached_file' => $attached_file,
			'post_parent'   => (int) $row['post_parent'],
			'basename'      => basename( $attached_file ),
		];
		$attachments_by_basename[ $basename_lower ][] = $record;
		$attachment_id_lookup[ $attachment_id ]       = $record;
	}

	$registered_count = count( $attachment_rows );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$product_rows = $wpdb->get_results(
		"SELECT p.ID AS product_id,
		        p.post_type,
		        p.post_parent,
		        sku.meta_value   AS sku,
		        thumb.meta_value AS thumbnail_id,
		        gallery.meta_value AS gallery_meta
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} sku
		         ON sku.post_id = p.ID
		        AND sku.meta_key = '_sku'
		 LEFT JOIN {$wpdb->postmeta} thumb
		        ON thumb.post_id = p.ID
		       AND thumb.meta_key = '_thumbnail_id'
		 LEFT JOIN {$wpdb->postmeta} gallery
		        ON gallery.post_id = p.ID
		       AND gallery.meta_key = '_product_image_gallery'
		 WHERE p.post_type IN ('product', 'product_variation')
		   AND p.post_status != 'trash'
		   AND sku.meta_value != ''
		 ORDER BY p.ID ASC",
		ARRAY_A
	);

	$thumbnail_ids = array_values( array_unique( array_filter( array_map( static function ( array $row ): int {
		return absint( $row['thumbnail_id'] ?? 0 );
	}, (array) $product_rows ) ) ) );
	$thumbnail_files_by_id = [];
	if ( ! empty( $thumbnail_ids ) ) {
		$thumbnail_placeholders = implode( ',', array_fill( 0, count( $thumbnail_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$thumbnail_file_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value AS attached_file
				 FROM {$wpdb->postmeta}
				 WHERE meta_key = '_wp_attached_file'
				   AND post_id IN ({$thumbnail_placeholders})",
				$thumbnail_ids
			),
			ARRAY_A
		);
		foreach ( $thumbnail_file_rows as $thumbnail_file_row ) {
			$thumbnail_files_by_id[ (int) $thumbnail_file_row['post_id'] ] = (string) $thumbnail_file_row['attached_file'];
		}
	}

	$sku_map = [];
	foreach ( $product_rows as $row ) {
		$sku_lower = strtolower( trim( (string) $row['sku'] ) );
		$sku_map[ $sku_lower ] = [
			'product_id'    => (int) $row['product_id'],
			'post_type'     => (string) $row['post_type'],
			'parent_id'     => (int) $row['post_parent'],
			'thumbnail_id'  => absint( $row['thumbnail_id'] ?? 0 ),
			'gallery_ids'   => dtb_image_sync_parse_gallery_ids( (string) ( $row['gallery_meta'] ?? '' ) ),
		];
	}

	$missing_wc_skus            = [];
	$missing_disk_samples       = [];
	$missing_attachment_samples = [];
	$primary_mismatch_samples   = [];
	$gallery_mismatch_samples   = [];
	$expected_present_refs      = 0;
	$expected_missing_refs      = 0;
	$missing_disk_report        = [];
	$missing_disk_unique        = [];
	$expected_registered_refs   = 0;
	$expected_missing_att_refs  = 0;
	$wc_expected_skus           = 0;
	$primary_ok                 = 0;
	$primary_missing            = 0;
	$gallery_ok                 = 0;
	$gallery_partial            = 0;
	$gallery_missing            = 0;
	$variation_primary_missing  = 0;
	$products_waiting_on_media  = 0;

	foreach ( $expected_by_sku as $sku_lower => $expected_filenames ) {
		$expected_attachment_ids = [];
		$expected_attachment_filenames = [];
		$missing_for_this_sku    = [];

		foreach ( $expected_filenames as $filename ) {
			$basename_lower = strtolower( $filename );
			if ( isset( $disk_basenames_present[ $basename_lower ] ) ) {
				++$expected_present_refs;
				$attachment_candidates = $attachments_by_basename[ $basename_lower ] ?? [];
				if ( ! empty( $attachment_candidates ) ) {
					++$expected_registered_refs;
					$expected_attachment_ids[] = (int) $attachment_candidates[0]['attachment_id'];
					$expected_attachment_filenames[] = $filename;
				} else {
					++$expected_missing_att_refs;
					$missing_for_this_sku[] = $filename;
				}
			} else {
				++$expected_missing_refs;
				$missing_disk_report[] = [
					'sku'      => (string) $sku_lower,
					'filename' => (string) $filename,
				];
				$missing_disk_unique[ strtolower( (string) $filename ) ] = true;
			}
		}

		if ( ! empty( $missing_disk_by_sku[ $sku_lower ] ) ) {
			$missing_disk_samples[] = [
				'sku'      => $sku_lower,
				'expected' => array_values( $missing_disk_by_sku[ $sku_lower ] ),
			];
		}
		if ( ! empty( $missing_for_this_sku ) ) {
			$missing_attachment_samples[] = [
				'sku'      => $sku_lower,
				'expected' => array_values( $missing_for_this_sku ),
			];
		}

		$product = $sku_map[ $sku_lower ] ?? null;
		if ( ! $product ) {
			$missing_wc_skus[] = $sku_lower;
			continue;
		}

		++$wc_expected_skus;
		$is_variation = 'product_variation' === $product['post_type'];

		if ( empty( $expected_attachment_ids ) ) {
			++$products_waiting_on_media;
			if ( $is_variation ) {
				++$variation_primary_missing;
			} else {
				++$primary_missing;
			}
			$primary_mismatch_samples[] = [
				'sku'                    => $sku_lower,
				'product_id'             => (int) $product['product_id'],
				'current_thumbnail_id'   => (int) $product['thumbnail_id'],
				'current_thumbnail_file' => $thumbnail_files_by_id[ (int) $product['thumbnail_id'] ] ?? '',
				'expected_attachment_id' => 0,
				'expected_filename'      => $expected_filenames[0] ?? '',
				'reason'                 => 'expected_image_unavailable',
			];
			continue;
		}

		$expected_primary_id = (int) $expected_attachment_ids[0];
		$current_thumb_id    = (int) $product['thumbnail_id'];
		if ( $current_thumb_id === $expected_primary_id ) {
			++$primary_ok;
		} else {
			if ( $is_variation ) {
				++$variation_primary_missing;
			} else {
				++$primary_missing;
			}
			$primary_mismatch_samples[] = [
				'sku'                   => $sku_lower,
				'product_id'            => (int) $product['product_id'],
				'current_thumbnail_id'  => $current_thumb_id,
				'current_thumbnail_file' => $thumbnail_files_by_id[ $current_thumb_id ] ?? '',
				'expected_attachment_id' => $expected_primary_id,
				'expected_filename'      => $expected_attachment_filenames[0] ?? ( $expected_filenames[0] ?? '' ),
				'reason'                 => 'primary_image_differs',
			];
		}

		if ( $is_variation ) {
			continue;
		}

		$expected_gallery_ids = array_values( array_map( 'intval', array_slice( $expected_attachment_ids, 1 ) ) );
		$current_gallery_ids  = array_values( array_map( 'intval', $product['gallery_ids'] ) );

		if ( empty( $expected_gallery_ids ) ) {
			if ( empty( $current_gallery_ids ) ) {
				++$gallery_ok;
			} else {
				++$gallery_partial;
				$gallery_mismatch_samples[] = [
					'sku'                 => $sku_lower,
					'product_id'          => (int) $product['product_id'],
					'current_gallery_ids' => $current_gallery_ids,
					'expected_gallery_ids'=> $expected_gallery_ids,
				];
			}
			continue;
		}

		if ( $current_gallery_ids === $expected_gallery_ids ) {
			++$gallery_ok;
		} elseif ( empty( $current_gallery_ids ) ) {
			++$gallery_missing;
			$gallery_mismatch_samples[] = [
				'sku'                 => $sku_lower,
				'product_id'          => (int) $product['product_id'],
				'current_gallery_ids' => $current_gallery_ids,
				'expected_gallery_ids'=> $expected_gallery_ids,
			];
		} else {
			++$gallery_partial;
			$gallery_mismatch_samples[] = [
				'sku'                 => $sku_lower,
				'product_id'          => (int) $product['product_id'],
				'current_gallery_ids' => $current_gallery_ids,
				'expected_gallery_ids'=> $expected_gallery_ids,
			];
		}
	}

	$unexpected_disk_files = [];
	foreach ( $disk_by_basename as $basename_lower => $file ) {
		if ( ! isset( $expected_unique_basenames[ $basename_lower ] ) ) {
			$unexpected_disk_files[] = $file['filename'];
		}
	}

	$orphan_attachments = [];
	$duplicate_attachment_basenames = [];
	foreach ( $attachments_by_basename as $basename_lower => $records ) {
		if ( ! isset( $expected_unique_basenames[ $basename_lower ] ) ) {
			foreach ( $records as $record ) {
				$orphan_attachments[] = [
					'basename'      => $record['basename'],
					'attachment_id' => (int) $record['attachment_id'],
					'post_parent'   => (int) $record['post_parent'],
				];
			}
		}
		if ( count( $records ) > 1 ) {
			$duplicate_attachment_basenames[] = [
				'basename'       => $records[0]['basename'],
				'attachment_ids' => array_values( array_map( static fn( $record ) => (int) $record['attachment_id'], $records ) ),
			];
		}
	}

	$expected_unique_files_total   = count( $expected_unique_basenames );
	$expected_present_unique_files = 0;
	$expected_registered_unique    = 0;
	foreach ( array_keys( $expected_unique_basenames ) as $basename_lower ) {
		if ( isset( $disk_basenames_present[ $basename_lower ] ) ) {
			++$expected_present_unique_files;
		}
		if ( ! empty( $attachments_by_basename[ $basename_lower ] ) ) {
			++$expected_registered_unique;
		}
	}

	$missing_disk_total       = array_sum( array_map( 'count', $missing_disk_by_sku ) );
	$missing_wc_total         = count( $missing_wc_skus );
	$missing_attachment_total = $expected_missing_att_refs;
	$products_link_total      = $wc_expected_skus;
	$primary_error_total      = $primary_missing + $variation_primary_missing;
	$gallery_error_total      = $gallery_missing + $gallery_partial;

	$recommendations = [];
	if ( ! $dir_exists ) {
		$recommendations[] = [
			'severity' => 'error',
			'label'    => 'Upload directory not found',
			'action'   => 'Create or select a valid uploads directory before syncing.',
		];
	}
	if ( $missing_wc_total > 0 ) {
		$recommendations[] = [
			'severity' => 'warning',
			'label'    => 'Catalog SKUs are missing in WooCommerce',
			'action'   => 'Import or publish the missing products before linking images.',
		];
	}
	if ( $missing_disk_total > 0 ) {
		$recommendations[] = [
			'severity' => 'error',
			'label'    => 'Expected image files are missing on disk',
			'action'   => 'Upload the missing basenames listed below before running sync.',
		];
	}
	if ( $missing_attachment_total > 0 ) {
		$recommendations[] = [
			'severity' => 'warning',
			'label'    => 'Files exist on disk but are not registered as media attachments',
			'action'   => 'Run Register Images Only or the full pipeline.',
		];
	}
	if ( $primary_error_total > 0 || $gallery_error_total > 0 ) {
		$recommendations[] = [
			'severity' => 'warning',
			'label'    => 'Registered media is not fully linked to WooCommerce products',
			'action'   => 'Run Link Registered Images or the full pipeline.',
		];
	}
	if ( $sync_locked ) {
		$recommendations[] = [
			'severity' => 'warning',
			'label'    => 'Sync lock is active',
			'action'   => 'Wait for the current run to finish or release the lock if the progress is stale.',
		];
	}

	$elapsed_seconds = 0;
	$throughput_per_min = 0.0;
	$eta_seconds = null;
	if ( is_array( $progress ) && ! empty( $progress['started_at'] ) ) {
		$started_at = strtotime( (string) $progress['started_at'] );
		if ( false !== $started_at ) {
			$elapsed_seconds = max( 0, time() - $started_at );
			$processed = (int) ( $progress['processed'] ?? 0 );
			if ( $elapsed_seconds > 0 && $processed > 0 ) {
				$throughput_per_min = round( ( $processed / $elapsed_seconds ) * 60, 2 );
			}
			$remaining = max( 0, (int) ( $progress['batch_total'] ?? 0 ) - $processed );
			if ( $throughput_per_min > 0 && $remaining > 0 ) {
				$eta_seconds = (int) round( ( $remaining / $throughput_per_min ) * 60 );
			}
		}
	}

	$disk_errors    = ( ! $dir_exists ? 1 : 0 ) + $missing_disk_total;
	$disk_warnings  = count( $unexpected_disk_files );
	$media_errors   = $missing_attachment_total;
	$media_warnings = count( $duplicate_attachment_basenames ) + count( $orphan_attachments );
	$link_errors    = $primary_error_total + $gallery_missing;
	$link_warnings  = $gallery_partial;
	$catalog_errors = count( $config['csv_missing'] ?? [] );
	$catalog_warnings = $missing_wc_total;

	$health = [
		'catalog' => dtb_image_sync_health_label( $catalog_errors, $catalog_warnings ),
		'disk'    => dtb_image_sync_health_label( $disk_errors, $disk_warnings ),
		'media'   => dtb_image_sync_health_label( $media_errors, $media_warnings ),
		'links'   => dtb_image_sync_health_label( $link_errors, $link_warnings ),
		'run'     => dtb_image_sync_health_label( 0, 0, is_array( $progress ) && ! empty( $progress ), $sync_locked && empty( $progress ) ),
	];
	$health['overall'] = dtb_image_sync_health_label(
		$catalog_errors + $disk_errors + $media_errors + $link_errors,
		$catalog_warnings + $disk_warnings + $media_warnings + $link_warnings,
		false,
		$sync_locked && empty( $progress )
	);

	return [
		'directory'        => "wp-content/uploads/{$relative_directory}",
		'dir_exists'       => $dir_exists,
		'files_on_disk'    => $files_on_disk,
		'registered_in_db' => $registered_count,
		'linked_products'  => $primary_ok,
		'gallery_products' => $gallery_ok,
		'active_csv'       => $config['csv_filename'] ?? '',
		'csv_source'       => $config['csv_source'] ?? '',
		'csv_missing'      => $config['csv_missing'] ?? [],
		'sync_locked'      => $sync_locked,
		'health'           => $health,
		'catalog'          => [
			'expected_skus_total'              => count( $expected_by_sku ),
			'expected_wc_products_total'       => $wc_expected_skus,
			'expected_missing_wc_products'     => $missing_wc_total,
			'expected_image_references_total'  => $expected_image_refs_total,
			'expected_unique_filenames_total'  => $expected_unique_files_total,
		],
		'disk'             => [
			'files_on_disk_total'              => $files_on_disk,
			'expected_present_references'      => $expected_present_refs,
			'expected_missing_references'      => $expected_missing_refs,
			'expected_present_unique_files'    => $expected_present_unique_files,
			'unexpected_files_total'           => count( $unexpected_disk_files ),
		],
		'media'            => [
			'registered_attachments_total'     => $registered_count,
			'expected_registered_references'   => $expected_registered_refs,
			'expected_missing_attachments'     => $missing_attachment_total,
			'expected_registered_unique_files' => $expected_registered_unique,
			'orphan_attachments_total'         => count( $orphan_attachments ),
			'duplicate_filename_collisions'    => count( $duplicate_attachment_basenames ),
		],
		'links'            => [
			'products_expected_total'          => $products_link_total,
			'products_with_correct_primary'    => $primary_ok,
			'products_missing_primary'         => $primary_missing,
			'variations_missing_primary'       => $variation_primary_missing,
			'products_waiting_on_media'        => $products_waiting_on_media,
			'products_with_complete_gallery'   => $gallery_ok,
			'products_missing_gallery'         => $gallery_missing,
			'products_partial_gallery'         => $gallery_partial,
		],
		'run'              => [
			'locked'               => $sync_locked,
			'progress'             => $progress,
			'elapsed_seconds'      => $elapsed_seconds,
			'throughput_per_min'   => $throughput_per_min,
			'eta_seconds'          => $eta_seconds,
		],
		'recommendations'  => $recommendations,
		'samples'          => [
			'missing_wc_skus'               => array_slice( $missing_wc_skus, 0, 20 ),
			'missing_disk_files'            => array_slice( $missing_disk_samples, 0, 20 ),
			'missing_attachments'           => array_slice( $missing_attachment_samples, 0, 20 ),
			'primary_mismatches'            => array_slice( $primary_mismatch_samples, 0, 20 ),
			'gallery_mismatches'            => array_slice( $gallery_mismatch_samples, 0, 20 ),
			'unexpected_disk_files'         => array_slice( $unexpected_disk_files, 0, 20 ),
			'orphan_attachments'            => array_slice( $orphan_attachments, 0, 20 ),
			'duplicate_attachment_basenames'=> array_slice( $duplicate_attachment_basenames, 0, 20 ),
		],
		'reports'          => [
			'missing_disk_files'       => $missing_disk_report,
			'missing_disk_unique_count'=> count( $missing_disk_unique ),
			'primary_mismatches'       => $primary_mismatch_samples,
		],
	];
}


function dtb_image_sync_log( string $message ): void {
	if ( function_exists( 'dtb_log' ) ) {
		dtb_log( $message );
	} else {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[DTB Image Sync] ' . $message );
	}
}


function dtb_image_sync_get_status(): array {
	$last_run_at = get_option( 'dtb_image_sync_last_run_at', null );
	$last_synced = (int) get_option( 'dtb_image_sync_last_synced', 0 );
	$last_errors = (int) get_option( 'dtb_image_sync_last_errors', 0 );

	if ( ! $last_run_at ) {
		$health = 'never';
	} elseif ( $last_errors > 0 ) {
		$health = 'warning';
	} else {
		$health = 'ok';
	}

	return [
		'last_run_at' => $last_run_at,
		'last_synced' => $last_synced,
		'last_errors' => $last_errors,
		'health'      => $health,
	];
}

/**
 * Record a completed sync run's metrics in wp_options.
 *
 * Called after a non-dry-run sync completes its final batch.
 *
 * @param int $synced Number of images successfully synced/registered/linked.
 * @param int $errors Number of hard failures.
 */
function dtb_image_sync_log_run( int $synced, int $errors ): void {
	update_option( 'dtb_image_sync_last_run_at', gmdate( 'c' ), false );
	update_option( 'dtb_image_sync_last_synced', $synced, false );
	update_option( 'dtb_image_sync_last_errors', $errors, false );
}

