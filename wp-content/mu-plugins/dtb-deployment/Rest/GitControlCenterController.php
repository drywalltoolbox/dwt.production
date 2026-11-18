<?php
/**
 * Rest — GitControlCenterController
 *
 * Read-only repository visibility endpoints (Repository, Pull Requests,
 * Workflow Runs, Releases & Tags tabs of the unified System Manager
 * console — see dtb-platform/SystemManager/SystemManagerPage.php).
 * All routes require dtb_manage_deployments — the same capability that
 * gates release dispatch, since this is one console.
 *
 * Endpoints:
 *   GET /dtb/v1/deployment/git/repository
 *   GET /dtb/v1/deployment/git/branches
 *   GET /dtb/v1/deployment/git/commits
 *   GET /dtb/v1/deployment/git/pull-requests
 *   GET /dtb/v1/deployment/git/releases
 *   GET /dtb/v1/deployment/git/workflow-runs
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_git_control_center_register_routes' );

function dtb_git_control_center_register_routes(): void {
	$cap = fn() => current_user_can( 'dtb_manage_deployments' );

	register_rest_route( 'dtb/v1', '/deployment/git/repository', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => fn() => new WP_REST_Response( dtb_git_control_center_repository_info(), 200 ),
		'permission_callback' => $cap,
	] );

	register_rest_route( 'dtb/v1', '/deployment/git/branches', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => function ( WP_REST_Request $r ) {
			$limit = max( 1, min( 50, (int) ( $r->get_param( 'limit' ) ?? 15 ) ) );
			return new WP_REST_Response( dtb_git_control_center_branches( $limit ), 200 );
		},
		'permission_callback' => $cap,
	] );

	register_rest_route( 'dtb/v1', '/deployment/git/commits', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => function ( WP_REST_Request $r ) {
			$limit = max( 1, min( 50, (int) ( $r->get_param( 'limit' ) ?? 15 ) ) );
			$ref   = sanitize_text_field( (string) ( $r->get_param( 'ref' ) ?? '' ) );
			return new WP_REST_Response( dtb_git_control_center_commits( $ref, $limit ), 200 );
		},
		'permission_callback' => $cap,
	] );

	register_rest_route( 'dtb/v1', '/deployment/git/pull-requests', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => function ( WP_REST_Request $r ) {
			$state = sanitize_key( (string) ( $r->get_param( 'state' ) ?? 'open' ) );
			$limit = max( 1, min( 50, (int) ( $r->get_param( 'limit' ) ?? 15 ) ) );
			return new WP_REST_Response( dtb_git_control_center_pull_requests( $state, $limit ), 200 );
		},
		'permission_callback' => $cap,
	] );

	register_rest_route( 'dtb/v1', '/deployment/git/releases', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => function ( WP_REST_Request $r ) {
			$limit = max( 1, min( 30, (int) ( $r->get_param( 'limit' ) ?? 10 ) ) );
			return new WP_REST_Response( [
				'releases' => dtb_git_control_center_releases( $limit ),
				'tags'     => dtb_git_control_center_tags( 100 ),
			], 200 );
		},
		'permission_callback' => $cap,
	] );

	register_rest_route( 'dtb/v1', '/deployment/git/workflow-runs', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => function ( WP_REST_Request $r ) {
			$limit = max( 1, min( 50, (int) ( $r->get_param( 'limit' ) ?? 15 ) ) );
			return new WP_REST_Response( dtb_git_control_center_workflow_runs( $limit ), 200 );
		},
		'permission_callback' => $cap,
	] );
}
