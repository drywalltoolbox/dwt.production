<?php
defined( 'ABSPATH' ) || exit;

function dtb_link_images_to_product( int $product_id, int $attachment_id, array $gallery_ids = [] ): bool|WP_Error {
	if ( ! function_exists( 'wc_get_product' ) ) {
		// WooCommerce not active — fall back to raw meta.
		set_post_thumbnail( $product_id, $attachment_id );
		if ( ! empty( $gallery_ids ) ) {
			update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
		} else {
			delete_post_meta( $product_id, '_product_image_gallery' );
		}
		return true;
	}

	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return new WP_Error( 'product_not_found', "No WC product found for post ID {$product_id}" );
	}

	$product->set_image_id( $attachment_id );
	if ( method_exists( $product, 'set_gallery_image_ids' ) ) {
		$product->set_gallery_image_ids( $gallery_ids );
	}
	$product->save();

	// Flush product transients so REST API reflects the new images immediately.
	wc_delete_product_transients( $product_id );

	return true;
}

/**
 * Find a WooCommerce product whose SKU matches the filename stem.
 *
 * Checks three variants in order:
 *   1. Direct lower-case match (wc_get_product_id_by_sku — indexed lookup).
 *   2. Upper-case match (e.g. file "ez10-ad" → SKU "EZ10-AD").
 *   3. Hyphen↔underscore normalisation (e.g. "tc_01tt" → "tc-01tt").
 *   4. Case-insensitive meta query fallback.
 *
 * @param string $stem Filename without extension, lower-cased.
 * @return int|null    Product post ID, or null if not found.
 */

function dtb_find_product_by_sku_stem( string $stem ): ?int {
	if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
		foreach ( [
			$stem,
			strtoupper( $stem ),
			str_replace( '_', '-', $stem ),
			str_replace( '-', '_', $stem ),
		] as $candidate ) {
			$id = (int) wc_get_product_id_by_sku( $candidate );
			if ( $id ) {
				return $id;
			}
		}
	}

	// Fallback: case-insensitive meta query.
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$id = $wpdb->get_var( $wpdb->prepare(
		"SELECT post_id FROM {$wpdb->postmeta}
		 WHERE meta_key = '_sku'
		   AND LOWER(meta_value) = %s
		 LIMIT 1",
		$stem
	) );

	return $id ? (int) $id : null;
}

/**
 * Return all image file paths in a directory.
 *
 * @param string   $dir        Absolute path.
 * @param string[] $extensions Allowed extensions without dot.
 * @return string[]
 */

