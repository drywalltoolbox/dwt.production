<?php
defined( 'ABSPATH' ) || exit;

// ============================================================================
// ROUTE REGISTRATION
// ============================================================================

add_action( 'rest_api_init', 'dtb_image_sync_register_routes', 10 );

function dtb_image_sync_register_routes(): void {
	$ns = defined( 'DTB_API_NAMESPACE' ) ? DTB_API_NAMESPACE : 'dtb/v1';

	// Shared year/month args used across multiple routes.
	$dir_args = [
		'upload_path' => [
			'required'          => false,
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => 'dtb_image_sync_validate_upload_path',
			'description'       => 'Relative uploads path under wp-content/uploads/. Defaults to the production media path if omitted.',
		],
		'year'  => [
			'required'          => false,
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => fn( $v ) => '' === $v || 1 === preg_match( '/^\d{4}$/', ltrim( (string) $v, '/' ) ),
			'description'       => 'Legacy year folder in wp-content/uploads/. Use upload_path instead.',
		],
		'month' => [
			'required'          => false,
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => fn( $v ) => '' === $v || 1 === preg_match( '/^(0[1-9]|1[0-2])$/', ltrim( (string) $v, '/' ) ),
			'description'       => 'Legacy zero-padded month folder. Use upload_path instead.',
		],
	];

	// POST /dtb/v1/sync-images
	register_rest_route( $ns, '/sync-images', [
		'methods'             => 'POST',
		'callback'            => 'dtb_route_sync_images',
		'permission_callback' => 'dtb_image_sync_permission',
		'args'                => array_merge( $dir_args, [
			'dry_run' => [
				'required'          => false,
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'description'       => 'When true, scan and report without writing to the database.',
			],
			'limit'   => [
				'required'          => false,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'description'       => 'Max SKUs to process. 0 = all. Use with offset for batching.',
			],
			'offset'  => [
				'required'          => false,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'description'       => 'Number of SKUs to skip. Use with limit for batching.',
			],
			'force'   => [
				'required'          => false,
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'description'       => 'When true, re-register and re-link already-synced images.',
			],
			'register_only' => [
				'required'          => false,
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'description'       => 'When true, register attachments but do not link them to products.',
			],
		] ),
	] );

	// GET /dtb/v1/sync-images/status
	register_rest_route( $ns, '/sync-images/status', [
		'methods'             => 'GET',
		'callback'            => 'dtb_route_sync_images_status',
		'permission_callback' => 'dtb_image_sync_permission',
		'args'                => $dir_args,
	] );

	// GET /dtb/v1/sync-images/progress
	register_rest_route( $ns, '/sync-images/progress', [
		'methods'             => 'GET',
		'callback'            => 'dtb_route_sync_images_progress',
		'permission_callback' => 'dtb_image_sync_permission',
	] );

	// POST /dtb/v1/sync-images/link-only
	register_rest_route( $ns, '/sync-images/link-only', [
		'methods'             => 'POST',
		'callback'            => 'dtb_route_link_registered_images',
		'permission_callback' => 'dtb_image_sync_permission',
		'args'                => array_merge( $dir_args, [
			'dry_run' => [
				'required'          => false,
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'description'       => 'When true, scan and report without writing to the database.',
			],
			'limit'   => [
				'required'          => false,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'description'       => 'Max SKUs to process. 0 = all. Use with offset for batching.',
			],
			'offset'  => [
				'required'          => false,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'description'       => 'Number of SKUs to skip. Use with limit for batching.',
			],
			'force'   => [
				'required'          => false,
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'description'       => 'When true, re-link products even when image assignments already match.',
			],
		] ),
	] );

	// POST /dtb/v1/sync-images/reset — DESTRUCTIVE, dry_run=true by default
	register_rest_route( $ns, '/sync-images/reset', [
		'methods'             => 'POST',
		'callback'            => 'dtb_route_reset_images',
		'permission_callback' => 'dtb_image_sync_permission',
		'args'                => array_merge( $dir_args, [
			'dry_run' => [
				'required'          => false,
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'description'       => 'Default TRUE. Pass false to actually execute.',
			],
		] ),
	] );

	// POST /dtb/v1/sync-images/purge-unlinked — DESTRUCTIVE, dry_run=true by default
	register_rest_route( $ns, '/sync-images/purge-unlinked', [
		'methods'             => 'POST',
		'callback'            => 'dtb_route_purge_unlinked_attachments',
		'permission_callback' => 'dtb_image_sync_permission',
		'args'                => array_merge( $dir_args, [
			'dry_run' => [
				'required'          => false,
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'description'       => 'Default TRUE. Pass false to actually delete.',
			],
			'limit'  => [
				'required'          => false,
				'default'           => 500,
				'sanitize_callback' => 'absint',
			],
			'offset' => [
				'required'          => false,
				'default'           => 0,
				'sanitize_callback' => 'absint',
			],
		] ),
	] );

	// POST /dtb/v1/sync-images/fix-renamed
	register_rest_route( $ns, '/sync-images/fix-renamed', [
		'methods'             => 'POST',
		'callback'            => 'dtb_route_fix_renamed_files',
		'permission_callback' => 'dtb_image_sync_permission',
		'args'                => array_merge( $dir_args, [
			'dry_run' => [
				'required'          => false,
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'description'       => 'Default TRUE. Pass false to actually rename.',
			],
		] ),
	] );

	// POST /dtb/v1/sync-images/release-lock
	// Emergency endpoint to clear a stuck sync lock without waiting for TTL.
	register_rest_route( $ns, '/sync-images/release-lock', [
		'methods'             => 'POST',
		'callback'            => function () {
			dtb_image_sync_release_lock( null, true );
			return rest_ensure_response( [ 'released' => true ] );
		},
		'permission_callback' => 'dtb_image_sync_permission',
	] );
}

// ============================================================================
// PERMISSION CALLBACK
// ============================================================================


// ============================================================================
// POST /dtb/v1/sync-images
// ============================================================================

