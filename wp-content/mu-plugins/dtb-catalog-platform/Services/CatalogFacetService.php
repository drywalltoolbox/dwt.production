<?php
/**
 * DTB_CatalogFacetService
 *
 * Aggregates scoped catalog facets (brands, broad categories, and display
 * categories) from published WooCommerce products. The storefront uses display
 * categories as the customer-facing product taxonomy; broad categories remain
 * internal classification metadata.
 *
 * Customer-facing invariant:
 * - Any product flagged as a replacement part is surfaced under the Parts
 *   display bucket, regardless of its compatibility/tool-family metadata.
 * - Tool display-category cards only represent tool products.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_CatalogFacetService {

	const CACHE_KEY = 'dtb_catalog_facets_v3';
	const CACHE_TTL = 600; // 10 minutes
	const CACHE_GENERATION_OPTION = 'dtb_catalog_facets_generation';

	/**
	 * Return cached facets or rebuild from WC.
	 *
	 * @param  array<string,mixed> $scope Optional facet scope.
	 * @return array{ brands: array[], categories: array[], displayCategoriesByBrand: array }
	 */
	public static function get( array $scope = [] ): array {
		$scope = self::normalize_scope( $scope );
		$key   = self::cache_key( $scope );

		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$facets = self::build( $scope );
		set_transient( $key, $facets, self::CACHE_TTL );
		return $facets;
	}

	/** Invalidate all facet scopes in database and external object caches. */
	public static function invalidate(): void {
		update_option( self::CACHE_GENERATION_OPTION, wp_generate_uuid4(), false );
	}

	// ── Private ────────────────────────────────────────────────────────────────

	/**
	 * @param array<string,mixed> $scope
	 * @return array<string,string>
	 */
	private static function normalize_scope( array $scope ): array {
		$normalized = [];

		foreach ( [ 'brand', 'category', 'display_category', 'product_kind' ] as $key ) {
			$value = isset( $scope[ $key ] ) ? sanitize_text_field( (string) $scope[ $key ] ) : '';
			if ( '' !== $value ) {
				$normalized[ $key ] = $value;
			}
		}

		if ( array_key_exists( 'is_parts', $scope ) && null !== $scope['is_parts'] && '' !== $scope['is_parts'] ) {
			$normalized['is_parts'] = filter_var( $scope['is_parts'], FILTER_VALIDATE_BOOLEAN ) ? '1' : '0';
		}

		ksort( $normalized );
		return $normalized;
	}

	/** @param array<string,string> $scope */
	private static function cache_key( array $scope ): string {
		if ( empty( $scope ) ) {
			return self::CACHE_KEY . '_' . md5( (string) get_option( self::CACHE_GENERATION_OPTION, '1' ) );
		}

		return self::CACHE_KEY . '_' . md5( (string) get_option( self::CACHE_GENERATION_OPTION, '1' ) . '|' . ( wp_json_encode( $scope ) ?: '' ) );
	}

	/**
	 * @param array<string,string> $scope
	 * @return array{ brands: array[], categories: array[], displayCategoriesByBrand: array }
	 */
	private static function build( array $scope = [] ): array {
		$brands           = [];
		$categories       = [];
		$display_by_brand = [];

		$page     = 1;
		$per_page = 100;

		do {
			$response = dtb_cached_wc_get( 'wc/v3/products', [
				'status'   => 'publish',
				'per_page' => $per_page,
				'page'     => $page,
				'_fields'  => 'id,type,categories,meta_data,attributes,brands,name,images,sku,slug',
			] );

			if ( $response->get_status() !== 200 ) {
				break;
			}

			$batch = $response->get_data();
			if ( ! is_array( $batch ) || empty( $batch ) ) {
				break;
			}

			foreach ( $batch as $wc ) {
				$dto = DTB_CatalogProductNormalizer::normalize( $wc );

				if ( ! self::matches_scope( $dto, $scope ) ) {
					continue;
				}

				$brand   = self::canonical_brand_identity( $dto['brand'] ?? [] );
				$cat     = $dto['category'];
				$dis_cat = self::customer_display_category( $dto );

				if ( '' !== $brand['key'] ) {
					if ( ! isset( $brands[ $brand['key'] ] ) ) {
						$brands[ $brand['key'] ] = [
							'key'          => $brand['key'],
							'label'        => $brand['label'],
							'slug'         => $brand['slug'],
							'productCount' => 0,
						];
					}
					$brands[ $brand['key'] ]['productCount']++;
				}

				if ( '' !== $cat['key'] ) {
					if ( ! isset( $categories[ $cat['key'] ] ) ) {
						$categories[ $cat['key'] ] = [
							'key'          => $cat['key'],
							'label'        => $cat['label'],
							'slug'         => $cat['slug'],
							'productCount' => 0,
						];
					}
					$categories[ $cat['key'] ]['productCount']++;
				}

				if ( '' !== $brand['key'] && '' !== $dis_cat['key'] ) {
					if ( ! isset( $display_by_brand[ $brand['key'] ] ) ) {
						$display_by_brand[ $brand['key'] ] = [];
					}
					$dk = $dis_cat['key'];
					if ( ! isset( $display_by_brand[ $brand['key'] ][ $dk ] ) ) {
						$display_by_brand[ $brand['key'] ][ $dk ] = [
							'key'          => $dk,
							'label'        => $dis_cat['label'],
							'slug'         => $dis_cat['slug'],
							'productCount' => 0,
							'image'        => '',
						];
					}
					$display_by_brand[ $brand['key'] ][ $dk ]['productCount']++;

					if ( empty( $display_by_brand[ $brand['key'] ][ $dk ]['image'] ) && empty( $dto['isParts'] ) ) {
						$image = (string) ( $dto['media']['image'] ?? '' );
						if ( '' !== $image ) {
							$display_by_brand[ $brand['key'] ][ $dk ]['image'] = $image;
						}
					}
				}
			}

			$page++;
		} while ( count( $batch ) === $per_page );

		$brands_arr = array_values( $brands );
		usort( $brands_arr, static fn( $a, $b ) => strcmp( $a['label'], $b['label'] ) );

		$cats_arr = array_values( $categories );
		usort( $cats_arr, static fn( $a, $b ) => strcmp( $a['label'], $b['label'] ) );

		$display_arr = [];
		foreach ( $display_by_brand as $bk => $dcs ) {
			$display_arr[ $bk ] = array_values( $dcs );
			usort( $display_arr[ $bk ], static fn( $a, $b ) => strcmp( $a['label'], $b['label'] ) );
		}

		return [
			'brands'                   => $brands_arr,
			'categories'               => $cats_arr,
			'displayCategoriesByBrand' => $display_arr,
		];
	}

	/**
	 * Collapse legacy brand keys onto the canonical brand identity used by routes.
	 * Known brands must never be split into separate facet buckets merely because
	 * an older import stored a non-canonical _dtb_brand_key value.
	 *
	 * @param  array<string,mixed> $brand
	 * @return array{ key: string, label: string, slug: string }
	 */
	private static function canonical_brand_identity( array $brand ): array {
		$source = trim( (string) ( $brand['label'] ?? '' ) );
		if ( '' === $source ) {
			$source = trim( (string) ( $brand['key'] ?? $brand['slug'] ?? '' ) );
		}

		$normalized = DTB_BrandNormalizer::normalize( $source );
		if ( '' === $normalized['key'] ) {
			return $normalized;
		}

		if ( ! DTB_BrandNormalizer::is_known_slug( $normalized['slug'] ) ) {
			$legacy_key = sanitize_title( (string) ( $brand['key'] ?? $brand['slug'] ?? '' ) );
			if ( '' !== $legacy_key ) {
				$normalized['key']  = $legacy_key;
				$normalized['slug'] = $legacy_key;
			}
		}

		return $normalized;
	}

	/** Return the customer-facing display bucket for a normalized product. */
	private static function customer_display_category( array $dto ): array {
		if ( ! empty( $dto['isParts'] ) ) {
			return [
				'key'   => 'parts',
				'label' => 'Parts',
				'slug'  => 'parts',
			];
		}

		if ( function_exists( 'dtb_catalog_product_is_compound_tube' ) && dtb_catalog_product_is_compound_tube( $dto ) ) {
			return [
				'key'   => 'automatic_compound_tubes',
				'label' => 'Compound Tubes',
				'slug'  => 'compound-tubes',
			];
		}

		$display = $dto['displayCategory'] ?? [];
		if ( ! empty( $display['key'] ) ) {
			return $display;
		}

		return [ 'key' => '', 'label' => '', 'slug' => '' ];
	}

	/**
	 * @param array<string,mixed>  $dto
	 * @param array<string,string> $scope
	 */
	private static function matches_scope( array $dto, array $scope ): bool {
		if ( isset( $scope['is_parts'] ) ) {
			$is_parts = ! empty( $dto['isParts'] ) ? '1' : '0';
			if ( $is_parts !== $scope['is_parts'] ) {
				return false;
			}
		}

		if ( isset( $scope['brand'] ) && '' !== $scope['brand'] ) {
			$needle = sanitize_title( $scope['brand'] );
			$brand  = self::canonical_brand_identity( $dto['brand'] ?? [] );
			$values = array_filter([
				sanitize_title( (string) ( $brand['key'] ?? '' ) ),
				sanitize_title( (string) ( $brand['slug'] ?? '' ) ),
				sanitize_title( (string) ( $brand['label'] ?? '' ) ),
			]);
			if ( ! in_array( $needle, $values, true ) ) {
				return false;
			}
		}

		if ( isset( $scope['category'] ) && '' !== $scope['category'] ) {
			if ( ( $dto['category']['key'] ?? '' ) !== $scope['category'] ) {
				return false;
			}
		}

		if ( isset( $scope['display_category'] ) && '' !== $scope['display_category'] ) {
			$display      = self::customer_display_category( $dto );
			$scope_slug   = sanitize_title( (string) $scope['display_category'] );
			// Normalise the stored key to a slug so comparisons work regardless of
			// whether the meta was saved with hyphens, underscores, spaces, or title case.
			$display_slug = sanitize_title( (string) ( $display['key'] ?? '' ) );
			if ( '' === $display_slug || $scope_slug !== $display_slug ) {
				return false;
			}
		}

		if ( isset( $scope['product_kind'] ) && '' !== $scope['product_kind'] ) {
			if ( ( $dto['productKind'] ?? '' ) !== $scope['product_kind'] ) {
				return false;
			}
		}

		return true;
	}
}
