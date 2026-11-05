<?php
defined( 'ABSPATH' ) || exit;

add_action( 'wp_ajax_dtb_image_sync', 'dtb_ajax_image_sync_handler' );

/**
 * Admin-AJAX handler for the DTB Image Sync admin page.
 *
 * Serves as a reliable fallback for environments where WordPress REST API
 * cookie-based nonce authentication is broken (for example, a reverse proxy
 * where the server strips session cookies on /wp-json/ requests).
 *
 * Authentication: check_ajax_referer() verifies the form nonce against the
 * current WP session — this is immune to the REST API "Cookie check failed"
 * issue because admin-ajax.php uses the standard PHP session, not the REST
 * nonce system.
 *
 * @return never — always terminates via wp_send_json_*().
 */
function dtb_ajax_image_sync_handler(): void {
	if ( false === check_ajax_referer( 'dtb_image_sync_admin', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Image sync security check failed. Refresh the page and try again.' ], 403 );
	}

	if ( ! dtb_image_sync_can_manage() ) {
		wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$sync_action = sanitize_key( wp_unslash( $_POST['sync_action'] ?? '' ) );

	$request = new WP_REST_Request();
	// phpcs:disable WordPress.Security.NonceVerification.Missing
	$_ajax_default_path = defined( 'DTB_IMAGE_SYNC_DEFAULT_UPLOAD_RELATIVE_PATH' ) ? DTB_IMAGE_SYNC_DEFAULT_UPLOAD_RELATIVE_PATH : '2026/media';
	$request->set_param( 'upload_path',   sanitize_text_field( wp_unslash( $_POST['upload_path'] ?? $_ajax_default_path ) ) );
	$request->set_param( 'dry_run',       rest_sanitize_boolean( wp_unslash( $_POST['dry_run']     ?? false ) ) );
	$request->set_param( 'force',         rest_sanitize_boolean( wp_unslash( $_POST['force']       ?? false ) ) );
	$request->set_param( 'register_only', rest_sanitize_boolean( wp_unslash( $_POST['register_only'] ?? false ) ) );
	$request->set_param( 'limit',         max( 1, absint( $_POST['limit']  ?? 25 ) ) );
	$request->set_param( 'offset',        max( 0, absint( $_POST['offset'] ?? 0 ) ) );
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	try {
		if ( 'sync_schematics' === $sync_action ) {
			$result = dtb_image_sync_route_schematic_pathway( $request );
		} elseif ( 'progress' === $sync_action ) {
			$result = dtb_route_sync_images_progress();
		} elseif ( 'status' === $sync_action || 'status_snapshot' === $sync_action ) {
			$result = dtb_route_sync_images_status( $request );
		} elseif ( 'link_only' === $sync_action ) {
			$result = dtb_route_link_registered_images( $request );
		} elseif ( 'fix_renamed' === $sync_action ) {
			$result = dtb_route_fix_renamed_files( $request );
		} elseif ( 'release_lock' === $sync_action ) {
			dtb_image_sync_release_lock( null, true );
			$result = rest_ensure_response( [ 'released' => true, 'message' => 'Sync lock released.' ] );
		} else {
			// Default: register (+ link unless register_only).
			$result = dtb_route_sync_images( $request );
		}
	} catch ( Throwable $throwable ) {
		dtb_image_sync_log(
			sprintf(
				'image_sync ajax exception [%s]: %s in %s:%d',
				$sync_action ?: 'sync',
				$throwable->getMessage(),
				$throwable->getFile(),
				$throwable->getLine()
			)
		);

		wp_send_json_error(
			[
				'message' => 'Image sync failed before the batch completed. Open System Manager for diagnostics.',
			],
			500
		);
	}

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
	}

	wp_send_json_success( $result->get_data() );
}

/**
 * Image Sync transport adapter for the Schematics-owned registration/linking
 * pathway. Product-media services must never process schematic diagrams.
 */
function dtb_image_sync_route_schematic_pathway( WP_REST_Request $request ) {
	if ( ! function_exists( 'dtb_schematics_can_manage' ) || ! dtb_schematics_can_manage() ) {
		return new WP_Error( 'dtb_schematic_sync_forbidden', 'You do not have permission to synchronize schematics.' );
	}
	if ( '2026/schematics' !== (string) $request->get_param( 'upload_path' ) ) {
		return new WP_Error( 'dtb_schematic_sync_path_invalid', 'The Schematics pathway is restricted to uploads/2026/schematics.' );
	}
	if ( ! function_exists( 'dtb_schematic_run_operation' ) ) {
		return new WP_Error( 'dtb_schematic_sync_unavailable', 'The Schematics synchronization service is unavailable.' );
	}
	$uploads       = wp_upload_dir();
	if ( is_wp_error( $uploads ) ) {
		return new WP_Error( 'dtb_schematic_sync_uploads_unavailable', 'The WordPress uploads directory is unavailable: ' . $uploads->get_error_message() );
	}
	$schematic_dir = is_array( $uploads ) && ! empty( $uploads['basedir'] ) ? trailingslashit( (string) $uploads['basedir'] ) . '2026/schematics' : '';
	if ( '' === $schematic_dir || ! is_dir( $schematic_dir ) || ! dtb_schematics_directory_has_image_files( $schematic_dir ) ) {
		return new WP_Error( 'dtb_schematic_sync_source_missing', 'No schematic images were found in uploads/2026/schematics.' );
	}

	$dry_run = (bool) $request->get_param( 'dry_run' );
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by the calling AJAX handler.
	if ( ! $dry_run && '1' !== (string) ( $_POST['schematic_confirmation'] ?? '' ) ) {
		return new WP_Error( 'dtb_schematic_sync_confirmation_required', 'Confirm schematic registration and linking before applying changes.' );
	}

	$offset = max( 0, (int) $request->get_param( 'offset' ) );
	$limit  = max( 1, min( DTB_SCHEMATIC_RECONCILE_MAX_BATCH_SIZE, (int) $request->get_param( 'limit' ) ) );
	$run    = dtb_schematic_run_operation(
		[
			'kind'          => DTB_SCHEMATIC_OPERATION_RECONCILE,
			'dry_run'       => $dry_run,
			'operator_id'   => get_current_user_id(),
			'batch_size'    => $limit,
			'resume'        => false,
			'persist_state' => false,
			'state'         => [ 'cursor' => $offset ],
		]
	);
	if ( is_wp_error( $run ) ) {
		return $run;
	}
	if ( 'completed' !== ( $run['status'] ?? '' ) ) {
		return new WP_Error( 'dtb_schematic_sync_failed', (string) ( $run['error'] ?? 'Schematic synchronization failed.' ) );
	}

	$report = (array) ( $run['result'] ?? [] );
	$errors = [];
	foreach ( (array) ( $report['assets'] ?? [] ) as $asset ) {
		if ( DTB_Schematic_Asset_Disposition::AMBIGUOUS_AND_UNRESOLVED === ( $asset['disposition'] ?? '' ) ) {
			$errors[] = sprintf( '%s: %s', $asset['source_filename'] ?? 'unknown', implode( '; ', (array) ( $asset['notes'] ?? [] ) ) );
		}
	}

	return rest_ensure_response(
		[
			'pathway'    => 'schematics',
			'run_id'     => $run['id'],
			'dry_run'    => $dry_run,
			'scanned'    => (int) ( $report['examined'] ?? 0 ),
			'total'      => (int) ( $report['source_row_count'] ?? 0 ),
			'changed'    => (int) ( $report['changed'] ?? 0 ),
			'unchanged'  => (int) ( $report['unchanged'] ?? 0 ),
			'skipped'    => (int) ( $report['skipped'] ?? 0 ),
			'unresolved' => (int) ( $report['unresolved'] ?? 0 ),
			'errors'     => array_slice( $errors, 0, 10 ),
			'next_offset'=> $report['next_offset'] ?? null,
		]
	);
}

/**
 * Register DTB Image Sync page under the DTB Tools admin menu.
 */

