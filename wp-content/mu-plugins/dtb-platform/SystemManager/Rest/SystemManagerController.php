<?php
/**
 * DTB Platform — SystemManager REST Controllers
 *
 * Endpoints:
 *   GET  /dtb/v1/system/health
 *   GET  /dtb/v1/system/queues
 *   GET  /dtb/v1/system/integrations
 *   GET  /dtb/v1/system/webhooks
 *   GET  /dtb/v1/system/audit
 *   POST /dtb/v1/system/flush-cache
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_system_manager_register_routes' );

function dtb_system_manager_register_routes(): void {
	$read_cap = fn() => current_user_can( 'dtb_manage_system' );

	$routes = [
		'health'       => 'dtb_system_health_get',
		'queues'       => 'dtb_queue_health_get',
		'integrations' => fn() => dtb_integration_health_get(),
		'webhooks'     => fn() => dtb_webhook_health_get(),
		'cron'         => fn() => dtb_cron_health_get(),
		'workflow-links' => fn() => dtb_system_workflow_link_diagnostics(),
		'workbench-contracts' => fn() => dtb_system_workbench_contract_diagnostics(),
	];

	foreach ( $routes as $slug => $callback ) {
		register_rest_route( 'dtb/v1', "/system/{$slug}", [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => fn( $r ) => new WP_REST_Response( $callback(), 200 ),
			'permission_callback' => $read_cap,
		] );
	}

	// Audit log.
	register_rest_route( 'dtb/v1', '/system/audit', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => function ( WP_REST_Request $r ) {
			$limit = (int) ( $r->get_param( 'limit' ) ?? 50 );
			return new WP_REST_Response( dtb_audit_log_get_recent( max( 1, min( $limit, 200 ) ) ), 200 );
		},
		'permission_callback' => $read_cap,
	] );

	// Flush all system caches.
	register_rest_route( 'dtb/v1', '/system/flush-cache', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => function () {
			delete_transient( 'dtb_system_health' );
			delete_transient( 'dtb_queue_health' );
			delete_transient( 'dtb_integration_health' );
			delete_transient( 'dtb_webhook_health' );
			delete_transient( 'dtb_cron_health' );
			return new WP_REST_Response( [ 'flushed' => true ], 200 );
		},
		'permission_callback' => $read_cap,
	] );

	// Live-region refresh endpoint for the unified System Manager console
	// (platform health tabs and, when the requester holds
	// dtb_manage_deployments, release-management tabs — see
	// dtb_system_manager_admin_system_handler()).
	register_rest_route( 'dtb/v1', '/admin/system', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'dtb_system_manager_admin_system_handler',
		'permission_callback' => fn() => current_user_can( 'dtb_manage_system' ) || current_user_can( 'dtb_manage_deployments' ),
		'args'                => [
			'tab' => [ 'sanitize_callback' => 'sanitize_key' ],
		],
	] );
}

/**
 * Validate registered Command Center/module links against canonical workflow filters.
 *
 * @return array<string,mixed>
 */
function dtb_system_workflow_link_diagnostics(): array {
	$data    = function_exists( 'dtb_command_center_get_dashboard_data' ) ? dtb_command_center_get_dashboard_data() : [ 'links' => [] ];
	$links   = (array) ( $data['links'] ?? [] );
	$results = [];

	foreach ( $links as $key => $url ) {
		$parts = wp_parse_url( (string) $url );
		$query = [];
		if ( ! empty( $parts['query'] ) ) {
			wp_parse_str( $parts['query'], $query );
		}

		$page   = sanitize_key( (string) ( $query['page'] ?? '' ) );
		$filter = sanitize_key( (string) ( $query['status'] ?? $query['tab'] ?? $query['filter'] ?? $query['queue'] ?? '' ) );
		$ok     = true;
		$module = '';

		if ( 'dtb-repairs' === $page && '' !== $filter ) {
			$module = 'repair';
			$ok     = '' !== dtb_admin_normalize_workflow_queue_filter( 'repair', $filter );
		} elseif ( 'dtb-returns' === $page && '' !== $filter ) {
			$module = 'return';
			$ok     = '' !== dtb_admin_normalize_workflow_queue_filter( 'return', $filter );
		} elseif ( 'dtb-support' === $page && '' !== $filter ) {
			$module = 'support_ticket';
			$ok     = '' !== dtb_admin_normalize_workflow_queue_filter( 'support_ticket', $filter );
		} elseif ( 'dtb-orders' === $page && '' !== $filter ) {
			$module = 'product_order';
			$ok     = '' !== dtb_admin_normalize_workflow_queue_filter( 'product_order', $filter );
		}

		$results[ $key ] = [
			'url'    => esc_url_raw( (string) $url ),
			'page'   => $page,
			'filter' => $filter,
			'module' => $module,
			'ok'     => $ok,
		];
	}

	return [
		'ok'      => ! in_array( false, wp_list_pluck( $results, 'ok' ), true ),
		'links'   => $results,
		'checked' => count( $results ),
	];
}

/**
 * Return AdminWorkbenchContract diagnostics for each operational module.
 *
 * @return array<string,mixed>
 */
function dtb_system_workbench_contract_diagnostics(): array {
	$modules = [ 'support', 'returns', 'repairs', 'orders' ];
	$results = [];
	foreach ( $modules as $module ) {
		$payload = function_exists( 'dtb_admin_prepare_workbench_payload' )
			? dtb_admin_prepare_workbench_payload( [ 'meta' => [ 'module' => $module ] ] )
			: [];
		$errors = function_exists( 'dtb_admin_validate_workbench_payload' )
			? dtb_admin_validate_workbench_payload( $payload )
			: [ 'AdminWorkbenchContract unavailable.' ];

		$results[ $module ] = [
			'ok'       => empty( $errors ),
			'errors'   => $errors,
			'required' => function_exists( 'dtb_admin_workbench_contract_required_keys' ) ? dtb_admin_workbench_contract_required_keys() : [],
		];
	}

	return [
		'ok'      => ! in_array( false, wp_list_pluck( $results, 'ok' ), true ),
		'modules' => $results,
	];
}

/**
 * Live-region refresh handler for GET /dtb/v1/admin/system.
 *
 * Renders the active tab's content for the unified System Manager console
 * — both platform-health tabs and release-management tabs, delegating to
 * the same dtb_system_manager_dispatch_tab() the page itself uses so the
 * two code paths can never drift out of sync. Per-tab capability gating
 * happens inside that dispatcher.
 *
 * The response's meta.fast_poll reflects whether a release is *currently*
 * in progress (recomputed on every request), which dtb-system-manager.js
 * uses to speed up polling in real time — the page's own initial
 * dtb-live-fast CSS class only covers the very first paint.
 */
function dtb_system_manager_admin_system_handler( WP_REST_Request $request ): WP_REST_Response {
	$active_tab      = sanitize_key( $request->get_param( 'tab' ) ?: 'overview' );
	$can_system      = current_user_can( 'dtb_manage_system' );
	$can_deployments = current_user_can( 'dtb_manage_deployments' );

	ob_start();
	dtb_system_manager_dispatch_tab( $active_tab, $can_system, $can_deployments );
	$html = ob_get_clean();

	$fast_poll = $can_deployments
		&& function_exists( 'dtb_release_history_in_progress' )
		&& null !== dtb_release_history_in_progress();

	return new WP_REST_Response( [
		'ok'   => true,
		'html' => $html,
		'meta' => [
			'updated_at' => current_time( 'H:i:s' ),
			'fast_poll'  => $fast_poll,
		],
	], 200 );
}
