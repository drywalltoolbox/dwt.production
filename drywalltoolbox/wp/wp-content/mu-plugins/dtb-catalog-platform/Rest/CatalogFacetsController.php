<?php
/**
 * Catalog facets and product-category navigation endpoints.
 *
 * WooCommerce product_cat is the runtime navigation authority. Facets are
 * built locally from the indexed catalog and WooCommerce product objects.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_CatalogFacetsController {

	public static function register_routes(): void {
		register_rest_route( 'dtb/v1', '/catalog/facets', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'handle' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'brand'            => [ 'sanitize_callback' => 'sanitize_text_field' ],
				'search'           => [ 'sanitize_callback' => 'sanitize_text_field' ],
				'category'         => [ 'sanitize_callback' => 'sanitize_key' ],
				'display_category' => [ 'sanitize_callback' => 'sanitize_text_field' ],
				'product_kind'     => [ 'sanitize_callback' => 'sanitize_key' ],
				'is_parts'         => [ 'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );

		register_rest_route( 'dtb/v1', '/catalog/category', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'handle_category' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'slug' => [ 'sanitize_callback' => 'sanitize_title' ],
			],
		] );
	}

	public static function handle_category( WP_REST_Request $request ): WP_REST_Response {
		if ( ! dtb_check_origin() ) {
			return new WP_REST_Response( dtb_error_envelope( 'forbidden_origin', 'Origin not allowed.', 403 ), 403 );
		}

		$slug = sanitize_title( (string) $request->get_param( 'slug' ) );
		if ( '' === $slug ) {
			return new WP_REST_Response( dtb_error_envelope( 'invalid_slug', 'Category slug is required.', 400 ), 400 );
		}

		$term = get_term_by( 'slug', $slug, 'product_cat' );
		if ( ! $term instanceof WP_Term || is_wp_error( $term ) ) {
			return new WP_REST_Response( dtb_error_envelope( 'not_found', 'Category not found.', 404 ), 404 );
		}

		$dto    = self::term_to_navigation_dto( $term );
		$parent = null;
		if ( $term->parent > 0 ) {
			$parent_term = get_term( $term->parent, 'product_cat' );
			if ( $parent_term instanceof WP_Term && ! is_wp_error( $parent_term ) ) {
				$parent_dto = self::term_to_navigation_dto( $parent_term );
				$parent = [
					'slug'  => $parent_dto['slug'],
					'label' => $parent_dto['label'],
				];
			}
		}

		$canonical_group = DTB_CatalogNavigationService::get_group( $slug );
		$children        = is_array( $canonical_group ) ? (array) ( $canonical_group['children'] ?? [] ) : [];
		if ( null === $canonical_group ) {
			$children_terms = get_terms( [
				'taxonomy'   => 'product_cat',
				'parent'     => $term->term_id,
				'hide_empty' => false,
				'number'     => 100,
			] );
			if ( is_array( $children_terms ) ) {
				foreach ( $children_terms as $child_term ) {
					if ( $child_term instanceof WP_Term ) {
						$children[] = array_merge(
							self::term_to_navigation_dto( $child_term ),
							[ 'productCount' => absint( $child_term->count ) ]
						);
					}
				}
			}
			usort( $children, static fn ( $a, $b ): int => strcmp( (string) $a['label'], (string) $b['label'] ) );
		}

		$hero_image        = '';
		$hero_image_srcset = '';
		$hero_image_id     = absint( get_term_meta( $term->term_id, 'dtb_hero_image_id', true ) );
		if ( $hero_image_id > 0 ) {
			$hero_image_url = wp_get_attachment_image_url( $hero_image_id, DTB_CATEGORY_HERO_IMAGE_SIZE );
			if ( is_string( $hero_image_url ) && '' !== $hero_image_url ) {
				$hero_image = esc_url_raw( $hero_image_url );
			}
			$srcset = wp_get_attachment_image_srcset( $hero_image_id, DTB_CATEGORY_HERO_IMAGE_SIZE );
			if ( is_string( $srcset ) && '' !== $srcset ) {
				$hero_image_srcset = esc_attr( $srcset );
			}
		}

		return new WP_REST_Response( [
			'contractVersion'  => DTB_CatalogNavigationService::CONTRACT_VERSION,
			'slug'            => $dto['slug'],
			'label'           => $dto['label'],
			'description'     => $dto['description'],
			'image'           => $dto['image'],
			'heroImage'       => $hero_image,
			'heroImageSrcset' => $hero_image_srcset,
			'productCount'    => absint( $term->count ),
			'parent'          => $parent,
			'children'        => $children,
		], 200 );
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		if ( ! dtb_check_origin() ) {
			return new WP_REST_Response( dtb_error_envelope( 'forbidden_origin', 'Origin not allowed.', 403 ), 403 );
		}

		$scope = [
			'brand'            => (string) $request->get_param( 'brand' ),
			'search'           => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'category'         => (string) $request->get_param( 'category' ),
			'display_category' => (string) $request->get_param( 'display_category' ),
			'product_kind'     => (string) $request->get_param( 'product_kind' ),
			'is_parts'         => $request->get_param( 'is_parts' ),
		];

		$facets = self::build_local_facets( $scope );
		if ( is_wp_error( $facets ) ) {
			$status = (int) ( $facets->get_error_data()['status'] ?? 503 );
			return new WP_REST_Response(
				dtb_error_envelope( $facets->get_error_code(), $facets->get_error_message(), $status ),
				$status
			);
		}
		return new WP_REST_Response( $facets, 200 );
	}

	/** @return array<string,mixed>|WP_Error */
	private static function build_local_facets( array $scope ) {
		$brands            = [];
		$categories        = [];
		$display_by_brand  = [];
		$page              = 1;
		$per_page          = 100;

		do {
			$query = DTB_CatalogProductRepository::find_ids( [
				'brand'            => (string) ( $scope['brand'] ?? '' ),
				'search'           => (string) ( $scope['search'] ?? '' ),
				'category'         => (string) ( $scope['category'] ?? '' ),
				'display_category' => (string) ( $scope['display_category'] ?? '' ),
				'product_kind'     => (string) ( $scope['product_kind'] ?? '' ),
				'is_parts'         => self::normalize_optional_bool( $scope['is_parts'] ?? null ),
				'page'             => $page,
				'per_page'         => $per_page,
				'sort'             => 'az',
			] );

			$ids = array_values( array_filter( array_map( 'absint', (array) ( $query['ids'] ?? [] ) ) ) );
			if ( [] === $ids ) {
				break;
			}

			$response = dtb_catalog_wc_get_products_by_ids_response( $ids );
			if ( ! $response instanceof WP_REST_Response || 200 !== $response->get_status() ) {
				if ( class_exists( 'DTB_Logger' ) ) {
					DTB_Logger::warning( 'Catalog facet local Woo read failed', [
						'page'   => $page,
						'status' => $response instanceof WP_REST_Response ? $response->get_status() : 0,
					] );
				}
				return new WP_Error( 'catalog_facets_unavailable', 'Catalog navigation data is temporarily unavailable.', [ 'status' => 503 ] );
			}

			$raw_products = $response->get_data();
			if ( ! is_array( $raw_products ) ) {
				return new WP_Error( 'catalog_facets_invalid_response', 'Catalog navigation data is temporarily unavailable.', [ 'status' => 503 ] );
			}

			foreach ( $raw_products as $raw ) {
				if ( ! is_array( $raw ) ) {
					continue;
				}

				$dto      = dtb_catalog_normalize_product( $raw );
				$brand    = self::canonical_brand( $dto['brand'] ?? [] );
				$category = is_array( $dto['category'] ?? null ) ? $dto['category'] : [];
				$display  = self::customer_display_category( $dto );

				if ( '' !== $brand['key'] ) {
					$brands[ $brand['key'] ] ??= [
						'key'          => $brand['key'],
						'label'        => $brand['label'],
						'slug'         => $brand['slug'],
						'productCount' => 0,
					];
					$brands[ $brand['key'] ]['productCount']++;
				}

				$category_key = sanitize_key( (string) ( $category['key'] ?? '' ) );
				if ( '' !== $category_key ) {
					$categories[ $category_key ] ??= [
						'key'          => $category_key,
						'label'        => sanitize_text_field( (string) ( $category['label'] ?? $category_key ) ),
						'slug'         => sanitize_title( (string) ( $category['slug'] ?? $category_key ) ),
						'productCount' => 0,
					];
					$categories[ $category_key ]['productCount']++;
				}

				$display_key = sanitize_key( (string) ( $display['key'] ?? '' ) );
				if ( '' !== $brand['key'] && '' !== $display_key ) {
					$display_by_brand[ $brand['key'] ] ??= [];
					$display_by_brand[ $brand['key'] ][ $display_key ] ??= [
						'key'          => $display_key,
						'label'        => sanitize_text_field( (string) ( $display['label'] ?? $display_key ) ),
						'slug'         => sanitize_title( (string) ( $display['slug'] ?? $display_key ) ),
						'productCount' => 0,
						'image'        => '',
					];
					$entry =& $display_by_brand[ $brand['key'] ][ $display_key ];
					$entry['productCount']++;
					if ( '' === $entry['image'] && empty( $dto['isParts'] ) ) {
						$entry['image'] = esc_url_raw( (string) ( $dto['media']['image'] ?? '' ) );
					}
					unset( $entry );
				}

			}

			$page++;
			$total_pages = max( 1, absint( $query['totalPages'] ?? 1 ) );
		} while ( $page <= $total_pages );

		$brands     = array_values( $brands );
		$categories = array_values( $categories );
		usort( $brands, static fn ( $a, $b ): int => strcmp( (string) $a['label'], (string) $b['label'] ) );
		usort( $categories, static fn ( $a, $b ): int => strcmp( (string) $a['label'], (string) $b['label'] ) );

		$display_result = [];
		foreach ( $display_by_brand as $brand_key => $entries ) {
			$display_result[ $brand_key ] = array_values( $entries );
			usort( $display_result[ $brand_key ], static fn ( $a, $b ): int => strcmp( (string) $a['label'], (string) $b['label'] ) );
		}

		return [
			'contractVersion'          => DTB_CatalogNavigationService::CONTRACT_VERSION,
			'brands'                   => $brands,
			'categories'               => $categories,
			'displayCategoriesByBrand' => $display_result,
			'navigationGroups'         => DTB_CatalogNavigationService::get_groups(),
		];
	}

	/** @return int|null */
	private static function normalize_optional_bool( $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? 1 : 0;
	}

	/** @param array<string,mixed> $brand */
	private static function canonical_brand( array $brand ): array {
		$source     = trim( (string) ( $brand['label'] ?? $brand['key'] ?? $brand['slug'] ?? '' ) );
		$normalized = DTB_BrandNormalizer::normalize( $source );
		return [
			'key'   => sanitize_key( (string) ( $normalized['key'] ?? '' ) ),
			'label' => sanitize_text_field( (string) ( $normalized['label'] ?? $source ) ),
			'slug'  => sanitize_title( (string) ( $normalized['slug'] ?? $source ) ),
		];
	}

	/** @param array<string,mixed> $dto */
	private static function customer_display_category( array $dto ): array {
		if ( ! empty( $dto['isParts'] ) ) {
			return [ 'key' => 'parts', 'label' => 'Parts', 'slug' => 'parts' ];
		}
		if ( function_exists( 'dtb_catalog_product_is_compound_tube' ) && dtb_catalog_product_is_compound_tube( $dto ) ) {
			return [ 'key' => 'compound_tubes', 'label' => 'Compound Tubes', 'slug' => 'compound-tubes' ];
		}
		return is_array( $dto['displayCategory'] ?? null ) ? $dto['displayCategory'] : [];
	}

	/** @return array{label:string,slug:string,description:string,image:string} */
	private static function term_to_navigation_dto( WP_Term $term ): array {
		$slug        = sanitize_title( $term->slug );
		$label       = sanitize_text_field( wp_specialchars_decode( (string) $term->name, ENT_QUOTES ) );
		$description = sanitize_text_field( wp_specialchars_decode( wp_strip_all_tags( (string) $term->description ), ENT_QUOTES ) );
		$image        = '';
		$thumbnail_id = absint( get_term_meta( $term->term_id, 'thumbnail_id', true ) );
		if ( $thumbnail_id > 0 ) {
			$image_url = wp_get_attachment_image_url( $thumbnail_id, 'large' );
			if ( is_string( $image_url ) && '' !== $image_url ) {
				$image = esc_url_raw( $image_url );
			}
		}
		return [
			'label'       => $label,
			'slug'        => $slug,
			'description' => $description,
			'image'       => $image,
		];
	}
}
