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
	private const CARD_FIELDS = 'id,type,status,sku,slug,name,price,regular_price,sale_price,on_sale,purchasable,manage_stock,stock_quantity,backorders_allowed,stock_status,parent_id,categories,brands,images,attributes,default_attributes,meta_data';

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
		$include_total = ! $request->has_param( 'include_total' )
			|| 1 === absint( $request->get_param( 'include_total' ) );

		if ( null !== $builder_eligible && ! in_array( $builder_eligible, [ 0, 1 ], true ) ) {
			$builder_eligible = null;
		}

		if ( null !== $is_parts && ! in_array( $is_parts, [ 0, 1 ], true ) ) {
			$is_parts = null;
		}

		$filters = [
			'brand'            => (string) ( $request->get_param( 'brand' ) ?? '' ),
			'category'         => (string) ( $request->get_param( 'category' ) ?? '' ),
			'display_category' => (string) ( $request->get_param( 'display_category' ) ?? '' ),
			'tool_family'      => (string) ( $request->get_param( 'tool_family' ) ?? '' ),
			'product_kind'     => (string) ( $request->get_param( 'product_kind' ) ?? '' ),
			'builder_eligible' => $builder_eligible,
			'builder_slot'     => (string) ( $request->get_param( 'builder_slot' ) ?? '' ),
			'workflow_scope'   => (string) ( $request->get_param( 'workflow_scope' ) ?? '' ),
			'is_parts'         => $is_parts,
			'include_total'    => $include_total,
			'search'           => (string) ( $request->get_param( 'search' ) ?? '' ),
			'page'             => max( 1, absint( $request->get_param( 'page' ) ?? 1 ) ),
			'per_page'         => min( 100, max( 1, absint( $request->get_param( 'per_page' ) ?? 24 ) ) ),
			'sort'             => (string) ( $request->get_param( 'sort' ) ?? 'popular' ),
		];
		$projection = (string) ( $request->get_param( 'projection' ) ?? 'full' );

		if ( 'card' === $projection && self::is_cacheable_public_card_read( $request, $filters ) && function_exists( 'dtb_cached_proxy' ) ) {
			$cached = dtb_cached_proxy(
				'catalog/products',
				[ 'filters' => $filters, 'projection' => $projection ],
				static function () use ( $filters, $projection ) {
					$response = self::build_response( $filters, $projection );
					if ( 200 !== $response->get_status() ) {
						return new WP_Error(
							'dtb_catalog_response_passthrough',
							__( 'Catalog response must not be cached.', 'drywall-toolbox' ),
							[ 'response' => $response ]
						);
					}

					return $response->get_data();
				}
			);

			if ( is_wp_error( $cached ) ) {
				$passthrough = $cached->get_error_data()['response'] ?? null;
				if ( $passthrough instanceof WP_REST_Response ) {
					return $passthrough;
				}

				return new WP_REST_Response(
					dtb_error_envelope( 'catalog_unavailable', __( 'The product catalog is temporarily unavailable.', 'drywall-toolbox' ), 503 ),
					503
				);
			}

			return new WP_REST_Response( $cached, 200 );
		}

		return self::build_response( $filters, $projection );
	}

	/**
	 * Restrict server-side response caching to anonymous, bounded card reads.
	 * Session-bound and search results always use the live catalog path.
	 *
	 * @param WP_REST_Request $request
	 * @param array           $filters
	 */
	private static function is_cacheable_public_card_read( WP_REST_Request $request, array $filters ): bool {
		if ( is_user_logged_in() || '' !== (string) $request->get_header( 'authorization' ) ) {
			return false;
		}

		if ( '' !== (string) ( $filters['search'] ?? '' )
			|| 1 !== (int) ( $filters['page'] ?? 1 )
			|| (int) ( $filters['per_page'] ?? 24 ) > 24 ) {
			return false;
		}

		foreach ( array_keys( $_COOKIE ) as $cookie_name ) {
			if ( 0 === strpos( $cookie_name, 'wp_woocommerce_session_' )
				|| in_array( $cookie_name, [ 'woocommerce_items_in_cart', 'woocommerce_cart_hash' ], true ) ) {
				return false;
			}
		}

		return true;
	}

	/** Build an uncached canonical catalog response from WooCommerce data. */
	private static function build_response( array $filters, string $projection ): WP_REST_Response {
		$query = DTB_CatalogProductRepository::find_ids( $filters );

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

		$raw_response = dtb_catalog_wc_get_products_by_ids_response(
			$ids,
			'card' === $projection ? self::CARD_FIELDS : null
		);
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
			// Only the explicitly configured WooCommerce variation ID is resolved
			// for a card. Missing or stale configuration leaves the parent card in
			// its options state instead of triggering a legacy child-list fallback.
			if ( 'variable' === $dto['type'] ) {
				$default_var = null;
				$default_id  = absint( $dto['defaultVariationId'] ?? 0 );
				if ( $default_id > 0 ) {
					$default_var = DTB_VariationReadModelService::get_normalized_by_id(
						$default_id,
						false,
						$raw_by_id[ $id ]
					);
					if ( absint( $default_var['parentId'] ?? 0 ) !== absint( $dto['id'] ) ) {
						$default_var = null;
					}
				}
				$dto         = dtb_catalog_apply_default_variation_to_card( $dto, $default_var );
			}
			if ( 'card' === $projection ) {
				$dto = dtb_catalog_product_card_projection( $dto );
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
			'include_total'    => [ 'type' => 'integer', 'default' => 1 ],
			'search'           => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static fn( $value, $request = null, $param = null ): bool => is_string( $value ) && strlen( $value ) <= 100,
			],
			'projection'       => [
				'type'              => 'string',
				'default'           => 'full',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => static fn( $value, $request = null, $param = null ): bool => in_array( $value, [ 'full', 'card' ], true ),
			],
			'page'             => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
			'per_page'         => [ 'type' => 'integer', 'default' => 24, 'minimum' => 1, 'maximum' => 100 ],
			'sort'             => [ 'type' => 'string', 'default' => 'popular', 'sanitize_callback' => 'sanitize_text_field' ],
		];
	}
}
