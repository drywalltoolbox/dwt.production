<?php
defined( 'ABSPATH' ) || exit;

/**
 * Read raw product meta value.
 */
function dtb_catalog_meta_get( int $product_id, string $meta_key ) {
	return get_post_meta( $product_id, $meta_key, true );
}

/**
 * Read the complete DTB meta map for a product.
 *
 * @return array<string,mixed>
 */
function dtb_catalog_meta_get_dtb_map( int $product_id ): array {
	$dtb_meta = [];

	foreach ( array_keys( DTB_ProductMeta::FIELDS ) as $meta_key ) {
		$raw = dtb_catalog_meta_get( $product_id, $meta_key );
		$dtb_meta[ ltrim( $meta_key, '_' ) ] = '' === $raw ? null : $raw;
	}

	return $dtb_meta;
}
