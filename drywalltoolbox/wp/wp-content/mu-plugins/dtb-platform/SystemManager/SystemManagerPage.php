<?php
/**
 * DTB Platform — SystemManagerPage
 *
 * Renders dtb-system-manager — the unified operations console for
 * technical admins. This is the single home for platform health,
 * configuration, diagnostics, and restricted recovery tools. Tabs:
 *
 *   Overview
 *   Platform Health:    System Info | Queues & Cron | Integrations | Webhooks
 *   Platform Tools:     Tools & Recovery
 *   Diagnostics:         Audit Log | Debug Log
 *
 * This console is live by default: dtb-system-manager.js polls the active
 * tab's content automatically (faster while a release is in progress) with
 * an operator-facing pause/resume control, rather than the manual-only
 * snapshot mode this page used previously.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tab groups, in display order. Used to build the tab bar and to drive the
 * page-scoped CSS group dividers in dtb-system-manager.css (first tab of
 * each group after the first carries a `data-dtb-group-start` marker).
 *
 * @return array<string, array{label:string, tabs: array<int, array{id:string, label:string, requires?:string}>}>
 */
function dtb_system_manager_tab_groups(): array {
	return [
		'primary' => [
			'label' => __( 'System Manager', 'drywall-toolbox' ),
			'tabs'  => [
				[ 'id' => 'overview', 'label' => __( 'Overview', 'drywall-toolbox' ) ],
				[ 'id' => 'operations', 'label' => __( 'Operations', 'drywall-toolbox' ) ],
				[ 'id' => 'live-updates', 'label' => __( 'Live Updates', 'drywall-toolbox' ) ],
				[ 'id' => 'more', 'label' => __( 'More', 'drywall-toolbox' ) ],
			],
		],
	];
}

function dtb_system_manager_render_page(): void {
	$can_system      = current_user_can( 'dtb_manage_system' );
	// Git-backed release administration is intentionally not exposed on the
	// SiteGround production control surface.
	$can_deployments = false;

	if ( ! $can_system && ! $can_deployments ) {
		dtb_admin_shell_access_denied();
		return;
	}

	$active_tab = sanitize_key( $_GET['tab'] ?? 'overview' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$base_url   = admin_url( 'admin.php?page=dtb-system-manager' );

	$tabs = [];
	foreach ( dtb_system_manager_tab_groups() as $group_key => $group ) {
		$first_in_group = true;
		foreach ( $group['tabs'] as $tab ) {
			$tabs[] = [
				'id'     => $tab['id'],
				'label'  => $tab['label'],
				'active' => $active_tab === $tab['id'] || ( 'more' === $tab['id'] && in_array( $active_tab, [ 'system', 'queues', 'integrations', 'webhooks', 'audit', 'logs', 'integration-settings', 'tools' ], true ) ),
				'url'    => add_query_arg( 'tab', $tab['id'], $base_url ),
				'group'  => $group_key,
				'group_start' => $first_in_group,
			];
			$first_in_group = false;
		}
	}

	$in_progress = ( $can_deployments && function_exists( 'dtb_release_history_in_progress' ) )
		? dtb_release_history_in_progress()
		: null;

	dtb_admin_shell_open( [
		'title'       => __( 'System Manager', 'drywall-toolbox' ),
		'subtitle'    => __( 'Unified platform health, queues, integrations, webhooks, configuration, and restricted recovery.', 'drywall-toolbox' ),
		'section'     => 'operations',
		'page'        => 'dtb-system-manager',
		'template'    => 'dashboard',
		'icon'        => 'dashicons-monitor',
		'tabs'        => $tabs,
		'live_target' => 'dtb-system-workspace',
	] );

	// Live region wraps all tab content panels. The dtb-live-fast class
	// signals dtb-system-manager.js to poll faster while a release is
	// actively running, and is refreshed on every live-region swap because
	// the server re-renders this open() call each time.
	dtb_admin_shell_live_region_open( [
		'id'       => 'dtb-system-workspace',
		'module'   => 'system-manager',
		'endpoint' => add_query_arg( 'tab', $active_tab, rest_url( 'dtb/v1/admin/system' ) ),
		'interval' => 60000,
		'class'    => $in_progress ? 'dtb-live-fast' : '',
	] );

	dtb_system_manager_dispatch_tab( $active_tab, $can_system, $can_deployments );

	dtb_admin_shell_live_region_close();

	if ( $can_deployments && function_exists( 'dtb_deployment_center_render_script' ) ) {
		dtb_deployment_center_render_script();
	}

	dtb_admin_shell_close();
}

/**
 * Dispatch to the correct tab renderer, enforcing per-tab capability where
 * a tab belongs to release management (dtb_manage_deployments) rather than
 * general platform health (dtb_manage_system).
 */
function dtb_system_manager_dispatch_tab( string $active_tab, bool $can_system, bool $can_deployments ): void {
	$deployment_tabs = [ 'deployment', 'repository', 'pull-requests', 'workflow-runs', 'releases', 'history', 'rollback', 'deploy-settings' ];

	if ( in_array( $active_tab, $deployment_tabs, true ) ) {
		if ( ! $can_deployments ) {
			dtb_system_manager_render_tab_access_denied( __( 'Release management requires the "Manage Deployments" capability.', 'drywall-toolbox' ) );
			return;
		}
		if ( ! function_exists( 'dtb_deployment_center_render_deployment_tab' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo dtb_admin_ui_alert( __( 'The dtb-deployment module is not deployed to this server.', 'drywall-toolbox' ), 'danger', __( 'Module Unavailable', 'drywall-toolbox' ) );
			return;
		}
		switch ( $active_tab ) {
			case 'deployment':
				dtb_deployment_center_render_deployment_tab();
				break;
			case 'repository':
				dtb_deployment_center_render_repository_tab();
				break;
			case 'pull-requests':
				dtb_deployment_center_render_pull_requests_tab();
				break;
			case 'workflow-runs':
				dtb_deployment_center_render_workflow_runs_tab();
				break;
			case 'releases':
				dtb_deployment_center_render_releases_tab();
				break;
			case 'history':
				dtb_deployment_center_render_history_tab();
				break;
			case 'rollback':
				dtb_deployment_center_render_rollback_tab();
				break;
			case 'deploy-settings':
				dtb_deployment_center_render_settings_tab();
				break;
		}
		return;
	}

	if ( ! $can_system ) {
		dtb_system_manager_render_tab_access_denied( __( 'Platform health requires the "Manage System" capability.', 'drywall-toolbox' ) );
		return;
	}

	switch ( $active_tab ) {
		case 'operations':
			dtb_system_manager_render_queues_tab();
			dtb_system_manager_render_integrations_tab();
			break;
		case 'more':
			dtb_system_manager_render_more_tab();
			break;
		case 'queues':
			dtb_system_manager_render_queues_tab();
			break;
		case 'integrations':
			dtb_system_manager_render_integrations_tab();
			break;
		case 'webhooks':
			dtb_system_manager_render_webhooks_tab();
			break;
		case 'live-updates':
			dtb_system_manager_render_live_updates_tab();
			break;
		case 'audit':
			dtb_system_manager_render_audit_tab();
			break;
		case 'logs':
			dtb_system_manager_render_logs_tab();
			break;
		case 'system':
			dtb_system_manager_render_system_tab();
			break;
		case 'integration-settings':
			dtb_system_manager_render_integration_settings_tab();
			break;
		case 'tools':
			dtb_system_manager_render_tools_tab();
			break;
		default:
			dtb_system_manager_render_overview_tab( $can_system, $can_deployments );
			break;
	}
}

/**
 * Secondary technical views remain available without occupying launch-critical
 * navigation space. Keep the original tab handlers and deep links intact.
 */
function dtb_system_manager_render_more_tab(): void {
	$views = [
		[ 'system', __( 'System Info', 'drywall-toolbox' ), __( 'WordPress and server environment.', 'drywall-toolbox' ) ],
		[ 'webhooks', __( 'Webhooks', 'drywall-toolbox' ), __( 'Webhook delivery and failures.', 'drywall-toolbox' ) ],
		[ 'audit', __( 'Audit Log', 'drywall-toolbox' ), __( 'Detailed administrative history.', 'drywall-toolbox' ) ],
		[ 'logs', __( 'Debug Log', 'drywall-toolbox' ), __( 'Troubleshooting when needed.', 'drywall-toolbox' ) ],
		[ 'integration-settings', __( 'Integration Settings', 'drywall-toolbox' ), __( 'Connector configuration.', 'drywall-toolbox' ) ],
		[ 'tools', __( 'Tools & Recovery', 'drywall-toolbox' ), __( 'Restricted administrative utilities.', 'drywall-toolbox' ) ],
	];
	echo '<div class="dtb-grid dtb-grid--two">';
	foreach ( $views as $view ) {
		$url = add_query_arg( [ 'page' => 'dtb-system-manager', 'tab' => $view[0] ], admin_url( 'admin.php' ) );
		echo '<section class="dtb-card"><div class="dtb-card__body">';
		echo '<h2 class="dtb-card__title"><a href="' . esc_url( $url ) . '">' . esc_html( $view[1] ) . '</a></h2>';
		echo '<p>' . esc_html( $view[2] ) . '</p>';
		echo '</div></section>';
	}
	echo '</div>';
}

/** Surface capability-protected platform tools without duplicating ownership. */
function dtb_system_manager_render_tools_tab(): void {
	$tools = [
		[ 'capability' => 'dtb_view_api_health', 'title' => __( 'API Health', 'drywall-toolbox' ), 'description' => __( 'Run bounded WordPress, WooCommerce, REST, authentication, and endpoint diagnostics.', 'drywall-toolbox' ), 'slug' => 'dtb-api-health' ],
		[ 'capability' => 'dtb_manage_cache_tools', 'title' => __( 'Cache', 'drywall-toolbox' ), 'description' => __( 'Inspect and invalidate DTB, WordPress, WooCommerce, SiteGround, and storefront caches.', 'drywall-toolbox' ), 'slug' => 'dtb-cache-tools' ],
		[ 'capability' => 'dtb_view_config_reference', 'title' => __( 'Configuration', 'drywall-toolbox' ), 'description' => __( 'Review the allowlisted DTB configuration and capability reference.', 'drywall-toolbox' ), 'slug' => 'dtb-config-reference' ],
		[ 'capability' => 'dtb_manage_system', 'title' => __( 'Restricted Recovery', 'drywall-toolbox' ), 'description' => __( 'Permanently remove explicitly selected operational records. Use only after backup and review.', 'drywall-toolbox' ), 'slug' => 'dtb-record-cleanup' ],
	];

	echo '<div class="dtb-grid dtb-grid--two">';
	foreach ( $tools as $tool ) {
		if ( ! current_user_can( $tool['capability'] ) ) {
			continue;
		}
		echo '<section class="dtb-card"><div class="dtb-card__body"><h2 class="dtb-card__title">' . esc_html( $tool['title'] ) . '</h2>';
		echo '<p>' . esc_html( $tool['description'] ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . $tool['slug'] ) ) . '">' . esc_html__( 'Open tool', 'drywall-toolbox' ) . '</a></p></div></section>';
	}
	echo '</div>';
}

function dtb_system_manager_render_tab_access_denied( string $message ): void {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_alert( esc_html( $message ), 'warning', __( 'Insufficient Permission', 'drywall-toolbox' ) );
}

// ── Overview (mission control) ──────────────────────────────────────────────

function dtb_system_manager_render_overview_tab( bool $can_system, bool $can_deployments ): void {
	$h    = $can_system ? dtb_system_health_get() : null;
	$q    = $can_system ? dtb_queue_health_get() : null;
	$ih   = $can_system ? dtb_integration_health_get() : null;
	$wh   = $can_system ? dtb_webhook_health_get() : null;
	$dep  = ( $can_deployments && function_exists( 'dtb_deployment_overview_get' ) ) ? dtb_deployment_overview_get() : null;

	$kpis = [];
	if ( $h ) {
		$kpis[] = [
			'value'      => $h['php_ok'] && $h['ssl_active'] ? __( 'Healthy', 'drywall-toolbox' ) : __( 'Attention', 'drywall-toolbox' ),
			'label'      => __( 'System', 'drywall-toolbox' ),
			'icon'       => 'dashicons-desktop',
			'icon_color' => $h['php_ok'] && $h['ssl_active'] ? 'success' : 'danger',
			'href'       => add_query_arg( 'tab', 'system', admin_url( 'admin.php?page=dtb-system-manager' ) ),
		];
	}
	if ( $q ) {
		$kpis[] = [
			'value'      => (string) ( $q['failed_recent_24h'] ?? 0 ),
			'label'      => __( 'Failed Queue Jobs', 'drywall-toolbox' ),
			'icon'       => 'dashicons-database',
			'icon_color' => ( $q['failed_recent_24h'] ?? 0 ) > 0 ? 'danger' : 'success',
			'href'       => add_query_arg( 'tab', 'queues', admin_url( 'admin.php?page=dtb-system-manager' ) ),
		];
	}
	if ( $ih ) {
		$missing = count( array_filter( $ih['integrations'], static fn( array $i ): bool => empty( $i['ok'] ) ) );
		$kpis[]  = [
			'value'      => sprintf( '%d/%d', count( $ih['integrations'] ) - $missing, count( $ih['integrations'] ) ),
			'label'      => __( 'Integrations Active', 'drywall-toolbox' ),
			'icon'       => 'dashicons-admin-plugins',
			'icon_color' => $missing > 0 ? 'warning' : 'success',
			'href'       => add_query_arg( 'tab', 'integrations', admin_url( 'admin.php?page=dtb-system-manager' ) ),
		];
	}
	if ( $wh ) {
		$kpis[] = [
			'value'      => (string) $wh['failing'],
			'label'      => __( 'Failing Webhooks', 'drywall-toolbox' ),
			'icon'       => 'dashicons-rest-api',
			'icon_color' => $wh['failing'] > 0 ? 'danger' : 'success',
			'href'       => add_query_arg( 'tab', 'webhooks', admin_url( 'admin.php?page=dtb-system-manager' ) ),
		];
	}
	if ( $dep ) {
		$current = $dep['current_production'];
		$drift   = $dep['drift'];
		$kpis[]  = [
			'value'      => $current ? substr( (string) $current['commit_sha'], 0, 7 ) : '—',
			'label'      => __( 'Deployed Commit', 'drywall-toolbox' ),
			'icon'       => 'dashicons-cloud-upload',
			'icon_color' => $current ? 'success' : 'neutral',
			'href'       => add_query_arg( 'tab', 'deployment', admin_url( 'admin.php?page=dtb-system-manager' ) ),
		];
		$kpis[]  = [
			'value'      => $drift['ok'] ? (string) $drift['ahead_by'] : '?',
			'label'      => __( 'Commits Ahead on GitHub', 'drywall-toolbox' ),
			'icon'       => 'dashicons-migrate',
			'icon_color' => $drift['ok'] && $drift['ahead_by'] > 0 ? 'warning' : 'success',
			'href'       => add_query_arg( 'tab', 'repository', admin_url( 'admin.php?page=dtb-system-manager' ) ),
		];
		$kpis[]  = [
			'value'      => $dep['in_progress'] ? dtb_release_status_label( $dep['in_progress']['status'] ) : __( 'Idle', 'drywall-toolbox' ),
			'label'      => __( 'Release Status', 'drywall-toolbox' ),
			'icon'       => 'dashicons-update',
			'icon_color' => $dep['in_progress'] ? 'processing' : 'success',
			'href'       => add_query_arg( 'tab', 'deployment', admin_url( 'admin.php?page=dtb-system-manager' ) ),
		];
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_kpi_grid( $kpis );

	if ( $dep && $dep['in_progress'] ) {
		ob_start();
		echo dtb_admin_ui_alert( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			sprintf(
				/* translators: 1: release id, 2: status label */
				__( 'Release %1$s is currently %2$s. This console is polling live.', 'drywall-toolbox' ),
				$dep['in_progress']['release_id'],
				dtb_release_status_label( $dep['in_progress']['status'] )
			),
			'warning',
			__( 'Release In Progress', 'drywall-toolbox' )
		);
		echo dtb_deployment_center_render_timeline( $dep['in_progress']['events'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$body = ob_get_clean();
		echo dtb_admin_ui_card( $body, [ 'title' => __( 'Live Release Progress', 'drywall-toolbox' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	echo '<div class="dtb-grid dtb-grid--two dtb-sm-columns">';

	// Attention Needed.
	$attention = dtb_system_manager_collect_attention( $h, $q, $ih, $wh, $dep );
	ob_start();
	if ( empty( $attention ) ) {
		echo dtb_admin_ui_empty_state( __( 'All Systems Nominal', 'drywall-toolbox' ), __( 'No open issues across platform health, queues, integrations, webhooks, or release management.', 'drywall-toolbox' ), [ 'icon' => 'dashicons-yes-alt' ] );
	} else {
		echo '<ul class="dtb-sm-attention-list">';
		foreach ( $attention as $item ) {
			printf(
				'<li class="dtb-sm-attention-row"><a href="%s">%s<span class="dtb-sm-attention-text">%s</span></a></li>',
				esc_url( $item['href'] ),
				dtb_admin_ui_badge( $item['label'], $item['type'] ),
				esc_html( $item['text'] )
			);
		}
		echo '</ul>';
	}
	$body = ob_get_clean();
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Attention Needed', 'drywall-toolbox' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	// Recent Activity — merged audit log + release events.
	ob_start();
	$activity = dtb_system_manager_collect_recent_activity( $can_system, $can_deployments, 12 );
	if ( empty( $activity ) ) {
		echo dtb_admin_ui_empty_state( __( 'No Recent Activity', 'drywall-toolbox' ) );
	} else {
		echo dtb_admin_ui_timeline( $activity );
	}
	$body = ob_get_clean();
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Recent Activity', 'drywall-toolbox' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	echo '</div>';
}

/**
 * Aggregate cross-subsystem warnings for the "Attention Needed" panel.
 *
 * @return array<int, array{label:string, type:string, text:string, href:string}>
 */
function dtb_system_manager_collect_attention( ?array $h, ?array $q, ?array $ih, ?array $wh, ?array $dep ): array {
	$base = admin_url( 'admin.php?page=dtb-system-manager' );
	$out  = [];

	if ( $h && ( ! $h['php_ok'] || ! $h['ssl_active'] ) ) {
		$out[] = [ 'label' => __( 'System', 'drywall-toolbox' ), 'type' => 'danger', 'text' => __( 'PHP version or SSL requires attention.', 'drywall-toolbox' ), 'href' => add_query_arg( 'tab', 'system', $base ) ];
	}
	if ( $q && ( $q['failed_recent_24h'] ?? 0 ) > 0 ) {
		$out[] = [ 'label' => __( 'Queue', 'drywall-toolbox' ), 'type' => 'danger', 'text' => sprintf( _n( '%d queue job failed in the past 24 hours.', '%d queue jobs failed in the past 24 hours.', $q['failed_recent_24h'], 'drywall-toolbox' ), $q['failed_recent_24h'] ), 'href' => add_query_arg( 'tab', 'queues', $base ) ];
	}
	if ( $ih ) {
		foreach ( $ih['integrations'] as $item ) {
			if ( empty( $item['ok'] ) ) {
				$out[] = [ 'label' => __( 'Integration', 'drywall-toolbox' ), 'type' => 'warning', 'text' => sprintf( /* translators: %s: integration name */ __( '%s is not active.', 'drywall-toolbox' ), $item['name'] ), 'href' => add_query_arg( 'tab', 'integrations', $base ) ];
			}
		}
	}
	if ( $wh && $wh['failing'] > 0 ) {
		$out[] = [ 'label' => __( 'Webhook', 'drywall-toolbox' ), 'type' => 'danger', 'text' => sprintf( _n( '%d webhook is failing.', '%d webhooks are failing.', $wh['failing'], 'drywall-toolbox' ), $wh['failing'] ), 'href' => add_query_arg( 'tab', 'webhooks', $base ) ];
	}
	if ( $dep ) {
		if ( ! $dep['github_enabled'] || ! $dep['webhook_enabled'] ) {
			$out[] = [ 'label' => __( 'Release', 'drywall-toolbox' ), 'type' => 'warning', 'text' => __( 'GitHub dispatch or webhook reporting is not configured.', 'drywall-toolbox' ), 'href' => add_query_arg( 'tab', 'deploy-settings', $base ) ];
		}
		if ( $dep['drift']['ok'] && $dep['drift']['ahead_by'] > 0 ) {
			$out[] = [ 'label' => __( 'Release', 'drywall-toolbox' ), 'type' => 'warning', 'text' => sprintf( /* translators: %d: commit count */ _n( '%d commit on GitHub is not yet deployed.', '%d commits on GitHub are not yet deployed.', (int) $dep['drift']['ahead_by'], 'drywall-toolbox' ), (int) $dep['drift']['ahead_by'] ), 'href' => add_query_arg( 'tab', 'repository', $base ) ];
		}
		if ( $dep['in_progress'] && in_array( $dep['in_progress']['status'], [ 'failed', 'rollback_failed' ], true ) ) {
			$out[] = [ 'label' => __( 'Release', 'drywall-toolbox' ), 'type' => 'danger', 'text' => sprintf( /* translators: %s: release id */ __( 'Release %s needs operator attention.', 'drywall-toolbox' ), $dep['in_progress']['release_id'] ), 'href' => add_query_arg( 'tab', 'history', $base ) ];
		}
	}

	return $out;
}

/**
 * Merge recent audit-log entries and release events into one
 * chronologically sorted activity feed for the Overview timeline.
 *
 * @return array<int, array{time:string, title:string, desc:string, badge:string}>
 */
function dtb_system_manager_collect_recent_activity( bool $can_system, bool $can_deployments, int $limit ): array {
	$items = [];

	if ( $can_system && function_exists( 'dtb_audit_log_get_recent' ) ) {
		foreach ( dtb_audit_log_get_recent( $limit ) as $e ) {
			$ts = (string) ( $e['ts'] ?? '' );
			if ( '' === $ts ) {
				continue;
			}
			$ts_unix = strtotime( $ts . ' UTC' ) ?: 0;
			$items[] = [
				'sort'  => $ts_unix,
				'time'  => sprintf( /* translators: %s: human-readable time difference */ __( '%s ago', 'drywall-toolbox' ), human_time_diff( $ts_unix ) ),
				'title' => (string) ( $e['summary'] ?? $e['action'] ?? '' ),
				'desc'  => '<code>' . esc_html( sanitize_key( (string) ( $e['module'] ?? '' ) ) ) . '</code>',
				'badge' => 'muted',
			];
		}
	}

	if ( $can_deployments && function_exists( 'dtb_release_history_get' ) ) {
		foreach ( dtb_release_history_get( 8 ) as $release ) {
			foreach ( array_slice( $release['events'], -3 ) as $event ) {
				$ts = (string) ( $event['created_at'] ?? '' );
				if ( '' === $ts ) {
					continue;
				}
				$items[] = [
					'sort'  => strtotime( $ts . ' UTC' ) ?: 0,
					'time'  => dtb_time_ago( $ts, true ),
					'title' => sprintf( '%s — %s', $release['release_id'], dtb_release_status_label( dtb_release_status_for_events( [ (string) $event['event_type'] ] ) ) ),
					'desc'  => '<code>' . esc_html( (string) $event['event_type'] ) . '</code>',
					'badge' => str_contains( (string) $event['event_type'], 'failed' ) ? 'danger' : 'primary',
				];
			}
		}
	}

	usort( $items, static fn( array $a, array $b ): int => $b['sort'] <=> $a['sort'] );

	return array_slice( $items, 0, $limit );
}

// ── Platform health tab renderers ───────────────────────────────────────────

function dtb_system_manager_render_system_tab(): void {
	$h = dtb_system_health_get();

	ob_start();
	echo dtb_admin_ui_detail_row( __( 'PHP Version', 'drywall-toolbox' ),
		dtb_admin_ui_badge( esc_html( $h['php_version'] ), $h['php_ok'] ? 'success' : 'danger' ) );
	echo dtb_admin_ui_detail_row( __( 'WordPress Version', 'drywall-toolbox' ), esc_html( $h['wp_version'] ) );
	echo dtb_admin_ui_detail_row( __( 'Memory Limit', 'drywall-toolbox' ),      esc_html( $h['memory_limit'] ) );
	echo dtb_admin_ui_detail_row( __( 'Max Execution', 'drywall-toolbox' ),     esc_html( $h['max_execution_time'] . 's' ) );
	echo dtb_admin_ui_detail_row( __( 'Upload Max Size', 'drywall-toolbox' ),   esc_html( $h['upload_max_filesize'] ) );
	echo dtb_admin_ui_detail_row( __( 'SSL Active', 'drywall-toolbox' ),        dtb_admin_ui_badge( $h['ssl_active'] ? __( 'Yes', 'drywall-toolbox' ) : __( 'No', 'drywall-toolbox' ), $h['ssl_active'] ? 'success' : 'danger' ) );
	echo dtb_admin_ui_detail_row( __( 'WP Debug', 'drywall-toolbox' ),          dtb_admin_ui_badge( $h['wp_debug'] ? 'ON' : 'OFF', $h['wp_debug'] ? 'warning' : 'neutral' ) );
	echo dtb_admin_ui_detail_row( __( 'WP Debug Log', 'drywall-toolbox' ),      dtb_admin_ui_badge( $h['wp_debug_log'] ? 'ON' : 'OFF', $h['wp_debug_log'] ? 'warning' : 'neutral' ) );
	echo dtb_admin_ui_detail_row( __( 'Timezone', 'drywall-toolbox' ),          esc_html( $h['timezone'] ) );
	echo dtb_admin_ui_detail_row( __( 'Site URL', 'drywall-toolbox' ),          esc_html( $h['site_url'] ) );
	$body = ob_get_clean();

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'PHP & WordPress Environment', 'drywall-toolbox' ) ] );

	$php_log = (array) ( $h['php_log'] ?? [] );
	$rest    = (array) ( $h['rest'] ?? [] );
	$cache   = (array) ( $h['cache'] ?? [] );
	$proj    = (array) ( $h['projections'] ?? [] );

	ob_start();
	echo dtb_admin_ui_detail_row( __( 'PHP Log', 'drywall-toolbox' ), dtb_admin_ui_badge( ! empty( $php_log['available'] ) ? __( 'Available', 'drywall-toolbox' ) : __( 'Unavailable', 'drywall-toolbox' ), ! empty( $php_log['available'] ) ? 'success' : 'neutral' ) );
	echo dtb_admin_ui_detail_row( __( 'PHP Log Size', 'drywall-toolbox' ), esc_html( size_format( (int) ( $php_log['size_bytes'] ?? 0 ) ) ) );
	echo dtb_admin_ui_detail_row( __( 'DTB REST Routes', 'drywall-toolbox' ), dtb_admin_ui_badge( (string) ( $rest['dtb_routes'] ?? 0 ), ( (int) ( $rest['dtb_routes'] ?? 0 ) > 0 ) ? 'success' : 'warning' ) );
	echo dtb_admin_ui_detail_row( __( 'Expired DTB Cache Entries', 'drywall-toolbox' ), dtb_admin_ui_badge( (string) ( $cache['expired_dtb_transients'] ?? 0 ), ( (int) ( $cache['expired_dtb_transients'] ?? 0 ) > 25 ) ? 'warning' : 'neutral' ) );
	echo dtb_admin_ui_detail_row( __( 'Stale Projections', 'drywall-toolbox' ), dtb_admin_ui_badge( (string) ( $proj['stale'] ?? 0 ), ( (int) ( $proj['stale'] ?? 0 ) > 0 ) ? 'warning' : 'success' ) );
	$ops_body = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $ops_body, [ 'title' => __( 'Diagnostics Summary', 'drywall-toolbox' ) ] );

	$catalog    = (array) ( $h['catalog'] ?? [] );
	$media      = (array) ( $h['media'] ?? [] );
	$schematics = (array) ( $h['schematics'] ?? [] );
	ob_start();
	echo dtb_admin_ui_detail_row( __( 'Catalog Services', 'drywall-toolbox' ), dtb_admin_ui_badge( ! empty( $catalog['dtb_catalog_available'] ) ? __( 'Available', 'drywall-toolbox' ) : __( 'Missing', 'drywall-toolbox' ), ! empty( $catalog['dtb_catalog_available'] ) ? 'success' : 'warning' ) );
	echo dtb_admin_ui_detail_row( __( 'Uploads Writable', 'drywall-toolbox' ), dtb_admin_ui_badge( ! empty( $media['uploads_writable'] ) ? __( 'Yes', 'drywall-toolbox' ) : __( 'No', 'drywall-toolbox' ), ! empty( $media['uploads_writable'] ) ? 'success' : 'danger' ) );
	echo dtb_admin_ui_detail_row( __( 'Image Sync', 'drywall-toolbox' ), dtb_admin_ui_badge( ! empty( $media['image_sync_available'] ) ? __( 'Available', 'drywall-toolbox' ) : __( 'Missing', 'drywall-toolbox' ), ! empty( $media['image_sync_available'] ) ? 'success' : 'neutral' ) );
	echo dtb_admin_ui_detail_row( __( 'Schematics', 'drywall-toolbox' ), dtb_admin_ui_badge( ! empty( $schematics['schematics_available'] ) ? __( 'Available', 'drywall-toolbox' ) : __( 'Missing', 'drywall-toolbox' ), ! empty( $schematics['schematics_available'] ) ? 'success' : 'warning' ) );
	$library_body = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $library_body, [ 'title' => __( 'Catalog, Media & Schematics', 'drywall-toolbox' ) ] );
}

function dtb_system_manager_render_queues_tab(): void {
	$q = dtb_queue_health_get();
	$c = dtb_cron_health_get();

	// Action Scheduler.
	ob_start();
	echo dtb_admin_ui_detail_row( __( 'Pending', 'drywall-toolbox' ),   dtb_admin_ui_badge( (string) $q['pending'], $q['pending'] > 50 ? 'warning' : 'neutral' ) );
	echo dtb_admin_ui_detail_row( __( 'Running', 'drywall-toolbox' ),   dtb_admin_ui_badge( (string) $q['running'], 'processing' ) );
	echo dtb_admin_ui_detail_row( __( 'Failed — Last 24 Hours', 'drywall-toolbox' ), dtb_admin_ui_badge( (string) ( $q['failed_recent_24h'] ?? 0 ), ( $q['failed_recent_24h'] ?? 0 ) > 0 ? 'danger' : 'success' ) );
	echo dtb_admin_ui_detail_row( __( 'Failed — All Retained History', 'drywall-toolbox' ), esc_html( number_format_i18n( $q['failed'] ) ) );
	echo dtb_admin_ui_detail_row( __( 'Completed', 'drywall-toolbox' ), esc_html( number_format( $q['complete'] ) ) );
	echo dtb_admin_ui_detail_row( __( 'Failed Notification Jobs', 'drywall-toolbox' ), dtb_admin_ui_badge( (string) ( $q['failed_notification_jobs'] ?? 0 ), ( (int) ( $q['failed_notification_jobs'] ?? 0 ) > 0 ) ? 'danger' : 'success' ) );
	if ( $q['oldest_pending_seconds'] > 0 ) {
		$mins = round( $q['oldest_pending_seconds'] / 60 );
		echo dtb_admin_ui_detail_row( __( 'Oldest Pending', 'drywall-toolbox' ), dtb_admin_ui_badge( "{$mins}m ago", $mins > 30 ? 'danger' : 'neutral' ) );
	}
	foreach ( array_slice( (array) ( $q['failed_notification_hooks'] ?? [] ), 0, 5 ) as $hook ) {
		echo dtb_admin_ui_detail_row( esc_html( (string) ( $hook['hook'] ?? '' ) ), dtb_admin_ui_badge( (string) ( $hook['count'] ?? 0 ), 'danger' ) );
	}
	// Recent health and retained failure attribution are deliberately separate.
	echo dtb_admin_ui_detail_row( __( 'DTB Failed — Last 24 Hours', 'drywall-toolbox' ), dtb_admin_ui_badge( (string) ( $q['dtb_failed_24h'] ?? 0 ), ( $q['dtb_failed_24h'] ?? 0 ) > 0 ? 'danger' : 'success' ) );
	foreach ( (array) ( $q['failed_age_buckets'] ?? [] ) as $bucket ) {
		echo dtb_admin_ui_detail_row( esc_html( 'Failure age: ' . (string) ( $bucket['age'] ?? '' ) ), esc_html( number_format_i18n( (int) ( $bucket['count'] ?? 0 ) ) ) );
	}
	foreach ( array_slice( (array) ( $q['failed_by_group'] ?? [] ), 0, 12 ) as $group ) {
		echo dtb_admin_ui_detail_row( esc_html( 'Group: ' . (string) ( $group['group_name'] ?? '' ) ), esc_html( number_format_i18n( (int) ( $group['count'] ?? 0 ) ) . ' retained · ' . (int) ( $group['recent_24h'] ?? 0 ) . ' recent' ) );
	}
	foreach ( array_slice( (array) ( $q['failed_by_hook'] ?? [] ), 0, 20 ) as $hook ) {
		echo dtb_admin_ui_detail_row( esc_html( 'Hook: ' . (string) ( $hook['hook'] ?? '' ) ), esc_html( number_format_i18n( (int) ( $hook['count'] ?? 0 ) ) . ' retained · ' . (int) ( $hook['recent_24h'] ?? 0 ) . ' recent' ) );
	}
	$q_body = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $q_body, [ 'title' => __( 'Action Scheduler Queue', 'drywall-toolbox' ) ] );

	// WP-Cron.
	ob_start();
	echo dtb_admin_ui_detail_row( __( 'WP-Cron Enabled', 'drywall-toolbox' ), dtb_admin_ui_badge( $c['wp_cron_active'] ? __( 'Yes', 'drywall-toolbox' ) : __( 'No', 'drywall-toolbox' ), $c['wp_cron_active'] ? 'success' : 'warning' ) );
	echo dtb_admin_ui_detail_row( __( 'Overdue Events', 'drywall-toolbox' ),   dtb_admin_ui_badge( (string) $c['overdue_count'], $c['overdue_count'] > 0 ? 'danger' : 'success' ) );
	foreach ( array_slice( $c['overdue'], 0, 5 ) as $ev ) {
		$mins = round( $ev['overdue_s'] / 60 );
		echo dtb_admin_ui_detail_row( esc_html( $ev['hook'] ), dtb_admin_ui_badge( "{$mins}m overdue", 'danger' ) );
	}
	$c_body = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $c_body, [ 'title' => __( 'WP-Cron', 'drywall-toolbox' ) ] );
}

function dtb_system_manager_render_integrations_tab(): void {
	$ih = dtb_integration_health_get();

	ob_start();
	echo dtb_admin_ui_table_open( [
		[ 'label' => __( 'Integration', 'drywall-toolbox' ), 'key' => 'name' ],
		[ 'label' => __( 'Status', 'drywall-toolbox' ),      'key' => 'ok' ],
		[ 'label' => __( 'Version', 'drywall-toolbox' ),     'key' => 'version' ],
	], [] );
	foreach ( $ih['integrations'] as $item ) {
		echo '<tr>';
		echo '<td>' . esc_html( $item['name'] ) . '</td>';
		echo '<td>' . dtb_admin_ui_badge( $item['ok'] ? __( 'Active', 'drywall-toolbox' ) : __( 'Missing', 'drywall-toolbox' ), $item['ok'] ? 'success' : 'danger' ) . '</td>';
		echo '<td>' . esc_html( $item['version'] ) . '</td>';
		echo '</tr>';
	}
	echo dtb_admin_ui_table_close();
	$body = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Integration Health', 'drywall-toolbox' ) ] );
}

function dtb_system_manager_render_webhooks_tab(): void {
	$wh = dtb_webhook_health_get();

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_kpi_grid( [
		[ 'value' => $wh['total'],   'label' => __( 'Total Webhooks', 'drywall-toolbox' ) ],
		[ 'value' => $wh['active'],  'label' => __( 'Active', 'drywall-toolbox' ), 'icon_color' => 'success' ],
		[ 'value' => $wh['failing'], 'label' => __( 'Failing', 'drywall-toolbox' ), 'icon_color' => $wh['failing'] > 0 ? 'danger' : 'success' ],
	] );

	ob_start();
	echo dtb_admin_ui_table_open( [
		[ 'label' => __( 'Name', 'drywall-toolbox' ),          'key' => 'name' ],
		[ 'label' => __( 'Topic', 'drywall-toolbox' ),         'key' => 'topic' ],
		[ 'label' => __( 'Status', 'drywall-toolbox' ),        'key' => 'status' ],
		[ 'label' => __( 'Failures', 'drywall-toolbox' ),      'key' => 'failure_count' ],
	], [] );
	foreach ( $wh['webhooks'] as $w ) {
		echo '<tr>';
		echo '<td>' . esc_html( $w['name'] ) . '</td>';
		echo '<td>' . esc_html( $w['topic'] ) . '</td>';
		echo '<td>' . dtb_admin_ui_badge( esc_html( $w['status'] ), $w['status'] === 'active' ? 'success' : 'neutral' ) . '</td>';
		echo '<td>' . dtb_admin_ui_badge( (string) $w['failure_count'], $w['failure_count'] > 0 ? 'danger' : 'neutral' ) . '</td>';
		echo '</tr>';
	}
	echo dtb_admin_ui_table_close();
	$body = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Registered Webhooks', 'drywall-toolbox' ) ] );
}

/**
 * Read-only, automatically refreshed activity view. MCP writes are recorded in
 * dtb_mcp_admin_audit; GitHub release events appear only when already recorded.
 * This is a bounded audit feed, not a filesystem watcher or a deployment claim.
 */
function dtb_system_manager_render_live_updates_tab(): void {
	if ( ! current_user_can( 'dtb_manage_system' ) ) {
		dtb_system_manager_render_tab_access_denied( __( 'System access required.', 'drywall-toolbox' ) );
		return;
	}

	$audit = get_option( 'dtb_mcp_admin_audit', [] );
	$audit = is_array( $audit ) ? array_slice( $audit, 0, 100 ) : [];
	$actions = [ 'write-file', 'create-file', 'delete-file', 'restore-backup', 'set-plugin-state', 'switch-theme' ];
	$entries = [];
	foreach ( $audit as $entry ) {
		if ( ! is_array( $entry ) || ! in_array( (string) ( $entry['action'] ?? '' ), $actions, true ) ) {
			continue;
		}
		$context = is_array( $entry['context'] ?? null ) ? $entry['context'] : [];
		$path = (string) ( $context['path'] ?? $context['plugin'] ?? $context['stylesheet'] ?? '' );
		$entries[] = [
			'time'   => (string) ( $entry['timestamp_gmt'] ?? '' ),
			'action' => (string) $entry['action'],
			'path'   => $path,
			'root'   => (string) ( $context['root'] ?? '' ),
			'actor'  => absint( $entry['user_id'] ?? 0 ),
			'backup' => (string) ( $context['backup_id'] ?? '' ),
			'source' => 'MCP',
		];
	}

	// Release events are separate evidence, not implied by an MCP file write.
	if ( function_exists( 'dtb_release_history_get' ) ) {
		foreach ( dtb_release_history_get( 15 ) as $release ) {
			if ( ! is_array( $release ) ) {
				continue;
			}
			$entries[] = [
				'time'   => (string) ( $release['updated_at'] ?? $release['started_at'] ?? '' ),
				'action' => (string) ( $release['status_label'] ?? 'Release event' ),
				'path'   => (string) ( $release['release_id'] ?? '' ),
				'root'   => '',
				'actor'  => 0,
				'backup' => '',
				'source' => 'GitHub release record',
			];
		}
	}
	usort( $entries, static fn( $a, $b ) => strcmp( $b['time'], $a['time'] ) );
	$entries = array_slice( $entries, 0, 40 );

	ob_start();
	echo '<p>' . esc_html__( 'Recent recorded server changes. Refreshes automatically while this tab is open. Changes outside MCP or recorded release workflows may not appear.', 'drywall-toolbox' ) . '</p>';
	if ( empty( $entries ) ) {
		echo '<p>' . esc_html__( 'No recorded file updates yet.', 'drywall-toolbox' ) . '</p>';
	} else {
		echo dtb_admin_ui_table_open( [
			[ 'label' => __( 'When', 'drywall-toolbox' ), 'key' => 'time' ],
			[ 'label' => __( 'Update', 'drywall-toolbox' ), 'key' => 'update' ],
			[ 'label' => __( 'Origin', 'drywall-toolbox' ), 'key' => 'source' ],
		], [] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$action_labels = [
			'write-file'       => __( 'Updated', 'drywall-toolbox' ),
			'create-file'      => __( 'Created', 'drywall-toolbox' ),
			'delete-file'      => __( 'Deleted', 'drywall-toolbox' ),
			'restore-backup'   => __( 'Restored', 'drywall-toolbox' ),
			'set-plugin-state' => __( 'Plugin changed', 'drywall-toolbox' ),
			'switch-theme'     => __( 'Theme changed', 'drywall-toolbox' ),
		];
		foreach ( $entries as $entry ) {
			$location = trim( $entry['root'] . '/' . $entry['path'], '/' );
			$timestamp = strtotime( $entry['time'] );
			$when = $timestamp ? wp_date( 'M j, g:i A T', $timestamp, new DateTimeZone( 'America/Chicago' ) ) : $entry['time'];
			$name = basename( $location );
			$description = $action_labels[ $entry['action'] ] ?? $entry['action'];
			echo '<tr><td style="white-space:nowrap">' . esc_html( $when ) . '</td>';
			echo '<td><strong>' . esc_html( $description ) . '</strong> &middot; <code title="' . esc_attr( $location ) . '">' . esc_html( $name ) . '</code>';
			echo '<div style="font-size:12px;color:#667085;overflow-wrap:anywhere">' . esc_html( $location ) . '</div>';
			if ( '' !== $entry['backup'] ) {
				echo '<small>' . esc_html__( 'Recovery copy available', 'drywall-toolbox' ) . '</small>';
			}
			echo '</td><td>' . esc_html( $entry['source'] ) . '</td></tr>';
		}
		echo dtb_admin_ui_table_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	$body = ob_get_clean();
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Live Updates', 'drywall-toolbox' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

function dtb_system_manager_render_audit_tab(): void {
	$entries = dtb_audit_log_get_recent( 50 );

	if ( empty( $entries ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo dtb_admin_ui_empty_state( __( 'No Audit Events', 'drywall-toolbox' ), __( 'Audit entries will appear here as admin actions are taken.', 'drywall-toolbox' ) );
		return;
	}

	ob_start();
	echo dtb_admin_ui_table_open( [
		[ 'label' => __( 'Time (UTC)', 'drywall-toolbox' ), 'key' => 'ts' ],
		[ 'label' => __( 'Module', 'drywall-toolbox' ),     'key' => 'module' ],
		[ 'label' => __( 'Record', 'drywall-toolbox' ),     'key' => 'record_id' ],
		[ 'label' => __( 'User', 'drywall-toolbox' ),       'key' => 'user_id' ],
		[ 'label' => __( 'Action', 'drywall-toolbox' ),     'key' => 'action' ],
		[ 'label' => __( 'Source', 'drywall-toolbox' ),     'key' => 'source' ],
	], [] );
	foreach ( $entries as $e ) {
		$user_id = absint( $e['user_id'] ?? 0 );
		$user    = $user_id ? get_userdata( $user_id ) : false;
		$actor   = ! empty( $e['actor_label'] ) ? (string) $e['actor_label'] : ( $user ? $user->display_name : ( $user_id ? '#' . $user_id : __( 'System', 'drywall-toolbox' ) ) );
		$module  = sanitize_key( (string) ( $e['module'] ?? '' ) );
		$record  = absint( $e['record_id'] ?? 0 );
		$summary = (string) ( $e['summary'] ?? $e['action'] ?? '' );

		echo '<tr>';
		echo '<td>' . esc_html( (string) ( $e['ts'] ?? '' ) ) . '</td>';
		echo '<td>' . ( $module ? esc_html( $module ) : '&mdash;' ) . '</td>';
		echo '<td>' . ( $record ? esc_html( '#' . $record ) : '&mdash;' ) . '</td>';
		echo '<td>' . esc_html( $actor ) . '</td>';
		echo '<td><strong>' . esc_html( $summary ) . '</strong><br><code>' . esc_html( (string) ( $e['action'] ?? '' ) ) . '</code></td>';
		echo '<td>' . esc_html( (string) ( $e['source'] ?? '' ) ) . '</td>';
		echo '</tr>';
	}
	echo dtb_admin_ui_table_close();
	$body = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Recent Audit Events', 'drywall-toolbox' ) ] );
}

function dtb_system_manager_render_logs_tab(): void {
	$log = dtb_system_php_log_tail( 200 );

	ob_start();
	echo dtb_admin_ui_detail_row(
		__( 'Status', 'drywall-toolbox' ),
		dtb_admin_ui_badge(
			! empty( $log['available'] ) ? __( 'Readable', 'drywall-toolbox' ) : __( 'Unavailable', 'drywall-toolbox' ),
			! empty( $log['available'] ) ? 'success' : 'warning'
		)
	);
	echo dtb_admin_ui_detail_row( __( 'Path', 'drywall-toolbox' ), $log['path'] ? '<code>' . esc_html( $log['path'] ) . '</code>' : '&mdash;' );
	echo dtb_admin_ui_detail_row( __( 'Size', 'drywall-toolbox' ), esc_html( size_format( (int) ( $log['size_bytes'] ?? 0 ) ) ) );
	echo dtb_admin_ui_detail_row( __( 'Modified', 'drywall-toolbox' ), $log['modified_gmt'] ? esc_html( $log['modified_gmt'] ) : '&mdash;' );
	$summary = ob_get_clean();

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $summary, [ 'title' => __( 'Debug Log Source', 'drywall-toolbox' ) ] );

	if ( empty( $log['available'] ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo dtb_admin_ui_empty_state(
			__( 'Debug Log Unavailable', 'drywall-toolbox' ),
			esc_html( (string) ( $log['error'] ?? __( 'Enable WP_DEBUG_LOG or make the configured log path readable.', 'drywall-toolbox' ) ) )
		);
		return;
	}

	$lines = (array) ( $log['lines'] ?? [] );
	if ( empty( $lines ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo dtb_admin_ui_empty_state( __( 'Debug Log Empty', 'drywall-toolbox' ), __( 'No log entries were found in the readable log file.', 'drywall-toolbox' ) );
		return;
	}

	$body = '<pre class="dtb-system-log-tail" style="max-height:560px;overflow:auto;white-space:pre-wrap;word-break:break-word;margin:0;font-size:12px;line-height:1.55;">'
		. esc_html( implode( "\n", $lines ) )
		. '</pre>';

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Latest Log Entries', 'drywall-toolbox' ) ] );
}
