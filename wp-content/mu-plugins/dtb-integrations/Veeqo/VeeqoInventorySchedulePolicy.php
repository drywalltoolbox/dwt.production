<?php
/**
 * Veeqo inventory scheduling policy.
 *
 * The recurring trigger uses its own hook so a future recurring action does not
 * falsely deduplicate an operator/configuration-triggered immediate reconcile.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_VEEQO_INVENTORY_RECURRING_HOOK = 'dtb_veeqo_inventory_reconcile_recurring';

// Replace the compatibility scheduler registered by the projection service.
remove_action( 'init', 'dtb_veeqo_inventory_schedule_recurring', 40 );

function dtb_veeqo_inventory_schedule_recurring_trigger(): void {
	if ( defined( 'DTB_LAUNCH_EXTERNAL_INTEGRATIONS_DISABLED' ) && DTB_LAUNCH_EXTERNAL_INTEGRATIONS_DISABLED ) {
		return;
	}
	if ( ! function_exists( 'dtb_veeqo_inventory_readiness' ) || empty( dtb_veeqo_inventory_readiness()['ready'] ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
		return;
	}
	$already = function_exists( 'as_has_scheduled_action' )
		? as_has_scheduled_action( DTB_VEEQO_INVENTORY_RECURRING_HOOK, [], DTB_VEEQO_INVENTORY_ACTION_GROUP )
		: ( function_exists( 'as_next_scheduled_action' ) && false !== as_next_scheduled_action( DTB_VEEQO_INVENTORY_RECURRING_HOOK, [], DTB_VEEQO_INVENTORY_ACTION_GROUP ) );
	if ( ! $already ) {
		as_schedule_recurring_action(
			time() + MINUTE_IN_SECONDS,
			DTB_VEEQO_INVENTORY_INTERVAL_SECONDS,
			DTB_VEEQO_INVENTORY_RECURRING_HOOK,
			[],
			DTB_VEEQO_INVENTORY_ACTION_GROUP,
			true
		);
	}
}
add_action( 'init', 'dtb_veeqo_inventory_schedule_recurring_trigger', 40 );

function dtb_veeqo_inventory_run_recurring_trigger(): void {
	if ( defined( 'DTB_LAUNCH_EXTERNAL_INTEGRATIONS_DISABLED' ) && DTB_LAUNCH_EXTERNAL_INTEGRATIONS_DISABLED ) {
		return;
	}
	// Run inside Action Scheduler, never in an interactive request.
	dtb_veeqo_inventory_scheduled_run( 0 );
}
add_action( DTB_VEEQO_INVENTORY_RECURRING_HOOK, 'dtb_veeqo_inventory_run_recurring_trigger', 10, 0 );
