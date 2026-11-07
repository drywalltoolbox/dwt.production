<?php
/**
 * DTB Platform — CommandCenterController
 *
 * REST endpoint for Command Center data refresh.
 * GET /wp-json/dtb/v1/command-center
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_command_center_register_routes' );

function dtb_command_center_register_routes(): void {
	register_rest_route( 'dtb/v1', '/command-center', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'dtb_command_center_rest_get',
		'permission_callback' => fn() => current_user_can( 'dtb_view_command_center' ),
	] );

	register_rest_route( 'dtb/v1', '/command-center/flush', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'dtb_command_center_rest_flush',
		'permission_callback' => fn() => current_user_can( 'dtb_manage_system' ),
	] );

	// Live-region refresh endpoint for the Command Center admin page.
	register_rest_route( 'dtb/v1', '/admin/overview', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'dtb_command_center_admin_overview_handler',
		'permission_callback' => fn() => current_user_can( 'dtb_view_command_center' ),
	] );
}

function dtb_command_center_rest_get( WP_REST_Request $request ): WP_REST_Response {
	unset( $request );
	$data = dtb_command_center_get_dashboard_data();
	return new WP_REST_Response( $data, 200 );
}

function dtb_command_center_rest_flush( WP_REST_Request $request ): WP_REST_Response {
	unset( $request );
	dtb_command_center_flush_cache();
	return new WP_REST_Response( [ 'flushed' => true ], 200 );
}

/**
 * Live-region refresh handler for GET /dtb/v1/admin/overview.
 *
 * Renders the KPI grid and workflow-status cards that live inside the
 * dtb-command-center-workspace region — identical content to the initial
 * page render but returned as a JSON-wrapped HTML fragment.
 */
function dtb_command_center_admin_overview_handler(): WP_REST_Response {
	$data  = dtb_command_center_get_dashboard_data();
	$links = $data['links'] ?? [];
	$ord   = $data['orders']  ?? [];
	$rep   = $data['repairs'] ?? [];
	$ret   = $data['returns'] ?? [];
	$sup   = $data['support'] ?? [];
	$exc   = $data['exceptions'] ?? [];

	ob_start();

	// Customer-impacting exceptions banner.
	if ( ( $exc['total'] ?? 0 ) > 0 ) {
		echo dtb_admin_ui_alert( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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

	// KPI grid.
	echo '<div class="dtb-command-center-kpis">';
	echo dtb_admin_ui_kpi_grid( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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

	// Workflow status cards.
	echo '<div class="dtb-grid dtb-grid--two">';

	ob_start();
	echo dtb_admin_ui_detail_row( __( 'Processing',      'drywall-toolbox' ), dtb_admin_ui_badge( (string) ( $ord['processing'] ?? 0 ), 'processing' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_detail_row( __( 'On Hold',         'drywall-toolbox' ), dtb_admin_ui_badge( (string) ( $ord['on_hold'] ?? 0 ), ( ( $ord['on_hold'] ?? 0 ) > 0 ) ? 'warning' : 'neutral' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_detail_row( __( 'Pending',         'drywall-toolbox' ), dtb_admin_ui_badge( (string) ( $ord['pending'] ?? 0 ), 'info' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_detail_row( __( 'Payment Issues',  'drywall-toolbox' ), dtb_admin_ui_badge( (string) ( $ord['payment_issues'] ?? 0 ), ( ( $ord['payment_issues'] ?? 0 ) > 0 ) ? 'danger' : 'neutral' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( ob_get_clean(), [ 'title' => __( 'Orders', 'drywall-toolbox' ), 'header_html' => dtb_admin_ui_button( __( 'View All', 'drywall-toolbox' ), [ 'href' => add_query_arg( [ 'page' => 'dtb-orders' ], admin_url( 'admin.php' ) ), 'size' => 'sm', 'type' => 'ghost' ] ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	ob_start();
	echo dtb_admin_ui_detail_row( __( 'Awaiting Review',         'drywall-toolbox' ), dtb_admin_ui_badge( (string) ( $rep['awaiting_review'] ?? 0 ), ( ( $rep['awaiting_review'] ?? 0 ) > 0 ) ? 'warning' : 'neutral' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_detail_row( __( 'Awaiting Quote Approval', 'drywall-toolbox' ), dtb_admin_ui_badge( (string) ( $rep['awaiting_quote_approval'] ?? 0 ), ( ( $rep['awaiting_quote_approval'] ?? 0 ) > 0 ) ? 'warning' : 'neutral' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_detail_row( __( 'In Progress',             'drywall-toolbox' ), dtb_admin_ui_badge( (string) ( $rep['in_progress'] ?? 0 ), 'processing' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_detail_row( __( 'Ready to Ship',           'drywall-toolbox' ), dtb_admin_ui_badge( (string) ( $rep['ready_to_ship'] ?? 0 ), ( ( $rep['ready_to_ship'] ?? 0 ) > 0 ) ? 'success' : 'neutral' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( ob_get_clean(), [ 'title' => __( 'Repairs', 'drywall-toolbox' ), 'header_html' => dtb_admin_ui_button( __( 'View All', 'drywall-toolbox' ), [ 'href' => add_query_arg( [ 'page' => 'dtb-repairs' ], admin_url( 'admin.php' ) ), 'size' => 'sm', 'type' => 'ghost' ] ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	ob_start();
	echo dtb_admin_ui_detail_row( __( 'Pending Review',     'drywall-toolbox' ), dtb_admin_ui_badge( (string) ( $ret['pending_review'] ?? 0 ), ( ( $ret['pending_review'] ?? 0 ) > 0 ) ? 'warning' : 'neutral' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_detail_row( __( 'Pending Inspection', 'drywall-toolbox' ), dtb_admin_ui_badge( (string) ( $ret['pending_inspection'] ?? 0 ), 'info' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_detail_row( __( 'Refund Pending',     'drywall-toolbox' ), dtb_admin_ui_badge( (string) ( $ret['refund_pending'] ?? 0 ), ( ( $ret['refund_pending'] ?? 0 ) > 0 ) ? 'warning' : 'neutral' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( ob_get_clean(), [ 'title' => __( 'Returns', 'drywall-toolbox' ), 'header_html' => dtb_admin_ui_button( __( 'View All', 'drywall-toolbox' ), [ 'href' => add_query_arg( [ 'page' => 'dtb-returns' ], admin_url( 'admin.php' ) ), 'size' => 'sm', 'type' => 'ghost' ] ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	ob_start();
	echo dtb_admin_ui_detail_row( __( 'Open',        'drywall-toolbox' ), dtb_admin_ui_badge( (string) ( $sup['open'] ?? 0 ), 'primary' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_detail_row( __( 'Needs Reply', 'drywall-toolbox' ), dtb_admin_ui_badge( (string) ( $sup['needs_reply'] ?? 0 ), ( ( $sup['needs_reply'] ?? 0 ) > 0 ) ? 'danger' : 'neutral' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_detail_row( __( 'Past SLA',    'drywall-toolbox' ), dtb_admin_ui_badge( (string) ( $sup['past_sla'] ?? 0 ), ( ( $sup['past_sla'] ?? 0 ) > 0 ) ? 'danger' : 'success' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( ob_get_clean(), [ 'title' => __( 'Support', 'drywall-toolbox' ), 'header_html' => dtb_admin_ui_button( __( 'View All', 'drywall-toolbox' ), [ 'href' => add_query_arg( [ 'page' => 'dtb-support' ], admin_url( 'admin.php' ) ), 'size' => 'sm', 'type' => 'ghost' ] ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	echo '</div>'; // .dtb-grid--two

	$html = ob_get_clean();
	return new WP_REST_Response( [ 'ok' => true, 'html' => $html ], 200 );
}
