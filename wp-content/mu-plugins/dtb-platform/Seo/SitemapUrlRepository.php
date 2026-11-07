<?php
/**
 * DTB Platform — Sitemap URL repository.
 *
 * Maps WordPress and WooCommerce records to the canonical React storefront
 * route contract without changing commerce ownership.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_SitemapUrlRepository' ) ) {
	return;
}

final class DTB_SitemapUrlRepository {
	public const PAGE_SIZE = 1000;

	/** @return array<string,string> */
	public static function static_routes(): array {
		return [
			'/'                 => 'home',
			'/products'         => 'products',
			'/products/brands'  => 'brands',
			'/parts'            => 'parts',
			'/schematics'       => 'schematics',
			'/repairs'          => 'repairs',
			'/repairs/start'    => 'repair-start',
			'/repairs/packages' => 'repair-packages',
			'/faq'              => 'faq',
			'/calculators'      => 'calculators',
			'/shipping-policy'  => 'shipping-policy',
			'/returns'          => 'returns',
			'/return-policy'    => 'return-policy',
			'/policies'         => 'policies',
			'/contact'          => 'contact',
		];
	}

	/** @return array<int,array{loc:string,lastmod?:string}> */
	public static function static_urls(): array {
		$urls = [];
		foreach ( array_keys( self::static_routes() ) as $path ) {
			$urls[] = [ 'loc' => home_url( $path ) ];
		}
		return $urls;
	}

	public static function product_pages(): int {
		$query = self::product_query( 1, 1 );
		return 0 === (int) $query->found_posts ? 0 : (int) ceil( (int) $query->found_posts / self::PAGE_SIZE );
	}

	/** @return array<int,array{loc:string,lastmod?:string}> */
	public static function product_urls( int $page ): array {
		$query = self::product_query( max( 1, $page ), self::PAGE_SIZE );
		$urls  = [];

		foreach ( $query->posts as $product_id ) {
			$post = get_post( (int) $product_id );
			if ( ! $post instanceof WP_Post || '' === $post->post_name ) {
				continue;
			}
			$urls[] = [
				'loc'     => home_url( '/products/' . rawurlencode( $post->post_name ) ),
				'lastmod' => get_post_modified_time( DATE_W3C, true, $post ),
			];
		}

		return $urls;
	}

	public static function taxonomy_pages( string $taxonomy ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}
		$count = wp_count_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => true ] );
		return is_wp_error( $count ) ? 0 : (int) ceil( (int) $count / self::PAGE_SIZE );
	}

	/** @return array<int,array{loc:string,lastmod?:string}> */
	public static function taxonomy_urls( string $taxonomy, int $page, string $route_prefix ): array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return [];
		}

		$terms = get_terms( [
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
			'number'     => self::PAGE_SIZE,
			'offset'     => ( max( 1, $page ) - 1 ) * self::PAGE_SIZE,
			'orderby'    => 'term_id',
			'order'      => 'ASC',
		] );
		if ( is_wp_error( $terms ) ) {
			return [];
		}

		$urls = [];
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term || '' === $term->slug ) {
				continue;
			}
			$urls[] = [ 'loc' => home_url( trailingslashit( $route_prefix ) . rawurlencode( $term->slug ) ) ];
		}
		return $urls;
	}

	public static function brand_taxonomy(): string {
		foreach ( [ 'product_brand', 'pwb-brand', 'pa_brand' ] as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				return $taxonomy;
			}
		}
		return '';
	}

	private static function product_query( int $page, int $page_size ): WP_Query {
		$tax_query = [];
		$excluded  = get_term_by( 'name', 'exclude-from-search', 'product_visibility' );
		if ( $excluded instanceof WP_Term ) {
			$tax_query[] = [
				'taxonomy' => 'product_visibility',
				'field'    => 'term_taxonomy_id',
				'terms'    => [ (int) $excluded->term_taxonomy_id ],
				'operator' => 'NOT IN',
			];
		}

		$args = [
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'posts_per_page'         => max( 1, min( self::PAGE_SIZE, $page_size ) ),
			'paged'                  => max( 1, $page ),
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				[
					'key'     => '_dtb_seo_noindex',
					'compare' => 'NOT EXISTS',
				],
				[
					'key'     => '_dtb_seo_noindex',
					'value'   => '1',
					'compare' => '!=',
				],
			],
		];
		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		return new WP_Query( $args );
	}
}
