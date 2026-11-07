<?php
/**
 * Domain — ReleaseStatus
 *
 * Event-type constants and status-derivation rules for the release
 * lifecycle. A "release" is an event-sourced aggregate: every row in
 * wp_dtb_release_events for a given release_id is one lifecycle fact, and
 * the release's current status is derived from which event types are
 * present — the same event-sourcing shape already used by
 * dtb-repair-service (RepairEvent) and dtb-order-platform.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

// ── Event type constants ──────────────────────────────────────────────────

const DTB_RELEASE_EVENT_PLANNED                    = 'release_planned';
const DTB_RELEASE_EVENT_VALIDATED                  = 'release_validated';
const DTB_RELEASE_EVENT_BACKUP_STARTED             = 'release_backup_started';
const DTB_RELEASE_EVENT_BACKED_UP                  = 'release_backed_up';
const DTB_RELEASE_EVENT_DEPLOY_STARTED             = 'release_deploy_started';
const DTB_RELEASE_EVENT_DEPLOYED                   = 'release_deployed';
const DTB_RELEASE_EVENT_VERIFIED                   = 'release_verified';
const DTB_RELEASE_EVENT_COMPLETED                  = 'release_completed';
const DTB_RELEASE_EVENT_FAILED                     = 'release_failed';
const DTB_RELEASE_EVENT_ROLLED_BACK_AUTOMATICALLY  = 'release_rolled_back_automatically';
const DTB_RELEASE_EVENT_ROLLBACK_STARTED           = 'release_rollback_started';
const DTB_RELEASE_EVENT_ROLLED_BACK                = 'release_rolled_back';
const DTB_RELEASE_EVENT_ROLLBACK_FAILED            = 'release_rollback_failed';

/**
 * All event types the webhook receiver accepts.
 *
 * @return string[]
 */
function dtb_release_known_event_types(): array {
	return [
		DTB_RELEASE_EVENT_PLANNED,
		DTB_RELEASE_EVENT_VALIDATED,
		DTB_RELEASE_EVENT_BACKUP_STARTED,
		DTB_RELEASE_EVENT_BACKED_UP,
		DTB_RELEASE_EVENT_DEPLOY_STARTED,
		DTB_RELEASE_EVENT_DEPLOYED,
		DTB_RELEASE_EVENT_VERIFIED,
		DTB_RELEASE_EVENT_COMPLETED,
		DTB_RELEASE_EVENT_FAILED,
		DTB_RELEASE_EVENT_ROLLED_BACK_AUTOMATICALLY,
		DTB_RELEASE_EVENT_ROLLBACK_STARTED,
		DTB_RELEASE_EVENT_ROLLED_BACK,
		DTB_RELEASE_EVENT_ROLLBACK_FAILED,
	];
}

/**
 * Event types that represent successful production deployment (cache
 * purge and drift recalculation trigger on these).
 *
 * @return string[]
 */
function dtb_release_deployed_event_types(): array {
	return [ DTB_RELEASE_EVENT_DEPLOYED, DTB_RELEASE_EVENT_ROLLED_BACK, DTB_RELEASE_EVENT_ROLLED_BACK_AUTOMATICALLY ];
}

/**
 * Derive a single status token from the set of event types recorded for a
 * release, evaluated in priority order (most terminal / most urgent first).
 *
 * @param string[] $event_types Event types present for one release_id.
 * @return string One of: rollback_failed, rolled_back, rolling_back,
 *                auto_rolled_back, failed, completed, verified, deployed,
 *                deploying, backed_up, backing_up, validated, planned, unknown.
 */
function dtb_release_status_for_events( array $event_types ): string {
	$present = array_flip( $event_types );

	if ( isset( $present[ DTB_RELEASE_EVENT_ROLLBACK_FAILED ] ) ) {
		return 'rollback_failed';
	}
	if ( isset( $present[ DTB_RELEASE_EVENT_ROLLED_BACK ] ) ) {
		return 'rolled_back';
	}
	if ( isset( $present[ DTB_RELEASE_EVENT_ROLLED_BACK_AUTOMATICALLY ] ) ) {
		return 'auto_rolled_back';
	}
	if ( isset( $present[ DTB_RELEASE_EVENT_ROLLBACK_STARTED ] ) ) {
		return 'rolling_back';
	}
	if ( isset( $present[ DTB_RELEASE_EVENT_FAILED ] ) ) {
		return 'failed';
	}
	if ( isset( $present[ DTB_RELEASE_EVENT_COMPLETED ] ) ) {
		return 'completed';
	}
	if ( isset( $present[ DTB_RELEASE_EVENT_VERIFIED ] ) ) {
		return 'verified';
	}
	if ( isset( $present[ DTB_RELEASE_EVENT_DEPLOYED ] ) ) {
		return 'deployed';
	}
	if ( isset( $present[ DTB_RELEASE_EVENT_DEPLOY_STARTED ] ) ) {
		return 'deploying';
	}
	if ( isset( $present[ DTB_RELEASE_EVENT_BACKED_UP ] ) ) {
		return 'backed_up';
	}
	if ( isset( $present[ DTB_RELEASE_EVENT_BACKUP_STARTED ] ) ) {
		return 'backing_up';
	}
	if ( isset( $present[ DTB_RELEASE_EVENT_VALIDATED ] ) ) {
		return 'validated';
	}
	if ( isset( $present[ DTB_RELEASE_EVENT_PLANNED ] ) ) {
		return 'planned';
	}

	return 'unknown';
}

/**
 * True when a status token represents a release that reached a terminal
 * state (nothing further will happen without a new operator action).
 */
function dtb_release_status_is_terminal( string $status ): bool {
	return in_array( $status, [ 'completed', 'rolled_back', 'auto_rolled_back', 'failed', 'rollback_failed' ], true );
}

/**
 * True when a status token represents an actively running release/rollback.
 */
function dtb_release_status_is_in_progress( string $status ): bool {
	return ! dtb_release_status_is_terminal( $status ) && 'unknown' !== $status;
}

/**
 * Human-readable label for a status token.
 */
function dtb_release_status_label( string $status ): string {
	$labels = [
		'planned'          => __( 'Planned', 'drywall-toolbox' ),
		'validated'        => __( 'Validated', 'drywall-toolbox' ),
		'backing_up'       => __( 'Backing Up', 'drywall-toolbox' ),
		'backed_up'        => __( 'Backed Up', 'drywall-toolbox' ),
		'deploying'        => __( 'Deploying', 'drywall-toolbox' ),
		'deployed'         => __( 'Deployed — Verifying', 'drywall-toolbox' ),
		'verified'         => __( 'Verified', 'drywall-toolbox' ),
		'completed'        => __( 'Completed', 'drywall-toolbox' ),
		'failed'           => __( 'Failed', 'drywall-toolbox' ),
		'auto_rolled_back' => __( 'Failed — Auto-Restored', 'drywall-toolbox' ),
		'rolling_back'     => __( 'Rolling Back', 'drywall-toolbox' ),
		'rolled_back'      => __( 'Rolled Back', 'drywall-toolbox' ),
		'rollback_failed'  => __( 'Rollback Failed', 'drywall-toolbox' ),
		'unknown'          => __( 'Unknown', 'drywall-toolbox' ),
	];

	return $labels[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) );
}

/**
 * Badge type ('success' | 'warning' | 'danger' | 'processing' | 'neutral')
 * for a status token, for use with dtb_admin_ui_badge().
 */
function dtb_release_status_badge_type( string $status ): string {
	$map = [
		'planned'          => 'neutral',
		'validated'        => 'neutral',
		'backing_up'       => 'processing',
		'backed_up'        => 'neutral',
		'deploying'        => 'processing',
		'deployed'         => 'processing',
		'verified'         => 'neutral',
		'completed'        => 'success',
		'failed'           => 'danger',
		'auto_rolled_back' => 'warning',
		'rolling_back'     => 'processing',
		'rolled_back'      => 'success',
		'rollback_failed'  => 'danger',
		'unknown'          => 'neutral',
	];

	return $map[ $status ] ?? 'neutral';
}
