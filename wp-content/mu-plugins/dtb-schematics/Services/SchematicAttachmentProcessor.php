<?php
/**
 * Schematic attachment processor.
 *
 * Schematics-specific orchestration for registering Media Library attachments
 * and resolving a safe product parent context from CSV-linked product IDs.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve a valid WooCommerce product parent from linked product IDs.
 *
 * Rules:
 * - Only post types `product` and `product_variation` are accepted.
 * - Variations resolve to their parent product.
 * - Trashed/invalid posts are ignored.
 * - Returns first valid product parent in input order.
 *
 * @param int[] $product_ids Linked product IDs from schematic metadata.
 * @return int Product post ID or 0 when no valid parent exists.
 */
function dtb_schematics_resolve_parent_product_id( array $product_ids ): int {
	foreach ( $product_ids as $candidate ) {
		$id = absint( $candidate );
		if ( $id <= 0 ) {
			continue;
		}

		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || 'trash' === $post->post_status ) {
			continue;
		}

		if ( 'product' === $post->post_type ) {
			return $id;
		}

		if ( 'product_variation' === $post->post_type ) {
			$parent_id = (int) wp_get_post_parent_id( $id );
			if ( $parent_id <= 0 ) {
				continue;
			}

			$parent_post = get_post( $parent_id );
			if ( ! $parent_post instanceof WP_Post || 'product' !== $parent_post->post_type || 'trash' === $parent_post->post_status ) {
				continue;
			}

			return $parent_id;
		}
	}

	return 0;
}
