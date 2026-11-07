<?php
defined( 'ABSPATH' ) || exit;

/**
 * DTB Commerce — OrderAdminQueueController
 *
 * REST endpoint: GET /dtb/v1/admin/orders
 *
 * Returns an HTML fragment (JSON-wrapped) consumed by liveNavigate
 * to refresh the Orders live region without a full page reload.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_orders_admin_register_routes' );

function dtb_orders_admin_register_routes(): void {
	register_rest_route( 'dtb/v1', '/admin/orders', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'dtb_orders_admin_queue_handler',
		'permission_callback' => fn() => current_user_can( 'dtb_manage_orders' ),
		'args'                => [
			'status' => [ 'sanitize_callback' => 'sanitize_key' ],
			'tab'    => [ 'sanitize_callback' => 'sanitize_key' ],
			'order_type' => [ 'sanitize_callback' => 'sanitize_key' ],
			's'      => [ 'sanitize_callback' => 'sanitize_text_field' ],
			'paged'  => [ 'sanitize_callback' => 'absint' ],
		],
	] );
}

function dtb_orders_admin_queue_handler( WP_REST_Request $request ): WP_REST_Response {
	$status = sanitize_key( $request->get_param( 'status' ) ?? '' );
	$tab    = sanitize_key( $request->get_param( 'tab' ) ?? '' );
	// Normalize 'all' (live tab value) to empty string for WC query.
	if ( 'all' === $status ) $status = '';
	if ( 'all' === $tab )    $tab    = '';
	if ( '' === $status && '' !== $tab ) {
		$status = $tab;
	}
	$status = function_exists( 'dtb_orders_admin_normalize_filter' ) ? dtb_orders_admin_normalize_filter( $status ) : $status;
	$order_type = function_exists( 'dtb_order_type_normalize' )
		? dtb_order_type_normalize( sanitize_key( $request->get_param( 'order_type' ) ?? '' ) )
		: '';
	$search = sanitize_text_field( $request->get_param( 's' ) ?? '' );
	if ( '' === $search ) {
		$search = sanitize_text_field( $request->get_param( 'search' ) ?? '' );
	}
	$paged  = max( 1, (int) ( $request->get_param( 'paged' ) ?: 1 ) );
	$per    = (int) get_option( 'dtb_admin_items_per_page', 25 );

	$query_args  = dtb_orders_admin_build_query_args( $status, $search, $paged, $per, $order_type );
	$total_count = dtb_orders_admin_count( $status, $search, $order_type );
	$total_pages = $per > 0 ? (int) ceil( $total_count / $per ) : 1;
	$orders      = wc_get_orders( $query_args );

	ob_start();

	if ( empty( $orders ) ) {
		echo dtb_admin_ui_empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'No orders found', 'drywall-toolbox' ),
			__( 'Try adjusting your filters.', 'drywall-toolbox' )
		);
	} else {
		echo dtb_admin_ui_update_badge( 'dtb-orders-workspace' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo dtb_admin_ui_table_open( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			[ 'label' => __( 'Order',    'drywall-toolbox' ), 'key' => 'id' ],
			[ 'label' => __( 'Date',     'drywall-toolbox' ), 'key' => 'date' ],
			[ 'label' => __( 'Type',     'drywall-toolbox' ), 'key' => 'type' ],
			[ 'label' => __( 'Customer', 'drywall-toolbox' ), 'key' => 'customer' ],
			[ 'label' => __( 'Status',   'drywall-toolbox' ), 'key' => 'status' ],
			[ 'label' => __( 'Total',    'drywall-toolbox' ), 'key' => 'total' ],
			[ 'label' => '',                                   'key' => 'actions' ],
		], [ 'class' => 'dtb-orders-table' ] );

		foreach ( $orders as $order ) {
			/** @var WC_Order $order */
			$order_id     = $order->get_id();
			$raw_status   = $order->get_status();
			$badge_type   = dtb_admin_ui_status_badge_type( $raw_status );
			$status_label = wc_get_order_status_name( $raw_status );
			$type         = function_exists( 'dtb_order_type_from_order' ) ? dtb_order_type_from_order( $order ) : 'product';
			$type_label   = function_exists( 'dtb_order_type_label' ) ? dtb_order_type_label( $type ) : ucfirst( $type );
			$type_badge   = function_exists( 'dtb_order_type_badge_type' ) ? dtb_order_type_badge_type( $type ) : 'info';

			echo '<tr class="dtb-table__row dtb-table__row--clickable"'
				. ' data-dtb-drawer="dtb-orders-detail-drawer"'
				. ' data-dtb-open-order="' . esc_attr( (string) $order_id ) . '"'
				. ' data-dtb-drawer-title="' . esc_attr( sprintf( __( 'Order #%s', 'drywall-toolbox' ), $order_id ) ) . '"'
				. ' data-dtb-field-orderid="' . esc_attr( '#' . $order_id ) . '"'
				. ' data-dtb-field-type="' . esc_attr( $type_label ) . '"'
				. ' data-dtb-field-customer="' . esc_attr( $order->get_formatted_billing_full_name() ?: __( 'Guest', 'drywall-toolbox' ) ) . '"'
				. ' data-dtb-field-status="' . esc_attr( $status_label ) . '"'
				. ' data-dtb-field-total="' . esc_attr( wp_strip_all_tags( $order->get_formatted_order_total() ) ) . '"'
				. ' data-dtb-field-date="' . esc_attr( $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '—' ) . '"'
				. ' data-dtb-field-viewurl="' . esc_attr( get_edit_post_link( $order_id ) ) . '">';
			echo '<td class="dtb-table__cell"><a href="' . esc_url( get_edit_post_link( $order_id ) ) . '">#' . esc_html( $order_id ) . '</a></td>';
			echo '<td class="dtb-table__cell">' . esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '—' ) . '</td>';
			echo '<td class="dtb-table__cell">' . dtb_admin_ui_badge( esc_html( $type_label ), $type_badge ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td class="dtb-table__cell">' . esc_html( $order->get_formatted_billing_full_name() ?: __( 'Guest', 'drywall-toolbox' ) ) . '</td>';
			echo '<td class="dtb-table__cell">' . dtb_admin_ui_badge( esc_html( $status_label ), $badge_type ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td class="dtb-table__cell">' . wp_kses_post( $order->get_formatted_order_total() ) . '</td>';
			echo '<td class="dtb-table__cell">';
			echo dtb_admin_ui_button( __( 'View', 'drywall-toolbox' ), [ 'href' => '#', 'size' => 'xs', 'type' => 'ghost', 'data' => [ 'dtb-open-order' => $order_id ] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</td>';
			echo '</tr>';
		}

		echo dtb_admin_ui_table_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo dtb_admin_ui_pagination( $paged, $total_pages ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	$html = ob_get_clean();

	// Build lightweight summary counts for KPI sync.
	$summary_attention  = dtb_orders_admin_count( 'attention', '', $order_type );
	$summary_processing = dtb_orders_admin_count( 'processing', '', $order_type );

	return new WP_REST_Response( [
		'ok'      => true,
		'html'    => $html,
		'state'   => [
			'tab'    => $status ?: 'all',
			'status' => $status,
			'order_type' => $order_type,
			'search' => $search,
			'paged'  => $paged,
		],
		'summary' => [
			'total'           => $total_count,
			'needs_attention' => $summary_attention,
			'processing'      => $summary_processing,
		],
		'meta'    => [
			'updated_at'     => gmdate( 'c' ),
			'poll_after_ms'  => 180000,
		],
	], 200 );
}

