<?php
/**
 * DTB Returns — ReturnRepository
 *
 * CPT-backed persistence layer for returns.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_returns_register_post_type(): void {
	register_post_type( 'dtb_return', [
		'labels' => [
			'name'          => __( 'Returns', 'drywall-toolbox' ),
			'singular_name' => __( 'Return', 'drywall-toolbox' ),
		],
		'public'       => false,
		'show_ui'      => false,
		'show_in_rest' => false,
		'supports'     => [ 'title', 'custom-fields' ],
	] );
}

function dtb_returns_count_by_status( ?string $status = null ) {
	global $wpdb;

	if ( $status !== null ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(p.ID) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_dtb_return_status'
			 WHERE p.post_type = 'dtb_return' AND p.post_status = 'publish' AND pm.meta_value = %s",
			$status
		) );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$rows = $wpdb->get_results(
		"SELECT pm.meta_value AS status, COUNT(p.ID) AS cnt
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_dtb_return_status'
		 WHERE p.post_type = 'dtb_return' AND p.post_status = 'publish'
		 GROUP BY pm.meta_value",
		ARRAY_A
	);

	$counts = [];
	foreach ( (array) $rows as $row ) {
		$counts[ $row['status'] ] = (int) $row['cnt'];
	}
	return $counts;
}

function dtb_returns_query( array $args = [] ): array {
	$per_page = (int) ( $args['per_page'] ?? 20 );
	$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

	$query_args = [
		'post_type'      => 'dtb_return',
		'post_status'    => 'publish',
		'posts_per_page' => $per_page,
		'paged'          => $page,
		'orderby'        => 'date',
		'order'          => 'DESC',
	];

	if ( ! empty( $args['status'] ) && $args['status'] !== 'all' ) {
		$query_args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			[ 'key' => '_dtb_return_status', 'value' => sanitize_key( $args['status'] ) ],
		];
	}

	if ( ! empty( $args['search'] ) ) {
		$query_args['s'] = sanitize_text_field( $args['search'] );
	}

	$q     = new WP_Query( $query_args );
	$items = [];
	foreach ( $q->posts as $post ) {
		$items[] = DTB_Return_Entity::from_post( $post );
	}

	return [
		'items' => $items,
		'total' => (int) $q->found_posts,
		'pages' => (int) $q->max_num_pages,
	];
}

function dtb_returns_get( int $id ): ?DTB_Return_Entity {
	$post = get_post( $id );
	if ( ! $post || $post->post_type !== 'dtb_return' ) {
		return null;
	}
	return DTB_Return_Entity::from_post( $post );
}

/**
 * Persist (create or update) a return.
 *
 * Structured return lines are stored as JSON post meta so WooCommerce line-item
 * identity remains explicit without creating a second product/order authority.
 */
function dtb_returns_save( array $data ) {
	$id = (int) ( $data['id'] ?? 0 );

	$post_data = [
		'post_type'   => 'dtb_return',
		'post_status' => 'publish',
		'post_title'  => 'Return #' . ( $id ?: 'new' ),
	];

	if ( $id ) {
		$post_data['ID'] = $id;
		$result = wp_update_post( $post_data, true );
	} else {
		$result = wp_insert_post( $post_data, true );
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$id = (int) $result;

	$meta_map = [
		'_dtb_return_order_id'        => 'order_id',
		'_dtb_return_order_number'    => 'order_number',
		'_dtb_return_customer_name'   => 'customer_name',
		'_dtb_return_customer_email'  => 'customer_email',
		'_dtb_return_request_type'    => 'request_type',
		'_dtb_return_reason'          => 'reason',
		'_dtb_return_resolution'      => 'resolution',
		'_dtb_return_status'          => 'status',
		'_dtb_return_idempotency_key' => 'idempotency_key',
	];

	foreach ( $meta_map as $meta_key => $field ) {
		if ( array_key_exists( $field, $data ) ) {
			update_post_meta( $id, $meta_key, sanitize_text_field( (string) $data[ $field ] ) );
		}
	}

	if ( array_key_exists( 'notes', $data ) ) {
		update_post_meta( $id, '_dtb_return_notes', sanitize_textarea_field( (string) $data['notes'] ) );
	}

	if ( array_key_exists( 'items', $data ) ) {
		$items = [];
		foreach ( (array) $data['items'] as $item ) {
			$items[] = [
				'item_id'      => (int) ( $item['item_id'] ?? 0 ),
				'product_id'   => (int) ( $item['product_id'] ?? 0 ),
				'variation_id' => (int) ( $item['variation_id'] ?? 0 ),
				'name'         => sanitize_text_field( (string) ( $item['name'] ?? '' ) ),
				'sku'          => sanitize_text_field( (string) ( $item['sku'] ?? '' ) ),
				'quantity'     => max( 1, (int) ( $item['quantity'] ?? 1 ) ),
			];
		}
		update_post_meta( $id, '_dtb_return_items', wp_json_encode( $items ) );
	}

	wp_update_post( [ 'ID' => $id, 'post_title' => 'Return #' . $id ] );

	return $id;
}
