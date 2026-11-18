<?php
/**
 * Services — DeploymentLockService
 *
 * A short-lived dispatch lock that prevents an operator from firing two
 * workflow_dispatch calls for the same release action within a few seconds
 * of each other (double-click / repeated submit). This is a UX safety net
 * only — the authoritative concurrency guard is the release workflow's own
 * `concurrency: group: siteground-production-release` in
 * .github/workflows/release-siteground.yml, which GitHub enforces
 * regardless of what triggered the dispatch.
 *
 * Whether a release is currently in progress (for display and for blocking
 * new "Deploy"/"Rollback" actions in the UI) is derived from the event log
 * via dtb_release_history_in_progress() in ReleaseHistoryService.php, not
 * from this lock.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_DEPLOYMENT_DISPATCH_LOCK_TTL = 30; // seconds

/**
 * Attempt to acquire the dispatch lock. Uses add_option()'s atomic
 * insert-if-absent semantics, the same pattern used by the QuickBooks
 * webhook processing lock.
 */
function dtb_deployment_dispatch_lock_acquire(): bool {
	$key = 'dtb_deployment_dispatch_lock';

	if ( add_option( $key, time(), '', false ) ) {
		return true;
	}

	$locked_at = (int) get_option( $key, 0 );
	if ( $locked_at > 0 && $locked_at < time() - DTB_DEPLOYMENT_DISPATCH_LOCK_TTL ) {
		delete_option( $key );
		return add_option( $key, time(), '', false );
	}

	return false;
}

/**
 * Release the dispatch lock (call after the workflow_dispatch call returns,
 * success or failure).
 */
function dtb_deployment_dispatch_lock_release(): void {
	delete_option( 'dtb_deployment_dispatch_lock' );
}
