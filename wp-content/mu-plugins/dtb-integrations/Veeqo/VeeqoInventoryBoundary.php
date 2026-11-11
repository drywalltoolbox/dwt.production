<?php
/**
 * DTB Veeqo Inventory Boundary.
 *
 * Veeqo is the inventory/fulfillment source of truth. WooCommerce stores the
 * checkout-facing stock projection. This file prevents public bulk inventory
 * disclosure and exposes a narrow public cart-availability endpoint.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DTB_VEEQO_CART_AVAILABILITY_RATE_LIMIT' ) ) {
	define( 'DTB_VEEQO_CART_AVAILABILITY_RATE_LIMIT', 60 );
}
if ( ! defined( 'DTB_VEEQO_CART_AVAILABILITY_IP_RATE_LIMIT' ) ) {
	define( 'DTB_VEEQO_CART_AVAILABILITY_IP_RATE_LIMIT', 600 );
}
if ( ! defined( 'DTB_VEEQO_CART_AVAILABILITY_RATE_WINDOW' ) ) {
	define( 'DTB_VEEQO_CART_AVAILABILITY_RATE_WINDOW', MINUTE_IN_SECONDS );
}
if ( ! defined( 'DTB_VEEQO_CART_AVAILABILITY_MAX_ITEMS' ) ) {
	define( 'DTB_VEEQO_CART_AVAILABILITY_MAX_ITEMS', 100 );
}

if ( ! function_exists( 'dtb_veeqo_inventory_boundary_client_ip' ) ) {
	function dtb_veeqo_inventory_boundary_client_ip(): string {
		if ( function_exists( 'dtb_get_client_ip' ) ) {
			return (string) dtb_get_client_ip();
		}
		return sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
	}
}

if ( ! function_exists( 'dtb_veeqo_inventory_boundary_client_token' ) ) {
	/**
	 * Build a privacy-safe identity token for per-shopper rate limiting.
	 *
	 * Logged-in users, WooCommerce sessions, and storefront-generated client
	 * tokens receive isolated buckets. The user-agent fallback is only used when
	 * none of those stronger identifiers is available.
	 */
	function dtb_veeqo_inventory_boundary_client_token( ?WP_REST_Request $request = null ): string {
		$user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		if ( $user_id > 0 ) {
			return 'user:' . $user_id;
		}

		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		if ( is_object( $woocommerce ) && isset( $woocommerce->session ) && is_object( $woocommerce->session ) && method_exists( $woocommerce->session, 'get_customer_id' ) ) {
			$customer_id = trim( (string) $woocommerce->session->get_customer_id() );
			if ( '' !== $customer_id ) {
				return 'wc-session:' . hash( 'sha256', $customer_id );
			}
		}

		foreach ( (array) $_COOKIE as $cookie_name => $cookie_value ) {
			if ( str_starts_with( (string) $cookie_name, 'wp_woocommerce_session_' ) && '' !== (string) $cookie_value ) {
				return 'wc-cookie:' . hash( 'sha256', (string) $cookie_value );
			}
		}

		$client_token = $request instanceof WP_REST_Request
			? trim( sanitize_text_field( (string) $request->get_header( 'x-dtb-client-token' ) ) )
			: '';
		if ( preg_match( '/^[A-Za-z0-9._:-]{16,128}$/', $client_token ) ) {
			return 'client:' . hash( 'sha256', $client_token );
		}

		$user_agent = substr( sanitize_text_field( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? 'anonymous' ) ), 0, 240 );
		return 'anonymous:' . hash( 'sha256', $user_agent );
	}
}

if ( ! function_exists( 'dtb_veeqo_inventory_boundary_rate_limited' ) ) {
	function dtb_veeqo_inventory_boundary_rate_limited( string $bucket, int $limit, int $window ): bool {
		$key   = 'dtb_veeqo_inv_rl_' . md5( $bucket );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return true;
		}
		set_transient( $key, $count + 1, max( 30, $window ) );
		return false;
	}
}

if ( ! function_exists( 'dtb_veeqo_inventory_boundary_request_rate_limited' ) ) {
	/**
	 * Apply a shopper/session limit plus a higher shared-IP safety ceiling.
	 *
	 * The composite bucket avoids throttling unrelated shoppers behind the same
	 * NAT, while the IP ceiling prevents trivial cookie rotation from fully
	 * bypassing abuse protection.
	 */
	function dtb_veeqo_inventory_boundary_request_rate_limited( WP_REST_Request $request ): bool {
		$ip       = dtb_veeqo_inventory_boundary_client_ip();
		$identity = dtb_veeqo_inventory_boundary_client_token( $request );
		$window   = (int) DTB_VEEQO_CART_AVAILABILITY_RATE_WINDOW;

		$identity_limited = dtb_veeqo_inventory_boundary_rate_limited(
			'identity:' . $ip . ':' . $identity,
			(int) DTB_VEEQO_CART_AVAILABILITY_RATE_LIMIT,
			$window
		);
		$ip_limited = dtb_veeqo_inventory_boundary_rate_limited(
			'ip:' . $ip,
			(int) DTB_VEEQO_CART_AVAILABILITY_IP_RATE_LIMIT,
			$window
		);

		return $identity_limited || $ip_limited;
	}
}

add_filter(
	'rest_pre_dispatch',
	static function ( $result, $server, WP_REST_Request $request ) {
		if ( null !== $result ) {
			return $result;
		}

		$route  = (string) $request->get_route();
		$method = strtoupper( (string) $request->get_method() );
		if ( '/dtb/v1/veeqo/inventory' !== $route || 'GET' !== $method ) {
			return $result;
		}

		if ( current_user_can( 'manage_woocommerce' ) ) {
			return $result;
		}

		if ( function_exists( 'dtb_veeqo_log' ) ) {
			dtb_veeqo_log( 'warn', 'public_bulk_inventory_blocked', 'Blocked public request to bulk Veeqo inventory endpoint.', [
				'ip' => dtb_veeqo_inventory_boundary_client_ip(),
			] );
		}

		return new WP_REST_Response(
			[
				'code'    => 'dtb_bulk_inventory_admin_only',
				'message' => 'Bulk Veeqo inventory is admin-only. Use /dtb/v1/veeqo/cart-availability for storefront availability checks.',
			],
			403
		);
	},
	-50,
	3
);

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route( 'dtb/v1', '/veeqo/cart-availability', [
			'methods'             => 'POST',
			'callback'            => 'dtb_veeqo_route_cart_availability',
			'permission_callback' => '__return_true',
			'args'                => [
				'items' => [
					'required' => true,
					'type'     => 'array',
				],
			],
		] );
	},
	20
);

if ( ! function_exists( 'dtb_veeqo_normalize_cart_availability_items' ) ) {
	function dtb_veeqo_normalize_cart_availability_items( array $items ): array {
		$normalized = [];
		foreach ( $items as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$sku        = trim( sanitize_text_field( (string) ( $raw['sku'] ?? $raw['sku_code'] ?? '' ) ) );
			$product_id = absint( $raw['variation_id'] ?? $raw['product_id'] ?? $raw['id'] ?? 0 );
			$raw_qty    = $raw['quantity'] ?? $raw['qty'] ?? 1;
			$quantity   = is_numeric( $raw_qty ) ? max( 1, absint( $raw_qty ) ) : 1;

			if ( '' === $sku && $product_id <= 0 ) {
				continue;
			}

			$normalized[] = [
				'sku'        => $sku,
				'quantity'   => $quantity,
				'product_id' => $product_id,
				'name'       => sanitize_text_field( (string) ( $raw['name'] ?? $raw['productName'] ?? '' ) ),
			];
		}

		return array_slice( $normalized, 0, max( 1, (int) DTB_VEEQO_CART_AVAILABILITY_MAX_ITEMS ) );
	}
}

if ( ! function_exists( 'dtb_veeqo_check_projected_stock_for_sku' ) ) {
	/**
	 * Check WooCommerce's Veeqo-synchronized stock projection by SKU or product ID.
	 */
	function dtb_veeqo_check_projected_stock_for_sku( string $sku, int $requested, int $fallback_product_id = 0, string $name = '' ): array {
		$product_id = '' !== $sku && function_exists( 'wc_get_product_id_by_sku' )
			? absint( wc_get_product_id_by_sku( $sku ) )
			: 0;
		if ( $product_id <= 0 && $fallback_product_id > 0 ) {
			$product_id = $fallback_product_id;
		}

		$product = $product_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product instanceof WC_Product ) {
			return [
				'sku'         => $sku,
				'productId'   => $fallback_product_id ?: null,
				'productName' => $name,
				'requested'   => $requested,
				'available'   => null,
				'inStock'     => true,
				'status'      => 'unknown',
				'message'     => 'Product is not mapped in the WooCommerce stock projection; checkout will rely on server-side WooCommerce validation.',
			];
		}

		$resolved_sku = trim( (string) $product->get_sku() );
		$available    = $product->managing_stock() ? $product->get_stock_quantity() : null;
		$in_stock     = $product->is_in_stock() && ( null === $available || (int) $available >= $requested );

		return [
			'sku'         => '' !== $resolved_sku ? $resolved_sku : $sku,
			'productId'   => $product->get_id(),
			'productName' => $product->get_name(),
			'requested'   => $requested,
			'available'   => null === $available ? null : (int) $available,
			'inStock'     => $in_stock,
			'status'      => $in_stock ? 'available' : 'insufficient_stock',
		];
	}
}

if ( ! function_exists( 'dtb_veeqo_route_cart_availability' ) ) {
	function dtb_veeqo_route_cart_availability( WP_REST_Request $request ): WP_REST_Response {
		if ( dtb_veeqo_inventory_boundary_request_rate_limited( $request ) ) {
			$response = new WP_REST_Response( [ 'code' => 'rate_limited', 'message' => 'Too many availability checks. Please try again shortly.' ], 429 );
			$response->header( 'Retry-After', (string) DTB_VEEQO_CART_AVAILABILITY_RATE_WINDOW );
			return $response;
		}

		$body  = $request->get_json_params();
		$items = dtb_veeqo_normalize_cart_availability_items( is_array( $body ) && is_array( $body['items'] ?? null ) ? $body['items'] : [] );
		if ( empty( $items ) ) {
			return new WP_REST_Response( [ 'code' => 'invalid_items', 'message' => 'Request body must include at least one item with a SKU or product ID and quantity.' ], 400 );
		}

		$checks = [];
		foreach ( $items as $item ) {
			$checks[] = dtb_veeqo_check_projected_stock_for_sku( $item['sku'], $item['quantity'], $item['product_id'], $item['name'] );
		}

		$out_of_stock = array_values( array_filter( $checks, static fn( array $check ): bool => empty( $check['inStock'] ) ) );

		return new WP_REST_Response(
			[
				'available'  => empty( $out_of_stock ),
				'items'      => $checks,
				'outOfStock' => $out_of_stock,
				'source'     => 'woocommerce_stock_projection_from_veeqo',
			],
			200
		);
	}
}
