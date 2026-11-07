<?php
/**
 * Services — ReleaseHistoryService
 *
 * Projects the wp_dtb_release_events log into release summaries for the
 * Deployment Center: history list, current production state, in-progress
 * detection, and GitHub/production drift.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build a release summary from its ordered events.
 *
 * @param string                       $release_id
 * @param array<int,array<string,mixed>> $events Ordered oldest → newest.
 * @return array<string,mixed>
 */
function dtb_release_history_summarize( string $release_id, array $events ): array {
	$first = $events[0] ?? [];
	$last  = $events[ count( $events ) - 1 ] ?? [];

	$ref_type   = '';
	$ref        = '';
	$commit_sha = '';
	$actor      = '';
	foreach ( $events as $event ) {
		$ref_type   = $ref_type ?: (string) ( $event['ref_type'] ?? '' );
		$ref        = $ref ?: (string) ( $event['ref'] ?? '' );
		$commit_sha = $commit_sha ?: (string) ( $event['commit_sha'] ?? '' );
		$actor      = $actor ?: (string) ( $event['actor'] ?? '' );
	}

	$event_types = array_map( static fn( array $e ): string => (string) $e['event_type'], $events );
	$status      = dtb_release_status_for_events( $event_types );
	$is_rollback = str_starts_with( $release_id, 'rollback-' );

	return [
		'release_id'   => $release_id,
		'kind'         => $is_rollback ? 'rollback' : 'deploy',
		'status'       => $status,
		'status_label' => dtb_release_status_label( $status ),
		'badge_type'   => dtb_release_status_badge_type( $status ),
		'in_progress'  => dtb_release_status_is_in_progress( $status ),
		'ref_type'     => $ref_type,
		'ref'          => $ref,
		'commit_sha'   => $commit_sha,
		'actor'        => $actor,
		'started_at'   => (string) ( $first['created_at'] ?? '' ),
		'updated_at'   => (string) ( $last['created_at'] ?? '' ),
		'events'       => $events,
	];
}

/**
 * Recent release summaries, newest first.
 *
 * @return array<int,array<string,mixed>>
 */
function dtb_release_history_get( int $limit = 50 ): array {
	$release_ids = dtb_release_recent_release_ids( $limit );
	if ( empty( $release_ids ) ) {
		return [];
	}

	// One batch query instead of one query per release.
	$grouped   = dtb_release_events_for_many( $release_ids );
	$summaries = [];

	foreach ( $release_ids as $release_id ) {
		$events = $grouped[ $release_id ] ?? [];
		if ( empty( $events ) ) {
			continue;
		}
		$summaries[] = dtb_release_history_summarize( $release_id, $events );
	}

	return $summaries;
}

/**
 * Single release summary, or null if unknown.
 *
 * @return array<string,mixed>|null
 */
function dtb_release_history_get_one( string $release_id ): ?array {
	$events = dtb_release_events_for( $release_id );
	if ( empty( $events ) ) {
		return null;
	}

	return dtb_release_history_summarize( $release_id, $events );
}

/**
 * The most recent release that reached a successful, live-production
 * terminal state (completed deploy, or successful rollback) — this is
 * "what is currently deployed" for the Overview panel.
 *
 * @return array<string,mixed>|null
 */
function dtb_release_history_current_production(): ?array {
	foreach ( dtb_release_history_get( 50 ) as $summary ) {
		if ( in_array( $summary['status'], [ 'completed', 'rolled_back' ], true ) ) {
			return $summary;
		}
	}

	return null;
}

/**
 * The most recent release still in progress, if any — used to block new
 * deploy/rollback dispatches and to show a live progress banner.
 *
 * @return array<string,mixed>|null
 */
function dtb_release_history_in_progress(): ?array {
	foreach ( dtb_release_history_get( 10 ) as $summary ) {
		if ( $summary['in_progress'] ) {
			return $summary;
		}
	}

	return null;
}

/**
 * Compare the currently deployed commit against the GitHub default branch
 * HEAD to report repository drift ("N commits available to deploy").
 *
 * @param array<string,mixed>|null $current_production Pass the caller's
 *        already-computed dtb_release_history_current_production() result
 *        to avoid recomputing it (it scans release history); omit to have
 *        this function compute it itself.
 * @return array{ok:bool, deployed_sha:string, github_branch:string, github_sha:string, ahead_by:int, error:string}
 */
function dtb_release_history_drift( ?array $current_production = null ): array {
	$current      = $current_production ?? dtb_release_history_current_production();
	$deployed_sha = $current['commit_sha'] ?? '';

	if ( ! dtb_deployment_github_enabled() ) {
		return [
			'ok'            => false,
			'deployed_sha'  => $deployed_sha,
			'github_branch' => '',
			'github_sha'    => '',
			'ahead_by'      => 0,
			'error'         => 'GitHub integration is not configured (DTB_GITHUB_DEPLOYMENT_TOKEN).',
		];
	}

	$head = dtb_deployment_github_default_branch_head();
	if ( ! $head['ok'] ) {
		return [
			'ok'            => false,
			'deployed_sha'  => $deployed_sha,
			'github_branch' => $head['branch'],
			'github_sha'    => '',
			'ahead_by'      => 0,
			'error'         => $head['error'],
		];
	}

	if ( '' === $deployed_sha ) {
		return [
			'ok'            => true,
			'deployed_sha'  => '',
			'github_branch' => $head['branch'],
			'github_sha'    => $head['sha'],
			'ahead_by'      => 0,
			'error'         => 'No completed release is recorded yet; drift cannot be computed.',
		];
	}

	$compare = dtb_deployment_github_compare( $deployed_sha, $head['sha'] );

	return [
		'ok'            => $compare['ok'],
		'deployed_sha'  => $deployed_sha,
		'github_branch' => $head['branch'],
		'github_sha'    => $head['sha'],
		'ahead_by'      => $compare['ahead_by'],
		'error'         => $compare['error'],
	];
}
