<?php
/**
 * DTB Returns — ReturnsPage
 *
 * Renders dtb-returns admin page — returns queue.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_returns_render_page(): void {
	if ( ! current_user_can( 'dtb_manage_returns' ) ) {
		dtb_admin_shell_access_denied();
		return;
	}

	$active_tab = sanitize_key( $_GET['tab'] ?? 'all' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$status_arg = sanitize_key( $_GET['status'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	// Resolve a raw status URL param to the canonical queue-filter tab via the registry.
	if ( 'all' === $active_tab && '' !== $status_arg ) {
		$resolved = function_exists( 'dtb_admin_normalize_workflow_queue_filter' )
			? dtb_admin_normalize_workflow_queue_filter( 'return', $status_arg )
			: '';
		if ( '' !== $resolved ) {
			$active_tab = $resolved;
		}
	}

	$search      = sanitize_text_field( $_GET['s'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$live_search = sanitize_text_field( $_GET['search'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( '' === $search && '' !== $live_search ) {
		$search = $live_search;
	}
	$base_url = admin_url( 'admin.php?page=dtb-returns' );

	// Build status labels from the workflow registry; fall back to a static list if
	// the registry is unavailable (e.g. during early bootstrap).
	$registry_def    = function_exists( 'dtb_admin_get_workflow_definition' )
		? dtb_admin_get_workflow_definition( 'return' )
		: [];
	$registry_labels = (array) ( $registry_def['labels'] ?? [] );

	$status_labels = array_merge(
		[ 'all' => __( 'All', 'drywall-toolbox' ) ],
		$registry_labels ?: [
			'pending_review' => __( 'Pending Review', 'drywall-toolbox' ),
			'approved'       => __( 'Approved',       'drywall-toolbox' ),
			'awaiting_item'  => __( 'Awaiting Item',  'drywall-toolbox' ),
			'item_received'  => __( 'Item Received',  'drywall-toolbox' ),
			'refund_issued'  => __( 'Refund Issued',  'drywall-toolbox' ),
			'exchange_sent'  => __( 'Exchange Sent',  'drywall-toolbox' ),
			'closed'         => __( 'Closed',         'drywall-toolbox' ),
		]
	);

	$counts = dtb_returns_count_by_status();
	$tabs   = [];
	foreach ( $status_labels as $id => $label ) {
		$count  = 'all' === $id ? array_sum( $counts ) : ( $counts[ $id ] ?? 0 );
		$tabs[] = [
			'id'     => $id,
			'label'  => $count > 0 ? sprintf( '%s (%d)', $label, (int) $count ) : $label,
			'active' => $active_tab === $id,
			'url'    => add_query_arg( 'tab', $id, $base_url ),
		];
	}

	dtb_admin_shell_open( [
		'title'       => __( 'Returns', 'drywall-toolbox' ),
		'subtitle'    => __( 'Manage return requests and RMA workflows.', 'drywall-toolbox' ),
		'section'     => 'operations',
		'page'        => 'dtb-returns',
		'template'    => 'queue',
		'icon'        => 'dashicons-undo',
		'tabs'        => $tabs,
		'live_target' => 'dtb-returns-workspace',
	] );

	// KPI strip.
	$kpis = [
		[
			'value'      => array_sum( $counts ),
			'label'      => __( 'Total Returns', 'drywall-toolbox' ),
			'icon'       => 'dashicons-undo',
			'icon_color' => '' === $active_tab || 'all' === $active_tab ? 'primary' : 'neutral',
			'trend'      => __( 'All queues', 'drywall-toolbox' ),
			'trend_dir'  => 'flat',
			'href'       => $base_url,
		],
		[
			'value'      => (int) ( $counts['pending_review'] ?? 0 ),
			'label'      => __( 'Pending Review', 'drywall-toolbox' ),
			'icon'       => 'dashicons-visibility',
			'icon_color' => 'warning',
			'trend'      => __( 'Needs intake', 'drywall-toolbox' ),
			'trend_dir'  => 'flat',
			'href'       => add_query_arg( 'tab', 'pending_review', $base_url ),
		],
		[
			'value'      => (int) ( $counts['approved'] ?? 0 ),
			'label'      => __( 'Approved', 'drywall-toolbox' ),
			'icon'       => 'dashicons-yes-alt',
			'icon_color' => 'success',
			'trend'      => __( 'Awaiting drop-off', 'drywall-toolbox' ),
			'trend_dir'  => 'flat',
			'href'       => add_query_arg( 'tab', 'approved', $base_url ),
		],
		[
			'value'      => (int) ( $counts['awaiting_item'] ?? 0 ),
			'label'      => __( 'Awaiting Item', 'drywall-toolbox' ),
			'icon'       => 'dashicons-archive',
			'icon_color' => 'info',
			'trend'      => __( 'Transit', 'drywall-toolbox' ),
			'trend_dir'  => 'flat',
			'href'       => add_query_arg( 'tab', 'awaiting_item', $base_url ),
		],
		[
			'value'      => (int) ( ( $counts['refund_issued'] ?? 0 ) + ( $counts['exchange_sent'] ?? 0 ) ),
			'label'      => __( 'Refund / Exchange', 'drywall-toolbox' ),
			'icon'       => 'dashicons-money-alt',
			'icon_color' => 'accent',
			'trend'      => __( 'Resolution', 'drywall-toolbox' ),
			'trend_dir'  => 'up',
			'href'       => add_query_arg( 'tab', 'refund_issued', $base_url ),
		],
	];
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<div class="dtb-kpi-strip">';
	foreach ( $kpis as $kpi ) {
		echo dtb_admin_ui_kpi( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$kpi['value'],
			$kpi['label'],
			[
				'icon'       => $kpi['icon'],
				'icon_color' => $kpi['icon_color'],
				'trend'      => $kpi['trend'],
				'trend_dir'  => $kpi['trend_dir'],
				'href'       => $kpi['href'],
			]
		);
	}
	echo '</div>';
	if ( function_exists( 'dtb_admin_render_module_exception_chips' ) ) {
		echo dtb_admin_render_module_exception_chips( 'returns' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	// Toolbar.
	echo dtb_admin_ui_toolbar_open(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_search_input( __( 'Search returns…', 'drywall-toolbox' ), $search, true, 's', 'dtb-returns-workspace' );
	echo dtb_admin_ui_toolbar_spacer(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_button( __( 'Refresh', 'drywall-toolbox' ), [
		'type' => 'secondary',
		'icon' => 'dashicons-update',
		'size' => 'sm',
		'data' => [ 'dtb-live-refresh' => 'dtb-returns-workspace' ],
	] );
	echo dtb_admin_ui_toolbar_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	// Query.
	$paged_returns = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$result = dtb_returns_query( [
		'status'   => $active_tab,
		'search'   => $search,
		'per_page' => (int) get_option( 'dtb_admin_items_per_page', 25 ),
		'page'     => $paged_returns,
	] );

	/** @var DTB_Return_Entity[] $items */
	$items       = $result['items'];
	$total_pages = isset( $result['total'], $result['per_page'] ) && $result['per_page'] > 0
		? (int) ceil( $result['total'] / $result['per_page'] )
		: 1;

	// Live region always wraps the data grid.
	dtb_admin_shell_live_region_open( [
		'id'       => 'dtb-returns-workspace',
		'module'   => 'returns',
		'endpoint' => rest_url( 'dtb/v1/admin/returns' ),
		'interval' => 30000,
	] );

	if ( empty( $items ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo dtb_admin_ui_empty_state(
			__( 'No returns found.', 'drywall-toolbox' ),
			__( 'No return requests match the current filter.', 'drywall-toolbox' )
		);
		dtb_admin_shell_live_region_close();
		dtb_admin_shell_close();
		return;
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_update_badge( 'dtb-returns-workspace' );

	echo '<div class="dtb-bulk-toolbar" data-dtb-bulk-toolbar data-dtb-bulk-record="return" data-dtb-bulk-endpoint="' . esc_attr( 'dtb/v1/admin/returns/bulk' ) . '" data-dtb-bulk-refresh="dtb-returns-workspace" data-dtb-bulk-label="' . esc_attr__( 'returns', 'drywall-toolbox' ) . '" hidden>';
	echo '<div class="dtb-bulk-toolbar__summary"><span class="dtb-bulk-toolbar__count" data-dtb-bulk-count>0</span><span>' . esc_html__( 'selected returns', 'drywall-toolbox' ) . '</span></div>';
	echo '<div class="dtb-bulk-toolbar__actions">';
	echo dtb_admin_ui_button( __( 'Move to Trash', 'drywall-toolbox' ), [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		'type' => 'danger',
		'size' => 'sm',
		'data' => [ 'dtb-bulk-delete' => '1' ],
	] );
	echo '</div></div>';

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_table_open( [
		[
			'label' => '',
			'key'   => 'select',
			'class' => 'dtb-table__select-col',
			'html'  => '<input type="checkbox" class="dtb-bulk-select-all" data-dtb-bulk-select-all data-dtb-bulk-record="return" aria-label="' . esc_attr__( 'Select all returns', 'drywall-toolbox' ) . '">',
		],
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

	foreach ( $items as $item ) {
		$badge_type  = dtb_admin_ui_status_badge_type( $item->status->value() );
		$view_url    = admin_url( 'admin.php?page=dtb-returns&action=view&return_id=' . $item->id );
		$order_url   = $item->order_id ? admin_url( 'post.php?post=' . $item->order_id . '&action=edit' ) : '';
		$reason      = (string) ( $item->reason ?? '' );
		$resolution  = ucwords( str_replace( '_', ' ', $item->resolution ) );
		$rma_label   = $item->rma_number ?? ( '#' . $item->id );

		$status_val = $item->status->value();
		$na_label = function_exists( 'dtb_admin_compute_next_best_action' )
			? dtb_admin_compute_next_best_action( 'returns', [
				'id'       => (int) $item->id,
				'status'   => $status_val,
				'order_id' => (int) $item->order_id,
				'resolution' => (string) $item->resolution,
			] )
			: ucwords( str_replace( '_', ' ', $status_val ) );
		$na_type = in_array( $status_val, [ 'pending_review', 'item_received' ], true )
			? 'warning'
			: ( in_array( $status_val, [ 'refund_issued', 'exchange_sent' ], true ) ? 'success' : 'muted' );

		echo '<tr class="dtb-table__row dtb-table__row--clickable dtb-returns-row"'
			. ' data-dtb-return-id="' . esc_attr( (string) $item->id ) . '"'
			. ' data-dtb-return-ref="' . esc_attr( $rma_label ) . '"'
			. ' data-dtb-field-status="' . esc_attr( $status_val ) . '"'
			. ' data-dtb-field-resolution="' . esc_attr( $item->resolution ) . '"'
			. '>';

		echo '<td class="dtb-table__cell dtb-table__cell--select"><input type="checkbox" class="dtb-bulk-checkbox" data-dtb-bulk-record="return" data-dtb-bulk-id="' . esc_attr( (string) $item->id ) . '" aria-label="' . esc_attr( sprintf( __( 'Select return #%d', 'drywall-toolbox' ), $item->id ) ) . '"></td>';

		echo '<td class="dtb-table__cell">';
		echo '<div class="dtb-object-cell">';
		echo '<div>';
		echo '<p class="dtb-object-title">' . esc_html( $rma_label ) . '</p>';
		echo '<div class="dtb-object-meta">' . esc_html__( 'Return request', 'drywall-toolbox' ) . '</div>';
		echo '</div>';
		echo '</div>';
		echo '</td>';

		echo '<td class="dtb-table__cell">';
		echo '<p class="dtb-object-title">' . esc_html( $item->customer_name ) . '</p>';
		echo '</td>';

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

		echo '<td class="dtb-table__cell">' . esc_html( $resolution ) . '</td>';

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

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_table_close();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_pagination( $paged_returns, $total_pages );

	dtb_admin_shell_live_region_close();

	// Returns fullscreen modal command center.
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_modal(
		'dtb-returns-modal',
		__( 'Return Request', 'drywall-toolbox' ),
		'<div class="dtb-returns-modal-loading">' . esc_html__( 'Select a return to view details.', 'drywall-toolbox' ) . '</div>',
		'<div class="dtb-returns-modal__footer-actions">'
			. dtb_admin_ui_button( __( 'Open Full Record', 'drywall-toolbox' ), [
				'type' => 'secondary',
				'size' => 'sm',
				'data' => [ 'dtb-returns-modal-action' => 'view' ],
			] )
		. '</div>',
		'returns'
	);

	dtb_admin_shell_close();
}

