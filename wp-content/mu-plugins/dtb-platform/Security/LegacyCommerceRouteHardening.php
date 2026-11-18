<?php
/**
 * Harden legacy commerce proxy routes without changing their public read shape.
 *
 * - Replaces the public DTB config route with a capability-only response.
 * - Retires raw browser order creation through drywall/v1.
 * - Binds legacy order/customer reads to the authenticated customer.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_register_hardened_legacy_commerce_routes', 100 );

/**
 * Replace unsafe legacy route registrations after the compatibility proxy has
 * registered its original routes at priority 10.
 */
function dtb_register_hardened_legacy_commerce_routes(): void {
	if ( ! function_exists( 'unregister_rest_route' ) ) {
		return;
	}

	unregister_rest_route( 'dtb/v1', '/config' );
	register_rest_route(
		'dtb/v1',
		'/config',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dtb_public_runtime_config_route',
			'permission_callback' => '__return_true',
		]
	);

	// Remove both legacy GET and POST registrations. Re-register GET only.
	unregister_rest_route( 'drywall/v1', '/orders' );
	register_rest_route(
		'drywall/v1',
		'/orders',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dtb_legacy_customer_orders_route',
			'permission_callback' => 'dtb_legacy_customer_route_permission',
		]
	);

	unregister_rest_route( 'drywall/v1', '/orders/(?P<id>\d+)' );
	register_rest_route(
		'drywall/v1',
		'/orders/(?P<id>\d+)',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dtb_legacy_customer_order_route',
			'permission_callback' => 'dtb_legacy_customer_route_permission',
			'args'                => [
				'id' => [
					'required'          => true,
					'type'              => 'integer',
					'minimum'           => 1,
					'sanitize_callback' => 'absint',
				],
			],
		]
	);

	unregister_rest_route( 'drywall/v1', '/customers/(?P<id>\d+)' );
	register_rest_route(
		'drywall/v1',
		'/customers/(?P<id>\d+)',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dtb_legacy_customer_profile_route',
			'permission_callback' => 'dtb_legacy_customer_route_permission',
			'args'                => [
				'id' => [
					'required'          => true,
					'type'              => 'integer',
					'minimum'           => 1,
					'sanitize_callback' => 'absint',
				],
			],
		]
	);
}

/**
 * Public runtime configuration containing no credentials or privileged data.
 */
function dtb_public_runtime_config_route(): WP_REST_Response {
	$response = new WP_REST_Response(
		[
			'site_url'               => home_url( '/' ),
			'rest_url'               => rest_url(),
			'dtb_api_base'           => rest_url( 'dtb/v1' ),
			'store_api_base'         => rest_url( 'wc/store/v1' ),
			'authentication'         => 'http_only_cookie_or_bearer',
			'wc_credentials_exposed' => false,
		],
		200
	);
	$response->header( 'Cache-Control', 'private, no-store' );
	return $response;
}

/**
 * Mark a legacy response as deprecated using an HTTP-date value.
 */
function dtb_legacy_mark_deprecated( WP_REST_Response $response ): WP_REST_Response {
	$response->header( 'Deprecation', 'Sun, 12 Jul 2026 00:00:00 GMT' );
	return $response;
}

/**
 * Require either a normal WordPress commerce administrator or a valid DTB JWT.
 *
 * @return true|WP_Error
 */
function dtb_legacy_customer_route_permission( WP_REST_Request $request ) {
	if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) {
		return true;
	}

	if ( ! function_exists( 'dtb_jwt_permission' ) ) {
		return new WP_Error( 'authentication_unavailable', 'Authentication is unavailable.', [ 'status' => 503 ] );
	}

	return dtb_jwt_permission( $request );
}

/**
 * Resolve the authenticated WordPress/WooCommerce customer ID.
 */
function dtb_legacy_authenticated_customer_id(): int {
	if ( function_exists( 'dtb_jwt_get_user_id' ) ) {
		$user_id = (int) dtb_jwt_get_user_id();
		if ( $user_id > 0 ) {
			return $user_id;
		}
	}

	return (int) get_current_user_id();
}

/**
 * Return whether the current request is an authenticated commerce administrator.
 */
function dtb_legacy_request_is_commerce_admin(): bool {
	return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
}

/**
 * Customer-bound legacy order list.
 */
function dtb_legacy_customer_orders_route( WP_REST_Request $request ): WP_REST_Response {
	if ( ! function_exists( 'dtb_wc_get' ) ) {
		return new WP_REST_Response( dtb_error_envelope( 'proxy_unavailable', 'Order service is unavailable.', 503 ), 503 );
	}

	$params = [];
	foreach ( [ 'page', 'per_page', 'status', 'orderby', 'order' ] as $key ) {
		$value = $request->get_param( $key );
		if ( null !== $value && '' !== $value ) {
			$params[ $key ] = sanitize_text_field( (string) $value );
		}
	}

	if ( dtb_legacy_request_is_commerce_admin() ) {
		$requested_customer = absint( $request->get_param( 'customer' ) );
		if ( $requested_customer > 0 ) {
			$params['customer'] = $requested_customer;
		}
	} else {
		$customer_id = dtb_legacy_authenticated_customer_id();
		if ( $customer_id <= 0 ) {
			return new WP_REST_Response( dtb_error_envelope( 'missing_customer', 'Authenticated customer is required.', 401 ), 401 );
		}
		$params['customer'] = $customer_id;
	}

	return dtb_legacy_mark_deprecated( dtb_wc_get( 'wc/v3/orders', $params ) );
}

/**
 * Customer-bound legacy order detail.
 */
function dtb_legacy_customer_order_route( WP_REST_Request $request ): WP_REST_Response {
	$order_id = absint( $request->get_param( 'id' ) );
	$order    = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;

	if ( ! $order ) {
		return new WP_REST_Response( dtb_error_envelope( 'order_not_found', 'Order not found.', 404 ), 404 );
	}

	if ( ! dtb_legacy_request_is_commerce_admin() ) {
		$customer_id = dtb_legacy_authenticated_customer_id();
		$owns_order  = $customer_id > 0 && (int) $order->get_customer_id() === $customer_id;

		// Permit a previously-guest order only when its billing email exactly
		// matches the authenticated account email.
		if ( ! $owns_order && 0 === (int) $order->get_customer_id() && $customer_id > 0 ) {
			$user        = get_userdata( $customer_id );
			$user_email  = $user instanceof WP_User ? strtolower( trim( (string) $user->user_email ) ) : '';
			$order_email = strtolower( trim( (string) $order->get_billing_email() ) );
			$owns_order  = '' !== $user_email && hash_equals( $user_email, $order_email );
		}

		if ( ! $owns_order ) {
			return new WP_REST_Response( dtb_error_envelope( 'order_not_found', 'Order not found.', 404 ), 404 );
		}
	}

	if ( ! function_exists( 'dtb_wc_get' ) ) {
		return new WP_REST_Response( dtb_error_envelope( 'proxy_unavailable', 'Order service is unavailable.', 503 ), 503 );
	}

	return dtb_legacy_mark_deprecated( dtb_wc_get( 'wc/v3/orders/' . $order_id ) );
}

/**
 * Customer-bound legacy profile read.
 */
function dtb_legacy_customer_profile_route( WP_REST_Request $request ): WP_REST_Response {
	$requested_id = absint( $request->get_param( 'id' ) );

	if ( ! dtb_legacy_request_is_commerce_admin() ) {
		$customer_id = dtb_legacy_authenticated_customer_id();
		if ( $customer_id <= 0 || $requested_id !== $customer_id ) {
			return new WP_REST_Response( dtb_error_envelope( 'customer_not_found', 'Customer not found.', 404 ), 404 );
		}
	}

	if ( ! function_exists( 'dtb_wc_get' ) ) {
		return new WP_REST_Response( dtb_error_envelope( 'proxy_unavailable', 'Customer service is unavailable.', 503 ), 503 );
	}

	return dtb_legacy_mark_deprecated( dtb_wc_get( 'wc/v3/customers/' . $requested_id ) );
}
