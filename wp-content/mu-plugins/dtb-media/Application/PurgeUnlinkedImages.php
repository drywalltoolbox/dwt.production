<?php
defined( 'ABSPATH' ) || exit;

function dtb_route_purge_unlinked_attachments( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	if ( function_exists( 'ini_set' ) ) {
		ini_set( 'memory_limit', '512M' ); // phpcs:ignore
	}
	if ( function_exists( 'set_time_limit' ) ) {
		set_time_limit( 120 ); // phpcs:ignore
	}

	$year    = ltrim( sanitize_text_field( (string) $request->get_param( 'year' ) ),  '/' );
	$month   = ltrim( sanitize_text_field( (string) $request->get_param( 'month' ) ), '/' );
	$dry_run = (bool) $request->get_param( 'dry_run' );
	$limit   = max( 1, (int) $request->get_param( 'limit' ) );
	$offset  = (int) $request->get_param( 'offset' );

	if ( ! ctype_digit( $year ) || ! ctype_digit( $month ) ) {
		return new WP_Error( 'invalid_params', 'year and month must be numeric.', [ 'status' => 400 ] );
	}

	if ( get_transient( DTB_SYNC_LOCK_KEY ) ) {
		return new WP_Error(
			'sync_locked',
			'A sync is already in progress. Use /release-lock if the previous run crashed.',
			[ 'status' => 423 ]
		);
	}

	global $wpdb;
	$relative_prefix = $wpdb->esc_like( "$year/$month/" );

	// All attachments from this directory.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$all_ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT post_id
		 FROM {$wpdb->postmeta}
		 WHERE meta_key = '_wp_attached_file'
		   AND meta_value LIKE %s
		 ORDER BY post_id ASC",
		$relative_prefix . '%'
	) );

	if ( empty( $all_ids ) ) {
		return rest_ensure_response( [
			'status'         => 'completed',
			'total_unlinked' => 0,
			'deleted'        => 0,
			'dry_run'        => $dry_run,
		] );
	}

	// Thumbnails in use.
	$id_list = implode( ',', array_map( 'intval', $all_ids ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$thumbnail_ids = $wpdb->get_col(
		"SELECT DISTINCT CAST(meta_value AS UNSIGNED)
		 FROM {$wpdb->postmeta}
		 WHERE meta_key   = '_thumbnail_id'
		   AND CAST(meta_value AS UNSIGNED) IN ({$id_list})" // phpcs:ignore
	);

	// Gallery IDs in use.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$gallery_raw = $wpdb->get_col(
		"SELECT meta_value
		 FROM {$wpdb->postmeta}
		 WHERE meta_key   = '_product_image_gallery'
		   AND meta_value != ''"
	);
	$gallery_ids = [];
	foreach ( $gallery_raw as $csv ) {
		foreach ( explode( ',', $csv ) as $gid ) {
			$gid = (int) $gid;
			if ( $gid > 0 ) {
				$gallery_ids[ $gid ] = true;
			}
		}
	}

	$in_use      = array_flip( array_map( 'intval', $thumbnail_ids ) );
	$in_use      = array_merge( $in_use, $gallery_ids );
	$unlinked    = array_filter( $all_ids, fn( $id ) => ! isset( $in_use[ (int) $id ] ) );
	$unlinked    = array_values( $unlinked );
	$total       = count( $unlinked );
	$batch       = array_slice( $unlinked, $offset, $limit );

	$deleted = 0;
	$errors  = [];

	if ( ! $dry_run ) {
		foreach ( $batch as $id ) {
			$result = wp_delete_attachment( (int) $id, true );
			if ( false !== $result ) {
				++$deleted;
			} else {
				$errors[] = "Failed to delete attachment ID {$id}";
			}
		}
	}

	return rest_ensure_response( [
		'status'       => $dry_run ? 'dry_run' : 'completed',
		'directory'    => "wp-content/uploads/$year/$month",
		'total_unlinked' => $total,
		'batch_size'   => count( $batch ),
		'deleted'      => $dry_run ? 0 : $deleted,
		'offset'       => $offset,
		'limit'        => $limit,
		'dry_run'      => $dry_run,
		'errors'       => $errors,
		'next_offset'  => ( count( $batch ) === $limit && $total > $offset + $limit )
			? $offset + $limit
			: null,
	] );
}

// ============================================================================
// POST /dtb/v1/sync-images/fix-renamed
// ============================================================================

/**
 * Repair files that wp_unique_filename() renamed during a failed sync run.
 *
 * When WP encounters an attachment record pointing to {sku}.webp it can rename
 * the physical file to {sku}-1.webp. This handler finds {stem}-{n}.{ext} files
 * where no un-suffixed {stem}.{ext} exists and renames them back. Also updates
 * the _wp_attached_file meta to match the renamed file.
 *
 * dry_run=true by default.
 */

