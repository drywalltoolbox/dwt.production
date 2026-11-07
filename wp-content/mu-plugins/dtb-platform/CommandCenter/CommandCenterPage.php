<?php
/**
 * DTB Platform — CommandCenterPage
 *
 * Renders the Drywall Toolbox → Command Center dashboard.
 * Business observability only — no backend diagnostics.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Page render callback registered in OperationsMenu.
 */
function dtb_command_center_render_page(): void {
	if ( ! current_user_can( 'dtb_view_command_center' ) ) {
		dtb_admin_shell_access_denied();
		return;
	}

	$data  = dtb_command_center_get_dashboard_data();
	$links = $data['links'] ?? [];
	$ord   = $data['orders']  ?? [];
	$rep   = $data['repairs'] ?? [];
	$ret   = $data['returns'] ?? [];
	$sup   = $data['support'] ?? [];
	$exc   = $data['exceptions'] ?? [];

	dtb_admin_shell_open( [
		'title'    => __( 'Command Center', 'drywall-toolbox' ),
		'subtitle' => __( 'Business operations overview — orders, repairs, returns, and support.', 'drywall-toolbox' ),
		'section'  => 'operations',
		'page'     => 'dtb-command-center',
		'template' => 'dashboard',
		'icon'     => 'dashicons-dashboard',
		'actions'  => [
			dtb_admin_ui_button( __( 'Refresh', 'drywall-toolbox' ), [
				'type' => 'secondary',
				'icon' => 'dashicons-update',
				'size' => 'sm',
				'attr' => 'data-dtb-live-refresh="dtb-command-center-workspace"',
			] ),
			dtb_admin_ui_button( __( 'System Manager', 'drywall-toolbox' ), [
				'type' => 'secondary',
				'href' => $links['system_manager'] ?? admin_url( 'admin.php?page=dtb-system-manager' ),
				'icon' => 'dashicons-monitor',
				'size' => 'sm',
			] ),
		],
	] );

	// Live region wraps all business-data content so Refresh + polling update it.
	dtb_admin_shell_live_region_open( [
		'id'       => 'dtb-command-center-workspace',
		'module'   => 'command-center',
		'endpoint' => rest_url( 'dtb/v1/admin/overview' ),
		'interval' => 180000,
	] );

	// Customer-impacting exceptions banner.
	if ( ( $exc['total'] ?? 0 ) > 0 ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo dtb_admin_ui_alert(
			sprintf(
				/* translators: %d = count */
				_n(
					'%d item needs attention across your active workflows.',
					'%d items need attention across your active workflows.',
					$exc['total'],
					'drywall-toolbox'
				),
				$exc['total']
			),
			'warning',
			__( 'Items Require Attention', 'drywall-toolbox' ),
			false
		);
	}

	if ( function_exists( 'dtb_command_center_render_exception_queues' ) ) {
		echo dtb_command_center_render_exception_queues( $exc ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	// ── KPI Overview ──────────────────────────────────────────────────────────
	echo '<div class="dtb-command-center-kpis">';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_kpi_grid( [
		[
			'value'      => $ord['active'] ?? 0,
			'label'      => __( 'Active Orders', 'drywall-toolbox' ),
			'icon'       => 'dashicons-cart',
			'icon_color' => 'primary',
			'href'       => add_query_arg( [ 'page' => 'dtb-orders', 'status' => 'active' ], admin_url( 'admin.php' ) ),
		],
		[
			'value'      => $ord['needs_attention'] ?? 0,
			'label'      => __( 'Urgent Orders', 'drywall-toolbox' ),
			'icon'       => 'dashicons-flag',
			'icon_color' => ( ( $ord['needs_attention'] ?? 0 ) > 0 ) ? 'warning' : 'success',
			'href'       => $links['orders_attention'] ?? '',
		],
		[
			'value'      => $rep['total_open'] ?? 0,
			'label'      => __( 'Open Repairs', 'drywall-toolbox' ),
			'icon'       => 'dashicons-hammer',
			'icon_color' => 'accent',
			'href'       => add_query_arg( [ 'page' => 'dtb-repairs' ], admin_url( 'admin.php' ) ),
		],
		[
			'value'      => $ret['total_open'] ?? 0,
			'label'      => __( 'Open Returns', 'drywall-toolbox' ),
			'icon'       => 'dashicons-undo',
			'icon_color' => 'info',
			'href'       => add_query_arg( [ 'page' => 'dtb-returns' ], admin_url( 'admin.php' ) ),
		],
		[
			'value'      => $sup['total_open'] ?? 0,
			'label'      => __( 'Support Tickets', 'drywall-toolbox' ),
			'icon'       => 'dashicons-format-chat',
			'icon_color' => ( ( $sup['past_sla'] ?? 0 ) > 0 ) ? 'danger' : 'neutral',
			'href'       => $links['support_open'] ?? '',
		],
		[
			'value'      => $ord['payment_issues'] ?? 0,
			'label'      => __( 'Payment Issues', 'drywall-toolbox' ),
			'icon'       => 'dashicons-warning',
			'icon_color' => ( ( $ord['payment_issues'] ?? 0 ) > 0 ) ? 'danger' : 'success',
			'href'       => $links['orders_failed'] ?? '',
		],
	] );
	echo '</div>';

	// ── Workflow Status Cards ─────────────────────────────────────────────────
	echo '<div class="dtb-grid dtb-grid--two">';

	// Orders card.
	ob_start();
	echo dtb_admin_ui_detail_row( __( 'Processing', 'drywall-toolbox' ), dtb_admin_ui_badge( (string) ( $ord['processing'] ?? 0 ), 'processing' ) );
	echo dtb_admin_ui_detail_row( __( 'On Hold', 'drywall-toolbox' ),    dtb_admin_ui_badge( (string) ( $ord['on_hold'] ?? 0 ), ( ( $ord['on_hold'] ?? 0 ) > 0 ) ? 'warning' : 'neutral' ) );
	echo dtb_admin_ui_detail_row( __( 'Pending', 'drywall-toolbox' ), dtb_admin_ui_badge( (string) ( $ord['pending'] ?? 0 ), 'info' ) );
	echo dtb_admin_ui_detail_row( __( 'Payment Issues', 'drywall-toolbox' ),   dtb_admin_ui_badge( (string) ( $ord['payment_issues'] ?? 0 ), ( ( $ord['payment_issues'] ?? 0 ) > 0 ) ? 'danger' : 'neutral' ) );
	$orders_body = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $orders_body, [
		'title'       => __( 'Orders', 'drywall-toolbox' ),
		'header_html' => dtb_admin_ui_button( __( 'View All', 'drywall-toolbox' ), [ 'href' => add_query_arg( [ 'page' => 'dtb-orders' ], admin_url( 'admin.php' ) ), 'size' => 'sm', 'type' => 'ghost' ] ),
	] );

	// Repairs card.
	ob_start();
	echo dtb_admin_ui_detail_row( __( 'Awaiting Review', 'drywall-toolbox' ),         dtb_admin_ui_badge( (string) ( $rep['awaiting_review'] ?? 0 ), ( ( $rep['awaiting_review'] ?? 0 ) > 0 ) ? 'warning' : 'neutral' ) );
	echo dtb_admin_ui_detail_row( __( 'Awaiting Quote Approval', 'drywall-toolbox' ), dtb_admin_ui_badge( (string) ( $rep['awaiting_quote_approval'] ?? 0 ), ( ( $rep['awaiting_quote_approval'] ?? 0 ) > 0 ) ? 'warning' : 'neutral' ) );
	echo dtb_admin_ui_detail_row( __( 'In Progress', 'drywall-toolbox' ),             dtb_admin_ui_badge( (string) ( $rep['in_progress'] ?? 0 ), 'processing' ) );
	echo dtb_admin_ui_detail_row( __( 'Ready to Ship', 'drywall-toolbox' ),           dtb_admin_ui_badge( (string) ( $rep['ready_to_ship'] ?? 0 ), ( ( $rep['ready_to_ship'] ?? 0 ) > 0 ) ? 'success' : 'neutral' ) );
	$repairs_body = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $repairs_body, [
		'title'       => __( 'Repairs', 'drywall-toolbox' ),
		'header_html' => dtb_admin_ui_button( __( 'View All', 'drywall-toolbox' ), [ 'href' => add_query_arg( [ 'page' => 'dtb-repairs' ], admin_url( 'admin.php' ) ), 'size' => 'sm', 'type' => 'ghost' ] ),
	] );

	// Returns card.
	ob_start();
	echo dtb_admin_ui_detail_row( __( 'Pending Review', 'drywall-toolbox' ),     dtb_admin_ui_badge( (string) ( $ret['pending_review'] ?? 0 ), ( ( $ret['pending_review'] ?? 0 ) > 0 ) ? 'warning' : 'neutral' ) );
	echo dtb_admin_ui_detail_row( __( 'Pending Inspection', 'drywall-toolbox' ), dtb_admin_ui_badge( (string) ( $ret['pending_inspection'] ?? 0 ), 'info' ) );
	echo dtb_admin_ui_detail_row( __( 'Refund Pending', 'drywall-toolbox' ),     dtb_admin_ui_badge( (string) ( $ret['refund_pending'] ?? 0 ), ( ( $ret['refund_pending'] ?? 0 ) > 0 ) ? 'warning' : 'neutral' ) );
	$returns_body = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $returns_body, [
		'title'       => __( 'Returns', 'drywall-toolbox' ),
		'header_html' => dtb_admin_ui_button( __( 'View All', 'drywall-toolbox' ), [ 'href' => add_query_arg( [ 'page' => 'dtb-returns' ], admin_url( 'admin.php' ) ), 'size' => 'sm', 'type' => 'ghost' ] ),
	] );

	// Support card.
	ob_start();
	echo dtb_admin_ui_detail_row( __( 'Open', 'drywall-toolbox' ),       dtb_admin_ui_badge( (string) ( $sup['open'] ?? 0 ), 'primary' ) );
	echo dtb_admin_ui_detail_row( __( 'Needs Reply', 'drywall-toolbox' ), dtb_admin_ui_badge( (string) ( $sup['needs_reply'] ?? 0 ), ( ( $sup['needs_reply'] ?? 0 ) > 0 ) ? 'danger' : 'neutral' ) );
	echo dtb_admin_ui_detail_row( __( 'Past SLA', 'drywall-toolbox' ),    dtb_admin_ui_badge( (string) ( $sup['past_sla'] ?? 0 ), ( ( $sup['past_sla'] ?? 0 ) > 0 ) ? 'danger' : 'success' ) );
	$support_body = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $support_body, [
		'title'       => __( 'Support', 'drywall-toolbox' ),
		'header_html' => dtb_admin_ui_button( __( 'View All', 'drywall-toolbox' ), [ 'href' => add_query_arg( [ 'page' => 'dtb-support' ], admin_url( 'admin.php' ) ), 'size' => 'sm', 'type' => 'ghost' ] ),
	] );

	echo '</div>'; // .dtb-grid--two

	dtb_admin_shell_live_region_close();
	dtb_admin_shell_close();
}
