<?php
/**
 * DTB Order Access Validator — REST permission callbacks.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_order_rest_resolve_request_user_id( WP_REST_Request $request, bool $allow_guest = false ): int|WP_Error {
	if ( is_user_logged_in() ) {
		$user_id = get_current_user_id();
		return $user_id > 0 ? $user_id : ( $allow_guest ? 0 : new WP_Error( 'dtb_unauthorized', 'Authentication required.', [ 'status' => 401 ] ) );
	}

	if ( ! function_exists( 'dtb_jwt_permission' ) || ! function_exists( 'dtb_jwt_get_user_id' ) ) {
		return $allow_guest
			? 0
			: new WP_Error( 'dtb_unauthorized', 'Authentication required.', [ 'status' => 401 ] );
	}

	$jwt_check = dtb_jwt_permission( $request );
	if ( is_wp_error( $jwt_check ) ) {
		return $allow_guest
			? 0
			: $jwt_check;
	}

	$user_id = (int) dtb_jwt_get_user_id();
	if ( $user_id <= 0 ) {
		return $allow_guest
			? 0
			: new WP_Error( 'dtb_unauthorized', 'Authentication required.', [ 'status' => 401 ] );
	}

	$user = get_user_by( 'id', $user_id );
	if ( ! ( $user instanceof WP_User ) ) {
		return $allow_guest
			? 0
			: new WP_Error( 'dtb_unauthorized', 'Authentication required.', [ 'status' => 401 ] );
	}

	// Set the current user so downstream capability checks and get_current_user_id()
	// reflect the authenticated JWT principal for this request lifecycle.
	wp_set_current_user( $user_id, $user->user_login );
	return $user_id;
}

function dtb_order_rest_require_auth( WP_REST_Request $request ): bool|WP_Error {
	$user_id = dtb_order_rest_resolve_request_user_id( $request );
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	if ( $user_id <= 0 ) {
		return new WP_Error( 'dtb_unauthorized', 'Authentication required.', [ 'status' => 401 ] );
	}

	return true;
}

function dtb_order_rest_check_order_access( WP_REST_Request $request ): bool|WP_Error {
	$order_id  = (int) $request->get_param( 'id' );
	$order_key = sanitize_text_field( (string) $request->get_param( 'order_key' ) );
	$user_id   = dtb_order_rest_resolve_request_user_id( $request, true );
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}
	$user_id = (int) $user_id;

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return new WP_Error( 'dtb_not_found', 'Order not found.', [ 'status' => 404 ] );
	}

	if ( current_user_can( 'manage_woocommerce' ) ) {
		return true;
	}

	if ( $user_id && (int) $order->get_customer_id() === $user_id ) {
		return true;
	}

	if ( $order_key && hash_equals( $order->get_order_key(), $order_key ) ) {
		return true;
	}

	return new WP_Error( 'dtb_forbidden', 'You do not have access to this order.', [ 'status' => 403 ] );
}
