<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_CatalogProductRepository' ) ) {
	return;
}

/**
 * DTB_CatalogProductRepository
 *
 * Query layer for the DTB catalog listing endpoint.
 *
 * Applies all supported filters in WP_Query before pagination so totals and
 * page counts remain correct for filtered catalog views.
 *
 * Important catalog rule:
 * - The canonical `/products` surface may include tools and parts.
 * - Customer-facing tool display categories must not include replacement parts.
 * - Replacement parts are exposed through the dedicated `parts` display bucket.
 *
 * @package drywall-toolbox
 */
final class DTB_CatalogProductRepository {

	/** SKU/name/meta pattern for compound, mud, and cam-lock tube tools. */
	const COMPOUND_TUBE_PATTERN = '(compound[[:space:]_-]*tube|mud[[:space:]_-]*tube|cam[[:space:]_-]*lock[[:space:]_-]*tube|(^|[^A-Z0-9])((CMT|CLT|CT)[0-9]{2,3}(TT)?|CLTBF)([^A-Z0-9]|$))';

	/**
	 * Return a paginated set of product IDs and totals for the current filter set.
	 *
	 * @param  array $filters
	 * @return array{ ids: int[], page: int, perPage: int, total: int, totalPages: int }
	 */
	public static function find_ids( array $filters ): array {
		$page     = max( 1, absint( $filters['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, absint( $filters['per_page'] ?? 24 ) ) );
		$sort     = (string) ( $filters['sort'] ?? 'popular' );
		$search   = (string) ( $filters['search'] ?? '' );

		$args = [
			'post_type'           => 'product',
			'post_status'         => 'publish',
			'ignore_sticky_posts' => true,
			'fields'              => 'ids',
			'posts_per_page'      => $per_page,
			'paged'               => $page,
			'no_found_rows'       => false,
		];

		if ( '' !== $search ) {
			global $wpdb;

			$like = '%' . $wpdb->esc_like( $search ) . '%';

			// WP_Query 's' only searches post_title and post_content.
			// Parts are commonly searched by SKU token (e.g. "BD-100", "PAHC"),
			// so we supplement with a direct meta lookup against _sku.
			$sku_ids = array_map( 'absint', (array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT pm.post_id
					   FROM {$wpdb->postmeta} pm
					  INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
					  WHERE pm.meta_key = '_sku'
					    AND pm.meta_value LIKE %s
					    AND p.post_status = 'publish'
					    AND p.post_type   = 'product'",
					$like
				)
			) );

			if ( ! empty( $sku_ids ) ) {
				// Also collect title/excerpt matches so we don't lose non-SKU results.
				$title_ids = array_map( 'absint', (array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT ID FROM {$wpdb->posts}
						  WHERE ( post_title   LIKE %s
						       OR post_excerpt LIKE %s )
						    AND post_status = 'publish'
						    AND post_type   = 'product'",
						$like,
						$like
					)
				) );

				$combined = array_values( array_unique( array_merge( $sku_ids, $title_ids ) ) );
				// Use post__in so WP_Query applies all remaining meta filters
				// (brand, display_category, is_parts, etc.) against these IDs.
				$args['post__in'] = ! empty( $combined ) ? $combined : [ 0 ];
				// Do NOT set 's' — post__in already covers both title and SKU hits.
			} else {
				// No SKU matches — fall back to WP native full-text search.
				$args['s'] = $search;
			}
		}

		$args = array_merge( $args, self::sort_args( $sort ) );
		// Keep replacement parts after primary tools across every catalog page,
		// while preserving the selected sort within each group.
		$args['dtb_parts_first'] = true;

		$meta_query = [ 'relation' => 'AND' ];

		$brand = (string) ( $filters['brand'] ?? '' );
		if ( '' !== $brand ) {
			$label = DTB_BrandNormalizer::label_from_slug( $brand );
			if ( '' !== $label ) {
				$brand_values = DTB_BrandNormalizer::meta_query_candidates( $brand );
				$meta_query[] = [
					'relation' => 'OR',
					[
						'key'     => DTB_ProductMeta::BRAND_KEY,
						'value'   => $brand_values['keys'],
						'compare' => 'IN',
					],
					[
						'key'     => DTB_ProductMeta::BRAND_LABEL,
						'value'   => $brand_values['labels'],
						'compare' => 'IN',
					],
				];
			} else {
				$meta_query[] = [
					'key'     => DTB_ProductMeta::BRAND_KEY,
					'value'   => $brand,
					'compare' => '=',
				];
			}
		}

		$category = trim( (string) ( $filters['category'] ?? '' ) );
		if ( '' !== $category ) {
			// Canonical storefront navigation is owned by WooCommerce product_cat.
			// Resolve a taxonomy slug first so parent categories naturally include
			// descendants. Preserve the metadata lookup only as a compatibility
			// fallback for existing callers that pass legacy DTB category keys.
			$category_slug = sanitize_title( str_replace( '_', '-', $category ) );
			$category_term = get_term_by( 'slug', $category_slug, 'product_cat' );

			if ( $category_term instanceof WP_Term ) {
				$args['tax_query'] = [
					[
						'taxonomy'         => 'product_cat',
						'field'            => 'term_id',
						'terms'            => [ (int) $category_term->term_id ],
						'include_children' => true,
						'operator'         => 'IN',
					],
				];
			} else {
				$meta_query[] = [
					'key'     => DTB_ProductMeta::CATEGORY_KEY,
					'value'   => $category,
					'compare' => '=',
				];
			}
		}

		$is_parts_constrained = false;

		// `display_category` accepts either a single slug or a comma-separated
		// list of slugs for multi-select storefront filtering. Multiple values
		// are OR-combined: a product qualifies if it matches ANY listed category.
		$display_category_raw    = (string) ( $filters['display_category'] ?? '' );
		$display_category_values = array_values( array_filter(
			array_map( 'trim', explode( ',', $display_category_raw ) ),
			static function ( $value ) {
				return '' !== $value;
			}
		) );

		if ( count( $display_category_values ) > 1 ) {
			// Multi-select path: resolve each slug's matching product IDs
			// independently (reusing the exact single-slug resolution rules
			// below) and union the results so any-match products qualify.
			$union_ids       = [];
			$any_slug_parsed = false;

			foreach ( $display_category_values as $raw_value ) {
				$slug = sanitize_title( $raw_value );
				if ( '' === $slug ) {
					continue;
				}
				$any_slug_parsed = true;
				$union_ids        = array_merge( $union_ids, self::resolve_display_category_ids( $slug, $filters ) );
			}

			if ( $any_slug_parsed ) {
				$union_ids = array_values( array_unique( array_map( 'absint', $union_ids ) ) );
				self::intersect_post_ids( $args, $union_ids );
				$is_parts_constrained = true;
			}
		} elseif ( 1 === count( $display_category_values ) ) {
			$display_category_slug = sanitize_title( $display_category_values[0] );
			$display_category_key  = str_replace( '-', '_', $display_category_slug );
			if ( '' !== $display_category_slug ) {
				if ( self::is_parts_display_category( $display_category_slug ) ) {
					self::intersect_post_ids( $args, self::replacement_part_product_ids() );
					$is_parts_constrained = true;
				} else {
					// Resolve to canonical slug and expand to all known raw DB forms so
					// products imported with any alias variant (e.g. 'Nailspotters',
					// 'nail_spotters', 'Nail Spotters') are matched correctly.
					$canonical  = DTB_CategoryNormalizer::canonical_display_slug( $display_category_key );
					$raw_forms  = DTB_CategoryNormalizer::display_category_raw_forms( $canonical );

					// Always include the standard derived forms as a safety net for
					// any unknown/custom values not covered by the alias map.
					$space_form = str_replace( '_', ' ', $display_category_key );
					$title_form = ucwords( $space_form );
					$hyphen_form = str_replace( '_', '-', $display_category_key );
					$all_forms  = array_values( array_unique( array_filter( array_merge(
						$raw_forms,
						[ $display_category_key, $display_category_slug, $hyphen_form, $space_form, $title_form ]
					) ) ) );

					$is_compound_selection = 'automatic_compound_tubes' === $canonical;
					$is_legacy_tube_category = in_array(
						$canonical,
						[ 'automatic_tapers', 'semi_automatic_tapers', 'corner_tools', 'predator_family' ],
						true
					);
					$compound_ids = ( $is_compound_selection || $is_legacy_tube_category )
						? self::compound_tube_product_ids()
						: [];

					if ( $is_compound_selection ) {
						self::intersect_post_ids( $args, $compound_ids );
					} else {
						$meta_query[] = [
							'key'     => DTB_ProductMeta::DISPLAY_CATEGORY_KEY,
							'value'   => $all_forms,
							'compare' => 'IN',
						];

						// A tube imported under a legacy display category must not leak
						// into that category while its response DTO says Compound Tubes.
						if ( ! empty( $compound_ids ) ) {
							$args['post__not_in'] = array_values( array_unique( array_merge(
								array_map( 'absint', $args['post__not_in'] ?? [] ),
								$compound_ids
							) ) );
						}
					}

					// Tool/category filters must not leak schematic replacement parts.
					if ( ! array_key_exists( 'is_parts', $filters ) || null === $filters['is_parts'] ) {
						$meta_query[] = [
							'relation' => 'OR',
							[
								'key'     => DTB_ProductMeta::IS_PARTS,
								'value'   => '1',
								'compare' => '!=',
							],
							[
								'key'     => DTB_ProductMeta::IS_PARTS,
								'compare' => 'NOT EXISTS',
							],
						];
						$is_parts_constrained = true;
					}
				}
			}
		}

		$tool_family = (string) ( $filters['tool_family'] ?? '' );
		if ( '' !== $tool_family ) {
			$meta_query[] = [
				'key'     => DTB_ProductMeta::TOOL_FAMILY,
				'value'   => $tool_family,
				'compare' => '=',
			];
		}

		$product_kind = (string) ( $filters['product_kind'] ?? '' );
		if ( '' !== $product_kind ) {
			$meta_query[] = [
				'key'     => DTB_ProductMeta::PRODUCT_KIND,
				'value'   => $product_kind,
				'compare' => '=',
			];
		}

		if ( isset( $filters['builder_eligible'] ) && null !== $filters['builder_eligible'] ) {
			$meta_query[] = [
				'key'     => DTB_ProductMeta::BUILDER_ELIGIBLE,
				'value'   => (int) $filters['builder_eligible'] ? '1' : '0',
				'compare' => '=',
			];
		}

		if ( ! $is_parts_constrained && isset( $filters['is_parts'] ) && null !== $filters['is_parts'] ) {
			if ( (int) $filters['is_parts'] ) {
				// Include explicitly flagged parts and legacy parts-category records
				// whose import left the DTB flag blank or absent.
				self::intersect_post_ids( $args, self::replacement_part_product_ids() );
				$is_parts_constrained = true;
			} else {
				// Requesting non-parts: include products whose meta is absent
				// (legacy imports) as well as those explicitly set to a non-'1' value.
				$meta_query[] = [
					'relation' => 'OR',
					[
						'key'     => DTB_ProductMeta::IS_PARTS,
						'value'   => '1',
						'compare' => '!=',
					],
					[
						'key'     => DTB_ProductMeta::IS_PARTS,
						'compare' => 'NOT EXISTS',
					],
				];
			}
		}

		$builder_slot = (string) ( $filters['builder_slot'] ?? '' );
		if ( '' !== $builder_slot ) {
			$meta_query[] = self::meta_token_query( DTB_ProductMeta::BUILDER_SLOTS, $builder_slot );
		}

		$workflow_scope = (string) ( $filters['workflow_scope'] ?? '' );
		if ( '' !== $workflow_scope ) {
			$meta_query[] = self::meta_token_query( DTB_ProductMeta::WORKFLOW_SCOPES, $workflow_scope );
		}

		if ( count( $meta_query ) > 1 ) {
			$args['meta_query'] = $meta_query;
		}

		global $wpdb;
		$parts_first_orderby = static function ( string $orderby, WP_Query $query ) use ( $wpdb ): string {
			if ( empty( $query->query['dtb_parts_first'] ) ) {
				return $orderby;
			}

			$parts_meta_key = esc_sql( DTB_ProductMeta::IS_PARTS );
			$parts_rank     = "CASE WHEN EXISTS (SELECT 1 FROM {$wpdb->postmeta} AS dtb_catalog_part_order WHERE dtb_catalog_part_order.post_id = {$wpdb->posts}.ID AND dtb_catalog_part_order.meta_key = '{$parts_meta_key}' AND dtb_catalog_part_order.meta_value = '1') THEN 1 ELSE 0 END ASC";

			return $parts_rank . ( '' !== $orderby ? ', ' . $orderby : '' );
		};
		add_filter( 'posts_orderby', $parts_first_orderby, 10, 2 );
		try {
			$query = new WP_Query( $args );
		} finally {
			remove_filter( 'posts_orderby', $parts_first_orderby, 10 );
		}

		$ids         = array_values( array_map( 'absint', $query->posts ?? [] ) );
		$total       = absint( $query->found_posts );
		$total_pages = max( 1, absint( $query->max_num_pages ) );

		return [
			'ids'        => $ids,
			'page'       => $page,
			'perPage'    => $per_page,
			'total'      => $total,
			'totalPages' => $total_pages,
		];
	}

	/**
	 * True when a customer-facing display-category selection is the dedicated
	 * replacement parts bucket.
	 */
	private static function is_parts_display_category( string $display_category ): bool {
		return in_array( sanitize_title( $display_category ), [ 'parts', 'repair-parts', 'replacement-parts' ], true );
	}

	/**
	 * Resolve explicit replacement-part flags plus legacy products assigned to
	 * WooCommerce's Replacement Parts category with no meaningful DTB flag.
	 *
	 * Explicit 0 remains authoritative, so accidentally categorized tools do
	 * not enter the parts catalog. This preserves a single shared rule for the
	 * Parts route, display-category facet, and scoped facet counts.
	 *
	 * @return int[]
	 */
	private static function replacement_part_product_ids(): array {
		static $resolved_ids = null;
		if ( null !== $resolved_ids ) {
			return $resolved_ids;
		}

		$base_args = [
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];

		$ids = [];
		self::merge_query_ids( $ids, array_merge( $base_args, [
			'meta_query' => [
				[
					'key'     => DTB_ProductMeta::IS_PARTS,
					'value'   => '1',
					'compare' => '=',
				],
			],
		] ) );

		$parts_term = get_term_by( 'slug', 'replacement-parts', 'product_cat' );
		if ( $parts_term instanceof WP_Term ) {
			self::merge_query_ids( $ids, array_merge( $base_args, [
				'tax_query' => [
					[
						'taxonomy'         => 'product_cat',
						'field'            => 'term_id',
						'terms'            => [ (int) $parts_term->term_id ],
						'include_children' => true,
						'operator'         => 'IN',
					],
				],
				'meta_query' => [
					'relation' => 'OR',
					[
						'key'     => DTB_ProductMeta::IS_PARTS,
						'compare' => 'NOT EXISTS',
					],
					[
						'key'     => DTB_ProductMeta::IS_PARTS,
						'value'   => '',
						'compare' => '=',
					],
				],
			] ) );
		}

		$resolved_ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		return $resolved_ids;
	}

	/**
	 * Resolve the full set of product IDs matching a single display-category
	 * slug, applying the same alias/raw-form expansion, parts-bucket, and
	 * compound-tube special-case rules as the single-value filter path.
	 *
	 * Used by the multi-select `display_category` path to OR-combine several
	 * category selections; each slug is resolved independently and the
	 * resulting ID sets are unioned by the caller.
	 *
	 * @param  string $display_category_slug  Already sanitize_title()'d slug.
	 * @param  array  $filters                Original filter set (checked for an explicit is_parts override).
	 * @return int[]
	 */
	private static function resolve_display_category_ids( string $display_category_slug, array $filters ): array {
		if ( '' === $display_category_slug ) {
			return [];
		}

		$base_args = [
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];

		if ( self::is_parts_display_category( $display_category_slug ) ) {
			return self::replacement_part_product_ids();
		}

		$display_category_key = str_replace( '-', '_', $display_category_slug );

		$canonical  = DTB_CategoryNormalizer::canonical_display_slug( $display_category_key );
		$raw_forms  = DTB_CategoryNormalizer::display_category_raw_forms( $canonical );

		$space_form  = str_replace( '_', ' ', $display_category_key );
		$title_form  = ucwords( $space_form );
		$hyphen_form = str_replace( '_', '-', $display_category_key );
		$all_forms   = array_values( array_unique( array_filter( array_merge(
			$raw_forms,
			[ $display_category_key, $display_category_slug, $hyphen_form, $space_form, $title_form ]
		) ) ) );

		$is_compound_selection    = 'automatic_compound_tubes' === $canonical;
		$is_legacy_tube_category  = in_array(
			$canonical,
			[ 'automatic_tapers', 'semi_automatic_tapers', 'corner_tools', 'predator_family' ],
			true
		);
		$compound_ids = ( $is_compound_selection || $is_legacy_tube_category )
			? self::compound_tube_product_ids()
			: [];

		if ( $is_compound_selection ) {
			$ids = $compound_ids;
		} else {
			$query_args = $base_args;
			$query_args['meta_query'] = [
				[
					'key'     => DTB_ProductMeta::DISPLAY_CATEGORY_KEY,
					'value'   => $all_forms,
					'compare' => 'IN',
				],
			];

			// A tube imported under a legacy display category must not leak
			// into that category while its response DTO says Compound Tubes.
			if ( ! empty( $compound_ids ) ) {
				$query_args['post__not_in'] = $compound_ids;
			}

			$query = new WP_Query( $query_args );
			$ids   = array_map( 'absint', $query->posts ?? [] );
		}

		// Tool/category filters must not leak schematic replacement parts,
		// unless the caller explicitly requested an is_parts value.
		if ( ! array_key_exists( 'is_parts', $filters ) || null === $filters['is_parts'] ) {
			$ids = self::exclude_parts_from_ids( $ids );
		}

		return $ids;
	}

	/**
	 * Filter a product ID list down to those that are NOT flagged as
	 * schematic replacement parts (mirrors the OR/NOT-EXISTS meta_query used
	 * in the single-category filter path).
	 *
	 * @param  int[] $ids
	 * @return int[]
	 */
	private static function exclude_parts_from_ids( array $ids ): array {
		if ( empty( $ids ) ) {
			return [];
		}

		$query = new WP_Query( [
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'post__in'               => array_values( array_unique( array_map( 'absint', $ids ) ) ),
			'meta_query'              => [
				'relation' => 'OR',
				[
					'key'     => DTB_ProductMeta::IS_PARTS,
					'value'   => '1',
					'compare' => '!=',
				],
				[
					'key'     => DTB_ProductMeta::IS_PARTS,
					'compare' => 'NOT EXISTS',
				],
			],
		] );

		return array_map( 'absint', $query->posts ?? [] );
	}

	/** Return product IDs that resolve to the Compound Tubes display category. */
	private static function compound_tube_product_ids(): array {
		static $resolved_ids = null;
		if ( null !== $resolved_ids ) {
			return $resolved_ids;
		}

		$ids       = [];
		$raw_forms = DTB_CategoryNormalizer::display_category_raw_forms( 'automatic_compound_tubes' );
		$base_args = [
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];

		self::merge_query_ids( $ids, array_merge( $base_args, [
			'meta_query' => [
				'relation' => 'OR',
				[
					'key'     => DTB_ProductMeta::DISPLAY_CATEGORY_KEY,
					'value'   => $raw_forms,
					'compare' => 'IN',
				],
				[
					'key'     => '_sku',
					'value'   => self::COMPOUND_TUBE_PATTERN,
					'compare' => 'REGEXP',
				],
				[
					'key'     => DTB_ProductMeta::MPN,
					'value'   => self::COMPOUND_TUBE_PATTERN,
					'compare' => 'REGEXP',
				],
				[
					'key'     => DTB_ProductMeta::MANUFACTURER_SKU,
					'value'   => self::COMPOUND_TUBE_PATTERN,
					'compare' => 'REGEXP',
				],
			],
		] ) );

		$title_ids = [];
		foreach ( [ 'compound tube', 'mud tube', 'cam lock tube', 'camlock tube' ] as $term ) {
			self::merge_query_ids( $title_ids, array_merge( $base_args, [ 's' => $term ] ) );
		}
		foreach ( array_unique( array_map( 'absint', $title_ids ) ) as $product_id ) {
			if ( dtb_catalog_product_name_is_compound_tube_tool( get_the_title( $product_id ) ) ) {
				$ids[] = $product_id;
			}
		}

		$resolved_ids = array_values( array_unique( array_map( 'absint', $ids ) ) );

		return $resolved_ids;
	}

	/** Merge WP_Query ID results into an existing ID accumulator. */
	private static function merge_query_ids( array &$ids, array $args ): void {
		$query = new WP_Query( $args );
		$ids   = array_merge( $ids, array_map( 'absint', $query->posts ?? [] ) );
	}

	/** Intersect a new ID constraint with any search-derived post__in constraint. */
	private static function intersect_post_ids( array &$args, array $ids ): void {
		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );

		if ( isset( $args['post__in'] ) ) {
			$ids = array_values( array_intersect(
				array_map( 'absint', (array) $args['post__in'] ),
				$ids
			) );
		}

		$args['post__in'] = ! empty( $ids ) ? $ids : [ 0 ];
	}

	/**
	 * Build a token-aware meta query for CSV or serialized-array values.
	 *
	 * @param  string $meta_key
	 * @param  string $token
	 * @return array
	 */
	private static function meta_token_query( string $meta_key, string $token ): array {
		$token = sanitize_text_field( $token );

		return [
			'relation' => 'OR',
			[
				'key'     => $meta_key,
				'value'   => $token,
				'compare' => '=',
			],
			[
				'key'     => $meta_key,
				'value'   => '"' . $token . '"',
				'compare' => 'LIKE',
			],
			[
				'key'     => $meta_key,
				'value'   => $token,
				'compare' => 'LIKE',
			],
		];
	}

	/**
	 * Map API sort keys to WP_Query order args.
	 *
	 * @param  string $sort
	 * @return array
	 */
	private static function sort_args( string $sort ): array {
		switch ( $sort ) {
			case 'price-low':
				return [
					'meta_key' => '_price',
					'orderby'  => 'meta_value_num',
					'order'    => 'ASC',
				];
			case 'price-high':
				return [
					'meta_key' => '_price',
					'orderby'  => 'meta_value_num',
					'order'    => 'DESC',
				];
			case 'newest':
				return [
					'orderby' => 'date',
					'order'   => 'DESC',
				];
			case 'az':
				return [
					'orderby' => 'title',
					'order'   => 'ASC',
				];
			case 'popular':
			default:
				return [
					'orderby' => [
						'menu_order' => 'ASC',
						'title'      => 'ASC',
					],
					'order'   => 'ASC',
				];
		}
	}
}
