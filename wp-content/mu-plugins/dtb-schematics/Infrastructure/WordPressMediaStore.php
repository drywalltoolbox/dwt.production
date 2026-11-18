<?php
defined( 'ABSPATH' ) || exit;

/**
 * WordPress media store wrappers.
 */

function dtb_wp_media_get_attachment_image_url( int $attachment_id, string $size = 'thumbnail' ): string {
	$url = wp_get_attachment_image_url( $attachment_id, $size );
	return $url ? (string) $url : '';
}

function dtb_wp_media_get_attachment_url( int $attachment_id ): string {
	$url = wp_get_attachment_url( $attachment_id );
	return $url ? (string) $url : '';
}

/**
 * @return array<string,mixed>|false
 */
function dtb_wp_media_get_attachment_metadata( int $attachment_id ) {
	return wp_get_attachment_metadata( $attachment_id );
}

/**
 * @return array{id:int,name:string,sku:string}[]
 */
function dtb_wp_media_load_products_by_ids( array $product_ids ): array {
	$linked_products = [];

	foreach ( $product_ids as $product_id ) {
		$product = wc_get_product( (int) $product_id );
		if ( ! $product ) {
			continue;
		}

		$linked_products[] = [
			'id'   => $product->get_id(),
			'name' => $product->get_name(),
			'sku'  => $product->get_sku(),
		];
	}

	return $linked_products;
}
