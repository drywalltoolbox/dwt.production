<?php
/**
 * Rest — DeploymentAdminController
 *
 * Release-management admin API consumed by the unified System Manager
 * console (Deployment/Release History/Rollback/Deploy Settings tabs).
 * Repository browsing endpoints (Repository, Pull Requests, Workflow Runs,
 * Releases & Tags tabs) live in GitControlCenterController.php. The
 * live-region tab-refresh endpoint for the merged console is
 * /dtb/v1/admin/system (dtb-platform/SystemManager/Rest/SystemManagerController.php),
 * which dispatches to this module's tab renderers for release-management
 * tabs — there is no separate live-region endpoint here.
 *
 * All routes require the dtb_manage_deployments capability. Dispatch
 * endpoints never touch SiteGround directly — they only call GitHub's
 * workflow_dispatch API; the actual release execution and its secrets stay
 * entirely inside .github/workflows/release-siteground.yml.
 *
 * Endpoints:
 *   GET  /dtb/v1/deployment/overview
 *   GET  /dtb/v1/deployment/history
 *   GET  /dtb/v1/deployment/history/{release_id}
 *   GET  /dtb/v1/deployment/policy
 *   POST /dtb/v1/deployment/dispatch/deploy
 *   POST /dtb/v1/deployment/dispatch/rollback
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_deployment_admin_register_routes' );

function dtb_deployment_admin_register_routes(): void {
	$cap = fn() => current_user_can( 'dtb_manage_deployments' );

	register_rest_route( 'dtb/v1', '/deployment/overview', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => fn() => new WP_REST_Response( dtb_deployment_overview_get(), 200 ),
		'permission_callback' => $cap,
	] );

	register_rest_route( 'dtb/v1', '/deployment/history', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => function ( WP_REST_Request $r ) {
			$limit = max( 1, min( 200, (int) ( $r->get_param( 'limit' ) ?? 50 ) ) );
			return new WP_REST_Response( [ 'releases' => dtb_release_history_get( $limit ) ], 200 );
		},
		'permission_callback' => $cap,
	] );

	register_rest_route( 'dtb/v1', '/deployment/history/(?P<release_id>[a-z0-9\-]+)', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => function ( WP_REST_Request $r ) {
			$release = dtb_release_history_get_one( sanitize_text_field( (string) $r->get_param( 'release_id' ) ) );
			if ( null === $release ) {
				return new WP_Error( 'dtb_deployment_release_not_found', 'Release not found.', [ 'status' => 404 ] );
			}
			return new WP_REST_Response( $release, 200 );
		},
		'permission_callback' => $cap,
	] );

	register_rest_route( 'dtb/v1', '/deployment/policy', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => fn() => new WP_REST_Response( [
			'owned_paths'     => dtb_deployment_owned_paths(),
			'protected_paths' => dtb_deployment_protected_paths(),
			'github_enabled'  => dtb_deployment_github_enabled(),
			'webhook_enabled' => dtb_deployment_webhook_configured(),
		], 200 ),
		'permission_callback' => $cap,
	] );

	register_rest_route( 'dtb/v1', '/deployment/dispatch/deploy', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'dtb_deployment_route_dispatch_deploy',
		'permission_callback' => $cap,
	] );

	register_rest_route( 'dtb/v1', '/deployment/dispatch/rollback', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'dtb_deployment_route_dispatch_rollback',
		'permission_callback' => $cap,
	] );
}

/**
 * Overview payload: current production release, in-progress release, and
 * GitHub/production drift.
 *
 * @return array<string,mixed>
 */
function dtb_deployment_overview_get(): array {
	$current_production = dtb_release_history_current_production();

	return [
		'current_production' => $current_production,
		'in_progress'        => dtb_release_history_in_progress(),
		'drift'               => dtb_release_history_drift( $current_production ),
		'github_enabled'      => dtb_deployment_github_enabled(),
		'webhook_enabled'     => dtb_deployment_webhook_configured(),
		'recent_workflow_runs' => dtb_deployment_github_enabled()
			? dtb_deployment_github_recent_workflow_runs( 5 )['runs']
			: [],
	];
}

function dtb_deployment_route_dispatch_deploy( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$ref     = sanitize_text_field( (string) ( $request->get_param( 'ref' ) ?? '' ) );
	$confirm = sanitize_text_field( (string) ( $request->get_param( 'confirm' ) ?? '' ) );

	$valid = dtb_deployment_validate_deploy_request( $ref, $confirm );
	if ( is_wp_error( $valid ) ) {
		return new WP_REST_Response( dtb_error_envelope( $valid->get_error_code(), $valid->get_error_message(), (int) ( $valid->get_error_data()['status'] ?? 422 ) ), (int) ( $valid->get_error_data()['status'] ?? 422 ) );
	}

	if ( ! dtb_deployment_github_enabled() ) {
		return new WP_REST_Response( dtb_error_envelope( 'dtb_deployment_github_not_configured', 'GitHub deployment integration is not configured.', 503 ), 503 );
	}

	if ( $in_progress = dtb_release_history_in_progress() ) { // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
		return new WP_REST_Response(
			dtb_error_envelope( 'dtb_deployment_already_in_progress', sprintf( 'Release %s is already in progress.', $in_progress['release_id'] ), 409 ),
			409
		);
	}

	if ( ! dtb_deployment_dispatch_lock_acquire() ) {
		return new WP_REST_Response( dtb_error_envelope( 'dtb_deployment_dispatch_locked', 'A deployment dispatch was just submitted; please wait a moment.', 429 ), 429 );
	}

	$result = dtb_deployment_github_dispatch_workflow( [
		'action'  => 'deploy',
		'ref'     => $ref,
		'confirm' => 'DEPLOY',
	] );

	dtb_deployment_dispatch_lock_release();

	if ( function_exists( 'dtb_ops_audit_log' ) ) {
		dtb_ops_audit_log( 'deployment_dispatch_deploy', [ 'ref' => $ref, 'ok' => $result['ok'] ] );
	}

	if ( ! $result['ok'] ) {
		return new WP_REST_Response( dtb_error_envelope( 'dtb_deployment_dispatch_failed', $result['error'], 502 ), 502 );
	}

	return new WP_REST_Response( [
		'success' => true,
		'message' => sprintf( 'Release workflow dispatched for %s. Track progress in the History tab or GitHub Actions.', $ref ),
	], 202 );
}

function dtb_deployment_route_dispatch_rollback( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$release_id = sanitize_text_field( (string) ( $request->get_param( 'release_id' ) ?? '' ) );
	$confirm    = sanitize_text_field( (string) ( $request->get_param( 'confirm' ) ?? '' ) );

	$valid = dtb_deployment_validate_rollback_request( $release_id, $confirm );
	if ( is_wp_error( $valid ) ) {
		return new WP_REST_Response( dtb_error_envelope( $valid->get_error_code(), $valid->get_error_message(), (int) ( $valid->get_error_data()['status'] ?? 422 ) ), (int) ( $valid->get_error_data()['status'] ?? 422 ) );
	}

	if ( ! dtb_deployment_github_enabled() ) {
		return new WP_REST_Response( dtb_error_envelope( 'dtb_deployment_github_not_configured', 'GitHub deployment integration is not configured.', 503 ), 503 );
	}

	if ( $in_progress = dtb_release_history_in_progress() ) { // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
		return new WP_REST_Response(
			dtb_error_envelope( 'dtb_deployment_already_in_progress', sprintf( 'Release %s is already in progress.', $in_progress['release_id'] ), 409 ),
			409
		);
	}

	if ( ! dtb_deployment_dispatch_lock_acquire() ) {
		return new WP_REST_Response( dtb_error_envelope( 'dtb_deployment_dispatch_locked', 'A rollback dispatch was just submitted; please wait a moment.', 429 ), 429 );
	}

	$result = dtb_deployment_github_dispatch_workflow( [
		'action'               => 'rollback',
		'rollback_release_id'  => $release_id,
		'confirm'              => 'ROLLBACK',
	] );

	dtb_deployment_dispatch_lock_release();

	if ( function_exists( 'dtb_ops_audit_log' ) ) {
		dtb_ops_audit_log( 'deployment_dispatch_rollback', [ 'release_id' => $release_id, 'ok' => $result['ok'] ] );
	}

	if ( ! $result['ok'] ) {
		return new WP_REST_Response( dtb_error_envelope( 'dtb_deployment_dispatch_failed', $result['error'], 502 ), 502 );
	}

	return new WP_REST_Response( [
		'success' => true,
		'message' => sprintf( 'Rollback workflow dispatched to restore %s. Track progress in the Release History tab or GitHub Actions.', $release_id ),
	], 202 );
}
