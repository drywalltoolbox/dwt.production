<?php
/**
 * Drywall Toolbox customer order REST routes.
 *
 * Provides the frontend account dashboard/order panel with a stable customer-safe
 * order API independent of the legacy WooCommerce proxy routes.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_customer_orders_register_routes', 20 );

function dtb_customer_orders_register_routes(): void {
	$namespace = 'dtb/v1';

	register_rest_route( $namespace, '/orders', [
		'methods'             => 'GET',
		'callback'            => 'dtb_customer_orders_list_route',
		'permission_callback' => 'dtb_customer_orders_auth_permission',
		'args'                => [
			'page'     => [ 'sanitize_callback' => 'absint', 'default' => 1 ],
			'per_page' => [ 'sanitize_callback' => 'absint', 'default' => 20 ],
		],
	] );

	register_rest_route( $namespace, '/orders/(?P<id>\d+)', [
		'methods'             => 'GET',
		'callback'            => 'dtb_customer_orders_detail_route',
		'permission_callback' => 'dtb_customer_orders_order_permission',
		'args'                => [
			'id'        => [ 'sanitize_callback' => 'absint' ],
			'order_key' => [ 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
		],
	] );

	register_rest_route( $namespace, '/orders/(?P<id>\d+)/tracking', [
		'methods'             => 'GET',
		'callback'            => 'dtb_customer_orders_tracking_route',
		'permission_callback' => 'dtb_customer_orders_order_permission',
		'args'                => [
			'id'        => [ 'sanitize_callback' => 'absint' ],
			'order_key' => [ 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
		],
	] );

	register_rest_route( $namespace, '/orders/health', [
		'methods'             => 'GET',
		'callback'            => 'dtb_customer_orders_health_route',
		'permission_callback' => 'dtb_customer_orders_auth_permission',
	] );
}

function dtb_customer_orders_current_user_id(): int {
	$user_id = absint( get_current_user_id() );
	if ( $user_id > 0 ) {
		return $user_id;
	}

	if ( function_exists( 'dtb_jwt_get_user_id' ) ) {
		$user_id = absint( dtb_jwt_get_user_id() );
		if ( $user_id > 0 ) {
			return $user_id;
		}
	}

	return 0;
}

function dtb_customer_orders_current_user(): ?WP_User {
	$user_id = dtb_customer_orders_current_user_id();
	if ( $user_id <= 0 ) {
		return null;
	}

	$user = get_userdata( $user_id );
	return $user instanceof WP_User ? $user : null;
}

function dtb_customer_orders_user_can_manage_orders( int $user_id ): bool {
	if ( $user_id <= 0 ) {
		return false;
	}

	return user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'edit_shop_orders' );
}

function dtb_customer_orders_auth_permission( WP_REST_Request $request ): bool|WP_Error {
	if ( function_exists( 'dtb_check_origin' ) && ! dtb_check_origin() ) {
		return new WP_Error( 'forbidden_origin', 'Origin not allowed.', [ 'status' => 403 ] );
	}

	if ( function_exists( 'dtb_jwt_permission' ) ) {
		$result = dtb_jwt_permission( $request );
		if ( true === $result ) {
			return true;
		}
	}

	return is_user_logged_in()
		? true
		: new WP_Error( 'dtb_orders_auth_required', 'Authentication required.', [ 'status' => 401 ] );
}

function dtb_customer_orders_order_permission( WP_REST_Request $request ): bool|WP_Error {
	if ( function_exists( 'dtb_check_origin' ) && ! dtb_check_origin() ) {
		return new WP_Error( 'forbidden_origin', 'Origin not allowed.', [ 'status' => 403 ] );
	}

	$order_id = absint( $request['id'] ?? 0 );
	if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
		return new WP_Error( 'dtb_order_not_found', 'Order not found.', [ 'status' => 404 ] );
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return new WP_Error( 'dtb_order_not_found', 'Order not found.', [ 'status' => 404 ] );
	}

	$order_key = sanitize_text_field( (string) $request->get_param( 'order_key' ) );
	if ( '' !== $order_key && hash_equals( (string) $order->get_order_key(), $order_key ) ) {
		return true;
	}

	if ( function_exists( 'dtb_jwt_permission' ) ) {
		$result = dtb_jwt_permission( $request );
		if ( true !== $result && ! is_user_logged_in() ) {
			return new WP_Error( 'dtb_orders_auth_required', 'Authentication required.', [ 'status' => 401 ] );
		}
	}

	$current_user_id = dtb_customer_orders_current_user_id();
	$current_user    = dtb_customer_orders_current_user();
	$user_email      = $current_user ? strtolower( (string) $current_user->user_email ) : '';
	$order_email     = strtolower( (string) $order->get_billing_email() );
	$order_customer  = absint( $order->get_customer_id() );

	if ( dtb_customer_orders_user_can_manage_orders( $current_user_id ) ) {
		return true;
	}

	if ( $current_user_id > 0 && $order_customer === $current_user_id ) {
		return true;
	}

	if ( '' !== $user_email && '' !== $order_email && hash_equals( $order_email, $user_email ) ) {
		return true;
	}

	return new WP_Error( 'dtb_order_forbidden', 'You do not have permission to access this order.', [ 'status' => 403 ] );
}

function dtb_customer_orders_list_route( WP_REST_Request $request ): WP_REST_Response {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return new WP_REST_Response( [ 'orders' => [], 'page' => 1, 'per_page' => 20, 'has_more' => false ], 200 );
	}

	$user_id  = dtb_customer_orders_current_user_id();
	$user     = dtb_customer_orders_current_user();
	$email    = $user ? sanitize_email( (string) $user->user_email ) : '';
	$page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
	$per_page = min( 50, max( 1, absint( $request->get_param( 'per_page' ) ?: 20 ) ) );

	$orders_by_id = [];
	$query_base   = [
		'limit'   => $per_page,
		'paged'   => $page,
		'orderby' => 'date',
		'order'   => 'DESC',
		'return'  => 'objects',
	];

	if ( $user_id > 0 ) {
		foreach ( wc_get_orders( array_merge( $query_base, [ 'customer_id' => $user_id ] ) ) as $order ) {
			$orders_by_id[ $order->get_id() ] = $order;
		}
	}

	if ( '' !== $email ) {
		foreach ( wc_get_orders( array_merge( $query_base, [ 'billing_email' => $email ] ) ) as $order ) {
			$orders_by_id[ $order->get_id() ] = $order;
		}
	}

	$orders = array_values( $orders_by_id );
	usort( $orders, static function ( WC_Order $a, WC_Order $b ): int {
		$ad = $a->get_date_created();
		$bd = $b->get_date_created();
		return ( $bd ? $bd->getTimestamp() : 0 ) <=> ( $ad ? $ad->getTimestamp() : 0 );
	} );
	$orders = array_slice( $orders, 0, $per_page );

	return new WP_REST_Response( [
		'orders'   => array_map( 'dtb_customer_orders_format_order_summary', $orders ),
		'page'     => $page,
		'per_page' => $per_page,
		'has_more' => count( $orders ) === $per_page,
	], 200 );
}

function dtb_customer_orders_detail_route( WP_REST_Request $request ): WP_REST_Response {
	$order = wc_get_order( absint( $request['id'] ?? 0 ) );
	if ( ! $order ) {
		return new WP_REST_Response( [ 'code' => 'dtb_order_not_found', 'message' => 'Order not found.' ], 404 );
	}
	return new WP_REST_Response( dtb_customer_orders_format_order_detail( $order ), 200 );
}

function dtb_customer_orders_tracking_route( WP_REST_Request $request ): WP_REST_Response {
	$order = wc_get_order( absint( $request['id'] ?? 0 ) );
	if ( ! $order ) {
		return new WP_REST_Response( [ 'code' => 'dtb_order_not_found', 'message' => 'Order not found.' ], 404 );
	}

	$summary = dtb_customer_orders_format_order_summary( $order );
	$summary['line_items'] = dtb_customer_orders_format_line_items( $order );
	$summary['items']      = $summary['line_items'];
	$summary['tracking'] = [
		'number'   => (string) $order->get_meta( '_dtb_tracking_number', true ),
		'carrier'  => (string) $order->get_meta( '_dtb_tracking_carrier', true ),
		'url'      => (string) $order->get_meta( '_dtb_tracking_url', true ),
		'shipped'  => in_array( (string) $order->get_status(), [ 'shipped', 'completed' ], true ),
	];
	return new WP_REST_Response( $summary, 200 );
}

function dtb_customer_orders_health_route(): WP_REST_Response {
	return new WP_REST_Response( [
		'ok'          => true,
		'woocommerce' => function_exists( 'WC' ),
		'payments'    => function_exists( 'wc_get_payment_gateway_by_order' ) || class_exists( 'WC_Payment_Gateways' ),
		'queue'       => function_exists( 'as_enqueue_async_action' ),
		'veeqo'       => true,
		'quickbooks'  => true,
		'events_table'=> true,
	], 200 );
}

function dtb_customer_orders_get_line_item_product( $item ) {
	$product = method_exists( $item, 'get_product' ) ? $item->get_product() : false;
	if ( $product instanceof WC_Product ) {
		return $product;
	}

	$variation_id = method_exists( $item, 'get_variation_id' ) ? absint( $item->get_variation_id() ) : 0;
	$product_id   = method_exists( $item, 'get_product_id' ) ? absint( $item->get_product_id() ) : 0;
	$candidate_id = $variation_id ?: $product_id;

	if ( $candidate_id > 0 && function_exists( 'wc_get_product' ) ) {
		$product = wc_get_product( $candidate_id );
		return $product instanceof WC_Product ? $product : null;
	}

	return null;
}

function dtb_customer_orders_get_product_image_data( $product ): array {
	if ( ! $product instanceof WC_Product ) {
		return [
			'id'      => 0,
			'src'     => '',
			'thumb'   => '',
			'full'    => '',
			'srcset'  => '',
			'alt'     => '',
		];
	}

	$image_id = absint( $product->get_image_id() );
	if ( ! $image_id && method_exists( $product, 'get_gallery_image_ids' ) ) {
		$gallery_ids = array_filter( array_map( 'absint', (array) $product->get_gallery_image_ids() ) );
		$image_id    = $gallery_ids ? (int) reset( $gallery_ids ) : 0;
	}

	if ( ! $image_id && method_exists( $product, 'get_parent_id' ) ) {
		$parent_id = absint( $product->get_parent_id() );
		if ( $parent_id > 0 && function_exists( 'wc_get_product' ) ) {
			$parent = wc_get_product( $parent_id );
			if ( $parent instanceof WC_Product ) {
				$image_id = absint( $parent->get_image_id() );
				if ( ! $image_id && method_exists( $parent, 'get_gallery_image_ids' ) ) {
					$parent_gallery_ids = array_filter( array_map( 'absint', (array) $parent->get_gallery_image_ids() ) );
					$image_id           = $parent_gallery_ids ? (int) reset( $parent_gallery_ids ) : 0;
				}
			}
		}
	}

	if ( ! $image_id ) {
		return [
			'id'      => 0,
			'src'     => '',
			'thumb'   => '',
			'full'    => '',
			'srcset'  => '',
			'alt'     => '',
		];
	}

	$thumb  = (string) ( wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) ?: wp_get_attachment_image_url( $image_id, 'medium' ) ?: wp_get_attachment_url( $image_id ) );
	$full   = (string) ( wp_get_attachment_image_url( $image_id, 'full' ) ?: $thumb );
	$srcset = (string) wp_get_attachment_image_srcset( $image_id, 'woocommerce_thumbnail' );
	$alt    = (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true );

	return [
		'id'      => $image_id,
		'src'     => esc_url_raw( $thumb ),
		'thumb'   => esc_url_raw( $thumb ),
		'full'    => esc_url_raw( $full ),
		'srcset'  => $srcset,
		'alt'     => $alt ?: (string) $product->get_name(),
	];
}

function dtb_customer_orders_format_line_items( WC_Order $order ): array {
	$items = [];

	foreach ( $order->get_items( 'line_item' ) as $item ) {
		$product    = dtb_customer_orders_get_line_item_product( $item );
		$image_data = dtb_customer_orders_get_product_image_data( $product );

		$items[] = [
			'id'           => (int) $item->get_id(),
			'product_id'   => method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0,
			'variation_id' => method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0,
			'name'         => (string) $item->get_name(),
			'quantity'     => (int) $item->get_quantity(),
			'total'        => (string) $item->get_total(),
			'subtotal'     => (string) $item->get_subtotal(),
			'sku'          => $product ? (string) $product->get_sku() : '',
			'permalink'    => $product ? (string) $product->get_permalink() : '',
			'image'        => $image_data['src'],
			'image_url'    => $image_data['src'],
			'image_id'     => $image_data['id'],
			'image_thumb'  => $image_data['thumb'],
			'image_full'   => $image_data['full'],
			'image_srcset' => $image_data['srcset'],
			'image_alt'    => $image_data['alt'] ?: (string) $item->get_name(),
		];
	}

	return $items;
}

function dtb_customer_orders_format_order_summary( WC_Order $order ): array {
	$date_created = $order->get_date_created();
	$line_items   = $order->get_items( 'line_item' );
	$preview_items = array_slice( dtb_customer_orders_format_line_items( $order ), 0, 3 );

	return [
		'id'           => (int) $order->get_id(),
		'number'       => (string) $order->get_order_number(),
		'order_key'    => (string) $order->get_order_key(),
		'status'       => (string) $order->get_status(),
		'order_type'   => (string) ( $order->get_meta( '_dtb_order_type', true ) ?: 'product' ),
		'date_created' => $date_created ? $date_created->date( 'c' ) : '',
		'total'        => (string) $order->get_total(),
		'currency'     => (string) $order->get_currency(),
		'items_count'  => array_sum( array_map( static fn( $item ) => max( 1, absint( $item->get_quantity() ) ), $line_items ) ),
		'line_items'   => $preview_items,
		'items'        => $preview_items,
		'payment_method' => (string) $order->get_payment_method(),
		'payment_required' => in_array( (string) $order->get_status(), [ 'pending', 'failed', 'on-hold' ], true ),
		'payment_url'  => method_exists( $order, 'get_checkout_payment_url' ) ? (string) $order->get_checkout_payment_url() : '',
	];
}

function dtb_customer_orders_format_order_detail( WC_Order $order ): array {
	$summary = dtb_customer_orders_format_order_summary( $order );
	$summary['line_items'] = dtb_customer_orders_format_line_items( $order );
	$summary['items']      = $summary['line_items'];

	return $summary;
}
