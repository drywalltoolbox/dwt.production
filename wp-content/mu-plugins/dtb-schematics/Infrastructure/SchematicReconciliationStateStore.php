<?php
/**
 * DTB Schematics — SchematicReconciliationStateStore
 *
 * Persists bounded, resumable progress state for the Phase 3 reconciliation
 * operational script (Application/ReconcileSchematicSource.php). A single
 * `wp_options` row holds the current batch cursor, the set of canonical
 * schematic IDs observed as "covered by an active source row" during the
 * in-progress full pass (used only at the end of a completed pass to decide
 * retirement — never mid-pass), and compact summary counters.
 *
 * This store intentionally does not persist full per-asset reports — those
 * are returned to the caller (CLI/report output) for the run that produced
 * them and are not required to be durable across requests.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_SCHEMATIC_RECONCILE_STATE_OPTION = 'dtb_schematic_reconcile_state';

/**
 * @return array{
 *   cursor:int,
 *   pass_started_at:string,
 *   seen_canonical_ids: string[],
 *   last_run_at: string,
 *   last_run_mode: string,
 *   totals: array
 * }
 */
function dtb_schematic_reconcile_state_get(): array {
	$state = get_option( DTB_SCHEMATIC_RECONCILE_STATE_OPTION, [] );
	if ( ! is_array( $state ) ) {
		$state = [];
	}

	return array_merge(
		[
			'cursor'             => 0,
			'pass_started_at'    => '',
			'seen_canonical_ids' => [],
			'last_run_at'        => '',
			'last_run_mode'      => '',
			'totals'             => [],
		],
		$state
	);
}

function dtb_schematic_reconcile_state_save( array $state ): void {
	// Bound the "seen" set — a full pass never exceeds the manifest row count
	// (currently ~126), but cap defensively so this option can never grow
	// unbounded if the source package is ever much larger.
	if ( isset( $state['seen_canonical_ids'] ) && is_array( $state['seen_canonical_ids'] ) ) {
		$state['seen_canonical_ids'] = array_slice( array_values( array_unique( $state['seen_canonical_ids'] ) ), 0, 5000 );
	}

	update_option( DTB_SCHEMATIC_RECONCILE_STATE_OPTION, $state, false );
}

/**
 * Reset the cursor and "seen" accumulator to start a fresh full pass.
 * Does not clear last_run_at / last_run_mode (kept for operator visibility).
 */
function dtb_schematic_reconcile_state_start_pass(): array {
	$state                      = dtb_schematic_reconcile_state_get();
	$state['cursor']            = 0;
	$state['pass_started_at']   = current_time( 'mysql', true );
	$state['seen_canonical_ids'] = [];
	dtb_schematic_reconcile_state_save( $state );
	return $state;
}

function dtb_schematic_reconcile_state_delete(): void {
	delete_option( DTB_SCHEMATIC_RECONCILE_STATE_OPTION );
}
