<?php
/**
 * Services — GitHubRepositoryClient
 *
 * Read-only GitHub repository visibility for the Git Control Center:
 * repository summary, branches, commits, pull requests, tags, releases,
 * and repository-wide workflow runs. Built on the same authenticated
 * request helper (dtb_deployment_github_request()) and token
 * (DTB_GITHUB_DEPLOYMENT_TOKEN) as GitHubReleaseClient.php — this file
 * only adds general browsing, never repository writes.
 *
 * Every list here is short-TTL cached so the Git Control Center's
 * auto-refreshing live region cannot amplify into excessive GitHub API
 * traffic when left open in a browser tab.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_GIT_CONTROL_CENTER_CACHE_TTL = 2 * MINUTE_IN_SECONDS;

/**
 * Fetch-with-cache helper shared by every list function below.
 *
 * @param string   $cache_key
 * @param callable $fetch Returns the same shape this function returns.
 * @return array{ok:bool, data:mixed, error:string}
 */
function dtb_git_control_center_cached( string $cache_key, callable $fetch ): array {
	$cached = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$result = $fetch();
	if ( $result['ok'] ) {
		set_transient( $cache_key, $result, DTB_GIT_CONTROL_CENTER_CACHE_TTL );
	}

	return $result;
}

/**
 * Repository summary (name, description, default branch, activity).
 *
 * @return array{ok:bool, data:array<string,mixed>, error:string}
 */
function dtb_git_control_center_repository_info(): array {
	return dtb_git_control_center_cached( 'dtb_gcc_repo_info', static function (): array {
		$cfg    = dtb_deployment_github_config();
		$result = dtb_deployment_github_request( 'GET', "/repos/{$cfg['owner']}/{$cfg['repo']}" );
		if ( ! $result['ok'] ) {
			return [ 'ok' => false, 'data' => [], 'error' => $result['error'] ];
		}

		$r = (array) $result['data'];
		return [
			'ok'    => true,
			'data'  => [
				'full_name'         => sanitize_text_field( (string) ( $r['full_name'] ?? '' ) ),
				'description'       => sanitize_text_field( (string) ( $r['description'] ?? '' ) ),
				'default_branch'    => sanitize_text_field( (string) ( $r['default_branch'] ?? '' ) ),
				'html_url'          => esc_url_raw( (string) ( $r['html_url'] ?? '' ) ),
				'open_issues_count' => absint( $r['open_issues_count'] ?? 0 ),
				'pushed_at'         => sanitize_text_field( (string) ( $r['pushed_at'] ?? '' ) ),
				'visibility'        => sanitize_text_field( (string) ( $r['visibility'] ?? '' ) ),
			],
			'error' => '',
		];
	} );
}

/**
 * @return array{ok:bool, branches:array<int,array<string,mixed>>, error:string}
 */
function dtb_git_control_center_branches( int $limit = 15 ): array {
	$limit  = max( 1, min( 50, $limit ) );
	$result = dtb_git_control_center_cached( "dtb_gcc_branches_{$limit}", static function () use ( $limit ): array {
		$cfg = dtb_deployment_github_config();
		$res = dtb_deployment_github_request( 'GET', "/repos/{$cfg['owner']}/{$cfg['repo']}/branches?per_page={$limit}" );
		if ( ! $res['ok'] ) {
			return [ 'ok' => false, 'data' => [], 'error' => $res['error'] ];
		}
		$branches = array_map(
			static fn( array $b ): array => [
				'name'      => sanitize_text_field( (string) ( $b['name'] ?? '' ) ),
				'sha'       => sanitize_text_field( (string) ( $b['commit']['sha'] ?? '' ) ),
				'protected' => (bool) ( $b['protected'] ?? false ),
			],
			is_array( $res['data'] ) ? $res['data'] : []
		);
		return [ 'ok' => true, 'data' => $branches, 'error' => '' ];
	} );

	return [ 'ok' => $result['ok'], 'branches' => $result['data'], 'error' => $result['error'] ];
}

/**
 * @return array{ok:bool, commits:array<int,array<string,mixed>>, error:string}
 */
function dtb_git_control_center_commits( string $ref = '', int $limit = 15 ): array {
	$limit  = max( 1, min( 50, $limit ) );
	$ref        = preg_match( '/^[A-Za-z0-9._\/-]{1,200}$/', $ref ) ? $ref : '';
	$cache_key  = 'dtb_gcc_commits_' . substr( md5( $ref ), 0, 12 ) . "_{$limit}";
	$result     = dtb_git_control_center_cached( $cache_key, static function () use ( $ref, $limit ): array {
		$cfg   = dtb_deployment_github_config();
		$query = $ref ? "sha={$ref}&per_page={$limit}" : "per_page={$limit}";
		$res   = dtb_deployment_github_request( 'GET', "/repos/{$cfg['owner']}/{$cfg['repo']}/commits?{$query}" );
		if ( ! $res['ok'] ) {
			return [ 'ok' => false, 'data' => [], 'error' => $res['error'] ];
		}
		$commits = array_map(
			static fn( array $c ): array => [
				'sha'      => sanitize_text_field( (string) ( $c['sha'] ?? '' ) ),
				'message'  => sanitize_text_field( strtok( (string) ( $c['commit']['message'] ?? '' ), "\n" ) ),
				'author'   => sanitize_text_field( (string) ( $c['commit']['author']['name'] ?? $c['author']['login'] ?? '' ) ),
				'date'     => sanitize_text_field( (string) ( $c['commit']['author']['date'] ?? '' ) ),
				'html_url' => esc_url_raw( (string) ( $c['html_url'] ?? '' ) ),
			],
			is_array( $res['data'] ) ? $res['data'] : []
		);
		return [ 'ok' => true, 'data' => $commits, 'error' => '' ];
	} );

	return [ 'ok' => $result['ok'], 'commits' => $result['data'], 'error' => $result['error'] ];
}

/**
 * @return array{ok:bool, pulls:array<int,array<string,mixed>>, error:string}
 */
function dtb_git_control_center_pull_requests( string $state = 'open', int $limit = 15 ): array {
	$state  = in_array( $state, [ 'open', 'closed', 'all' ], true ) ? $state : 'open';
	$limit  = max( 1, min( 50, $limit ) );
	$result = dtb_git_control_center_cached( "dtb_gcc_pulls_{$state}_{$limit}", static function () use ( $state, $limit ): array {
		$cfg = dtb_deployment_github_config();
		$res = dtb_deployment_github_request( 'GET', "/repos/{$cfg['owner']}/{$cfg['repo']}/pulls?state={$state}&per_page={$limit}&sort=updated&direction=desc" );
		if ( ! $res['ok'] ) {
			return [ 'ok' => false, 'data' => [], 'error' => $res['error'] ];
		}
		$pulls = array_map(
			static fn( array $p ): array => [
				'number'     => absint( $p['number'] ?? 0 ),
				'title'      => sanitize_text_field( (string) ( $p['title'] ?? '' ) ),
				'author'     => sanitize_text_field( (string) ( $p['user']['login'] ?? '' ) ),
				'head'       => sanitize_text_field( (string) ( $p['head']['ref'] ?? '' ) ),
				'base'       => sanitize_text_field( (string) ( $p['base']['ref'] ?? '' ) ),
				'draft'      => (bool) ( $p['draft'] ?? false ),
				'updated_at' => sanitize_text_field( (string) ( $p['updated_at'] ?? '' ) ),
				'html_url'   => esc_url_raw( (string) ( $p['html_url'] ?? '' ) ),
			],
			is_array( $res['data'] ) ? $res['data'] : []
		);
		return [ 'ok' => true, 'data' => $pulls, 'error' => '' ];
	} );

	return [ 'ok' => $result['ok'], 'pulls' => $result['data'], 'error' => $result['error'] ];
}

/**
 * GitHub tags, split into DTB release/backup manifest tags and other tags.
 *
 * @return array{ok:bool, release_tags:array<int,array<string,mixed>>, backup_tags:array<int,array<string,mixed>>, other_tags:array<int,array<string,mixed>>, error:string}
 */
function dtb_git_control_center_tags( int $limit = 100 ): array {
	$limit  = max( 1, min( 100, $limit ) );
	$result = dtb_git_control_center_cached( "dtb_gcc_tags_{$limit}", static function () use ( $limit ): array {
		$cfg = dtb_deployment_github_config();
		$res = dtb_deployment_github_request( 'GET', "/repos/{$cfg['owner']}/{$cfg['repo']}/tags?per_page={$limit}" );
		if ( ! $res['ok'] ) {
			return [ 'ok' => false, 'data' => [], 'error' => $res['error'] ];
		}
		$tags = array_map(
			static fn( array $t ): array => [
				'name' => sanitize_text_field( (string) ( $t['name'] ?? '' ) ),
				'sha'  => sanitize_text_field( (string) ( $t['commit']['sha'] ?? '' ) ),
			],
			is_array( $res['data'] ) ? $res['data'] : []
		);
		return [ 'ok' => true, 'data' => $tags, 'error' => '' ];
	} );

	if ( ! $result['ok'] ) {
		return [ 'ok' => false, 'release_tags' => [], 'backup_tags' => [], 'other_tags' => [], 'error' => $result['error'] ];
	}

	$release = $backup = $other = [];
	foreach ( $result['data'] as $tag ) {
		if ( str_starts_with( $tag['name'], 'dtb-release/' ) ) {
			$release[] = $tag;
		} elseif ( str_starts_with( $tag['name'], 'dtb-backup/' ) ) {
			$backup[] = $tag;
		} else {
			$other[] = $tag;
		}
	}

	return [ 'ok' => true, 'release_tags' => $release, 'backup_tags' => $backup, 'other_tags' => $other, 'error' => '' ];
}

/**
 * @return array{ok:bool, releases:array<int,array<string,mixed>>, error:string}
 */
function dtb_git_control_center_releases( int $limit = 10 ): array {
	$limit  = max( 1, min( 30, $limit ) );
	$result = dtb_git_control_center_cached( "dtb_gcc_releases_{$limit}", static function () use ( $limit ): array {
		$cfg = dtb_deployment_github_config();
		$res = dtb_deployment_github_request( 'GET', "/repos/{$cfg['owner']}/{$cfg['repo']}/releases?per_page={$limit}" );
		if ( ! $res['ok'] ) {
			return [ 'ok' => false, 'data' => [], 'error' => $res['error'] ];
		}
		$releases = array_map(
			static fn( array $r ): array => [
				'tag_name'    => sanitize_text_field( (string) ( $r['tag_name'] ?? '' ) ),
				'name'        => sanitize_text_field( (string) ( $r['name'] ?? '' ) ),
				'draft'       => (bool) ( $r['draft'] ?? false ),
				'prerelease'  => (bool) ( $r['prerelease'] ?? false ),
				'published_at' => sanitize_text_field( (string) ( $r['published_at'] ?? '' ) ),
				'html_url'    => esc_url_raw( (string) ( $r['html_url'] ?? '' ) ),
			],
			is_array( $res['data'] ) ? $res['data'] : []
		);
		return [ 'ok' => true, 'data' => $releases, 'error' => '' ];
	} );

	return [ 'ok' => $result['ok'], 'releases' => $result['data'], 'error' => $result['error'] ];
}

/**
 * Repository-wide Actions runs (every workflow, not just the release
 * workflow — dtb_deployment_github_recent_workflow_runs() in
 * GitHubReleaseClient.php stays scoped to release-siteground.yml only).
 *
 * @return array{ok:bool, runs:array<int,array<string,mixed>>, error:string}
 */
function dtb_git_control_center_workflow_runs( int $limit = 15 ): array {
	$limit  = max( 1, min( 50, $limit ) );
	$result = dtb_git_control_center_cached( "dtb_gcc_runs_{$limit}", static function () use ( $limit ): array {
		$cfg = dtb_deployment_github_config();
		$res = dtb_deployment_github_request( 'GET', "/repos/{$cfg['owner']}/{$cfg['repo']}/actions/runs?per_page={$limit}" );
		if ( ! $res['ok'] ) {
			return [ 'ok' => false, 'data' => [], 'error' => $res['error'] ];
		}
		$runs = array_map(
			static fn( array $r ): array => [
				'id'           => absint( $r['id'] ?? 0 ),
				'name'         => sanitize_text_field( (string) ( $r['name'] ?? $r['display_title'] ?? '' ) ),
				'workflow'     => sanitize_text_field( (string) ( $r['path'] ?? '' ) ),
				'status'       => sanitize_key( (string) ( $r['status'] ?? '' ) ),
				'conclusion'   => sanitize_key( (string) ( $r['conclusion'] ?? '' ) ),
				'branch'       => sanitize_text_field( (string) ( $r['head_branch'] ?? '' ) ),
				'actor'        => sanitize_text_field( (string) ( $r['actor']['login'] ?? '' ) ),
				'event'        => sanitize_key( (string) ( $r['event'] ?? '' ) ),
				'created_at'   => sanitize_text_field( (string) ( $r['created_at'] ?? '' ) ),
				'html_url'     => esc_url_raw( (string) ( $r['html_url'] ?? '' ) ),
			],
			is_array( $res['data']['workflow_runs'] ?? null ) ? $res['data']['workflow_runs'] : []
		);
		return [ 'ok' => true, 'data' => $runs, 'error' => '' ];
	} );

	return [ 'ok' => $result['ok'], 'runs' => $result['data'], 'error' => $result['error'] ];
}
