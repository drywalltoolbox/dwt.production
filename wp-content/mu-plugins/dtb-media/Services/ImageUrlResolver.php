<?php
defined( 'ABSPATH' ) || exit;

/**
 * Resolve an absolute uploads directory and base URL for a relative path.
 */
function dtb_image_sync_resolve_upload_directory( string $relative_path ): array {
	$upload_dir = wp_upload_dir();
	$relative_path = trim( $relative_path, '/' );
	return [
		'basedir'  => trailingslashit( $upload_dir['basedir'] ) . $relative_path,
		'baseurl'  => trailingslashit( $upload_dir['baseurl'] ) . $relative_path,
		'relative' => $relative_path,
	];
}

