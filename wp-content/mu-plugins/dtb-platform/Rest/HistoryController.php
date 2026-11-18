<?php
/**
 * Unified customer history route.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_history_register_routes', 20 );

function dtb_history_register_routes(): void {
	register_rest_route( 'dtb/v1', '/account/history', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'dtb_history_get',
		'permission_callback' => 'dtb_history_permission',
		'args'                => [
			'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 50 ],
		],
	] );
}

function dtb_history_permission( WP_REST_Request $request ) {
	if ( function_exists( 'dtb_jwt_permission' ) ) {
		return dtb_jwt_permission( $request );
	}
	return is_user_logged_in() ? true : new WP_Error( 'dtb_history_forbidden', 'Sign in required.', [ 'status' => 401 ] );
}

function dtb_history_get_user() {
	if ( class_exists( 'DTB_CurrentUserResolver' ) ) {
		$user = DTB_CurrentUserResolver::resolve_user();
		if ( $user instanceof WP_User ) {
			return $user;
		}
	}

	if ( function_exists( 'dtb_jwt_get_user_id' ) ) {
		$user_id = absint( dtb_jwt_get_user_id() );
		$user    = $user_id ? get_userdata( $user_id ) : null;
		if ( $user instanceof WP_User ) {
			return $user;
		}
	}

	return is_user_logged_in() ? wp_get_current_user() : null;
}

/** Accurate customer-scoped counters; never derive totals from recent-history pages. */
function dtb_history_active_summary( WP_User $user ): array {
	$orders = [];
	if ( function_exists( 'wc_get_orders' ) ) {
		$base = [ 'status' => [ 'pending', 'on-hold', 'processing', 'shipped' ], 'limit' => -1, 'return' => 'ids' ];
		foreach ( wc_get_orders( array_merge( $base, [ 'customer_id' => (int) $user->ID ] ) ) as $id ) {
			$orders[ (int) $id ] = true;
		}
		if ( is_email( $user->user_email ) ) {
			foreach ( wc_get_orders( array_merge( $base, [ 'billing_email' => $user->user_email ] ) ) as $id ) {
				$orders[ (int) $id ] = true;
			}
		}
	}
	$active_orders = 0;
	foreach ( array_keys( $orders ) as $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order && 'repair_service' !== (string) $order->get_meta( '_dtb_order_type', true ) ) ++$active_orders;
	}
	$active_repairs = 0;
	if ( post_type_exists( 'dtb_repair_request' ) ) {
		$owner = [ 'relation' => 'OR', [ 'key' => '_repair_customer_user_id', 'value' => (string) $user->ID ] ];
		if ( is_email( $user->user_email ) ) $owner[] = [ 'key' => '_repair_customer_email', 'value' => $user->user_email ];
		$query = new WP_Query( [
			'post_type' => 'dtb_repair_request', 'post_status' => 'publish',
			'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => false,
			'meta_query' => [ 'relation' => 'AND', $owner, [ 'relation' => 'OR',
				[ 'key' => '_repair_status', 'compare' => 'NOT EXISTS' ],
				[ 'key' => '_repair_status', 'value' => [ 'closed', 'cancelled', 'quote_declined' ], 'compare' => 'NOT IN' ],
			] ],
		] );
		$active_repairs = (int) $query->found_posts;
	}
	$active_jobs = 0;
	if ( post_type_exists( 'dtb_customer_job' ) ) {
		$query = new WP_Query( [ 'post_type' => 'dtb_customer_job', 'post_status' => 'private',
			'author' => (int) $user->ID, 'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => false ] );
		$active_jobs = (int) $query->found_posts;
	}
	return [ 'active_orders' => $active_orders, 'active_repairs' => $active_repairs, 'active_jobs' => $active_jobs ];
}

function dtb_history_get( WP_REST_Request $request ): WP_REST_Response {
	$user = dtb_history_get_user();
	if ( ! $user instanceof WP_User ) {
		return new WP_REST_Response( [ 'code' => 'dtb_history_forbidden', 'message' => 'Sign in required.' ], 401 );
	}

	$per_page = min( 50, max( 1, absint( $request->get_param( 'per_page' ) ?: 50 ) ) );

	$support = dtb_history_support( $user, $per_page );
	$response = new WP_REST_Response( [
		'orders'          => dtb_history_orders( $user, $per_page ),
		'summary'         => dtb_history_active_summary( $user ),
		'repairs'         => dtb_history_repairs( $user, $per_page ),
		'returns'         => dtb_history_returns( $user, $per_page ),
		'tickets'         => $support,
		'support_tickets' => $support,
		'page'            => 1,
		'per_page'        => $per_page,
		'has_more'        => false,
	], 200 );
	$response->header( 'Cache-Control', 'private, no-store' );
	return $response;
}

function dtb_history_orders( WP_User $user, int $per_page ): array {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return [];
	}

	$email        = sanitize_email( (string) $user->user_email );
	$orders_by_id = [];
	$query_base   = [ 'limit' => $per_page, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects' ];

	foreach ( wc_get_orders( array_merge( $query_base, [ 'customer_id' => absint( $user->ID ) ] ) ) as $order ) {
		if ( is_object( $order ) && is_callable( [ $order, 'get_id' ] ) ) {
			$orders_by_id[ $order->get_id() ] = $order;
		}
	}

	if ( '' !== $email ) {
		foreach ( wc_get_orders( array_merge( $query_base, [ 'billing_email' => $email ] ) ) as $order ) {
			if ( is_object( $order ) && is_callable( [ $order, 'get_id' ] ) ) {
				$orders_by_id[ $order->get_id() ] = $order;
			}
		}
	}

	$orders = array_values( $orders_by_id );
	usort( $orders, static function ( $a, $b ): int {
		$ad = is_callable( [ $a, 'get_date_created' ] ) ? $a->get_date_created() : null;
		$bd = is_callable( [ $b, 'get_date_created' ] ) ? $b->get_date_created() : null;
		return ( $bd ? $bd->getTimestamp() : 0 ) <=> ( $ad ? $ad->getTimestamp() : 0 );
	} );

	return array_values( array_filter( array_map( 'dtb_history_format_order', array_slice( $orders, 0, $per_page ) ) ) );
}

function dtb_history_format_order( $order ): ?array {
	if ( ! is_object( $order ) || ! is_callable( [ $order, 'get_id' ] ) ) {
		return null;
	}
	if ( function_exists( 'dtb_customer_orders_format_order_summary' ) ) {
		$summary = dtb_customer_orders_format_order_summary( $order );
		return is_array( $summary ) ? $summary : null;
	}
	$date_created = is_callable( [ $order, 'get_date_created' ] ) && $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : '';
	return [
		'id'           => absint( $order->get_id() ),
		'number'       => is_callable( [ $order, 'get_order_number' ] ) ? (string) $order->get_order_number() : (string) $order->get_id(),
		'status'       => is_callable( [ $order, 'get_status' ] ) ? (string) $order->get_status() : 'pending',
		'order_type'   => (string) $order->get_meta( '_dtb_order_type', true ) ?: 'product_order',
		'date_created' => $date_created,
		'total'        => is_callable( [ $order, 'get_total' ] ) ? (float) $order->get_total() : 0,
		'currency'     => is_callable( [ $order, 'get_currency' ] ) ? (string) $order->get_currency() : 'USD',
		'items_count'  => is_callable( [ $order, 'get_item_count' ] ) ? (int) $order->get_item_count() : 0,
		'order_key'    => is_callable( [ $order, 'get_order_key' ] ) ? (string) $order->get_order_key() : '',
	];
}

function dtb_history_repairs( WP_User $user, int $per_page ): array {
	if ( ! post_type_exists( 'dtb_repair_request' ) ) {
		return [];
	}

	$email      = sanitize_email( (string) $user->user_email );
	$meta_query = [ 'relation' => 'OR' ];
	$meta_query[] = [ 'key' => '_repair_customer_user_id', 'value' => (string) absint( $user->ID ), 'compare' => '=' ];
	if ( '' !== $email ) {
		$meta_query[] = [ 'key' => '_repair_customer_email', 'value' => $email, 'compare' => '=' ];
	}

	$query = new WP_Query( [
		'post_type'      => 'dtb_repair_request',
		'post_status'    => 'publish',
		'posts_per_page' => $per_page,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
		'meta_query'     => $meta_query,
	] );

	return array_values( array_filter( array_map( static function ( $post ) {
		$repair_id = absint( is_object( $post ) ? $post->ID : $post );
		if ( function_exists( 'dtb_repair_format_customer_summary' ) ) {
			return dtb_repair_format_customer_summary( $repair_id );
		}
		return [
			'id'           => $repair_id,
			'repair_id'    => $repair_id,
			'number'       => (string) $repair_id,
			'status'       => sanitize_key( (string) get_post_meta( $repair_id, '_repair_status', true ) ) ?: 'submitted',
			'label'        => 'Submitted',
			'submitted_at' => get_post_time( 'c', true, $repair_id ) ?: '',
			'tool_label'   => sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_model', true ) ) ?: 'Repair request',
		];
	}, $query->posts ) ) );
}

function dtb_history_returns( WP_User $user, int $per_page ): array {
	if ( ! post_type_exists( 'dtb_return' ) ) {
		return [];
	}
	$email = sanitize_email( (string) $user->user_email );
	if ( '' === $email ) {
		return [];
	}
	$query = new WP_Query( [
		'post_type'      => 'dtb_return',
		'post_status'    => 'publish',
		'posts_per_page' => $per_page,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
		'meta_query'     => [ [ 'key' => '_dtb_return_customer_email', 'value' => $email, 'compare' => '=' ] ],
	] );
	return array_values( array_map( 'dtb_history_format_return', $query->posts ) );
}

function dtb_history_format_return( WP_Post $post ): array {
	if ( class_exists( 'DTB_Return_Entity' ) ) {
		$entity = DTB_Return_Entity::from_post( $post );
		$item   = $entity->to_array();
		if ( function_exists( 'dtb_returns_generate_public_status_token' ) ) {
			$item['public_token'] = dtb_returns_generate_public_status_token( $entity->id, $entity->customer_email );
		}
		return $item;
	}
	return [
		'id'           => absint( $post->ID ),
		'order_number' => sanitize_text_field( (string) get_post_meta( $post->ID, '_dtb_return_order_number', true ) ),
		'status'       => sanitize_key( (string) get_post_meta( $post->ID, '_dtb_return_status', true ) ) ?: 'pending_review',
		'created_at'   => get_post_time( 'c', true, $post ) ?: '',
		'reason'       => sanitize_text_field( (string) get_post_meta( $post->ID, '_dtb_return_reason', true ) ),
	];
}

function dtb_history_support( WP_User $user, int $per_page ): array {
	if ( ! function_exists( 'dtb_support_tickets_table' ) ) {
		return [];
	}
	global $wpdb;
	$table = dtb_support_tickets_table();
	if ( ! $table || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return [];
	}
	$email = sanitize_email( (string) $user->user_email );
	if ( '' === $email ) {
		return [];
	}
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$tickets = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, ticket_number, status, ticket_type, priority, subject, order_id, created_at, updated_at FROM {$table} WHERE customer_email = %s AND status <> 'spam' ORDER BY updated_at DESC, id DESC LIMIT %d",
		$email,
		$per_page
	) );
	// phpcs:enable
	return array_map( static function ( object $ticket ) use ( $email ): array {
		$status = (string) $ticket->status;
		return [
			'id'            => (int) $ticket->id,
			'ticket_number' => (string) $ticket->ticket_number,
			'status'        => $status,
			'status_label'  => function_exists( 'dtb_support_status_label' ) ? dtb_support_status_label( $status ) : ucwords( str_replace( '_', ' ', $status ) ),
			'ticket_type'   => (string) $ticket->ticket_type,
			'priority'      => (string) $ticket->priority,
			'subject'       => (string) $ticket->subject,
			'order_id'      => ! empty( $ticket->order_id ) ? (int) $ticket->order_id : null,
			'created_at'    => (string) $ticket->created_at,
			'updated_at'    => (string) $ticket->updated_at,
			'public_token'  => function_exists( 'dtb_support_generate_public_reply_token' ) ? dtb_support_generate_public_reply_token( (int) $ticket->id, $email ) : '',
		];
	}, (array) $tickets );
}
