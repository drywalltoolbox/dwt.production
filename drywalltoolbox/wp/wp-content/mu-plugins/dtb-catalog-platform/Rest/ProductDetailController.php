<?php
/**
 * DTB_ProductDetailController
 *
 * Handles:
 *   GET /wp-json/dtb/v1/catalog/products/:slug/detail
 *   GET /wp-json/dtb/v1/catalog/products/:id/variations
 *
 * The detail route returns the normalized PDP projection with full variation
 * context. The ID-based variations route returns a bounded configuration
 * selector projection without media-manifest/filesystem gallery enrichment.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_ProductDetailController {

	private const RELATED_PRODUCT_LIMIT = 10;

	public static function register_routes(): void {
		register_rest_route( 'dtb/v1', '/catalog/resolve-sku', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'handle_resolve_sku' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'sku' => [
					'required'          => true,
					'sanitize_callback' => static fn( $value, $request = null, $param = null ): string => trim( sanitize_text_field( (string) $value ) ),
					'validate_callback' => static fn( $value, $request = null, $param = null ): bool => is_string( $value ) && '' !== trim( $value ) && strlen( $value ) <= 100,
				],
			],
		] );

		register_rest_route( 'dtb/v1', '/catalog/products/(?P<slug>[a-zA-Z0-9_-]+)/detail', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'handle_detail' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( 'dtb/v1', '/catalog/products/(?P<id>\d+)/variations', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'handle_variations' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'id' => [
					'validate_callback' => static fn( $value, $request = null, $param = null ): bool =>
						is_numeric( $value ) && (int) $value > 0,
				],
			],
		] );
	}

	/** GET /dtb/v1/catalog/resolve-sku?sku={exact-sku} */
	public static function handle_resolve_sku( WP_REST_Request $request ): WP_REST_Response {
		if ( ! dtb_check_origin() ) {
			return new WP_REST_Response( dtb_error_envelope( 'forbidden_origin', 'Origin not allowed.', 403 ), 403 );
		}

		$rate_limit = dtb_rate_limit_get( 'dtb/v1/catalog/resolve-sku' );
		if ( $rate_limit ) {
			return $rate_limit;
		}

		$sku = (string) $request->get_param( 'sku' );
		if ( '' === $sku || ! function_exists( 'wc_get_product_id_by_sku' ) ) {
			return new WP_REST_Response( dtb_error_envelope( 'catalog_runtime_unavailable', 'Product catalog is temporarily unavailable.', 503 ), 503 );
		}

		$result = dtb_catalog_lookup_exact_sku_projection( $sku );
		if ( is_wp_error( $result ) ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 503 );
			return new WP_REST_Response( dtb_error_envelope( $result->get_error_code(), $result->get_error_message(), $status ), $status );
		}
		if ( null === $result ) {
			return new WP_REST_Response( dtb_error_envelope( 'not_found', 'Product not found.', 404 ), 404 );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/** GET /dtb/v1/catalog/products/:slug/detail */
	public static function handle_detail( WP_REST_Request $request ): WP_REST_Response {
		$slug = sanitize_title( $request->get_param( 'slug' ) );

		if ( '' === $slug ) {
			return new WP_REST_Response( dtb_error_envelope( 'invalid_slug', 'Product slug is required.', 400 ), 400 );
		}

		$parent_response = dtb_catalog_wc_get_response( 'wc/v3/products', [
			'slug'    => $slug,
			'status'  => 'publish',
			'_fields' => DTB_PRODUCT_DETAIL_FIELDS,
		] );

		if ( ! $parent_response instanceof WP_REST_Response ) {
			return new WP_REST_Response( dtb_error_envelope( 'upstream_error', 'Product catalog is temporarily unavailable.', 503 ), 503 );
		}
		if ( 200 !== $parent_response->get_status() ) {
			return $parent_response;
		}

		$products = $parent_response->get_data();
		if ( ! is_array( $products ) || empty( $products ) ) {
			return new WP_REST_Response( dtb_error_envelope( 'not_found', 'Product not found.', 404 ), 404 );
		}

		$wc_product = $products[0] ?? null;
		if ( ! is_array( $wc_product ) ) {
			return new WP_REST_Response( dtb_error_envelope( 'not_found', 'Product not found.', 404 ), 404 );
		}

		$product    = dtb_catalog_normalize_product( $wc_product );
		$variations = [];

		if ( 'variable' === $product['type'] && $product['id'] > 0 ) {
			$variation_result = DTB_VariationReadModelService::get_normalized( $product['id'], $wc_product );
			if ( is_wp_error( $variation_result ) ) {
				$status = (int) ( $variation_result->get_error_data()['status'] ?? 503 );
				return new WP_REST_Response(
					dtb_error_envelope( $variation_result->get_error_code(), $variation_result->get_error_message(), $status ),
					$status
				);
			}
			$variations = $variation_result;
		}

		$default_var = dtb_catalog_resolve_default_variation( $product, $variations );
		$product     = dtb_catalog_apply_default_variation_to_card( $product, $default_var );

		$in_stock_count = count( array_filter( $variations, static fn( $v ) =>
			'outofstock' !== $v['inventory']['stockStatus']
		) );

		$variation_diagnostics = method_exists( 'DTB_VariationReadModelService', 'get_last_diagnostics' )
			? DTB_VariationReadModelService::get_last_diagnostics()
			: [ 'available' => false ];
		$related_products = self::get_related_products( $product['id'], $product );
		$family_related_products = [];
		$category_related_products = [];
		$brand_related_products = [];
		$family_source = wc_get_product( $product['id'] );
		if ( $family_source instanceof WC_Product ) {
			$family_related_products = array_values( array_map(
				'dtb_catalog_normalize_product',
				dtb_catalog_wc_fetch_products_by_ids( array_map(
					static fn( WC_Product $related ): int => $related->get_id(),
					dtb_product_mapping_repo_get_family_related_products( $family_source )
				) )
			) );
			$category_related_products = array_values( array_map(
				'dtb_catalog_normalize_product',
				dtb_catalog_wc_fetch_products_by_ids( array_map(
					static fn( WC_Product $related ): int => $related->get_id(),
					dtb_product_mapping_repo_get_category_related_products( $family_source )
				) )
			) );
			$brand_related_products = array_values( array_map(
				'dtb_catalog_normalize_product',
				dtb_catalog_wc_fetch_products_by_ids( array_map(
					static fn( WC_Product $related ): int => $related->get_id(),
					dtb_product_mapping_repo_get_brand_related_products( $family_source )
				) )
			) );
		}
		$placed_product_ids = array_fill_keys(
			array_map(
				static fn( array $related ): int => absint( $related['id'] ?? 0 ),
				array_merge( $related_products, $family_related_products, $category_related_products )
			),
			true
		);
		$category_related_products = array_values( array_filter(
			$category_related_products,
			static fn( array $related ): bool => ! isset( $placed_product_ids[ absint( $related['id'] ?? 0 ) ] )
		) );
		$placed_product_ids = array_fill_keys(
			array_map( static fn( array $related ): int => absint( $related['id'] ?? 0 ), array_merge( $related_products, $family_related_products, $category_related_products ) ),
			true
		);
		$brand_related_products = array_values( array_filter(
			$brand_related_products,
			static fn( array $related ): bool => ! isset( $placed_product_ids[ absint( $related['id'] ?? 0 ) ] )
		) );
		$is_toolset = self::is_toolset( $product );
		$related_products_by_variation = $is_toolset && 'variable' === $product['type']
			? self::get_related_products_by_variation( $variations, $product['id'] )
			: [];

		return new WP_REST_Response( [
			'product'         => $product,
			'variations'      => $variations,
			'relatedProducts' => $related_products,
			'familyRelatedProducts' => $family_related_products,
			'categoryRelatedProducts' => $category_related_products,
			'brandRelatedProducts' => $brand_related_products,
			'relatedProductsByVariation' => $related_products_by_variation,
			'computed'        => [
				'defaultVariation'      => $default_var,
				'hasInStockVariation'   => $in_stock_count > 0,
				'variationCount'        => count( $variations ),
				'inStockVariationCount' => $in_stock_count,
				'variationMatrix'       => dtb_catalog_build_variation_matrix( $variations ),
				'variationDiagnostics'  => $variation_diagnostics,
				'relatedProductsContext' => $is_toolset ? 'included_components' : ( $related_products ? 'related' : ( $category_related_products ? 'category_related' : ( $family_related_products ? 'family_related' : ( $brand_related_products ? 'brand_related' : 'related' ) ) ) ),
			],
		], 200 );
	}

	/** Build the public PDP merchandising rail from WooCommerce relationships. */
	private static function get_related_products( int $product_id, array $product ): array {
		$source_product = wc_get_product( $product_id );
		if ( ! $source_product ) {
			return [];
		}

		// Toolsets use exact included SKUs; all other PDP recommendations must be explicitly curated.
		if ( self::is_toolset( $product ) ) {
			return self::get_included_products( $product_id, self::get_included_skus( $product_id ) );
		}

		// Keep upsells out of the storefront while cross-sells are the launch focus.
		// This rail reads only the operator-curated WooCommerce cross-sell mapping.
		$candidate_ids = $source_product->get_cross_sell_ids();
		$candidate_ids = array_values( array_unique( array_filter( array_map( 'absint', $candidate_ids ) ) ) );
		$visible_ids   = [];

		foreach ( $candidate_ids as $candidate_id ) {
			$candidate = wc_get_product( $candidate_id );
			if ( ! $candidate || 'publish' !== get_post_status( $candidate_id ) || ! $candidate->is_visible() ) {
				continue;
			}

			$visible_ids[] = $candidate_id;
			if ( count( $visible_ids ) >= self::RELATED_PRODUCT_LIMIT ) {
				break;
			}
		}

		return array_values( array_map(
			'dtb_catalog_normalize_product',
			dtb_catalog_wc_fetch_products_by_ids( $visible_ids )
		) );
	}

	/** Determine toolset semantics from canonical product kind, with legacy category fallback. */
	private static function is_toolset( array $product ): bool {
		if ( 'toolset' === strtolower( (string) ( $product['productKind'] ?? '' ) ) ) {
			return true;
		}

		$category_slug = (string) ( $product['displayCategory']['slug'] ?? '' );
		return in_array( $category_slug, [ 'automatic-tool-sets', 'automatic-tool-sets-kits', 'semi-automatic-tool-sets-kits', 'toolsets', 'tool-sets-kits', 'tool-sets-and-kits' ], true );
	}

	/** Read included SKUs from an exact product/variation DTO, falling back to the owner meta. */
	private static function get_included_skus( int $owner_id, array $meta_data = [] ): array {
		$includes = [];
		foreach ( $meta_data as $entry ) {
			$key = (string) ( $entry['key'] ?? '' );
			if ( preg_match( '/^_includes_(\d+)_sku$/', $key, $matches ) && '' !== trim( (string) ( $entry['value'] ?? '' ) ) ) {
				$includes[(int) $matches[1]] = trim( (string) $entry['value'] );
			}
		}
		if ( ! $includes && $owner_id > 0 ) {
			for ( $index = 0; $index < 20; $index++ ) {
				$sku = trim( (string) get_post_meta( $owner_id, '_includes_' . $index . '_sku', true ) );
				if ( '' !== $sku ) {
					$includes[$index] = $sku;
				}
			}
		}
		ksort( $includes, SORT_NUMERIC );
		return array_values( $includes );
	}

	/** Resolve only exact, published, visible component SKUs into bounded product cards. */
	private static function get_included_products( int $owner_id, array $skus ): array {
		$included_cards = [];
		foreach ( $skus as $sku ) {
			$included_id = wc_get_product_id_by_sku( $sku );
			$included = $included_id ? wc_get_product( $included_id ) : false;
			if ( ! $included || $included_id === $owner_id || (string) $included->get_sku() !== $sku || 'publish' !== get_post_status( $included_id ) ) {
				continue;
			}
			if ( $included->is_type( 'variation' ) ) {
				$parent = wc_get_product( $included->get_parent_id() );
				if ( ! $parent || 'publish' !== get_post_status( $parent->get_id() ) || ! $parent->is_visible() || ! $included->variation_is_visible() ) {
					continue;
				}
				$dto = DTB_VariationReadModelService::get_normalized_by_id( $included_id );
				if ( ! is_array( $dto ) ) {
					continue;
				}
				$dto['slug'] = $parent->get_slug();
				$dto['includedVariation'] = true;
				$dto['parentName'] = $parent->get_name();
				$included_cards[$included_id] = $dto;
			} elseif ( $included->is_visible() ) {
				$raw = dtb_catalog_wc_fetch_product_by_id( $included_id );
				if ( is_array( $raw ) ) {
					$included_cards[$included_id] = dtb_catalog_normalize_product( $raw );
				}
			}
			if ( count( $included_cards ) >= self::RELATED_PRODUCT_LIMIT ) {
				break;
			}
		}
		return array_values( $included_cards );
	}

	/** Build variation-specific toolset contents from each exact variation's recorded includes. */
	private static function get_related_products_by_variation( array $variations, int $parent_product_id ): array {
		$by_variation = [];
		$parent_skus = self::get_included_skus( $parent_product_id );
		foreach ( $variations as $variation ) {
			$variation_id = absint( $variation['id'] ?? 0 );
			if ( $variation_id <= 0 ) {
				continue;
			}
			$variation_skus = self::get_included_skus( $variation_id, (array) ( $variation['meta_data'] ?? [] ) );
			if ( ! $variation_skus ) {
				continue;
			}
			$by_variation[(string) $variation_id] = self::get_included_products( $variation_id, $variation_skus ?: $parent_skus );
		}
		return $by_variation;
	}

	/**
	 * GET /dtb/v1/catalog/products/:id/variations
	 *
	 * Returns the bounded configuration-selector projection. Full PDP variation
	 * gallery enrichment is intentionally excluded from this endpoint.
	 */
	public static function handle_variations( WP_REST_Request $request ): WP_REST_Response {
		$product_id = absint( $request->get_param( 'id' ) );

		if ( $product_id <= 0 ) {
			return new WP_REST_Response( dtb_error_envelope( 'invalid_id', 'Valid product ID required.', 400 ), 400 );
		}

		$parent_response = dtb_catalog_wc_get_response( 'wc/v3/products/' . $product_id, [] );
		if ( ! $parent_response instanceof WP_REST_Response ) {
			return new WP_REST_Response( dtb_error_envelope( 'upstream_error', 'Product catalog is temporarily unavailable.', 503 ), 503 );
		}
		if ( 200 !== $parent_response->get_status() ) {
			return $parent_response;
		}

		$wc_parent = $parent_response->get_data();
		if ( ! is_array( $wc_parent ) || empty( $wc_parent ) ) {
			return new WP_REST_Response( dtb_error_envelope( 'not_found', 'Product not found.', 404 ), 404 );
		}

		$variations = DTB_VariationReadModelService::get_selector_options( $product_id, $wc_parent );
		if ( is_wp_error( $variations ) ) {
			$status = (int) ( $variations->get_error_data()['status'] ?? 503 );
			return new WP_REST_Response(
				dtb_error_envelope( $variations->get_error_code(), $variations->get_error_message(), $status ),
				$status
			);
		}
		$variation_diagnostics = method_exists( 'DTB_VariationReadModelService', 'get_last_diagnostics' )
			? DTB_VariationReadModelService::get_last_diagnostics()
			: [ 'available' => false ];

		return new WP_REST_Response( [
			'productId'   => $product_id,
			'variations'  => $variations,
			'count'       => count( $variations ),
			'diagnostics' => $variation_diagnostics,
		], 200 );
	}
}
