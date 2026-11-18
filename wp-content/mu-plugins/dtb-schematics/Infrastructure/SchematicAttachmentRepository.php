<?php
/**
 * DTB Schematics — SchematicAttachmentRepository
 *
 * Read-only helper over WordPress attachment data used when building or
 * refreshing a schematic page definition. WordPress attachments remain
 * runtime media records; this repository never treats an attachment's
 * existence as proof that a schematic exists (the `dtb_schematic` CPT is
 * the sole authority for that).
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Describe an attachment for use inside a schematic page definition.
 *
 * @return array{exists:bool,attachment_id:int,url:string,media_type:string,width:int,height:int,sources:array}|null
 */
function dtb_schematic_attachment_repo_describe( int $attachment_id ): ?array {
	if ( $attachment_id <= 0 ) {
		return null;
	}

	$post = get_post( $attachment_id );
	if ( ! $post || 'attachment' !== $post->post_type ) {
		return [
			'exists'        => false,
			'attachment_id' => $attachment_id,
			'url'           => '',
			'media_type'    => '',
			'width'         => 0,
			'height'        => 0,
			'sources'       => [],
		];
	}

	$url  = dtb_wp_media_get_attachment_url( $attachment_id );
	$meta = dtb_wp_media_get_attachment_metadata( $attachment_id );
	// Metadata filters can return non-array error values even though the core
	// function normally returns an array|false. Normalize at this shared
	// boundary so callers never treat WP_Error as an array.
	if ( ! is_array( $meta ) ) {
		$meta = [];
	}

	$width  = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
	$height = isset( $meta['height'] ) ? (int) $meta['height'] : 0;

	$sources = [];
	if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
		$uploads = wp_upload_dir();
		if ( is_wp_error( $uploads ) || ! is_array( $uploads ) || empty( $uploads['baseurl'] ) ) {
			$uploads = [];
		}
		$base_url = ! empty( $uploads['baseurl'] ) ? trailingslashit( $uploads['baseurl'] ) : '';
		$dirname  = isset( $meta['file'] ) ? trailingslashit( dirname( (string) $meta['file'] ) ) : '';
		if ( '.' === $dirname ) {
			$dirname = '';
		}

		foreach ( $meta['sizes'] as $size_data ) {
			if ( ! is_array( $size_data ) || empty( $size_data['file'] ) || empty( $size_data['width'] ) ) {
				continue;
			}
			if ( '' === $base_url ) {
				continue;
			}
			$sources[] = [
				'url'   => $base_url . $dirname . $size_data['file'],
				'width' => (int) $size_data['width'],
			];
		}
	}

	return [
		'exists'        => true,
		'attachment_id' => $attachment_id,
		'url'           => $url,
		'media_type'    => (string) $post->post_mime_type,
		'width'         => $width,
		'height'        => $height,
		'sources'       => $sources,
	];
}
