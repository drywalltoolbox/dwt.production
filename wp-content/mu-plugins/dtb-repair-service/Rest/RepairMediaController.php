<?php
/**
 * Rest — RepairMediaController: POST /wp-json/dtb/v1/repairs/{id}/media
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_repair_register_media_route' );

function dtb_repair_register_media_route(): void {
register_rest_route(
'dtb/v1',
'/repairs/(?P<id>\d+)/media',
[
'methods'             => WP_REST_Server::CREATABLE,
'callback'            => 'dtb_repair_rest_media',
'permission_callback' => '__return_true',
'args'                => [
'id' => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
],
]
);
}

/**
 * Extract a public repair token from the request query or Bearer header.
 *
 * @param WP_REST_Request $request
 * @return string
 */
function dtb_repair_rest_media_token( WP_REST_Request $request ): string {
	$token = sanitize_text_field( (string) $request->get_param( 'token' ) );
	if ( '' !== $token ) {
		return $token;
	}

	$auth_header = (string) $request->get_header( 'authorization' );
	if ( preg_match( '/Bearer\s+(.+)/i', $auth_header, $matches ) ) {
		return sanitize_text_field( trim( (string) $matches[1] ) );
	}

	return '';
}

function dtb_repair_rest_media( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$repair_id = (int) $request->get_param( 'id' );
	$token     = dtb_repair_rest_media_token( $request );
	$post      = get_post( $repair_id );

	if ( ! $post || 'dtb_repair_request' !== $post->post_type ) {
		return new WP_Error( 'dtb_repair_not_found', __( 'Repair request not found.', 'drywall-toolbox' ), [ 'status' => 404 ] );
	}

	$access = function_exists( 'dtb_validate_repair_access' )
		? dtb_validate_repair_access( $repair_id, $token )
		: true;
	if ( is_wp_error( $access ) ) {
		return $access;
	}

	// Check terminal states.
	$current_status = (string) get_post_meta( $repair_id, '_repair_status', true );
	$terminal       = [ 'closed', 'cancelled', 'quote_declined' ];
	if ( in_array( $current_status, $terminal, true ) ) {
		return new WP_Error(
			'dtb_repair_terminal',
			__( 'Cannot upload media to a closed or cancelled repair.', 'drywall-toolbox' ),
			[ 'status' => 409 ]
		);
	}

	// Validate files are present.
	$files    = $request->get_file_params();
	$file_key = '';
	foreach ( [ 'files', 'files[]' ] as $possible_key ) {
		if ( isset( $files[ $possible_key ] ) ) {
			$file_key = $possible_key;
			break;
		}
	}
	if ( empty( $files ) || '' === $file_key ) {
		return new WP_Error( 'dtb_repair_no_files', __( 'No files uploaded.', 'drywall-toolbox' ), [ 'status' => 400 ] );
	}

	// Normalize single/multiple file uploads.
	$file_list = dtb_repair_normalize_file_params( $files[ $file_key ] );

	if ( count( $file_list ) > DTB_REPAIR_MAX_MEDIA_FILES ) {
		return new WP_Error(
			'dtb_repair_too_many_files',
			sprintf(
				/* translators: %d: maximum number of files */
				__( 'Maximum %d files per upload.', 'drywall-toolbox' ),
				DTB_REPAIR_MAX_MEDIA_FILES
			),
			[ 'status' => 400 ]
		);
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	$attachment_ids     = [];
	$validation_errors  = [];

	foreach ( $file_list as $index => $file ) {
		// Size check.
		if ( (int) $file['size'] > DTB_REPAIR_MAX_MEDIA_SIZE ) {
			$validation_errors[] = sprintf(
				/* translators: 1: file index, 2: max size in MB */
				__( 'File %1$d exceeds the %2$d MB size limit.', 'drywall-toolbox' ),
				$index + 1,
				DTB_REPAIR_MAX_MEDIA_SIZE / 1024 / 1024
			);
			continue;
		}

		// MIME type check via finfo (more reliable than trusting $_FILES['type']).
		$finfo     = finfo_open( FILEINFO_MIME_TYPE );
		$real_mime = finfo_file( $finfo, $file['tmp_name'] );
		finfo_close( $finfo );

		if ( ! in_array( $real_mime, DTB_REPAIR_ALLOWED_MIME_TYPES, true ) ) {
			$validation_errors[] = sprintf(
				/* translators: 1: file index, 2: detected MIME type */
				__( 'File %1$d has disallowed type "%2$s".', 'drywall-toolbox' ),
				$index + 1,
				esc_html( $real_mime )
			);
			continue;
		}

		// Extension/MIME consistency check.
		$ext      = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		$ext_map  = dtb_repair_allowed_mime_extension_map();
		if ( ! isset( $ext_map[ $ext ] ) || $ext_map[ $ext ] !== $real_mime ) {
			$validation_errors[] = sprintf(
				/* translators: %d: file index */
				__( 'File %d has inconsistent extension and MIME type.', 'drywall-toolbox' ),
				$index + 1
			);
			continue;
		}

		// Use the WP uploader.
		$_FILES['dtb_repair_upload'] = $file;
		$upload_id = media_handle_upload(
			'dtb_repair_upload',
			$repair_id,
			[],
			[ 'test_form' => false ]
		);

		if ( is_wp_error( $upload_id ) ) {
			$validation_errors[] = $upload_id->get_error_message();
			continue;
		}

		// Regenerate metadata to strip EXIF and build thumbnails.
		$attach_data = wp_generate_attachment_metadata( $upload_id, get_attached_file( $upload_id ) );
		wp_update_attachment_metadata( $upload_id, $attach_data );

		$attachment_ids[] = $upload_id;
	}

	if ( ! empty( $validation_errors ) && empty( $attachment_ids ) ) {
		return new WP_Error(
			'dtb_repair_media_invalid',
			implode( ' ', $validation_errors ),
			[ 'status' => 422 ]
		);
	}

	// Append new attachment IDs to existing.
	if ( ! empty( $attachment_ids ) ) {
		$existing_raw = (string) get_post_meta( $repair_id, '_repair_images', true );
		$existing     = ( '' !== $existing_raw ) ? (array) json_decode( $existing_raw, true ) : [];
		$merged       = array_values( array_unique( array_merge( $existing, $attachment_ids ) ) );
		update_post_meta( $repair_id, '_repair_images', wp_json_encode( $merged ) );

		if ( function_exists( 'dtb_repair_append_event' ) ) {
			dtb_repair_append_event(
				$repair_id,
				'repair.media_uploaded',
				[
					'payload'    => [ 'attachment_ids' => $attachment_ids ],
					'actor_type' => get_current_user_id() ? 'user' : 'customer',
					'actor_id'   => get_current_user_id() ?: null,
					'visibility' => 'customer',
				]
			);
		}
	}

	return new WP_REST_Response(
		[
			'attachment_ids'   => $attachment_ids,
			'errors'           => $validation_errors,
			'total_uploaded'   => count( $attachment_ids ),
		],
		200
	);
}
