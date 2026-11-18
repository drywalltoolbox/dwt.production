<?php
/**
 * DTB Returns — ReturnsAdminQueueController
 *
 * REST endpoint: GET /dtb/v1/admin/returns
 *
 * Returns an HTML fragment (JSON-wrapped) consumed by liveNavigate
 * to refresh the Returns live region without a full page reload.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_returns_admin_register_routes' );

function dtb_returns_admin_register_routes(): void {
	register_rest_route( 'dtb/v1', '/admin/returns', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'dtb_returns_admin_queue_handler',
		'permission_callback' => fn() => current_user_can( 'dtb_manage_returns' ),
		'args'                => [
			'tab'   => [ 'sanitize_callback' => 'sanitize_key' ],
			's'     => [ 'sanitize_callback' => 'sanitize_text_field' ],
			'paged' => [ 'sanitize_callback' => 'absint' ],
		],
	] );
}

function dtb_returns_admin_queue_handler( WP_REST_Request $request ): WP_REST_Response {
	$active_tab = sanitize_key( $request->get_param( 'tab' ) ?: 'all' );
	$status_arg = sanitize_key( $request->get_param( 'status' ) ?? '' );
	$status_to_tab = [
		'pending_review'   => 'pending_review',
		'approved'         => 'approved',
		'awaiting_item'    => 'awaiting_item',
		'item_received'    => 'item_received',
		'refund_issued'    => 'refund_issued',
		'exchange_sent'    => 'exchange_sent',
		'closed'           => 'closed',
		'under_review'     => 'pending_review',
		'inspection_pending' => 'item_received',
		'refund_pending'   => 'refund_issued',
	];
	if ( 'all' === $active_tab && isset( $status_to_tab[ $status_arg ] ) ) {
		$active_tab = $status_to_tab[ $status_arg ];
	}
	$search     = sanitize_text_field( $request->get_param( 's' ) ?? '' );
	if ( '' === $search ) {
		$search = sanitize_text_field( $request->get_param( 'search' ) ?? '' );
	}
	$paged      = max( 1, (int) ( $request->get_param( 'paged' ) ?: 1 ) );

	$result = dtb_returns_query( [
		'status'   => $active_tab,
		'search'   => $search,
		'per_page' => (int) get_option( 'dtb_admin_items_per_page', 25 ),
		'page'     => $paged,
	] );

	/** @var DTB_Return_Entity[] $items */
	$items       = $result['items'];
	$total_pages = isset( $result['total'], $result['per_page'] ) && $result['per_page'] > 0
		? (int) ceil( $result['total'] / $result['per_page'] )
		: 1;

	ob_start();

	if ( empty( $items ) ) {
		echo dtb_admin_ui_empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			__( 'No returns found.', 'drywall-toolbox' ),
			__( 'No return requests match the current filter.', 'drywall-toolbox' )
		);
	} else {
		echo dtb_admin_ui_update_badge( 'dtb-returns-workspace' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo dtb_admin_ui_table_open( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			[ 'label' => __( 'RMA / ID',    'drywall-toolbox' ), 'key' => 'rma' ],
			[ 'label' => __( 'Customer',    'drywall-toolbox' ), 'key' => 'customer' ],
			[ 'label' => __( 'Order',       'drywall-toolbox' ), 'key' => 'order' ],
			[ 'label' => __( 'Reason',      'drywall-toolbox' ), 'key' => 'reason' ],
			[ 'label' => __( 'Resolution',  'drywall-toolbox' ), 'key' => 'resolution' ],
			[ 'label' => __( 'Status',      'drywall-toolbox' ), 'key' => 'status' ],
			[ 'label' => __( 'Age',         'drywall-toolbox' ), 'key' => 'age' ],
			[ 'label' => __( 'Next Action', 'drywall-toolbox' ), 'key' => 'next_action' ],
			[ 'label' => '',                                     'key' => 'actions' ],
		], [] );

		$next_action_map = [
			'pending_review' => [ __( 'Review Request',    'drywall-toolbox' ), 'warning' ],
			'approved'       => [ __( 'Awaiting Drop-off', 'drywall-toolbox' ), 'primary' ],
			'awaiting_item'  => [ __( 'Monitor Transit',   'drywall-toolbox' ), 'primary' ],
			'item_received'  => [ __( 'Inspect Item',      'drywall-toolbox' ), 'warning' ],
			'refund_issued'  => [ __( 'Confirm Refund',    'drywall-toolbox' ), 'success' ],
			'exchange_sent'  => [ __( 'Track Exchange',    'drywall-toolbox' ), 'info' ],
			'closed'         => [ __( 'Closed',            'drywall-toolbox' ), 'muted' ],
		];

		foreach ( $items as $item ) {
			$badge_type = dtb_admin_ui_status_badge_type( $item->status->value() );
			$view_url   = admin_url( 'admin.php?page=dtb-returns&action=view&return_id=' . $item->id );
			$order_url  = $item->order_id ? admin_url( 'post.php?post=' . $item->order_id . '&action=edit' ) : '';
			$rma_label  = '#' . $item->id;
			$reason     = (string) ( $item->reason ?? '' );
			$status_val = $item->status->value();
			[ $na_label, $na_type ] = $next_action_map[ $status_val ] ?? [ ucwords( str_replace( '_', ' ', $status_val ) ), 'muted' ];

			echo '<tr class="dtb-table__row dtb-table__row--clickable dtb-returns-row"'
				. ' data-dtb-return-id="' . esc_attr( (string) $item->id ) . '"'
				. ' data-dtb-return-ref="' . esc_attr( $rma_label ) . '"'
				. ' data-dtb-field-status="' . esc_attr( $status_val ) . '"'
				. '>';

			echo '<td class="dtb-table__cell">';
			echo '<p class="dtb-object-title">' . esc_html( $rma_label ) . '</p>';
			echo '<div class="dtb-object-meta">' . esc_html__( 'Return request', 'drywall-toolbox' ) . '</div>';
			echo '</td>';

			echo '<td class="dtb-table__cell">' . esc_html( $item->customer_name ) . '</td>';

			echo '<td class="dtb-table__cell">';
			if ( $item->order_id ) {
				echo dtb_admin_ui_linked_entity_chip( '#' . $item->order_id, $order_url, 'order' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				echo '<span class="dtb-table__cell--muted">—</span>';
			}
			echo '</td>';

			echo '<td class="dtb-table__cell">';
			echo $reason ? esc_html( ucwords( str_replace( '_', ' ', $reason ) ) ) : '<span class="dtb-table__cell--muted">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</td>';

			echo '<td class="dtb-table__cell">' . esc_html( ucwords( str_replace( '_', ' ', $item->resolution ) ) ) . '</td>';

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td class="dtb-table__cell">' . dtb_admin_ui_badge( $item->status->label(), $badge_type ) . '</td>';

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td class="dtb-table__cell">' . dtb_admin_ui_age_badge( $item->created_at ) . '</td>';

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td class="dtb-table__cell">' . dtb_admin_ui_next_action( $na_label, $na_type ) . '</td>';

			echo '<td class="dtb-table__cell"><div class="dtb-table__actions">';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo dtb_admin_ui_button( __( 'View', 'drywall-toolbox' ), [
				'href' => $view_url,
				'size' => 'xs',
				'type' => 'ghost',
			] );
			echo '</div></td>';
			echo '</tr>';
		}

		echo dtb_admin_ui_table_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo dtb_admin_ui_pagination( $paged, $total_pages ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	$html = ob_get_clean();

	// Counts for summary.
	$all_counts = function_exists( 'dtb_returns_count_by_status' ) ? dtb_returns_count_by_status() : [];
	$total_open = (int) ( ( $all_counts['pending_review'] ?? 0 ) + ( $all_counts['awaiting_item'] ?? 0 ) + ( $all_counts['item_received'] ?? 0 ) );

	return new WP_REST_Response( [
		'ok'      => true,
		'html'    => $html,
		'state'   => [
			'tab'    => $active_tab,
			'search' => $search,
			'paged'  => $paged,
		],
		'summary' => [
			'total'          => array_sum( $all_counts ),
			'pending_review' => (int) ( $all_counts['pending_review'] ?? 0 ),
			'open'           => $total_open,
		],
		'meta'    => [
			'updated_at'   => gmdate( 'c' ),
			'poll_after_ms' => 30000,
		],
	], 200 );
}
