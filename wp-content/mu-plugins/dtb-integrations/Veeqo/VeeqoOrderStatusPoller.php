<?php
/**
 * Veeqo order-status poller.
 *
 * Veeqo's public API has no webhook/events support (confirmed: the
 * developers.veeqo.com/api/ navigation has no webhooks section, and a 2023
 * Veeqo developer-forum thread explicitly states webhooks aren't supported).
 * A fully-built webhook *receiver* already exists in this codebase
 * (OperationalPipeline/VeeqoWebhookPipelineController.php) but is fail-closed
 * behind an auth contract Veeqo can never satisfy, since it never calls it —
 * that file is intentionally left untouched and inert.
 *
 * Instead, this file periodically asks Veeqo for the current status of
 * orders we already know are being fulfilled through Veeqo, and applies any
 * change via the shared VeeqoOrderStatusApplier.php.
 *
 * Scope is deliberately conservative: rather than inventing a "list orders
 * changed since X" mechanism (unverified whether Veeqo's list/filter/paging
 * capabilities support that), this polls only WooCommerce orders that are
 * (a) in the non-terminal, Veeqo-relevant status `processing`
 * and (b) already correlated to a Veeqo order id via post meta. That bounds
 * the work per run to orders we already know are in flight.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_VEEQO_ORDER_STATUS_POLL_HOOK              = 'dtb_veeqo_order_status_poll';
const DTB_VEEQO_ORDER_STATUS_POLL_LEGACY_HOOK       = 'dtb_veeqo_order_status_poll_recurring';
const DTB_VEEQO_ORDER_STATUS_POLL_ACTION_GROUP      = 'dtb-integrations-veeqo-order-status-poll';
const DTB_VEEQO_ORDER_STATUS_POLL_STATE_OPTION      = 'dtb_veeqo_order_status_poll_state';
const DTB_VEEQO_ORDER_STATUS_POLL_EXPIRED_OPTION    = 'dtb_veeqo_order_status_poll_expired_ids';
const DTB_VEEQO_ORDER_STATUS_POLL_MIGRATION_OPTION  = 'dtb_veeqo_order_status_poll_scheduler_version';
const DTB_VEEQO_ORDER_STATUS_POLL_SCHEDULER_VERSION = 2;

// Cap on orders checked per run. If more candidates exist than this, the
// oldest-polled orders (by a short-lived scheduler cursor) are checked first,
// so no order is starved indefinitely across successive runs.
const DTB_VEEQO_ORDER_STATUS_POLL_BATCH_SIZE = 25;
const DTB_VEEQO_ORDER_STATUS_POLL_MAX_AGE    = 30 * DAY_IN_SECONDS;

/**
 * Remove the permanent five-minute recurrence introduced by scheduler v1.
 *
 * The replacement is a unique single action. It schedules a successor only
 * while an eligible in-flight order exists, so an idle store has no Veeqo
 * order-status polling work at all.
 */
function dtb_veeqo_order_status_poll_migrate_scheduler(): void {
	if ( (int) get_option( DTB_VEEQO_ORDER_STATUS_POLL_MIGRATION_OPTION, 0 ) >= DTB_VEEQO_ORDER_STATUS_POLL_SCHEDULER_VERSION ) {
		return;
	}

	if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
		return;
	}

	as_unschedule_all_actions( DTB_VEEQO_ORDER_STATUS_POLL_LEGACY_HOOK, [], DTB_VEEQO_ORDER_STATUS_POLL_ACTION_GROUP );
	update_option( DTB_VEEQO_ORDER_STATUS_POLL_MIGRATION_OPTION, DTB_VEEQO_ORDER_STATUS_POLL_SCHEDULER_VERSION, false );
	dtb_veeqo_order_status_poll_record_expired_candidates();
	dtb_veeqo_order_status_poll_schedule_if_needed( MINUTE_IN_SECONDS );
}
add_action( 'init', 'dtb_veeqo_order_status_poll_migrate_scheduler', 40 );

/**
 * Safety net for a legacy recurrence that was already running during deploy.
 */
function dtb_veeqo_order_status_poll_retire_legacy_action(): void {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( DTB_VEEQO_ORDER_STATUS_POLL_LEGACY_HOOK, [], DTB_VEEQO_ORDER_STATUS_POLL_ACTION_GROUP );
	}
	dtb_veeqo_order_status_poll_schedule_if_needed( MINUTE_IN_SECONDS );
}
add_action( DTB_VEEQO_ORDER_STATUS_POLL_LEGACY_HOOK, 'dtb_veeqo_order_status_poll_retire_legacy_action', 1 );

/**
 * Cheap watchdog for missed/failed single actions.
 *
 * Active chains are checked at most every 15 minutes; idle stores at most
 * every six hours. A newly synced order schedules immediately from the order
 * queue, so the idle watchdog is recovery rather than the primary trigger.
 */
function dtb_veeqo_order_status_poll_recover_scheduler(): void {
	$state     = (array) get_option( DTB_VEEQO_ORDER_STATUS_POLL_STATE_OPTION, [] );
	$is_active = in_array( (string) ( $state['status'] ?? '' ), [ 'active', 'running', 'scheduled' ], true );
	$key       = 'dtb_veeqo_order_status_poll_recovery_check';

	if ( false !== get_transient( $key ) ) {
		return;
	}

	set_transient( $key, '1', $is_active ? 15 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS );
	dtb_veeqo_order_status_poll_schedule_if_needed( MINUTE_IN_SECONDS );
}
add_action( 'init', 'dtb_veeqo_order_status_poll_recover_scheduler', 50 );

/**
 * Schedule one unique poll only when eligible work exists.
 *
 * @param int       $delay                Delay in seconds.
 * @param bool|null $known_has_candidates Optional already-computed candidate state.
 */
function dtb_veeqo_order_status_poll_schedule_if_needed( int $delay = 0, ?bool $known_has_candidates = null ): bool {
	if ( ! function_exists( 'as_schedule_single_action' )
		|| ! function_exists( 'dtb_veeqo_production_readiness' )
		|| empty( dtb_veeqo_production_readiness()['ready'] ) ) {
		dtb_veeqo_order_status_poll_update_state( 'idle', [ 'next_run_at' => null ] );
		return false;
	}

	$has_candidates = null === $known_has_candidates ? dtb_veeqo_order_status_poll_has_candidates() : $known_has_candidates;
	if ( ! $has_candidates ) {
		dtb_veeqo_order_status_poll_update_state( 'idle', [ 'next_run_at' => null ] );
		return false;
	}

	// Check pending work only. The current action may be in progress while it
	// schedules its successor and must not count as that pending successor.
	$scheduled = function_exists( 'as_next_scheduled_action' )
		? false !== as_next_scheduled_action( DTB_VEEQO_ORDER_STATUS_POLL_HOOK, [], DTB_VEEQO_ORDER_STATUS_POLL_ACTION_GROUP )
		: ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( DTB_VEEQO_ORDER_STATUS_POLL_HOOK, [], DTB_VEEQO_ORDER_STATUS_POLL_ACTION_GROUP ) );
	if ( $scheduled ) {
		return false;
	}

	$run_at = time() + max( MINUTE_IN_SECONDS, $delay );
	$action = as_schedule_single_action(
		$run_at,
		DTB_VEEQO_ORDER_STATUS_POLL_HOOK,
		[],
		DTB_VEEQO_ORDER_STATUS_POLL_ACTION_GROUP,
		false
	);
	if ( false === $action || 0 === $action ) {
		return false;
	}

	dtb_veeqo_order_status_poll_update_state( 'scheduled', [ 'next_run_at' => $run_at ] );
	return true;
}

add_action( DTB_VEEQO_ORDER_STATUS_POLL_HOOK, 'dtb_veeqo_order_status_poll_run', 10, 0 );

/**
 * Store a compact operational projection without touching an order.
 */
function dtb_veeqo_order_status_poll_update_state( string $status, array $extra = [] ): void {
	$state = array_merge(
		(array) get_option( DTB_VEEQO_ORDER_STATUS_POLL_STATE_OPTION, [] ),
		[
			'status'     => sanitize_key( $status ),
			'updated_at' => time(),
		],
		$extra
	);
	update_option( DTB_VEEQO_ORDER_STATUS_POLL_STATE_OPTION, $state, false );
}

/**
 * True when at least one bounded, non-terminal order needs observation.
 */
function dtb_veeqo_order_status_poll_has_candidates(): bool {
	return ! empty( dtb_veeqo_order_status_poll_candidate_orders( 1 ) );
}

/**
 * Project aged-out orders into operator events without mutating order state.
 */
function dtb_veeqo_order_status_poll_record_expired_candidates(): int {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return 0;
	}

	$recorded = array_values( array_filter( array_map( 'absint', (array) get_option( DTB_VEEQO_ORDER_STATUS_POLL_EXPIRED_OPTION, [] ) ) ) );
	$query    = [
		'status'       => [ 'processing' ],
		'limit'        => 250,
		'return'       => 'objects',
		'date_created' => '<' . ( time() - DTB_VEEQO_ORDER_STATUS_POLL_MAX_AGE ),
		'meta_query'   => [
			'relation' => 'OR',
			[ 'key' => '_dtb_veeqo_order_id', 'compare' => 'EXISTS' ],
			[ 'key' => '_veeqo_order_id', 'compare' => 'EXISTS' ],
		],
	];
	if ( ! empty( $recorded ) ) {
		$query['exclude'] = $recorded;
	}
	$orders = wc_get_orders( $query );

	$added = 0;
	foreach ( is_array( $orders ) ? $orders : [] as $order ) {
		if ( ! $order instanceof WC_Order || in_array( (int) $order->get_id(), $recorded, true ) ) {
			continue;
		}

		$order_id   = (int) $order->get_id();
		$recorded[] = $order_id;
		++$added;
		if ( function_exists( 'dtb_order_append_event' ) ) {
			dtb_order_append_event( $order_id, 'integration.veeqo.polling_expired', [
				'source'          => 'veeqo_poll_watchdog',
				'actor_type'      => 'system',
				'visibility'      => 'operator',
				'idempotency_key' => 'veeqo-polling-expired:' . $order_id,
				'payload'         => [ 'max_age_days' => (int) ( DTB_VEEQO_ORDER_STATUS_POLL_MAX_AGE / DAY_IN_SECONDS ) ],
			] );
		}
	}

	if ( $added > 0 ) {
		update_option( DTB_VEEQO_ORDER_STATUS_POLL_EXPIRED_OPTION, array_slice( array_values( array_unique( $recorded ) ), -500 ), false );
	}
	return $added;
}

/**
 * Adaptive delay: prompt for new orders, progressively quieter for older ones.
 */
function dtb_veeqo_order_status_poll_next_delay( array $orders, int $errors ): int {
	if ( $errors > 0 ) {
		return 30 * MINUTE_IN_SECONDS;
	}

	$youngest_age = PHP_INT_MAX;
	foreach ( $orders as $order ) {
		if ( ! $order instanceof WC_Order ) {
			continue;
		}
		$created_at = $order->get_date_created();
		if ( $created_at ) {
			$youngest_age = min( $youngest_age, max( 0, time() - $created_at->getTimestamp() ) );
		}
	}

	if ( $youngest_age <= DAY_IN_SECONDS ) {
		return 10 * MINUTE_IN_SECONDS;
	}
	if ( $youngest_age <= 7 * DAY_IN_SECONDS ) {
		return 30 * MINUTE_IN_SECONDS;
	}
	return 2 * HOUR_IN_SECONDS;
}

/**
 * Return the Veeqo order id correlated to a WooCommerce order, if any.
 */
function dtb_veeqo_order_status_poll_correlated_veeqo_id( WC_Order $order ): int {
	$id = absint( $order->get_meta( '_dtb_veeqo_order_id', true ) );
	if ( $id > 0 ) {
		return $id;
	}
	return absint( $order->get_meta( '_veeqo_order_id', true ) );
}

/**
 * Find WooCommerce orders that are still non-terminal and Veeqo-correlated,
 * oldest-polled first, capped at $limit.
 *
 * @param int $limit Maximum orders to return.
 * @return WC_Order[]
 */
function dtb_veeqo_order_status_poll_candidate_orders( int $limit ): array {
	if ( $limit <= 0 || ! function_exists( 'wc_get_orders' ) ) {
		return [];
	}

	// Pull a wider candidate window than $limit so we can sort by "oldest
	// polled first" in PHP (including orders never polled) without relying
	// on WooCommerce's meta-based ORDER BY across an optional meta key.
	$candidate_ceiling = 1 === $limit ? 1 : max( $limit * 4, 100 );

	$orders = wc_get_orders( [
		'status'     => [ 'processing' ],
		'limit'      => $candidate_ceiling,
		'return'     => 'objects',
		'orderby'    => 'date',
		'order'      => 'ASC',
		'date_created' => '>' . ( time() - DTB_VEEQO_ORDER_STATUS_POLL_MAX_AGE ),
		'meta_query' => [
			'relation' => 'OR',
			[ 'key' => '_dtb_veeqo_order_id', 'compare' => 'EXISTS' ],
			[ 'key' => '_veeqo_order_id', 'compare' => 'EXISTS' ],
		],
	] );

	if ( ! is_array( $orders ) || empty( $orders ) ) {
		return [];
	}

	usort(
		$orders,
		static function ( $a, $b ): int {
			$a_ts = $a instanceof WC_Order ? dtb_veeqo_order_status_poll_last_checked_at( (int) $a->get_id(), $a ) : PHP_INT_MAX;
			$b_ts = $b instanceof WC_Order ? dtb_veeqo_order_status_poll_last_checked_at( (int) $b->get_id(), $b ) : PHP_INT_MAX;
			return $a_ts <=> $b_ts;
		}
	);

	return array_slice( $orders, 0, $limit );
}

/**
 * Read the scheduler cursor without mutating the WooCommerce order.
 *
 * The legacy order-meta fallback preserves ordering across deployment. New
 * cursor writes use transients because a poll heartbeat is scheduler state,
 * not accounting/order state, and must not fire order-save integrations.
 */
function dtb_veeqo_order_status_poll_last_checked_at( int $order_id, WC_Order $order ): int {
	$cursor = get_transient( 'dtb_veeqo_order_status_polled_' . $order_id );
	if ( false !== $cursor ) {
		return absint( $cursor );
	}

	return absint( $order->get_meta( '_dtb_veeqo_last_polled_at', true ) );
}

/**
 * Best-effort extraction of tracking info from a Veeqo order payload.
 *
 * The exact nesting of shipment/tracking data under `GET /orders/{id}` was
 * not fully confirmed from available docs (the order resource references
 * `allocations[].shipment`, shown as null in the example response, with full
 * tracking detail living behind a separate tracking_events endpoint keyed by
 * shipment id). This extraction is therefore deliberately defensive and
 * best-effort: it only ever enriches tracking meta when a value is present
 * in an expected shape, and never fails or blocks the status update itself
 * if the shape differs from what's anticipated here.
 *
 * @param array $data Veeqo order payload (decoded JSON body).
 * @return array{tracking_number:string,tracking_carrier:string}
 */
function dtb_veeqo_order_status_poll_extract_tracking( array $data ): array {
	$number  = '';
	$carrier = '';

	if ( ! empty( $data['tracking_number'] ) && is_string( $data['tracking_number'] ) ) {
		$number = $data['tracking_number'];
	}
	if ( ! empty( $data['carrier'] ) && is_string( $data['carrier'] ) ) {
		$carrier = $data['carrier'];
	}

	if ( '' === $number && ! empty( $data['allocations'] ) && is_array( $data['allocations'] ) ) {
		foreach ( $data['allocations'] as $allocation ) {
			if ( ! is_array( $allocation ) || empty( $allocation['shipment'] ) || ! is_array( $allocation['shipment'] ) ) {
				continue;
			}
			$shipment = $allocation['shipment'];

			$tracking_field = $shipment['tracking_number'] ?? null;
			if ( is_array( $tracking_field ) ) {
				$number = (string) ( $tracking_field['tracking_number'] ?? '' );
			} elseif ( is_string( $tracking_field ) ) {
				$number = $tracking_field;
			}

			$carrier_field = $shipment['carrier'] ?? null;
			if ( is_array( $carrier_field ) ) {
				$carrier = (string) ( $carrier_field['name'] ?? '' );
			} elseif ( is_string( $carrier_field ) ) {
				$carrier = $carrier_field;
			}

			if ( '' !== $number ) {
				break;
			}
		}
	}

	return [
		'tracking_number'  => sanitize_text_field( $number ),
		'tracking_carrier' => sanitize_text_field( $carrier ),
	];
}

/**
 * Run one poll pass: fetch current status for a bounded batch of in-flight,
 * Veeqo-correlated WooCommerce orders and apply any change.
 *
 * Never runs interactively — only via Action Scheduler. Read-mostly toward
 * Veeqo: every Veeqo call is a GET, this never writes back to Veeqo.
 */
function dtb_veeqo_order_status_poll_run(): void {
	if ( ! function_exists( 'dtb_veeqo_production_readiness' ) || empty( dtb_veeqo_production_readiness()['ready'] ) || ! function_exists( 'dtb_veeqo_request' ) ) {
		// Veeqo not configured (or the request client isn't loaded): skip the
		// run entirely, same defensive posture as the inventory pull job.
		return;
	}

	dtb_veeqo_order_status_poll_update_state( 'running', [ 'started_at' => time(), 'next_run_at' => null ] );
	$expired = dtb_veeqo_order_status_poll_record_expired_candidates();
	$orders = dtb_veeqo_order_status_poll_candidate_orders( DTB_VEEQO_ORDER_STATUS_POLL_BATCH_SIZE );
	if ( empty( $orders ) ) {
		dtb_veeqo_order_status_poll_update_state( 'idle', [ 'last_checked' => 0, 'last_applied' => 0, 'last_errors' => 0, 'next_run_at' => null ] );
		if ( function_exists( 'dtb_veeqo_log' ) ) {
			dtb_veeqo_log( 'debug', 'order_status_poll_idle', 'Veeqo order status poll found no in-flight, Veeqo-correlated orders to check.' );
		}
		return;
	}

	$checked = 0;
	$applied = 0;
	$errors  = 0;

	foreach ( $orders as $order ) {
		if ( ! $order instanceof WC_Order ) {
			continue;
		}

		$order_id       = (int) $order->get_id();
		$veeqo_order_id = dtb_veeqo_order_status_poll_correlated_veeqo_id( $order );

		/*
		 * Mark as checked without saving the WooCommerce order. The previous
		 * implementation rewrote order meta every five minutes even when the
		 * Veeqo response was unchanged, which caused order-save observers to
		 * re-enter customer notification behavior.
		 */
		set_transient( 'dtb_veeqo_order_status_polled_' . $order_id, time(), WEEK_IN_SECONDS );
		++$checked;

		if ( $veeqo_order_id <= 0 ) {
			continue;
		}

		try {
			$response = dtb_veeqo_request( 'GET', '/orders/' . $veeqo_order_id );
			if ( empty( $response['ok'] ) || ! is_array( $response['data'] ?? null ) ) {
				++$errors;
				if ( function_exists( 'dtb_veeqo_log' ) ) {
					dtb_veeqo_log( 'warn', 'order_status_poll_fetch_failed', 'Failed to fetch a Veeqo order for status polling; will retry on a later run.', [
						'order_id'       => $order_id,
						'veeqo_order_id' => $veeqo_order_id,
						'status'         => (int) ( $response['status'] ?? 0 ),
						'error'          => (string) ( $response['error'] ?? '' ),
					] );
				}
				continue; // One order's failure never aborts the batch.
			}

			$data         = $response['data'];
			$veeqo_status = sanitize_key( (string) ( $data['status'] ?? '' ) );
			if ( '' === $veeqo_status ) {
				if ( function_exists( 'dtb_veeqo_log' ) ) {
					dtb_veeqo_log( 'warn', 'order_status_poll_missing_status', 'Veeqo order response had no status field; skipping.', [
						'order_id'       => $order_id,
						'veeqo_order_id' => $veeqo_order_id,
					] );
				}
				continue;
			}

			$tracking = dtb_veeqo_order_status_poll_extract_tracking( $data );

			$result = function_exists( 'dtb_veeqo_apply_order_fulfillment_status' )
				? dtb_veeqo_apply_order_fulfillment_status( $order, $veeqo_status, [
					'source'           => 'poll',
					'reference'        => 'poll-' . gmdate( 'YmdHi' ),
					'veeqo_order_id'   => $veeqo_order_id,
					'tracking_number'  => $tracking['tracking_number'],
					'tracking_carrier' => $tracking['tracking_carrier'],
					// Forwarded so a future confirmed shipment-identity field
					// (see VeeqoFulfillmentProjector.php::dtb_veeqo_resolve_shipment_identity())
					// has real Veeqo data to resolve against without any
					// further poller changes.
					'veeqo_payload'    => $data,
				] )
				: [ 'applied' => false, 'result' => 'unmapped_status' ];

			if ( ! empty( $result['applied'] ) ) {
				++$applied;
			} elseif ( 'unmapped_status' === ( $result['result'] ?? '' ) && function_exists( 'dtb_veeqo_log' ) ) {
				dtb_veeqo_log( 'debug', 'order_status_poll_unmapped', 'Veeqo order status has no confirmed WooCommerce mapping; left order unchanged.', [
					'order_id'       => $order_id,
					'veeqo_order_id' => $veeqo_order_id,
					'veeqo_status'   => $veeqo_status,
				] );
			}
		} catch ( Throwable $e ) {
			++$errors;
			if ( function_exists( 'dtb_veeqo_log' ) ) {
				dtb_veeqo_log( 'error', 'order_status_poll_order_failed', 'Unexpected failure while polling one Veeqo order; the remaining batch will continue.', [
					'order_id'       => $order_id,
					'veeqo_order_id' => $veeqo_order_id,
					'error_type'     => get_class( $e ),
					'error'          => sanitize_text_field( $e->getMessage() ),
				] );
			}
		}
	}

	if ( function_exists( 'dtb_veeqo_log' ) ) {
		dtb_veeqo_log(
			$errors > 0 ? 'warn' : 'info',
			'order_status_poll_complete',
			sprintf( 'Veeqo order status poll complete: checked %d order(s), applied %d update(s), %d fetch error(s).', $checked, $applied, $errors ),
			[ 'checked' => $checked, 'applied' => $applied, 'errors' => $errors ]
		);
	}

	if ( class_exists( 'DTB_VeeqoSyncJob' ) ) {
		DTB_VeeqoSyncJob::log_timestamp( 'order_status_poll' );
	} elseif ( function_exists( 'dtb_veeqo_log_sync_timestamp' ) ) {
		dtb_veeqo_log_sync_timestamp( 'order_status_poll' );
	}

	$next_delay    = dtb_veeqo_order_status_poll_next_delay( $orders, $errors );
	$has_candidates = dtb_veeqo_order_status_poll_has_candidates();
	dtb_veeqo_order_status_poll_update_state(
		$has_candidates ? 'active' : 'idle',
		[
			'last_checked' => $checked,
			'last_applied' => $applied,
			'last_errors'  => $errors,
			'expired_count' => count( (array) get_option( DTB_VEEQO_ORDER_STATUS_POLL_EXPIRED_OPTION, [] ) ),
			'newly_expired' => $expired,
			'last_run_at'  => time(),
			'next_run_at'  => null,
		]
	);
	dtb_veeqo_order_status_poll_schedule_if_needed( $next_delay, $has_candidates );
}
