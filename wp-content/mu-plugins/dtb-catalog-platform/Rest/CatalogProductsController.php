<?php
/**
 * DTB_CatalogProductsController
 *
 * Handles:
 *   GET /wp-json/dtb/v1/catalog/products
 *
 * Returns a paginated list of normalized DTB catalog product DTOs.
 * Supports server-side filtering by brand, category, display_category,
 * tool_family, product_kind, builder_eligible, builder_slot, workflow_scope,
 * search, page, per_page, and sort.
 *
 * `display_category` accepts either a single slug (e.g. `handles`) or a
 * comma-separated list of slugs (e.g. `handles,corner_tools`) for storefront
 * multi-select filtering. Multiple values are OR-combined: a product is
 * included if it matches ANY listed category.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_CatalogProductsController {

	public static function register_routes(): void {
		register_rest_route( 'dtb/v1', '/catalog/products', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'handle' ],
			'permission_callback' => '__return_true',
			'args'                => self::route_args(),
		] );
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		if ( ! dtb_check_origin() ) {
			return new WP_REST_Response( dtb_error_envelope( 'forbidden_origin', 'Origin not allowed.', 403 ), 403 );
		}

		$rl = dtb_rate_limit_get( 'wc/v3/products' );
		if ( $rl ) {
			return $rl;
		}

		$builder_eligible = $request->has_param( 'builder_eligible' )
			? absint( $request->get_param( 'builder_eligible' ) )
			: null;

		$is_parts = $request->has_param( 'is_parts' )
			? absint( $request->get_param( 'is_parts' ) )
			: null;

		if ( null !== $builder_eligible && ! in_array( $builder_eligible, [ 0, 1 ], true ) ) {
			$builder_eligible = null;
		}

		if ( null !== $is_parts && ! in_array( $is_parts, [ 0, 1 ], true ) ) {
			$is_parts = null;
		}

		$query = DTB_CatalogProductRepository::find_ids( [
			'brand'            => (string) ( $request->get_param( 'brand' ) ?? '' ),
			'category'         => (string) ( $request->get_param( 'category' ) ?? '' ),
			'display_category' => (string) ( $request->get_param( 'display_category' ) ?? '' ),
			'tool_family'      => (string) ( $request->get_param( 'tool_family' ) ?? '' ),
			'product_kind'     => (string) ( $request->get_param( 'product_kind' ) ?? '' ),
			'builder_eligible' => $builder_eligible,
			'builder_slot'     => (string) ( $request->get_param( 'builder_slot' ) ?? '' ),
			'workflow_scope'   => (string) ( $request->get_param( 'workflow_scope' ) ?? '' ),
			'is_parts'         => $is_parts,
			'search'           => (string) ( $request->get_param( 'search' ) ?? '' ),
			'page'             => max( 1, absint( $request->get_param( 'page' ) ?? 1 ) ),
			'per_page'         => min( 100, max( 1, absint( $request->get_param( 'per_page' ) ?? 24 ) ) ),
			'sort'             => (string) ( $request->get_param( 'sort' ) ?? 'popular' ),
		] );

		$ids = $query['ids'];
		if ( empty( $ids ) ) {
			return new WP_REST_Response( [
				'items'      => [],
				'pagination' => [
					'page'       => $query['page'],
					'perPage'    => $query['perPage'],
					'total'      => $query['total'],
					'totalPages' => $query['totalPages'],
				],
			], 200 );
		}

		$raw_response = dtb_catalog_wc_get_products_by_ids_response( $ids );
		if ( ! is_object( $raw_response ) ) {
			return new WP_REST_Response(
				dtb_error_envelope( 'upstream_error', 'Unexpected response from product catalog.', 502 ),
				502
			);
		}
		if ( $raw_response->get_status() !== 200 ) {
			return $raw_response;
		}

		$raw_products = $raw_response->get_data();
		if ( ! is_array( $raw_products ) || empty( $raw_products ) ) {
			return new WP_REST_Response( [
				'items'      => [],
				'pagination' => [
					'page'       => $query['page'],
					'perPage'    => $query['perPage'],
					'total'      => $query['total'],
					'totalPages' => $query['totalPages'],
				],
			], 200 );
		}

		$raw_by_id = [];
		foreach ( $raw_products as $raw ) {
			if ( is_array( $raw ) && ! empty( $raw['id'] ) ) {
				$raw_by_id[ absint( $raw['id'] ) ] = $raw;
			}
		}

		$items = [];
		foreach ( $ids as $id ) {
			if ( ! isset( $raw_by_id[ $id ] ) ) {
				continue;
			}

			$dto = dtb_catalog_normalize_product( $raw_by_id[ $id ] );

			// Listing enrichment for variable products: resolve and apply the
			// default variation directly into cardProduct for storefront cards.
			if ( 'variable' === $dto['type'] ) {
				$variations  = DTB_VariationReadModelService::get_normalized( $dto['id'], $raw_by_id[ $id ], false );
				$default_var = dtb_catalog_resolve_default_variation( $dto, $variations );
				$dto         = dtb_catalog_apply_default_variation_to_card( $dto, $default_var );
			}

			$items[] = $dto;
		}

		return new WP_REST_Response( [
			'items'      => $items,
			'pagination' => [
				'page'       => $query['page'],
				'perPage'    => $query['perPage'],
				'total'      => $query['total'],
				'totalPages' => $query['totalPages'],
			],
		], 200 );
	}

	private static function route_args(): array {
		return [
			'brand'            => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'category'         => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'display_category' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'tool_family'      => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'product_kind'     => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'builder_eligible' => [ 'type' => 'integer' ],
			'builder_slot'     => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'workflow_scope'   => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'is_parts'         => [ 'type' => 'integer' ],
			'search'           => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'page'             => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
			'per_page'         => [ 'type' => 'integer', 'default' => 24, 'minimum' => 1, 'maximum' => 100 ],
			'sort'             => [ 'type' => 'string', 'default' => 'popular', 'sanitize_callback' => 'sanitize_text_field' ],
		];
	}
}
