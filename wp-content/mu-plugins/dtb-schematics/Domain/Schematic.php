<?php
defined( 'ABSPATH' ) || exit;

/**
 * Build a normalized schematic admin payload.
 *
 * @param int   $attachment_id Attachment ID.
 * @param array $meta          Normalized schematic meta.
 * @param array $linked_products Linked Woo products.
 */
function dtb_schematic_make_admin_payload( int $attachment_id, array $meta, array $linked_products ): array {
	$thumb = dtb_wp_media_get_attachment_image_url( $attachment_id, 'thumbnail' );
	$url   = dtb_wp_media_get_attachment_url( $attachment_id );

	return [
		'id'           => $attachment_id,
		'brand'        => (string) ( $meta['brand'] ?? '' ),
		'model_number' => (string) ( $meta['model_number'] ?? '' ),
		'model_name'   => (string) ( $meta['model_name'] ?? '' ),
		'part_count'   => (int) ( $meta['part_count'] ?? 0 ),
		'notes'        => (string) ( $meta['notes'] ?? '' ),
		'thumb'        => $thumb ?: '',
		'url'          => $url ?: '',
		'filename'     => basename( $url ?: '' ),
		'products'     => array_values( $linked_products ),
	];
}
