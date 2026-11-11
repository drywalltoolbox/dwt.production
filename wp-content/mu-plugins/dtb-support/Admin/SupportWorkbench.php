<?php
/**
 * DTB Support — SupportWorkbench
 *
 * AdminShell-native support workbench layout and helpers.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Queue rail definitions used by the support workbench.
 *
 * @return array<string, array{label:string,hint:string,group:string,status:string}>
 */
function dtb_support_workbench_queue_items(): array {
	return [
		'all_active'             => [ 'label' => __( 'All Active', 'drywall-toolbox' ),          'hint' => __( 'Full open workload', 'drywall-toolbox' ),              'group' => __( 'Work Queue', 'drywall-toolbox' ),  'status' => '' ],
		'needs_reply'            => [ 'label' => __( 'Needs Reply', 'drywall-toolbox' ),         'hint' => __( 'Customer-facing replies waiting', 'drywall-toolbox' ), 'group' => __( 'Work Queue', 'drywall-toolbox' ),  'status' => 'needs-reply' ],
		'overdue'                => [ 'label' => __( 'Overdue', 'drywall-toolbox' ),             'hint' => __( 'Past due response targets', 'drywall-toolbox' ),       'group' => __( 'Work Queue', 'drywall-toolbox' ),  'status' => 'past-sla' ],
		'due_soon'               => [ 'label' => __( 'Due Soon', 'drywall-toolbox' ),            'hint' => __( 'Approaching response target', 'drywall-toolbox' ),      'group' => __( 'Work Queue', 'drywall-toolbox' ),  'status' => '' ],
		'urgent'                 => [ 'label' => __( 'Urgent', 'drywall-toolbox' ),              'hint' => __( 'Priority escalated tickets', 'drywall-toolbox' ),      'group' => __( 'Work Queue', 'drywall-toolbox' ),  'status' => '' ],
		'in_progress'            => [ 'label' => __( 'In Progress', 'drywall-toolbox' ),         'hint' => __( 'Active handling by staff', 'drywall-toolbox' ),        'group' => __( 'Workflow', 'drywall-toolbox' ),    'status' => 'open' ],
		'waiting_on_customer'    => [ 'label' => __( 'Waiting on Customer', 'drywall-toolbox' ), 'hint' => __( 'Blocked pending customer reply', 'drywall-toolbox' ),  'group' => __( 'Workflow', 'drywall-toolbox' ),    'status' => 'open' ],
		'snoozed'                => [ 'label' => __( 'Snoozed', 'drywall-toolbox' ),             'hint' => __( 'Temporarily paused tickets', 'drywall-toolbox' ),      'group' => __( 'Workflow', 'drywall-toolbox' ),    'status' => 'open' ],
		'resolved_pending_close' => [ 'label' => __( 'Resolved', 'drywall-toolbox' ),            'hint' => __( 'Completed pending closeout', 'drywall-toolbox' ),      'group' => __( 'Closed Loop', 'drywall-toolbox' ), 'status' => 'resolved' ],
		'closed'                 => [ 'label' => __( 'Closed', 'drywall-toolbox' ),              'hint' => __( 'Archived and closed tickets', 'drywall-toolbox' ),     'group' => __( 'Closed Loop', 'drywall-toolbox' ), 'status' => 'closed' ],
	];
}

/**
 * Map current tab status value to queue key.
 */
function dtb_support_workbench_status_to_queue( string $status ): string {
	$status = dtb_support_admin_normalize_status( $status );

	$map = [
		''            => 'all_active',
		'open'        => 'all_active',
		'needs-reply' => 'needs_reply',
		'past-sla'    => 'overdue',
		'resolved'    => 'resolved_pending_close',
		'closed'      => 'closed',
	];

	return $map[ $status ] ?? 'all_active';
}

/**
 * Build support KPI cards using shared DTB components.
 *
 * @return array<int, array<string, mixed>>
 */
function dtb_support_workbench_kpi_cards(): array {
	$kpis = dtb_support_get_kpis();

	return [
		[
			'value'      => number_format_i18n( (int) ( $kpis['active_total'] ?? 0 ) ),
			'label'      => __( 'Active', 'drywall-toolbox' ),
			'icon'       => 'dashicons-tickets-alt',
			'icon_color' => 'primary',
			'href'       => admin_url( 'admin.php?page=dtb-support' ),
		],
		[
			'value'      => number_format_i18n( (int) ( $kpis['needs_reply'] ?? 0 ) ),
			'label'      => __( 'Needs Reply', 'drywall-toolbox' ),
			'icon'       => 'dashicons-email-alt2',
			'icon_color' => 'warning',
			'href'       => add_query_arg( [ 'page' => 'dtb-support', 'status' => 'needs-reply' ], admin_url( 'admin.php' ) ),
		],
		[
			'value'      => number_format_i18n( (int) ( $kpis['overdue_count'] ?? 0 ) ),
			'label'      => __( 'Overdue', 'drywall-toolbox' ),
			'icon'       => 'dashicons-warning',
			'icon_color' => 'danger',
			'href'       => add_query_arg( [ 'page' => 'dtb-support', 'status' => 'past-sla' ], admin_url( 'admin.php' ) ),
		],
		[
			'value'      => number_format_i18n( (int) ( $kpis['urgent'] ?? 0 ) ),
			'label'      => __( 'Urgent', 'drywall-toolbox' ),
			'icon'       => 'dashicons-flag',
			'icon_color' => 'danger',
		],
	];
}

/**
 * Render the AdminShell support workbench.
 *
 * @param array $args {
 *   @type string $status
 *   @type string $queue
 *   @type string $search
 *   @type string $type
 *   @type string $priority
 *   @type int    $paged
 *   @type array  $result
 * }
 */
function dtb_support_render_workbench( array $args ): void {
	$status   = (string) ( $args['status'] ?? '' );
	$queue    = (string) ( $args['queue'] ?? '' );
	$search   = (string) ( $args['search'] ?? '' );
	$type     = (string) ( $args['type'] ?? '' );
	$priority = (string) ( $args['priority'] ?? '' );
	$paged    = (int) ( $args['paged'] ?? 1 );
	$result   = (array) ( $args['result'] ?? [] );

	$active_queue = '' !== $queue ? sanitize_key( $queue ) : dtb_support_workbench_status_to_queue( $status );
	$queue_items  = dtb_support_workbench_queue_items();
	$queue_counts = function_exists( 'dtb_support_get_queue_counts' ) ? dtb_support_get_queue_counts() : [];
	$queue_counts = function_exists( 'dtb_support_normalize_queue_counts' ) ? dtb_support_normalize_queue_counts( $queue_counts ) : $queue_counts;
	$status_counts = dtb_support_count_by_status();
	$queue_counts['closed'] = (int) ( $status_counts['closed'] ?? 0 );

	echo '<div id="dtb-support-workbench" class="dtb-support-workbench" data-dtb-support-workbench'
		. ' data-default-queue="' . esc_attr( $active_queue ?: 'needs_reply' ) . '"'
		. ' data-active-queue="' . esc_attr( $active_queue ?: 'needs_reply' ) . '"'
		. ' data-active-status="' . esc_attr( $status ) . '"'
		. ' data-active-search="' . esc_attr( $search ) . '"'
		. ' data-active-type="' . esc_attr( $type ) . '"'
		. ' data-active-priority="' . esc_attr( $priority ) . '"'
		. ' data-dtb-support-endpoint="' . esc_url( rest_url( 'dtb/v1/support/workbench' ) ) . '">';

	echo '<aside class="dtb-support-rail" aria-label="' . esc_attr__( 'Support queues', 'drywall-toolbox' ) . '">';
	$current_group = '';
	foreach ( $queue_items as $queue_key => $meta ) {
		if ( $current_group !== $meta['group'] ) {
			$current_group = $meta['group'];
			echo '<div class="dtb-support-rail-group">' . esc_html( $current_group ) . '</div>';
		}
		$is_active = $queue_key === $active_queue;
		$query_args = [
			'page'  => 'dtb-support',
			'queue' => $queue_key,
		];
		if ( '' !== $search ) {
			$query_args['search'] = $search;
		}
		if ( '' !== $type ) {
			$query_args['type'] = $type;
		}
		if ( '' !== $priority ) {
			$query_args['priority'] = $priority;
		}
		$queue_url = add_query_arg( $query_args, admin_url( 'admin.php' ) );

		echo '<a class="dtb-support-queue' . ( $is_active ? ' is-active' : '' ) . '"'
			. ' href="' . esc_url( $queue_url ) . '"'
			. ' data-dtb-support-queue="' . esc_attr( $queue_key ) . '"'
			. ' data-dtb-support-status="' . esc_attr( (string) $meta['status'] ) . '">';
		echo '<span class="dtb-support-queue__copy">';
		echo '<span class="dtb-support-queue__label">' . esc_html( $meta['label'] ) . '</span>';
		echo '<span class="dtb-support-queue__hint">' . esc_html( $meta['hint'] ) . '</span>';
		echo '</span>';
		echo '<span class="dtb-support-queue__count" data-dtb-support-queue-count="' . esc_attr( $queue_key ) . '">' . esc_html( (string) ( (int) ( $queue_counts[ $queue_key ] ?? 0 ) ) ) . '</span>';
		echo '</a>';
	}
	echo '</aside>';

	echo '<section class="dtb-support-main">';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_kpi_grid( dtb_support_workbench_kpi_cards() );
	if ( function_exists( 'dtb_admin_render_module_exception_chips' ) ) {
		echo dtb_admin_render_module_exception_chips( 'support' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	echo dtb_admin_ui_toolbar_open(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<div class="dtb-search-wrap">';
	echo '<span class="dtb-search-icon dashicons dashicons-search" aria-hidden="true"></span>';
	echo '<input type="search" class="dtb-input dtb-search-input" data-dtb-support-search name="search" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search tickets…', 'drywall-toolbox' ) . '" autocomplete="off">';
	echo '</div>';
	echo '<select class="dtb-select dtb-support-filter" data-dtb-support-filter="type" aria-label="' . esc_attr__( 'Filter by type', 'drywall-toolbox' ) . '">';
	echo '<option value="">' . esc_html__( 'All Types', 'drywall-toolbox' ) . '</option>';
	foreach ( dtb_support_all_types() as $slug => $label ) {
		echo '<option value="' . esc_attr( (string) $slug ) . '"' . selected( $type, (string) $slug, false ) . '>' . esc_html( (string) $label ) . '</option>';
	}
	echo '</select>';
	echo '<select class="dtb-select dtb-support-filter" data-dtb-support-filter="priority" aria-label="' . esc_attr__( 'Filter by priority', 'drywall-toolbox' ) . '">';
	echo '<option value="">' . esc_html__( 'All Priorities', 'drywall-toolbox' ) . '</option>';
	foreach ( dtb_support_all_priorities() as $slug => $label ) {
		echo '<option value="' . esc_attr( (string) $slug ) . '"' . selected( $priority, (string) $slug, false ) . '>' . esc_html( (string) $label ) . '</option>';
	}
	echo '</select>';
	echo dtb_admin_ui_toolbar_spacer(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_button( __( 'Refresh', 'drywall-toolbox' ), [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		'type' => 'secondary',
		'icon' => 'dashicons-update',
		'size' => 'sm',
		'data' => [ 'dtb-live-refresh' => 'dtb-support-workspace' ],
	] );
	if ( current_user_can( 'dtb_manage_support_settings' ) || current_user_can( 'manage_options' ) ) {
		echo dtb_admin_ui_button( __( 'Settings', 'drywall-toolbox' ), [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'href' => admin_url( 'admin.php?page=dtb-support-settings' ),
			'type' => 'ghost',
			'size' => 'sm',
		] );
	}
	echo dtb_admin_ui_toolbar_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	dtb_admin_shell_live_region_open( [
		'id'       => 'dtb-support-workspace',
		'module'   => 'support',
		'endpoint' => rest_url( 'dtb/v1/admin/support' ),
		'interval' => 30000,
	] );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_support_admin_render_queue_markup( $result, $paged );
	dtb_admin_shell_live_region_close();
	echo '</section>';

	echo '</div>';

	// Fullscreen modal detail workspace (replaces side drawer).
	echo dtb_admin_ui_modal( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		'dtb-support-ticket-modal',
		__( 'Support Ticket', 'drywall-toolbox' ),
		'<div class="dtb-support-modal-loading">' . esc_html__( 'Select a ticket to view details.', 'drywall-toolbox' ) . '</div>',
		'<div class="dtb-support-ticket-modal__footer-actions">'
			. dtb_admin_ui_button( __( 'Open Full Ticket', 'drywall-toolbox' ), [
				'type' => 'secondary',
				'size' => 'sm',
				'data' => [ 'dtb-support-modal-action' => 'view' ],
			] )
		. '</div>',
		'support-ticket'
	);
}
