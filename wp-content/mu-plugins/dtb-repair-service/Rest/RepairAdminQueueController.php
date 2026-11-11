<?php
/**
 * DTB Repair Service — RepairAdminQueueController
 *
 * REST endpoint: GET /dtb/v1/admin/repairs
 *
 * Returns an HTML fragment (JSON-wrapped) consumed by liveNavigate
 * to refresh the Repairs live region without a full page reload.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_repairs_admin_register_routes' );

function dtb_repairs_admin_register_routes(): void {
	register_rest_route( 'dtb/v1', '/admin/repairs', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'dtb_repairs_admin_queue_handler',
		'permission_callback' => fn() => current_user_can( 'dtb_manage_repairs' ),
		'args'                => [
			'status' => [ 'sanitize_callback' => 'sanitize_key' ],
			's'      => [ 'sanitize_callback' => 'sanitize_text_field' ],
			'paged'  => [ 'sanitize_callback' => 'absint' ],
		],
	] );
}

function dtb_repairs_admin_queue_handler( WP_REST_Request $request ): WP_REST_Response {
	$status = sanitize_key( $request->get_param( 'status' ) ?? '' );
	$tab    = sanitize_key( $request->get_param( 'tab' ) ?? '' );
	if ( '' === $status && '' !== $tab ) {
		$status = $tab;
	}
	$status = function_exists( 'dtb_repairs_normalize_status_filter' )
		? dtb_repairs_normalize_status_filter( $status )
		: $status;
	$search = sanitize_text_field( $request->get_param( 's' ) ?? '' );
	if ( '' === $search ) {
		$search = sanitize_text_field( $request->get_param( 'search' ) ?? '' );
	}
	$paged  = max( 1, (int) ( $request->get_param( 'paged' ) ?: 1 ) );
	$per    = (int) get_option( 'dtb_admin_items_per_page', 25 );

	$query = function_exists( 'dtb_repairs_query' )
		? dtb_repairs_query( $status, $search, $paged, $per )
		: new WP_Query( [
			'post_type'      => 'dtb_repair_request',
			'post_status'    => 'publish',
			'posts_per_page' => $per,
			'paged'          => $paged,
			's'              => $search,
		] );
	$total_pages = $query->max_num_pages ?: 1;

	ob_start();

	if ( function_exists( 'dtb_repairs_render_queue_workspace' ) ) {
		dtb_repairs_render_queue_workspace( $query, $paged, $total_pages );
	} else {
		echo dtb_admin_ui_empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'No repairs found', 'drywall-toolbox' ),
			__( 'Try adjusting your filters.', 'drywall-toolbox' )
		);
	}

	$html = ob_get_clean();

	$repair_counts = function_exists( 'dtb_repairs_count_by_status' ) ? dtb_repairs_count_by_status() : [];
	$needs_attention = (int) ( $repair_counts['review'] ?? 0 )
		+ (int) ( $repair_counts['ready_to_ship'] ?? 0 );
	$total = 0;
	foreach ( array_keys( function_exists( 'dtb_repairs_status_filter_map' ) ? dtb_repairs_status_filter_map() : [] ) as $filter ) {
		$total += (int) ( $repair_counts[ $filter ] ?? 0 );
	}

	return new WP_REST_Response( [
		'ok'      => true,
		'html'    => $html,
		'state'   => [
			'tab'    => $tab ?: $status,
			'search' => $search,
			'paged'  => $paged,
		],
		'summary' => [
			'total'           => $total,
			'review'          => (int) ( $repair_counts['review'] ?? 0 ),
			'quote_pending'   => (int) ( $repair_counts['quote_pending'] ?? 0 ),
			'in_progress'     => (int) ( $repair_counts['in_progress'] ?? 0 ),
			'ready_to_ship'   => (int) ( $repair_counts['ready_to_ship'] ?? 0 ),
			'needs_attention' => $needs_attention,
		],
		'meta'    => [
			'updated_at'    => gmdate( 'c' ),
			'poll_after_ms' => 180000,
		],
	], 200 );
}
