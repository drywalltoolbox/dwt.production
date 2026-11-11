<?php
defined( 'ABSPATH' ) || exit;

function dtb_route_reset_images( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	if ( function_exists( 'ini_set' ) ) {
		ini_set( 'memory_limit', '512M' ); // phpcs:ignore
	}
	if ( function_exists( 'set_time_limit' ) ) {
		set_time_limit( 300 ); // phpcs:ignore
	}

	$relative_path = dtb_image_sync_resolve_relative_upload_path( $request );
	if ( is_wp_error( $relative_path ) ) {
		return $relative_path;
	}

	$relative_prefix = ltrim( $relative_path, '/' ) . '/';
	$dry_run = (bool) $request->get_param( 'dry_run' );

	if ( '' === $relative_path ) {
		return new WP_Error( 'invalid_params', 'upload_path or legacy year/month must be provided.', [ 'status' => 400 ] );
	}

	if ( get_transient( DTB_SYNC_LOCK_KEY ) ) {
		return new WP_Error(
			'sync_locked',
			'A sync is already in progress. Use /release-lock if the previous run crashed.',
			[ 'status' => 423 ]
		);
	}

	global $wpdb;
	// $relative_prefix was set above from the resolved upload path; escape it
	// for use in a LIKE parameter.
	$like_prefix = $wpdb->esc_like( $relative_prefix );

	// Find ALL attachments from this directory via indexed _wp_attached_file meta.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$attachment_ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT post_id
		 FROM {$wpdb->postmeta}
		 WHERE meta_key = '_wp_attached_file'
		   AND meta_value LIKE %s
		 ORDER BY post_id ASC",
		$like_prefix . '%'
	) );

	$total_attachments = count( $attachment_ids );
	$deleted_atts      = 0;
	$errors            = [];

	if ( ! $dry_run ) {
		foreach ( $attachment_ids as $att_id ) {
			$result = wp_delete_attachment( (int) $att_id, true );
			if ( false !== $result ) {
				++$deleted_atts;
			} else {
				$errors[] = "Failed to delete attachment ID {$att_id}";
			}
		}

		// Clear _thumbnail_id and _product_image_gallery from all products.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$product_ids = $wpdb->get_col(
			"SELECT DISTINCT ID FROM {$wpdb->posts}
			 WHERE post_type = 'product' AND post_status != 'trash'"
		);

		foreach ( $product_ids as $pid ) {
			delete_post_meta( (int) $pid, '_thumbnail_id' );
			delete_post_meta( (int) $pid, '_product_image_gallery' );
			clean_post_cache( (int) $pid );
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( (int) $pid );
			}
		}

		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::get_transient_version( 'product', true );
		}

		dtb_image_sync_log( "image_sync reset: deleted {$deleted_atts} attachments from uploads/{$relative_path}" );
	}

	return rest_ensure_response( [
		'status'            => $dry_run ? 'dry_run' : 'completed',
		'directory'         => "wp-content/uploads/{$relative_path}",
		'dry_run'           => $dry_run,
		'total_attachments' => $total_attachments,
		'deleted_atts'      => $dry_run ? 0 : $deleted_atts,
		'errors'            => $errors,
	] );
}

// ============================================================================
// POST /dtb/v1/sync-images/purge-unlinked — DESTRUCTIVE
// ============================================================================

/**
 * Delete attachment records from uploads/<year>/<month>/ that are not set as
 * the _thumbnail_id or in _product_image_gallery of any product.
 *
 * Uses a JOIN-based query instead of FIND_IN_SET for better performance on
 * large catalogs. dry_run=true by default.
 */

