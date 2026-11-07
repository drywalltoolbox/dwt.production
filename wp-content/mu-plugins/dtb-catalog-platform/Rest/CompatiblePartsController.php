<?php
/**
 * DTB_CompatiblePartsController
 *
 * Exposes the schematic/parts compatibility graph via read-only REST endpoints.
 * All relationships are owned by product meta — no extra database tables needed.
 *
 * Routes:
 *   GET /wp-json/dtb/v1/products/:sku/compatible-parts
 *     Returns all part products whose _dtb_compatible_tool_skus or
 *     _dtb_replacement_part_for includes the given tool SKU.
 *
 *   GET /wp-json/dtb/v1/parts/:sku/compatible-tools
 *     Returns all tool products whose SKU appears in the given part's
 *     _dtb_compatible_tool_skus or _dtb_replacement_part_for meta.
 *
 *   GET /wp-json/dtb/v1/schematics/:schematicId/parts
 *     Returns all parts whose _dtb_schematic_brand + _dtb_schematic_group
 *     concatenate to the given schematicId (format: brand--group).
 *
 * Response shape for all three routes:
 *   { products: [ DTB product DTO, ... ], count: int }
 *
 * All queries use direct post_meta lookups (no graph DB, no extra tables).
 * Results are lightly cached via the DTB product cache layer.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_CompatiblePartsController {
	private const SKU_SEGMENT_PATTERN = '[A-Za-z0-9._-]+';

	public static function register_routes(): void {
		register_rest_route( 'dtb/v1', '/products/compatible-parts', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'handle_cart_compatible_parts' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'skus' => [
					'required'          => true,
					'type'              => 'string',
					'validate_callback' => static fn( $value ) => self::is_valid_sku_list( $value ),
				],
				'limit' => [
					'sanitize_callback' => 'absint',
					'validate_callback' => static fn( $value ) => is_numeric( $value ) && (int) $value >= 1 && (int) $value <= 100,
				],
			],
		] );

		register_rest_route( 'dtb/v1', '/products/(?P<sku>' . self::SKU_SEGMENT_PATTERN . ')/compatible-parts', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'handle_compatible_parts' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'sku' => [
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => static fn( $v ) => self::is_valid_sku( $v ),
				],
				'limit' => [
					'sanitize_callback' => 'absint',
					'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v >= 1 && (int) $v <= 100,
				],
			],
		] );

		register_rest_route( 'dtb/v1', '/parts/(?P<sku>' . self::SKU_SEGMENT_PATTERN . ')/compatible-tools', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'handle_compatible_tools' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'sku' => [
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => static fn( $v ) => self::is_valid_sku( $v ),
				],
			],
		] );

		register_rest_route( 'dtb/v1', '/schematics/(?P<schematicId>[a-zA-Z0-9_-]+)/parts', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'handle_schematic_parts' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'schematicId' => [
					'sanitize_callback' => 'sanitize_title',
				],
			],
		] );
	}

	/**
	 * GET /dtb/v1/products/compatible-parts?skus=SKU1,SKU2
	 *
	 * Return a bounded, deduplicated set of visible part products compatible
	 * with exact tool SKUs supplied by the current cart. This is a read-only
	 * projection of the canonical part-owned fitment metadata.
	 */
	public static function handle_cart_compatible_parts( WP_REST_Request $request ): WP_REST_Response {
		$requested_skus = array_values( array_unique( explode( ',', (string) $request->get_param( 'skus' ) ) ) );
		$tool_skus      = [];
		$source_by_tool = [];

		foreach ( $requested_skus as $requested_sku ) {
			$product_id = self::get_product_id_by_sku( $requested_sku );
			$product    = $product_id ? wc_get_product( $product_id ) : false;
			if ( ! $product || (string) $product->get_sku() !== $requested_sku || 'publish' !== get_post_status( $product_id ) ) {
				continue;
			}

			$owner_id = $product instanceof WC_Product_Variation ? $product->get_parent_id() : $product_id;
			if ( self::is_part_product( (int) $owner_id ) ) {
				continue;
			}
			$visible_source = $product instanceof WC_Product_Variation ? wc_get_product( $owner_id ) : $product;
			if ( ! $visible_source || 'publish' !== get_post_status( $owner_id ) || ! $visible_source->is_visible() ) {
				continue;
			}

			foreach ( self::get_tool_skus_for_product( $requested_sku ) as $tool_sku ) {
				if ( ! in_array( $tool_sku, $tool_skus, true ) ) {
					$tool_skus[] = $tool_sku;
				}
				$source_by_tool[ $tool_sku ][] = $requested_sku;
			}
		}

		$part_ids = self::find_products_with_skus_in_meta(
			$tool_skus,
			[ DTB_ProductMeta::COMPATIBLE_TOOL_SKUS, DTB_ProductMeta::REPLACEMENT_PART_FOR ]
		);
		$limit    = absint( $request->get_param( 'limit' ) );
		$dtos     = self::normalize_product_ids( $part_ids, $limit > 0 ? min( 100, $limit ) : 12 );

		foreach ( $dtos as &$dto ) {
			$part_id = absint( $dto['id'] ?? 0 );
			$stored_tool_skus = $part_id > 0 ? array_unique( array_merge(
				self::get_meta_sku_values( $part_id, DTB_ProductMeta::COMPATIBLE_TOOL_SKUS ),
				self::get_meta_sku_values( $part_id, DTB_ProductMeta::REPLACEMENT_PART_FOR )
			) ) : [];
			$matched_sources = [];
			foreach ( $tool_skus as $tool_sku ) {
				if ( in_array( $tool_sku, $stored_tool_skus, true ) ) {
					$matched_sources = array_merge( $matched_sources, $source_by_tool[ $tool_sku ] ?? [] );
				}
			}
			$dto['compatible_with_skus'] = array_values( array_unique( $matched_sources ) );
			if ( isset( $dto['compatible_part_variations'] ) && is_array( $dto['compatible_part_variations'] ) ) {
				foreach ( $dto['compatible_part_variations'] as &$variation ) {
					$variation_sources = [];
					$variation_tool_skus = array_unique( array_merge(
						(array) ( $variation['compatible_tool_skus'] ?? [] ),
						(array) ( $variation['replacement_part_for'] ?? [] )
					) );
					foreach ( $tool_skus as $tool_sku ) {
						if ( in_array( $tool_sku, $variation_tool_skus, true ) ) {
							$variation_sources = array_merge( $variation_sources, $source_by_tool[ $tool_sku ] ?? [] );
						}
					}
					$variation['compatible_with_skus'] = array_values( array_unique( $variation_sources ) );
					unset( $variation['compatible_tool_skus'], $variation['replacement_part_for'] );
				}
				unset( $variation );
			}
		}
		unset( $dto );

		return new WP_REST_Response( [
			'products' => $dtos,
			'count'    => count( $dtos ),
		], 200 );
	}

	/**
	 * GET /dtb/v1/products/:sku/compatible-parts
	 *
	 * Return all part products that list this tool SKU in
	 * _dtb_compatible_tool_skus OR _dtb_replacement_part_for.
	 */
	public static function handle_compatible_parts( WP_REST_Request $request ): WP_REST_Response {
		$tool_sku = strtoupper( sanitize_text_field( $request->get_param( 'sku' ) ) );

		if ( '' === $tool_sku ) {
			return new WP_REST_Response(
				dtb_error_envelope( 'invalid_sku', 'Tool SKU is required.', 400 ),
				400
			);
		}

		$tool_skus   = self::get_tool_skus_for_product( $tool_sku );
		$product_ids = self::find_products_with_skus_in_meta(
			$tool_skus,
			[ DTB_ProductMeta::COMPATIBLE_TOOL_SKUS, DTB_ProductMeta::REPLACEMENT_PART_FOR ]
		);
		$dtos = self::normalize_product_ids( $product_ids, absint( $request->get_param( 'limit' ) ) );

		return new WP_REST_Response( [
			'toolSku'  => $tool_sku,
			'products' => $dtos,
			'count'    => count( $dtos ),
		], 200 );
	}

	/**
	 * GET /dtb/v1/parts/:sku/compatible-tools
	 *
	 * Return all tool products that the given part claims compatibility with.
	 * Reads the part's own _dtb_compatible_tool_skus and _dtb_replacement_part_for
	 * to get the list of tool SKUs, then resolves those to product DTOs.
	 */
	public static function handle_compatible_tools( WP_REST_Request $request ): WP_REST_Response {
		$part_sku = strtoupper( sanitize_text_field( $request->get_param( 'sku' ) ) );

		if ( '' === $part_sku ) {
			return new WP_REST_Response(
				dtb_error_envelope( 'invalid_sku', 'Part SKU is required.', 400 ),
				400
			);
		}

		// Find the part product by SKU.
		$part_post = self::get_product_id_by_sku( $part_sku );
		if ( ! $part_post ) {
			return new WP_REST_Response(
				dtb_error_envelope( 'not_found', "Part '{$part_sku}' not found.", 404 ),
				404
			);
		}
		$part_product = wc_get_product( $part_post );
		$part_owner_id = $part_product instanceof WC_Product_Variation ? $part_product->get_parent_id() : $part_post;
		if ( ! $part_product || (string) $part_product->get_sku() !== $part_sku || 'publish' !== get_post_status( $part_post ) || $part_owner_id <= 0 || ! self::is_part_product( $part_owner_id ) || 'publish' !== get_post_status( $part_owner_id ) ) {
			return new WP_REST_Response(
				dtb_error_envelope( 'not_found', "Part '{$part_sku}' not found.", 404 ),
				404
			);
		}

		$tool_skus = array_filter( array_unique( array_merge(
			self::get_meta_sku_values( $part_post, DTB_ProductMeta::COMPATIBLE_TOOL_SKUS ),
			self::get_meta_sku_values( $part_post, DTB_ProductMeta::REPLACEMENT_PART_FOR )
		) ) );
		if ( count( $tool_skus ) > 100 ) {
			return new WP_REST_Response(
				dtb_error_envelope( 'relationship_set_too_large', 'This part has too many related SKUs to return safely.', 422 ),
				422
			);
		}

		$dtos = self::normalize_compatible_tool_skus( $tool_skus );
		if ( $dtos instanceof WP_REST_Response ) {
			return $dtos;
		}

		return new WP_REST_Response( [
			'partSku'  => $part_sku,
			'products' => array_values( $dtos ),
			'count'    => count( $dtos ),
		], 200 );
	}

	/**
	 * GET /dtb/v1/schematics/:schematicId/parts
	 *
	 * Return all parts for a schematic, identified by "brand--group" slug.
	 * Parts are sorted by _dtb_schematic_position (ascending, nulls last).
	 *
	 * schematicId format: {brand}--{group}  e.g. columbia--compound_tube
	 */
	public static function handle_schematic_parts( WP_REST_Request $request ): WP_REST_Response {
		$schematic_id = sanitize_title( $request->get_param( 'schematicId' ) );

		if ( '' === $schematic_id || ! str_contains( $schematic_id, '--' ) ) {
			return new WP_REST_Response(
				dtb_error_envelope(
					'invalid_schematic_id',
					"schematicId must be in the format '{brand}--{group}' (e.g. columbia--compound_tube).",
					400
				),
				400
			);
		}

		[ $brand, $group ] = explode( '--', $schematic_id, 2 );
		$brand = sanitize_title( $brand );
		$group = sanitize_title( $group );

		if ( '' === $brand || '' === $group ) {
			return new WP_REST_Response(
				dtb_error_envelope( 'invalid_schematic_id', 'Both brand and group must be non-empty.', 400 ),
				400
			);
		}

		$product_ids = get_posts( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'fields'         => 'ids',
			'meta_query'     => [
				'relation' => 'AND',
				[
					'key'     => DTB_ProductMeta::SCHEMATIC_BRAND,
					'value'   => $brand,
					'compare' => '=',
				],
				[
					'key'     => DTB_ProductMeta::SCHEMATIC_GROUP,
					'value'   => $group,
					'compare' => '=',
				],
			],
		] );

		$dtos = self::normalize_product_ids( (array) $product_ids );

		// Sort by schematic position (ascending), nulls last.
		usort( $dtos, static function ( array $a, array $b ): int {
			$pos_a = $a['schematics']['position'] ?? PHP_INT_MAX;
			$pos_b = $b['schematics']['position'] ?? PHP_INT_MAX;
			return $pos_a <=> $pos_b;
		} );

		return new WP_REST_Response( [
			'schematicId' => $schematic_id,
			'brand'       => $brand,
			'group'       => $group,
			'products'    => $dtos,
			'count'       => count( $dtos ),
		], 200 );
	}

	// ── Private helpers ────────────────────────────────────────────────────────

	private static function is_valid_sku( mixed $sku ): bool {
		return is_string( $sku ) && 1 === preg_match( '/^' . self::SKU_SEGMENT_PATTERN . '$/D', $sku );
	}

	private static function is_valid_sku_list( mixed $value ): bool {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}
		$skus = explode( ',', $value );
		if ( count( $skus ) < 1 || count( $skus ) > 20 ) {
			return false;
		}
		foreach ( $skus as $sku ) {
			if ( ! self::is_valid_sku( $sku ) ) {
				return false;
			}
		}
		return true;
	}

	private static function is_part_product( int $product_id ): bool {
		return $product_id > 0 && (
			'part' === (string) get_post_meta( $product_id, DTB_ProductMeta::PRODUCT_KIND, true )
			|| '1' === (string) get_post_meta( $product_id, DTB_ProductMeta::IS_PARTS, true )
		);
	}

	/**
	 * Find all published product IDs that contain the given SKU in any of the
	 * specified meta keys (comma-separated or serialized array values).
	 *
	 * @param  string   $sku        Normalized SKU to search for.
	 * @param  string[] $meta_keys  Meta keys to search in.
	 * @return int[]
	 */
	private static function find_products_with_skus_in_meta( array $skus, array $meta_keys ): array {
		$search_skus = array_values( array_unique( array_filter( array_map(
			static fn( $sku ): string => strtoupper( trim( (string) $sku ) ),
			$skus
		) ) ) );
		if ( empty( $search_skus ) || empty( $meta_keys ) ) {
			return [];
		}

		$meta_query = [ 'relation' => 'OR' ];
		foreach ( $meta_keys as $key ) {
			foreach ( $search_skus as $sku ) {
				$meta_query[] = [
					'key'     => $key,
					'value'   => $sku,
					'compare' => 'LIKE',
				];
			}
		}

		$found = get_posts( [
			'post_type'              => [ 'product', 'product_variation' ],
			'post_status'            => 'publish',
			'posts_per_page'         => 1000,
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'meta_query'             => $meta_query,
		] );

		$matches = [];
		foreach ( $found as $found_post ) {
			if ( ! is_object( $found_post ) ) {
				continue;
			}
			$product_id = absint( $found_post->ID ?? 0 );
			$is_variation = 'product_variation' === (string) ( $found_post->post_type ?? '' );
			$owner_id = $is_variation ? absint( $found_post->post_parent ?? 0 ) : $product_id;
			if ( $product_id <= 0 || $owner_id <= 0 || ! self::is_part_product( $owner_id ) || 'publish' !== get_post_status( $owner_id ) ) {
				continue;
			}
			if ( $is_variation && 'publish' !== get_post_status( $product_id ) ) {
				continue;
			}
			$stored_skus = array_merge(
				self::get_meta_sku_values( (int) $product_id, DTB_ProductMeta::COMPATIBLE_TOOL_SKUS ),
				self::get_meta_sku_values( (int) $product_id, DTB_ProductMeta::REPLACEMENT_PART_FOR )
			);
			$matched_skus = array_values( array_intersect( $skus, $stored_skus ) );
			if ( ! empty( $matched_skus ) ) {
				$matches[] = [
					'id'           => (int) $product_id,
					'matched_skus' => $matched_skus,
					'sku'          => strtoupper( (string) get_post_meta( $product_id, '_sku', true ) ),
				];
			}
		}

		$sku_order = array_flip( $skus );
		usort( $matches, static function ( array $a, array $b ) use ( $sku_order ): int {
			$match_count = count( $b['matched_skus'] ) <=> count( $a['matched_skus'] );
			if ( 0 !== $match_count ) {
				return $match_count;
			}

			$rank_a = min( array_map( static fn( $sku ): int => $sku_order[ $sku ] ?? PHP_INT_MAX, $a['matched_skus'] ) );
			$rank_b = min( array_map( static fn( $sku ): int => $sku_order[ $sku ] ?? PHP_INT_MAX, $b['matched_skus'] ) );
			if ( $rank_a !== $rank_b ) {
				return $rank_a <=> $rank_b;
			}

			return strcmp( $a['sku'], $b['sku'] );
		} );

		return array_values( array_map( static fn( array $match ): int => $match['id'], $matches ) );
	}

	/**
	 * @param  int[] $product_ids
	 * @return array[]  DTB product DTOs.
	 */
	private static function normalize_product_ids( array $product_ids, int $limit = 0 ): array {
		$ids = [];
		$part_variations_by_parent = [];
		foreach ( array_unique( array_map( 'absint', $product_ids ) ) as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			if ( $product instanceof WC_Product_Variation ) {
				$variation_id = $id;
				$id = $product->get_parent_id();
				$variation_sku = (string) $product->get_sku();
				if ( $id <= 0 || '' === $variation_sku || 'publish' !== get_post_status( $variation_id ) || (string) wc_get_product_id_by_sku( $variation_sku ) !== (string) $variation_id ) {
					continue;
				}
				$attributes = $product->get_variation_attributes();
				$permalink = function_exists( 'get_permalink' ) ? get_permalink( $id ) : '';
				if ( $permalink && $attributes && function_exists( 'add_query_arg' ) ) {
					$permalink = add_query_arg( $attributes, $permalink );
				}
				$part_variations_by_parent[ $id ][] = [
					'id' => $variation_id,
					'sku' => $variation_sku,
					'attributes' => $attributes,
					'permalink' => $permalink,
					'price' => (string) $product->get_price(),
					'regular_price' => (string) $product->get_regular_price(),
					'sale_price' => (string) $product->get_sale_price(),
					'purchasable' => (bool) $product->is_purchasable(),
					'is_in_stock' => (bool) $product->is_in_stock(),
					'compatible_tool_skus' => self::get_meta_sku_values( $variation_id, DTB_ProductMeta::COMPATIBLE_TOOL_SKUS ),
					'replacement_part_for' => self::get_meta_sku_values( $variation_id, DTB_ProductMeta::REPLACEMENT_PART_FOR ),
				];
				$product = wc_get_product( $id );
			}
			if ( ! $product || 'publish' !== get_post_status( $product->get_id() ) || ! $product->is_visible() || ! self::is_part_product( $product->get_id() ) ) {
				continue;
			}
			$ids[] = $product->get_id();
		}
		$ids = array_values( array_unique( $ids ) );
		if ( $limit > 0 ) {
			$ids = array_slice( $ids, 0, min( 100, $limit ) );
		}

		$dtos = [];
		foreach ( array_chunk( $ids, 100 ) as $chunk ) {
			foreach ( dtb_catalog_wc_fetch_products_by_ids( $chunk ) as $wc_product ) {
				if ( is_array( $wc_product ) && 'publish' === ( $wc_product['status'] ?? '' ) ) {
					$dto = dtb_catalog_normalize_product( $wc_product );
					if ( isset( $part_variations_by_parent[ (int) ( $wc_product['id'] ?? 0 ) ] ) ) {
						$dto['compatible_part_variations'] = $part_variations_by_parent[ (int) $wc_product['id'] ];
					}
					$dtos[] = $dto;
				}
			}
		}
		return $dtos;
	}

	/**
	 * Resolve exact published tool SKUs and normalize their parent product DTOs.
	 * Variation targets stay exact in compatible_variations while multiple exact
	 * children share one visible parent product DTO.
	 *
	 * @param  string[] $tool_skus
	 * @return array[]|WP_REST_Response
	 */
	private static function normalize_compatible_tool_skus( array $tool_skus ): array|WP_REST_Response {
		$parent_ids                = [];
		$variations_by_parent_id   = [];

		foreach ( $tool_skus as $tool_sku ) {
			$tool_id = self::get_product_id_by_sku( $tool_sku );
			$tool    = $tool_id ? wc_get_product( $tool_id ) : false;
			if ( ! $tool || (string) $tool->get_sku() !== $tool_sku || 'publish' !== get_post_status( $tool_id ) ) {
				continue;
			}

			if ( $tool instanceof WC_Product_Variation ) {
				$parent_id = absint( $tool->get_parent_id() );
				$parent    = $parent_id > 0 ? wc_get_product( $parent_id ) : false;
				if ( ! $parent || 'publish' !== get_post_status( $parent_id ) || ! $parent->is_visible() || self::is_part_product( $parent_id ) ) {
					continue;
				}

				$parent_ids[ $parent_id ] = true;
				$variations_by_parent_id[ $parent_id ][] = [
					'sku'        => (string) $tool->get_sku(),
					'attributes' => $tool->get_variation_attributes(),
				];
				continue;
			}

			if ( ! $tool->is_visible() || self::is_part_product( $tool_id ) ) {
				continue;
			}
			$parent_ids[ $tool_id ] = true;
		}

		if ( empty( $parent_ids ) ) {
			return [];
		}

		$response = dtb_catalog_wc_get_products_by_ids_response( array_map( 'absint', array_keys( $parent_ids ) ) );
		if ( ! $response instanceof WP_REST_Response || 200 !== $response->get_status() ) {
			return new WP_REST_Response(
				dtb_error_envelope( 'catalog_read_failed', 'Compatible tools could not be resolved from the product catalog.', 503 ),
				503
			);
		}
		$products = $response->get_data();
		if ( ! is_array( $products ) ) {
			return new WP_REST_Response(
				dtb_error_envelope( 'catalog_read_invalid', 'The product catalog returned an invalid compatible-tool response.', 502 ),
				502
			);
		}

		$products_by_id = [];
		foreach ( $products as $wc_product ) {
			if ( ! is_array( $wc_product ) || 'publish' !== ( $wc_product['status'] ?? '' ) ) {
				continue;
			}
			$product_id = absint( $wc_product['id'] ?? 0 );
			if ( isset( $parent_ids[ $product_id ] ) ) {
				$products_by_id[ $product_id ] = $wc_product;
			}
		}
		if ( count( $products_by_id ) !== count( $parent_ids ) ) {
			return new WP_REST_Response(
				dtb_error_envelope( 'catalog_read_incomplete', 'The product catalog returned an incomplete compatible-tool response.', 503 ),
				503
			);
		}

		$dtos = [];
		foreach ( array_keys( $parent_ids ) as $parent_id ) {
			if ( empty( $products_by_id[ $parent_id ] ) ) {
				continue;
			}
			$dto = dtb_catalog_normalize_product( $products_by_id[ $parent_id ] );
			if ( ! empty( $variations_by_parent_id[ $parent_id ] ) ) {
				$dto['compatible_variations'] = $variations_by_parent_id[ $parent_id ];
			}
			$dtos[] = $dto;
		}

		return $dtos;
	}

	/**
	 * Look up a product post ID by its WooCommerce SKU.
	 *
	 * @param  string $sku
	 * @return int|null
	 */
	private static function get_product_id_by_sku( string $sku ): ?int {
		$product_id = wc_get_product_id_by_sku( $sku );
		return $product_id > 0 ? $product_id : null;
	}

	/**
	 * Decode the catalog's comma-separated, JSON, or WordPress array meta value.
	 *
	 * @param  mixed $raw
	 * @return string[]
	 */
	private static function decode_sku_list( mixed $raw ): array {
		$values = DTB_CatalogProductNormalizer::decode_csv_or_array( $raw );
		return array_values( array_filter( array_map(
			static fn( $value ): string => is_scalar( $value ) ? (string) $value : '',
			$values
		), static fn( $value ) => '' !== $value ) );
	}

	/**
	 * Read every stored value for multi-value compatibility metadata.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $meta_key   Compatibility metadata key.
	 * @return string[]
	 */
	private static function get_meta_sku_values( int $product_id, string $meta_key ): array {
		$raw_values = get_post_meta( $product_id, $meta_key, false );
		$values     = [];
		foreach ( (array) $raw_values as $raw_value ) {
			$values = array_merge( $values, self::decode_sku_list( $raw_value ) );
		}
		return array_values( array_unique( $values ) );
	}

	/**
	 * Resolve the product SKU and exact SKUs of any included tool components.
	 *
	 * @param string $sku Requested product SKU.
	 * @return string[]
	 */
	private static function get_tool_skus_for_product( string $sku ): array {
		$skus       = [ $sku ];
		$product_id = wc_get_product_id_by_sku( $sku );
		$product    = $product_id > 0 ? wc_get_product( $product_id ) : false;
		if ( ! $product ) {
			return $skus;
		}
		$skus = [ (string) $product->get_sku() ];
		if ( $product instanceof WC_Product_Variation ) {
			$parent_id = $product->get_parent_id();
			$parent = $parent_id > 0 ? wc_get_product( $parent_id ) : false;
			$parent_is_part = $parent_id > 0 && (
				'part' === (string) get_post_meta( $parent_id, DTB_ProductMeta::PRODUCT_KIND, true )
				|| '1' === (string) get_post_meta( $parent_id, DTB_ProductMeta::IS_PARTS, true )
			);
			$eligible_parent = $parent && 'publish' === get_post_status( $parent_id ) && $parent->is_visible() && ! $parent_is_part;
			return self::append_parent_tool_sku( $skus, $parent ? (string) $parent->get_sku() : '', (bool) $eligible_parent );
		}

		for ( $index = 0; $index < 20; $index++ ) {
			$included_sku = (string) get_post_meta( $product_id, '_includes_' . $index . '_sku', true );
			if ( '' === $included_sku ) {
				continue;
			}
			$included_id  = wc_get_product_id_by_sku( $included_sku );
			$included     = $included_id > 0 ? wc_get_product( $included_id ) : false;
			if ( ! $included || (string) $included->get_sku() !== $included_sku ) {
				continue;
			}
			$skus[] = (string) $included->get_sku();
			if ( ! $included->is_type( 'variable' ) ) {
				continue;
			}
			foreach ( array_slice( $included->get_children(), 0, 100 ) as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( $variation instanceof WC_Product_Variation && 'publish' === get_post_status( $variation_id ) && $variation->is_visible() ) {
					$variation_sku = (string) $variation->get_sku();
					if ( '' !== $variation_sku ) {
						$skus[] = $variation_sku;
					}
				}
			}
		}

		return array_values( array_unique( array_filter( $skus ) ) );
	}

	/** Preserve exact variation identity and optionally include its eligible family SKU. */
	private static function append_parent_tool_sku( array $skus, string $parent_sku, bool $eligible ): array {
		if ( $eligible && '' !== $parent_sku ) {
			$skus[] = $parent_sku;
		}
		return array_values( array_unique( $skus ) );
	}
}
