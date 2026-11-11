<?php
/**
 * DTB Order List Controller — REST handler for order list endpoint.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_order_rest_list_orders( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$user_id  = dtb_order_rest_resolve_request_user_id( $request );
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}
	$user_id  = (int) $user_id;
	$page     = (int) $request->get_param( 'page' );
	$per_page = (int) $request->get_param( 'per_page' );
	$args = [
		'customer_id' => $user_id,
		'limit'       => $per_page,
		'paged'       => $page,
		'orderby'     => 'date',
		'order'       => 'DESC',
		'return'      => 'objects',
		'paginate'    => true,
	];

	$query_result = wc_get_orders( $args );
	$orders       = [];
	$total        = 0;
	$total_pages  = 0;

	if ( is_object( $query_result ) && isset( $query_result->orders ) ) {
		$orders      = is_array( $query_result->orders ) ? $query_result->orders : [];
		$total       = (int) ( $query_result->total ?? 0 );
		$total_pages = (int) ( $query_result->max_num_pages ?? 0 );
	} elseif ( is_array( $query_result ) ) {
		// Defensive fallback for environments where wc_get_orders does not return
		// the paginated result object.
		$orders = $query_result;
		$total  = count( $orders );
	}

	$results = [];

	foreach ( $orders as $order ) {
		$wc_order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! ( $wc_order instanceof WC_Order ) ) {
			continue;
		}

		if ( function_exists( 'dtb_payment_is_incomplete_checkout_order' ) && dtb_payment_is_incomplete_checkout_order( $wc_order ) ) {
			--$total;
			continue;
		}

		$results[] = dtb_order_format_summary( $wc_order );
	}

	$total = max( 0, $total );
	if ( $total_pages <= 0 || count( $results ) < count( $orders ) ) {
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;
	}
	if ( $total_pages <= 0 ) {
		$total_pages = 1;
	}

	$response = new WP_REST_Response( $results, 200 );
	$response->header( 'X-WP-Total',      (string) $total );
	$response->header( 'X-WP-TotalPages', (string) $total_pages );
	return $response;
}
