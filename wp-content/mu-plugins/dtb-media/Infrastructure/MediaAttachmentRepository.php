<?php
defined( 'ABSPATH' ) || exit;

function dtb_list_images_in_dir( string $dir, array $extensions ): array {
	$files = [];
	$it    = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $it as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}
		if ( ! in_array( strtolower( $file->getExtension() ), $extensions, true ) ) {
			continue;
		}
		// Exclude WordPress-generated sub-size variants (e.g. image-480x480.webp).
		// Sub-sizes always end with a -WxH suffix before the extension.
		if ( preg_match( '/-\d+x\d+$/', $file->getBasename( '.' . $file->getExtension() ) ) ) {
			continue;
		}
		$files[] = $file->getPathname();
	}
	sort( $files );
	return $files;
}

/**
 * Log a message via dtb_log() if available, otherwise via error_log.
 *
 * @param string $message Log message.
 */

