<?php
/**
 * Rest — RepairCustomerListController: GET /wp-json/dtb/v1/repairs
 *
 * Provides authenticated customers with a customer-safe list of repair requests
 * linked to their account by user ID and/or email address.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_repair_register_customer_list_route', 20 );

function dtb_repair_register_customer_list_route(): void {
	register_rest_route(
		'dtb/v1',
		'/repairs',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dtb_repair_rest_customer_list',
			'permission_callback' => 'dtb_repair_customer_list_permission',
			'args'                => [
				'page'     => [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 1, 'minimum' => 1 ],
				'per_page' => [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 20, 'minimum' => 1, 'maximum' => 50 ],
			],
		]
	);
}

function dtb_repair_customer_current_user_id(): int {
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

function dtb_repair_customer_current_user(): ?WP_User {
	$user_id = dtb_repair_customer_current_user_id();
	if ( $user_id <= 0 ) {
		return null;
	}

	$user = get_userdata( $user_id );
	return $user instanceof WP_User ? $user : null;
}

function dtb_repair_customer_list_permission( WP_REST_Request $request ): bool|WP_Error {
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
		: new WP_Error( 'dtb_repairs_auth_required', 'Authentication required.', [ 'status' => 401 ] );
}

function dtb_repair_rest_customer_list( WP_REST_Request $request ): WP_REST_Response {
	$user_id  = dtb_repair_customer_current_user_id();
	$user     = dtb_repair_customer_current_user();
	$email    = $user ? sanitize_email( (string) $user->user_email ) : '';
	$page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
	$per_page = min( 50, max( 1, absint( $request->get_param( 'per_page' ) ?: 20 ) ) );

	if ( $user_id <= 0 && '' === $email ) {
		return new WP_REST_Response(
			[ 'repairs' => [], 'page' => $page, 'per_page' => $per_page, 'has_more' => false ],
			200
		);
	}

	$meta_query = [ 'relation' => 'OR' ];
	if ( $user_id > 0 ) {
		$meta_query[] = [
			'key'     => '_repair_customer_user_id',
			'value'   => (string) $user_id,
			'compare' => '=',
		];
	}

	if ( '' !== $email ) {
		$meta_query[] = [
			'key'     => '_repair_customer_email',
			'value'   => $email,
			'compare' => '=',
		];
	}

	$query = new WP_Query(
		[
			'post_type'      => 'dtb_repair_request',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => false,
			'meta_query'     => $meta_query,
		]
	);

	$repairs = array_map(
		'dtb_repair_format_customer_summary',
		array_map( 'absint', wp_list_pluck( $query->posts, 'ID' ) )
	);

	return new WP_REST_Response(
		[
			'repairs'   => array_values( array_filter( $repairs ) ),
			'page'      => $page,
			'per_page'  => $per_page,
			'has_more'  => ( $page * $per_page ) < (int) $query->found_posts,
			'total'     => (int) $query->found_posts,
		],
		200
	);
}

function dtb_repair_format_customer_summary( int $repair_id ): ?array {
	if ( $repair_id <= 0 || 'dtb_repair_request' !== get_post_type( $repair_id ) ) {
		return null;
	}

	$status       = function_exists( 'dtb_get_repair_status' ) ? dtb_get_repair_status( $repair_id ) : (string) get_post_meta( $repair_id, '_repair_status', true );
	$status       = $status ?: 'submitted';
	$label        = function_exists( 'dtb_get_repair_status_label' ) ? dtb_get_repair_status_label( $status ) : ucwords( str_replace( '_', ' ', $status ) );
	$submitted_at = (string) get_post_meta( $repair_id, '_repair_submitted_at', true );
	if ( '' === $submitted_at ) {
		$submitted_at = get_post_time( 'c', true, $repair_id ) ?: '';
	}

	$last_updated = $submitted_at;
	if ( function_exists( 'dtb_repair_get_last_event' ) ) {
		$last_event = dtb_repair_get_last_event( $repair_id );
		if ( $last_event && ! empty( $last_event->created_at ) ) {
			$last_updated = (string) $last_event->created_at;
		}
	}

	$public_token = function_exists( 'dtb_repair_ensure_public_token' )
		? dtb_repair_ensure_public_token( $repair_id )
		: sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_public_token', true ) );

	$tool_brand = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_tool_brand', true ) );
	$tool_model = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_model', true ) );
	$tool_type  = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_tool_category', true ) );

	return [
		'id'              => $repair_id,
		'repair_id'       => $repair_id,
		'number'          => (string) $repair_id,
		'status'          => $status,
		'label'           => $label,
		'submitted_at'    => $submitted_at,
		'last_updated_at' => $last_updated,
		'customer_name'   => sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_customer_name', true ) ),
		'customer_email'  => sanitize_email( (string) get_post_meta( $repair_id, '_repair_customer_email', true ) ),
		'tool_brand'      => $tool_brand,
		'tool_model'      => $tool_model,
		'tool_type'       => $tool_type,
		'tool_label'      => trim( implode( ' ', array_filter( [ $tool_brand, $tool_model ?: $tool_type ] ) ) ) ?: 'Repair request',
		'service_tier'    => sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_service_tier', true ) ),
		'public_token'    => $public_token,
		'tracking_url'    => add_query_arg(
			[ 'token' => $public_token ],
			home_url( '/repairs/status/' . $repair_id )
		),
	];
}
