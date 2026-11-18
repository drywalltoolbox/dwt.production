<?php
/**
 * Services — GitHubReleaseClient
 *
 * Server-side GitHub REST API client. Used to detect production/GitHub
 * drift (compare the last deployed commit to the default branch HEAD) and
 * to let an operator trigger release-siteground.yml (deploy or rollback)
 * directly from the Deployment Center without WordPress ever holding
 * SiteGround Git/SSH credentials — the workflow_dispatch call is the only
 * bridge, and it carries no secrets itself.
 *
 * The token never reaches the browser: every route in
 * Rest/DeploymentAdminController.php that uses this client is
 * capability-gated server-side.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_GITHUB_API_BASE = 'https://api.github.com';

/**
 * URL-encode a Git ref (branch, tag, or SHA) for use as a GitHub API path
 * segment, without breaking branch names that legitimately contain slashes
 * (e.g. "feature/foo") — each slash-delimited component is encoded
 * individually and rejoined with literal "/" so GitHub's router still sees
 * the ref's hierarchy, while special characters within a component
 * (spaces, "#", "?", etc.) are safely escaped.
 */
function dtb_deployment_github_encode_ref( string $ref ): string {
	return implode( '/', array_map( 'rawurlencode', explode( '/', $ref ) ) );
}

/**
 * Resolve GitHub integration configuration from wp-config.php constants.
 *
 * Required for any GitHub-backed Deployment Center feature (drift
 * detection, one-click deploy/rollback dispatch):
 *   DTB_GITHUB_DEPLOYMENT_TOKEN — a fine-grained PAT scoped to this repo
 *     with "Actions: write" and "Contents: read" permissions.
 *
 * Optional (sensible defaults for this repository):
 *   DTB_GITHUB_REPO_OWNER   — default 'elliotttmiller'.
 *   DTB_GITHUB_REPO_NAME    — default 'launch-dtb'.
 *   DTB_GITHUB_RELEASE_WORKFLOW_FILE — default 'release-siteground.yml'.
 *
 * @return array{owner:string, repo:string, token:string, workflow_file:string}
 */
function dtb_deployment_github_config(): array {
	return [
		'owner'         => defined( 'DTB_GITHUB_REPO_OWNER' ) && '' !== (string) DTB_GITHUB_REPO_OWNER
			? (string) DTB_GITHUB_REPO_OWNER
			: 'elliotttmiller',
		'repo'          => defined( 'DTB_GITHUB_REPO_NAME' ) && '' !== (string) DTB_GITHUB_REPO_NAME
			? (string) DTB_GITHUB_REPO_NAME
			: 'launch-dtb',
		'token'         => defined( 'DTB_GITHUB_DEPLOYMENT_TOKEN' ) ? (string) DTB_GITHUB_DEPLOYMENT_TOKEN : '',
		'workflow_file' => defined( 'DTB_GITHUB_RELEASE_WORKFLOW_FILE' ) && '' !== (string) DTB_GITHUB_RELEASE_WORKFLOW_FILE
			? (string) DTB_GITHUB_RELEASE_WORKFLOW_FILE
			: 'release-siteground.yml',
	];
}

/**
 * True when a deployment token is configured (dispatch/drift features usable).
 */
function dtb_deployment_github_enabled(): bool {
	return '' !== dtb_deployment_github_config()['token'];
}

/**
 * Make an authenticated request to the GitHub REST API.
 *
 * @param string $method GET|POST
 * @param string $path   API path, e.g. '/repos/{owner}/{repo}'.
 * @param array  $body   Request body for POST (JSON-encoded).
 * @return array{ok:bool, status:int, data:mixed, error:string}
 */
function dtb_deployment_github_request( string $method, string $path, array $body = [] ): array {
	$cfg = dtb_deployment_github_config();

	if ( '' === $cfg['token'] ) {
		return [ 'ok' => false, 'status' => 503, 'data' => null, 'error' => 'DTB_GITHUB_DEPLOYMENT_TOKEN is not configured.' ];
	}

	$args = [
		'method'  => strtoupper( $method ),
		'headers' => [
			'Authorization'        => 'Bearer ' . $cfg['token'],
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'DTB-Deployment-Center',
		],
		'timeout' => 15,
	];

	if ( ! empty( $body ) ) {
		$args['headers']['Content-Type'] = 'application/json';
		$args['body']                    = wp_json_encode( $body );
	}

	$raw = wp_remote_request( DTB_GITHUB_API_BASE . $path, $args );

	if ( is_wp_error( $raw ) ) {
		return [ 'ok' => false, 'status' => 502, 'data' => null, 'error' => $raw->get_error_message() ];
	}

	$status = (int) wp_remote_retrieve_response_code( $raw );
	$body_raw = wp_remote_retrieve_body( $raw );
	$data   = '' !== $body_raw ? json_decode( $body_raw, true ) : null;

	if ( $status < 200 || $status >= 300 ) {
		$msg = is_array( $data ) && ! empty( $data['message'] ) ? sanitize_text_field( (string) $data['message'] ) : 'GitHub API error.';
		return [ 'ok' => false, 'status' => $status, 'data' => $data, 'error' => $msg ];
	}

	return [ 'ok' => true, 'status' => $status, 'data' => $data, 'error' => '' ];
}

/**
 * Resolve the default branch and its current HEAD commit SHA — used for
 * "available GitHub updates" / repository drift detection.
 *
 * @return array{ok:bool, branch:string, sha:string, error:string}
 */
function dtb_deployment_github_default_branch_head(): array {
	$cached = get_transient( 'dtb_deployment_github_default_head' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$cfg  = dtb_deployment_github_config();
	$repo = dtb_deployment_github_request( 'GET', "/repos/{$cfg['owner']}/{$cfg['repo']}" );
	if ( ! $repo['ok'] ) {
		return [ 'ok' => false, 'branch' => '', 'sha' => '', 'error' => $repo['error'] ];
	}

	$branch = sanitize_text_field( (string) ( $repo['data']['default_branch'] ?? 'main' ) );
	$commit = dtb_deployment_github_request( 'GET', "/repos/{$cfg['owner']}/{$cfg['repo']}/commits/" . dtb_deployment_github_encode_ref( $branch ) );
	if ( ! $commit['ok'] ) {
		return [ 'ok' => false, 'branch' => $branch, 'sha' => '', 'error' => $commit['error'] ];
	}

	$result = [
		'ok'     => true,
		'branch' => $branch,
		'sha'    => sanitize_text_field( (string) ( $commit['data']['sha'] ?? '' ) ),
		'error'  => '',
	];

	set_transient( 'dtb_deployment_github_default_head', $result, 2 * MINUTE_IN_SECONDS );

	return $result;
}

/**
 * Compare two commits/refs and return how far apart they are — used to
 * report "N commits ahead on GitHub, not yet deployed" drift in the
 * Deployment Center.
 *
 * @return array{ok:bool, ahead_by:int, behind_by:int, error:string}
 */
function dtb_deployment_github_compare( string $base, string $head ): array {
	if ( '' === $base || '' === $head ) {
		return [ 'ok' => false, 'ahead_by' => 0, 'behind_by' => 0, 'error' => 'Missing base or head ref.' ];
	}

	$cfg    = dtb_deployment_github_config();
	$result = dtb_deployment_github_request(
		'GET',
		"/repos/{$cfg['owner']}/{$cfg['repo']}/compare/" . dtb_deployment_github_encode_ref( $base ) . '...' . dtb_deployment_github_encode_ref( $head )
	);

	if ( ! $result['ok'] ) {
		return [ 'ok' => false, 'ahead_by' => 0, 'behind_by' => 0, 'error' => $result['error'] ];
	}

	return [
		'ok'        => true,
		'ahead_by'  => absint( $result['data']['ahead_by'] ?? 0 ),
		'behind_by' => absint( $result['data']['behind_by'] ?? 0 ),
		'error'     => '',
	];
}

/**
 * Trigger the release workflow via workflow_dispatch.
 *
 * @param array<string,string> $inputs Workflow inputs (action, ref/confirm, or rollback_release_id/confirm).
 * @return array{ok:bool, error:string}
 */
function dtb_deployment_github_dispatch_workflow( array $inputs ): array {
	$cfg = dtb_deployment_github_config();
	if ( '' === $cfg['token'] ) {
		return [ 'ok' => false, 'error' => 'DTB_GITHUB_DEPLOYMENT_TOKEN is not configured.' ];
	}

	$head = dtb_deployment_github_default_branch_head();
	$ref  = $head['ok'] && '' !== $head['branch'] ? $head['branch'] : 'main';

	$result = dtb_deployment_github_request(
		'POST',
		"/repos/{$cfg['owner']}/{$cfg['repo']}/actions/workflows/{$cfg['workflow_file']}/dispatches",
		[
			'ref'    => $ref,
			'inputs' => $inputs,
		]
	);

	return [ 'ok' => $result['ok'], 'error' => $result['error'] ];
}

/**
 * List recent runs of the release workflow, newest first.
 *
 * @return array{ok:bool, runs:array<int,array<string,mixed>>, error:string}
 */
function dtb_deployment_github_recent_workflow_runs( int $limit = 10 ): array {
	$cfg    = dtb_deployment_github_config();
	$limit  = max( 1, min( 50, $limit ) );
	$result = dtb_deployment_github_request(
		'GET',
		"/repos/{$cfg['owner']}/{$cfg['repo']}/actions/workflows/{$cfg['workflow_file']}/runs?per_page={$limit}"
	);

	if ( ! $result['ok'] ) {
		return [ 'ok' => false, 'runs' => [], 'error' => $result['error'] ];
	}

	$runs = is_array( $result['data']['workflow_runs'] ?? null ) ? $result['data']['workflow_runs'] : [];

	$normalized = array_map(
		static fn( array $run ): array => [
			'id'         => absint( $run['id'] ?? 0 ),
			'status'     => sanitize_key( (string) ( $run['status'] ?? '' ) ),
			'conclusion' => sanitize_key( (string) ( $run['conclusion'] ?? '' ) ),
			'html_url'   => esc_url_raw( (string) ( $run['html_url'] ?? '' ) ),
			'created_at' => sanitize_text_field( (string) ( $run['created_at'] ?? '' ) ),
			'actor'      => sanitize_text_field( (string) ( $run['actor']['login'] ?? '' ) ),
		],
		$runs
	);

	return [ 'ok' => true, 'runs' => $normalized, 'error' => '' ];
}
