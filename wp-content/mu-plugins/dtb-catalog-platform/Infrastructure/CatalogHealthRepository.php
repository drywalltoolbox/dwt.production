<?php
/**
 * Catalog Health repository.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_CatalogHealthRepository {
	/**
	 * Return paged variable product IDs.
	 *
	 * @param int $page     Page number.
	 * @param int $per_page Products per page.
	 * @return int[]
	 */
	public static function get_variable_product_ids( int $page, int $per_page ): array {
		$query = new WP_Query( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy' => 'product_type',
					'field'    => 'slug',
					'terms'    => 'variable',
				],
			],
			'fields'         => 'ids',
		] );

		return array_values( array_map( 'intval', (array) $query->posts ) );
	}

	/**
	 * Return paged published product IDs.
	 *
	 * @param int $page     Page number.
	 * @param int $per_page Products per page.
	 * @return int[]
	 */
	public static function get_published_product_ids( int $page, int $per_page ): array {
		$query = new WP_Query( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		] );

		return array_values( array_map( 'intval', (array) $query->posts ) );
	}
}
