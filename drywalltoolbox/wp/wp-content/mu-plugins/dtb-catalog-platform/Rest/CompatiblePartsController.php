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
		register_rest_route( 'dtb/v1', '/products/(?P<sku>' . self::SKU_SEGMENT_PATTERN . ')/compatible-parts', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'handle_compatible_parts' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'sku' => [
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => static fn( $v ) => self::is_valid_sku( $v ),
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

		$product_ids = self::find_products_with_sku_in_meta(
			$tool_sku,
			[ DTB_ProductMeta::COMPATIBLE_TOOL_SKUS, DTB_ProductMeta::REPLACEMENT_PART_FOR ]
		);

		$dtos = self::normalize_product_ids( $product_ids );

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

		$compatible_raw = get_post_meta( $part_post, DTB_ProductMeta::COMPATIBLE_TOOL_SKUS, true );
		$replacement_raw = get_post_meta( $part_post, DTB_ProductMeta::REPLACEMENT_PART_FOR, true );

		$tool_skus = array_filter( array_unique( array_merge(
			self::decode_sku_list( $compatible_raw ),
			self::decode_sku_list( $replacement_raw )
		) ) );

		$dtos = [];
		foreach ( $tool_skus as $tool_sku ) {
			$tool_id = self::get_product_id_by_sku( $tool_sku );
			if ( $tool_id ) {
				$dto = self::normalize_single( $tool_id );
				if ( $dto ) {
					$dtos[] = $dto;
				}
			}
		}

		return new WP_REST_Response( [
			'partSku'  => $part_sku,
			'products' => $dtos,
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

	/**
	 * Find all published product IDs that contain the given SKU in any of the
	 * specified meta keys (comma-separated or serialized array values).
	 *
	 * @param  string   $sku        Normalized SKU to search for.
	 * @param  string[] $meta_keys  Meta keys to search in.
	 * @return int[]
	 */
	private static function find_products_with_sku_in_meta( string $sku, array $meta_keys ): array {
		$ids = [];
		foreach ( $meta_keys as $key ) {
			$found = get_posts( [
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'meta_query'     => [
					[
						'key'     => $key,
						'value'   => $sku,
						'compare' => 'LIKE',
					],
				],
			] );
			foreach ( $found as $product_id ) {
				$stored_skus = self::decode_sku_list( get_post_meta( $product_id, $key, true ) );
				foreach ( $stored_skus as $stored_sku ) {
					if ( 0 === strcasecmp( $sku, $stored_sku ) ) {
						$ids[] = (int) $product_id;
						break;
					}
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param  int[] $product_ids
	 * @return array[]  DTB product DTOs.
	 */
	private static function normalize_product_ids( array $product_ids ): array {
		$dtos = [];
		foreach ( $product_ids as $id ) {
			$dto = self::normalize_single( (int) $id );
			if ( $dto ) {
				$dtos[] = $dto;
			}
		}
		return $dtos;
	}

	/**
	 * Normalize a single product ID into a DTB product DTO using the WC REST proxy.
	 *
	 * @param  int  $product_id
	 * @return array|null
	 */
	private static function normalize_single( int $product_id ): ?array {
		if ( $product_id <= 0 ) {
			return null;
		}

		$response = dtb_cached_wc_get( 'wc/v3/products/' . $product_id, [
			'_fields' => DTB_PRODUCT_DETAIL_FIELDS,
		] );

		if ( $response->get_status() !== 200 ) {
			return null;
		}

		$wc = $response->get_data();
		if ( ! is_array( $wc ) || empty( $wc ) ) {
			return null;
		}

		return dtb_catalog_normalize_product( $wc );
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
		return array_values( array_filter( array_map( 'trim', $values ), static fn( $value ) => '' !== $value ) );
	}
}
