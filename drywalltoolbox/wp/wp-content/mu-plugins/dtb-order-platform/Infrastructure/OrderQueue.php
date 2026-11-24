<?php
/**
 * Infrastructure: Order Queue — job enqueue, retry, and async job handlers.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DTB_ORDER_JOB_MAX_RETRIES' ) ) {
	define( 'DTB_ORDER_JOB_MAX_RETRIES', 3 );
}
if ( ! defined( 'DTB_ORDER_JOB_RETRY_BASE' ) ) {
	define( 'DTB_ORDER_JOB_RETRY_BASE', 300 );
}

/** Recursively sort associative job arguments before hashing queue identity. */
function dtb_order_queue_canonicalize_args( array $args ): array {
	foreach ( $args as $key => $value ) {
		if ( is_array( $value ) ) {
			$args[ $key ] = dtb_order_queue_canonicalize_args( $value );
		}
	}
	if ( ! array_is_list( $args ) ) {
		ksort( $args, SORT_STRING );
	}
	return $args;
}

/** Build stable queue identity from the owning Woo order, hook and canonical arguments. */
function dtb_order_queue_receipt_identity( string $job_type, int $order_id, array $args ): string {
	$canonical = wp_json_encode( dtb_order_queue_canonicalize_args( $args ) );
	return hash( 'sha256', $order_id . "\0" . $job_type . "\0" . ( is_string( $canonical ) ? $canonical : '' ) );
}

/** Find an exact Action Scheduler action, including terminal history. */
function dtb_order_queue_find_exact_action( string $job_type, array $scheduled_args ): array {
	if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
		return [ 'status' => 'missing', 'action_id' => 0 ];
	}
	$statuses = class_exists( 'ActionScheduler_Store' )
		? [
			ActionScheduler_Store::STATUS_PENDING,
			ActionScheduler_Store::STATUS_RUNNING,
			ActionScheduler_Store::STATUS_COMPLETE,
			ActionScheduler_Store::STATUS_FAILED,
			ActionScheduler_Store::STATUS_CANCELED,
		]
		: [ 'pending', 'in-progress', 'complete', 'failed', 'canceled' ];
	foreach ( $statuses as $status ) {
		$ids = as_get_scheduled_actions( [
			'hook'     => $job_type,
			'args'     => $scheduled_args,
			'group'    => 'dtb-orders',
			'status'   => $status,
			'per_page' => 1,
			'orderby'  => 'ID',
			'order'    => 'DESC',
		], 'ids' );
		if ( is_array( $ids ) && ! empty( $ids ) ) {
			return [ 'status' => (string) $status, 'action_id' => absint( reset( $ids ) ) ];
		}
	}
	return [ 'status' => 'missing', 'action_id' => 0 ];
}

/** Verify that a durable receipt belongs to the exact queue identity and action. */
function dtb_order_queue_receipt_matches( mixed $receipt, string $identity, array $allowed_statuses, ?int $action_id = null ): bool {
	if ( ! is_array( $receipt )
		|| ! hash_equals( $identity, (string) ( $receipt['identity'] ?? '' ) )
		|| ! in_array( (string) ( $receipt['status'] ?? '' ), $allowed_statuses, true )
	) {
		return false;
	}
	return null === $action_id || $action_id === absint( $receipt['action_id'] ?? 0 );
}

/**
 * Persist and return a structured Action Scheduler enqueue receipt.
 *
 * `outcome_unknown` deliberately blocks blind replay: it means an intent exists
 * but neither a durable receipt nor an exact pending action can prove outcome.
 *
 * @param array $policy Optional explicit disposition (`not_applicable_reason`).
 * @return array{status:string,identity:string,action_id:int,message:string}
 */
function dtb_order_enqueue_job_with_receipt( string $job_type, int $order_id, array $args = [], int $delay = 0, array $policy = [] ): array {
	$identity = dtb_order_queue_receipt_identity( $job_type, $order_id, $args );
	$result   = static fn( string $status, int $action_id = 0, string $message = '' ): array => [
		'status'    => $status,
		'identity'  => $identity,
		'action_id' => $action_id,
		'message'   => $message,
	];
	if ( '' === $job_type || $order_id <= 0 ) {
		return $result( 'schedule_failed', 0, 'Invalid queue identity.' );
	}

	$intent_key  = 'dtb_order_job_intent_' . $identity;
	$receipt_key = 'dtb_order_job_receipt_' . $identity;
	$receipt     = get_option( $receipt_key, null );
	if ( dtb_order_queue_receipt_matches( $receipt, $identity, [ 'scheduled', 'already_scheduled', 'already_completed', 'intentionally_not_applicable' ] ) ) {
		if ( in_array( (string) $receipt['status'], [ 'scheduled', 'already_scheduled' ], true ) ) {
			$stored_args = $args;
			$stored_args['_dtb_receipt'] = $identity;
			$history = dtb_order_queue_find_exact_action( $job_type, [ $order_id, $stored_args ] );
			if ( in_array( $history['status'], class_exists( 'ActionScheduler_Store' ) ? [ ActionScheduler_Store::STATUS_FAILED, ActionScheduler_Store::STATUS_CANCELED ] : [ 'failed', 'canceled' ], true ) ) {
				$receipt['status'] = 'blocked';
				$receipt['failure_status'] = $history['status'];
				update_option( $receipt_key, $receipt, false );
				return $result( 'blocked', absint( $history['action_id'] ), 'Exact prior Action Scheduler job ended without success.' );
			}
		}
		return $result( 'already_scheduled', absint( $receipt['action_id'] ?? 0 ), 'Durable queue receipt already exists.' );
	}

	$not_applicable_reason = sanitize_key( (string) ( $policy['not_applicable_reason'] ?? '' ) );
	if ( '' !== $not_applicable_reason ) {
		$allowed_dispositions = [
			'dtb_order_sync_veeqo'      => [ 'payment_pending', 'veeqo_sync_disabled' ],
			'dtb_order_sync_quickbooks' => [ 'payment_pending' ],
		];
		if ( ! in_array( $not_applicable_reason, $allowed_dispositions[ $job_type ] ?? [], true ) ) {
			return $result( 'schedule_failed', 0, 'Queue disposition is not allowed by owning policy.' );
		}
		$receipt = [
			'identity'   => $identity,
			'status'     => 'intentionally_not_applicable',
			'action_id'  => 0,
			'reason'     => $not_applicable_reason,
			'recorded_at'=> current_time( 'mysql', true ),
		];
		add_option( $receipt_key, $receipt, '', 'no' );
		if ( ! dtb_order_queue_receipt_matches( get_option( $receipt_key, null ), $identity, [ 'intentionally_not_applicable' ], 0 ) ) {
			return $result( 'outcome_unknown', 0, 'Could not prove the explicit queue disposition.' );
		}
		return $result( 'intentionally_not_applicable', 0, $not_applicable_reason );
	}

	if ( 'dtb_order_sync_veeqo' === $job_type && function_exists( 'dtb_veeqo_order_automation_is_paused' ) && dtb_veeqo_order_automation_is_paused() ) {
		return $result( 'blocked', 0, 'Veeqo order automation is paused.' );
	}
	if ( 'dtb_order_sync_veeqo' === $job_type && function_exists( 'dtb_order_veeqo_order_sync_enabled' ) && ! dtb_order_veeqo_order_sync_enabled() ) {
		return $result( 'blocked', 0, 'Veeqo order creation is disabled by the active launch policy.' );
	}
	if ( 'dtb_order_sync_quickbooks' === $job_type && function_exists( 'dtb_qbo_exports_paused' ) && dtb_qbo_exports_paused() ) {
		return $result( 'blocked', 0, 'QuickBooks export is paused.' );
	}
	$job_args                 = $args;
	$job_args['_dtb_receipt'] = $identity;
	if ( function_exists( 'dtb_order_write_boundary_should_block_job' ) && dtb_order_write_boundary_should_block_job( $job_type, $order_id, $job_args, true ) ) {
		return $result( 'blocked', 0, 'Order write boundary rejected the queue job.' );
	}
	if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
		return $result( 'schedule_failed', 0, 'Action Scheduler is unavailable.' );
	}

	$scheduled_args           = [ $order_id, $job_args ];
	$existing                 = dtb_order_queue_find_exact_action( $job_type, $scheduled_args );
	if ( 'missing' !== $existing['status'] ) {
		$terminal_success = class_exists( 'ActionScheduler_Store' )
			? ActionScheduler_Store::STATUS_COMPLETE === $existing['status']
			: 'complete' === $existing['status'];
		$terminal_failure = in_array( $existing['status'], class_exists( 'ActionScheduler_Store' )
			? [ ActionScheduler_Store::STATUS_FAILED, ActionScheduler_Store::STATUS_CANCELED ]
			: [ 'failed', 'canceled' ], true );
		if ( $terminal_failure ) {
			return $result( 'blocked', absint( $existing['action_id'] ), 'Exact prior Action Scheduler job ended without success.' );
		}
		$receipt = [
			'identity'    => $identity,
			'status'      => $terminal_success ? 'already_completed' : 'already_scheduled',
			'action_id'   => absint( $existing['action_id'] ),
			'recorded_at' => current_time( 'mysql', true ),
		];
		add_option( $receipt_key, $receipt, '', 'no' );
		if ( ! dtb_order_queue_receipt_matches( get_option( $receipt_key, null ), $identity, [ $receipt['status'] ], absint( $existing['action_id'] ) ) ) {
			return $result( 'outcome_unknown', absint( $existing['action_id'] ), 'Exact action exists but a durable receipt cannot be proven.' );
		}
		return $result( $terminal_success ? 'already_completed' : 'already_scheduled', absint( $existing['action_id'] ), 'Exact Action Scheduler job is reconciled.' );
	}

	$intent = [
		'order_id'   => $order_id,
		'job_type'   => $job_type,
		'args_hash'  => hash( 'sha256', (string) wp_json_encode( dtb_order_queue_canonicalize_args( $args ) ) ),
		'created_at' => current_time( 'mysql', true ),
	];
	if ( ! add_option( $intent_key, $intent, '', 'no' ) ) {
		$existing = dtb_order_queue_find_exact_action( $job_type, $scheduled_args );
		if ( 'missing' !== $existing['status'] ) {
			$terminal_success = class_exists( 'ActionScheduler_Store' )
				? ActionScheduler_Store::STATUS_COMPLETE === $existing['status']
				: 'complete' === $existing['status'];
			if ( $terminal_success ) {
				$receipt = [ 'identity' => $identity, 'status' => 'already_completed', 'action_id' => absint( $existing['action_id'] ), 'recorded_at' => current_time( 'mysql', true ) ];
				add_option( $receipt_key, $receipt, '', 'no' );
				if ( dtb_order_queue_receipt_matches( get_option( $receipt_key, null ), $identity, [ 'already_completed' ], absint( $existing['action_id'] ) ) ) {
					return $result( 'already_completed', absint( $existing['action_id'] ), 'Prior intent resolved to an exact completed action.' );
				}
			}
			return $result( 'outcome_unknown', absint( $existing['action_id'] ), 'Prior intent resolves to an action without a durable successful receipt.' );
		}
		// A missing exact action cannot prove that no earlier side effect occurred.
		// Only an audited operator recovery may authorize a replacement.
		return $result( 'outcome_unknown', 0, 'Prior intent has no exact Action Scheduler record; operator recovery is required.' );
	}

	$action_id = (int) as_schedule_single_action( time() + max( 0, $delay ), $job_type, $scheduled_args, 'dtb-orders', true );
	if ( $action_id <= 0 ) {
		$existing = dtb_order_queue_find_exact_action( $job_type, $scheduled_args );
		return 'missing' !== $existing['status']
			? $result( 'outcome_unknown', absint( $existing['action_id'] ), 'Action exists but its durable receipt could not be recorded.' )
			: $result( 'schedule_failed', 0, 'Action Scheduler rejected the job.' );
	}

	$receipt = [
		'identity'    => $identity,
		'status'      => 'scheduled',
		'action_id'   => $action_id,
		'scheduled_at'=> time() + max( 0, $delay ),
		'recorded_at' => current_time( 'mysql', true ),
	];
	add_option( $receipt_key, $receipt, '', 'no' );
	if ( ! dtb_order_queue_receipt_matches( get_option( $receipt_key, null ), $identity, [ 'scheduled' ], $action_id ) ) {
		return $result( 'outcome_unknown', $action_id, 'Action was accepted but its durable receipt could not be recorded.' );
	}

	return $result( 'scheduled', $action_id, 'Action Scheduler accepted the job and the receipt is durable.' );
}

/** Append an immutable recovery marker for an accepted marketplace dispatch that cannot be safely deferred. */
function dtb_order_marketplace_dispatch_mark_recovery( WC_Order $order, string $receipt_id, string $reason ): bool {
	$identity_hash = (string) $order->get_meta( '_dtb_marketplace_identity_hash', true );
	if ( ! preg_match( '/^[a-f0-9]{64}$/D', $identity_hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $receipt_id ) ) {
		return false;
	}
	$key = 'marketplace-dispatch-recovery:v2:' . $identity_hash . ':' . $receipt_id;
	$fallback_key = 'dtb_marketplace_dispatch_recovery_' . hash( 'sha256', (int) $order->get_id() . ':' . $receipt_id );
	$fallback     = [ 'order_id' => (int) $order->get_id(), 'receipt_id' => $receipt_id, 'reason' => sanitize_key( $reason ) ];
	add_option( $fallback_key, $fallback, '', 'no' );
	if ( function_exists( 'dtb_order_append_event' ) && function_exists( 'dtb_order_event_idempotency_exists' ) ) {
		dtb_order_append_event( (int) $order->get_id(), 'marketplace.dispatch.recovery_required', [
			'source' => 'action_scheduler', 'actor_type' => 'system', 'visibility' => 'operator',
			'idempotency_key' => $key, 'payload' => [ 'reason' => sanitize_key( $reason ), 'receipt_id' => $receipt_id ],
		] );
	}
	$stored_fallback = get_option( $fallback_key, null );
	return ( function_exists( 'dtb_order_event_idempotency_exists' ) && dtb_order_event_idempotency_exists( $key ) )
		|| ( is_array( $stored_fallback ) && (int) ( $stored_fallback['order_id'] ?? 0 ) === (int) $order->get_id() && hash_equals( $receipt_id, (string) ( $stored_fallback['receipt_id'] ?? '' ) ) );
}

/** Authorize marketplace dispatch only after its exact durable receipt and completion marker exist. */
function dtb_order_marketplace_dispatch_execution_ready( $order, string $job_type, array $args ): bool {
	if ( ! $order instanceof WC_Order || '2' !== (string) $order->get_meta( '_dtb_marketplace_materialization_contract', true ) ) {
		return true;
	}
	$receipt_id = (string) ( $args['_dtb_receipt'] ?? '' );
	$retry      = max( 0, absint( $args['_dtb_dispatch_retry'] ?? 0 ) );
	$base_args  = $args;
	unset( $base_args['_dtb_receipt'], $base_args['_dtb_dispatch_retry'], $base_args['attempt'] );
	$identity = dtb_order_queue_receipt_identity( $job_type, (int) $order->get_id(), $base_args );
	if ( 'marketplace_materialization' !== sanitize_key( (string) ( $args['trigger'] ?? '' ) )
		|| ! preg_match( '/^[a-f0-9]{64}$/D', $receipt_id )
		|| ! hash_equals( $identity, $receipt_id )
	) {
		return false;
	}
	$receipt = get_option( 'dtb_order_job_receipt_' . $identity, null );
	$receipt_action_id = is_array( $receipt ) ? absint( $receipt['action_id'] ?? 0 ) : 0;
	if ( $receipt_action_id <= 0 || ! dtb_order_queue_receipt_matches( $receipt, $identity, [ 'scheduled', 'already_scheduled' ], $receipt_action_id ) ) {
		return false;
	}
	$recovery_key = 'marketplace-dispatch-recovery:v2:' . (string) $order->get_meta( '_dtb_marketplace_identity_hash', true ) . ':' . $identity;
	$fallback_key = 'dtb_marketplace_dispatch_recovery_' . hash( 'sha256', (int) $order->get_id() . ':' . $identity );
	if ( ! function_exists( 'dtb_order_event_idempotency_exists' ) || dtb_order_event_idempotency_exists( $recovery_key ) || is_array( get_option( $fallback_key, null ) ) ) {
		return false;
	}
	if ( 'complete' === sanitize_key( (string) $order->get_meta( '_dtb_marketplace_orchestration_state', true ) ) ) {
		$channel = sanitize_key( (string) $order->get_meta( '_dtb_marketplace_channel', true ) );
		$row_id  = absint( $order->get_meta( '_dtb_marketplace_order_row_id', true ) );
		$hash    = (string) $order->get_meta( '_dtb_marketplace_identity_hash', true );
		$key     = 'marketplace-materialization-complete:v2:' . $hash;
		return '' !== $hash && $row_id > 0 && class_exists( 'DTB_MarketplaceEventService' )
			&& DTB_MarketplaceEventService::idempotent_event_matches( $key, DTB_MarketplaceEventService::EVT_MATERIALIZATION_COMPLETED, $channel, $row_id, (int) $order->get_id() );
	}
	if ( 'dispatch_pending' !== sanitize_key( (string) $order->get_meta( '_dtb_marketplace_orchestration_state', true ) ) ) {
		return false;
	}
	if ( $retry >= 6 || ! function_exists( 'as_schedule_single_action' ) ) {
		dtb_order_marketplace_dispatch_mark_recovery( $order, $identity, 'dispatch_fence_retry_exhausted' );
		return false;
	}
	$deferred = $args;
	$deferred['_dtb_dispatch_retry'] = $retry + 1;
	$deferred_args = [ (int) $order->get_id(), $deferred ];
	$deferred_id   = (int) as_schedule_single_action( time() + 30, $job_type, $deferred_args, 'dtb-orders', true );
	$deferred_readback = dtb_order_queue_find_exact_action( $job_type, $deferred_args );
	$verified_deferred_id = $deferred_id > 0 ? $deferred_id : absint( $deferred_readback['action_id'] ?? 0 );
	if ( $verified_deferred_id <= 0 || absint( $deferred_readback['action_id'] ?? 0 ) !== $verified_deferred_id
		|| ! in_array( (string) ( $deferred_readback['status'] ?? '' ), class_exists( 'ActionScheduler_Store' )
			? [ ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING ]
			: [ 'pending', 'in-progress' ], true )
	) {
		dtb_order_marketplace_dispatch_mark_recovery( $order, $identity, 'dispatch_fence_defer_unverified' );
	}
	return false;
}

function dtb_order_enqueue_job( string $job_type, int $order_id, array $args = [], int $delay = 0 ): string|int|false {
	if ( '' === $job_type || $order_id <= 0 ) {
		return false;
	}
	if ( 'dtb_order_sync_veeqo' === $job_type && function_exists( 'dtb_veeqo_order_automation_is_paused' ) && dtb_veeqo_order_automation_is_paused() ) { return false; }
	if ( 'dtb_order_sync_quickbooks' === $job_type && dtb_qbo_exports_paused() ) { return false; }
	if ( 'dtb_order_issue_rewards' === $job_type ) {
		return false;
	}
	if ( 'dtb_order_send_notification' === $job_type && empty( $args['template'] ) ) {
		return false;
	}
	if ( function_exists( 'dtb_order_write_boundary_should_block_job' ) && dtb_order_write_boundary_should_block_job( $job_type, $order_id, $args ) ) {
		return false;
	}

	$scheduled_args = [ $order_id, $args ];
	$timestamp      = time() + max( 0, $delay );

	if ( function_exists( 'as_schedule_single_action' ) ) {
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$existing = as_next_scheduled_action( $job_type, $scheduled_args, 'dtb-orders' );
			if ( false !== $existing ) {
				return $existing;
			}
		}
		$action_id = as_schedule_single_action( $timestamp, $job_type, $scheduled_args, 'dtb-orders', true );
		return $action_id > 0 ? $action_id : false;
	}

	$scheduled = wp_schedule_single_event( $timestamp, $job_type, $scheduled_args );
	return $scheduled ? $timestamp : false;
}

function dtb_order_retry_job( string $job_type, int $order_id, array $args = [] ): bool {
	if ( 'dtb_order_sync_veeqo' === $job_type && function_exists( 'dtb_veeqo_order_automation_is_paused' ) && dtb_veeqo_order_automation_is_paused() ) { return false; }
	if ( 'dtb_order_sync_quickbooks' === $job_type && dtb_qbo_exports_paused() ) { return false; }
	if ( 'dtb_order_issue_rewards' === $job_type ) {
		return false;
	}
	if ( function_exists( 'dtb_order_write_boundary_should_block_job' ) && dtb_order_write_boundary_should_block_job( $job_type, $order_id, $args ) ) {
		return false;
	}

	$attempt = isset( $args['attempt'] ) ? (int) $args['attempt'] : 1;
	if ( $attempt >= DTB_ORDER_JOB_MAX_RETRIES ) {
		error_log( sprintf( '[DTB Orders] Max retries reached for %s on order %d.', $job_type, $order_id ) );
		return false;
	}

	$delay           = DTB_ORDER_JOB_RETRY_BASE * (int) pow( 2, $attempt );
	$args['attempt'] = $attempt + 1;
	$scheduled = dtb_order_enqueue_job( $job_type, $order_id, $args, $delay );
	return false !== $scheduled;
}

function dtb_order_job_result_status( array $result ): string {
	$status = sanitize_key( (string) ( $result['status'] ?? '' ) );
	return '' !== $status ? $status : 'unknown';
}

function dtb_order_job_result_is_success( array $result ): bool {
	return in_array( dtb_order_job_result_status( $result ), [ 'synced', 'already_synced', 'created', 'updated' ], true );
}

function dtb_order_job_result_is_skipped( array $result ): bool {
	return in_array( dtb_order_job_result_status( $result ), [ 'skipped', 'not_configured', 'disabled', 'ineligible' ], true );
}

function dtb_order_job_result_is_locked( array $result ): bool {
	return 'locked' === dtb_order_job_result_status( $result );
}

function dtb_order_claim_notification_send( int $order_id, string $template ): bool {
	if ( $order_id <= 0 || '' === $template ) {
		return false;
	}

	return add_option( 'dtb_order_notification_' . hash( 'sha256', $order_id . ':' . $template ), (string) time(), '', 'no' );
}

function dtb_order_release_notification_send( int $order_id, string $template ): void {
	if ( $order_id > 0 && '' !== $template ) {
		delete_option( 'dtb_order_notification_' . hash( 'sha256', $order_id . ':' . $template ) );
	}
}

function dtb_order_job_exception_retryable( Throwable $e ): bool {
	if ( method_exists( $e, 'is_retryable' ) ) {
		return (bool) $e->is_retryable();
	}
	if ( function_exists( 'dtb_order_integration_retryable_error' ) ) {
		return dtb_order_integration_retryable_error( (int) $e->getCode(), $e->getMessage() );
	}
	return ! preg_match( '/\b(400|401|403|404|409|410|422)\b/', $e->getMessage() );
}

function dtb_order_job_should_skip_order_side_effects( $order, string $job_type = '', array $args = [] ): bool {
	if ( $order instanceof WC_Order && '2' === (string) $order->get_meta( '_dtb_marketplace_materialization_contract', true ) ) {
		return '' === $job_type || ! dtb_order_marketplace_dispatch_execution_ready( $order, $job_type, $args );
	}
	if ( ! function_exists( 'dtb_order_write_boundary_order_should_skip_side_effects' )
		|| ! dtb_order_write_boundary_order_should_skip_side_effects( $order )
	) {
		return false;
	}
	if ( '' !== $job_type
		&& $order instanceof WC_Order
		&& function_exists( 'dtb_order_write_boundary_should_block_job' )
		&& ! dtb_order_write_boundary_should_block_job( $job_type, (int) $order->get_id(), $args )
	) {
		return false;
	}
	return true;
}

/**
 * Storefront Veeqo order creation is deliberately off for the manual
 * supplier-direct launch. Inventory services remain available; enabling
 * order sync later requires an explicit site constant after stocked-order
 * eligibility and fulfillment policy are approved.
 */
function dtb_order_veeqo_order_sync_enabled(): bool {
	return ! ( defined( 'DTB_LAUNCH_EXTERNAL_INTEGRATIONS_DISABLED' ) && DTB_LAUNCH_EXTERNAL_INTEGRATIONS_DISABLED )
		&& defined( 'DTB_ORDER_VEEQO_SYNC_ENABLED' )
		&& filter_var( DTB_ORDER_VEEQO_SYNC_ENABLED, FILTER_VALIDATE_BOOLEAN );
}

add_action( 'dtb_order_sync_veeqo', 'dtb_order_job_sync_veeqo', 10, 2 );
function dtb_order_job_sync_veeqo( int $order_id, array $args = [] ): void {
	$attempt = isset( $args['attempt'] ) ? max( 1, (int) $args['attempt'] ) : 1;
	$order   = wc_get_order( $order_id );
	if ( $order instanceof WC_Order && '2' === (string) $order->get_meta( '_dtb_marketplace_materialization_contract', true )
		&& ! dtb_order_marketplace_dispatch_execution_ready( $order, 'dtb_order_sync_veeqo', $args ) ) {
		return;
	}
	if ( function_exists( 'dtb_veeqo_order_automation_is_paused' ) && dtb_veeqo_order_automation_is_paused() ) {
		dtb_order_update_integration_state( $order_id, 'veeqo', [ 'status' => 'disabled', 'error' => 'Veeqo order automation is temporarily paused.' ] );
		dtb_order_append_event( $order_id, 'integration.veeqo.skipped', [ 'source' => 'cron', 'actor_type' => 'system', 'visibility' => 'operator', 'payload' => [ 'reason' => 'veeqo_order_automation_paused' ] ] );
		return;
	}
	dtb_order_append_event( $order_id, 'integration.veeqo.queued', [
		'source'     => 'cron',
		'actor_type' => 'system',
		'visibility' => 'operator',
		'payload'    => [ 'attempt' => $attempt ],
	] );

	if ( ! $order ) {
		error_log( "[DTB Orders] dtb_order_job_sync_veeqo: order {$order_id} not found." );
		return;
	}
	if ( dtb_order_job_should_skip_order_side_effects( $order, 'dtb_order_sync_veeqo', $args ) ) {
		dtb_order_append_event( $order_id, 'integration.veeqo.skipped_duplicate', [ 'source' => 'cron', 'actor_type' => 'system', 'visibility' => 'operator', 'payload' => [ 'reason' => 'order_write_boundary' ] ] );
		return;
	}

	if ( ! dtb_order_veeqo_order_sync_enabled() ) {
		$message = 'Veeqo order creation is disabled for manual supplier-direct fulfillment.';
		dtb_order_update_integration_state( $order_id, 'veeqo', [
			'status'  => 'skipped',
			'error'   => $message,
			'attempt' => $attempt,
		] );
		dtb_order_append_event( $order_id, 'integration.veeqo.skipped', [
			'source'     => 'cron',
			'actor_type' => 'system',
			'visibility' => 'operator',
			'payload'    => [
				'status'  => 'skipped',
				'reason'  => 'manual_supplier_direct_launch_mode',
				'message' => $message,
			],
		] );
		return;
	}

	try {
		$result = function_exists( 'dtb_veeqo_sync_order' )
			? dtb_veeqo_sync_order( $order_id, $order )
			: [ 'status' => 'not_configured', 'message' => 'Veeqo sync contract is not configured.', 'retryable' => false ];

		if ( dtb_order_job_result_is_locked( $result ) ) {
			dtb_order_update_integration_state( $order_id, 'veeqo', [ 'status' => 'queued', 'error' => null, 'attempt' => $attempt ] );
			dtb_order_append_event( $order_id, 'integration.veeqo.locked', [
				'source'     => 'cron',
				'actor_type' => 'system',
				'visibility' => 'operator',
				'payload'    => [ 'message' => $result['message'] ?? 'Veeqo sync already in progress.' ],
			] );
			dtb_order_retry_job( 'dtb_order_sync_veeqo', $order_id, $args );
			return;
		}

		if ( dtb_order_job_result_is_skipped( $result ) ) {
			dtb_order_update_integration_state( $order_id, 'veeqo', [
				'status'  => dtb_order_job_result_status( $result ),
				'error'   => $result['message'] ?? null,
				'attempt' => $attempt,
			] );
			dtb_order_append_event( $order_id, 'integration.veeqo.skipped', [
				'source'     => 'cron',
				'actor_type' => 'system',
				'visibility' => 'operator',
				'payload'    => [ 'status' => dtb_order_job_result_status( $result ), 'message' => $result['message'] ?? '' ],
			] );
			return;
		}

		if ( ! dtb_order_job_result_is_success( $result ) ) {
			throw new RuntimeException( (string) ( $result['message'] ?? 'Veeqo sync did not return a successful status.' ) );
		}

		$veeqo_order_id  = $result['veeqo_order_id'] ?? $result['order_id'] ?? null;
		$tracking_number = $result['tracking_number'] ?? null;

		dtb_order_update_integration_state( $order_id, 'veeqo', [
			'status'          => 'synced',
			'source_status'   => dtb_order_job_result_status( $result ),
			'order_id'        => $veeqo_order_id,
			'tracking'        => $tracking_number,
			'error'           => null,
			'attempt'         => $attempt,
			'last_success_at' => current_time( 'mysql', true ),
		] );

		dtb_order_append_event( $order_id, 'integration.veeqo.synced', [
			'source'     => 'cron',
			'actor_type' => 'veeqo',
			'visibility' => 'operator',
			'payload'    => [
				'status'          => dtb_order_job_result_status( $result ),
				'veeqo_order_id'  => $veeqo_order_id,
				'tracking_number' => $tracking_number,
			],
		] );

		if ( ! empty( $tracking_number ) ) {
			dtb_order_set_fulfillment_substate( $order_id, 'shipped', [ 'tracking_number' => $tracking_number, 'carrier' => $result['carrier'] ?? null ] );
			// Shipment notification is operator-approved from the order workbench.
		} elseif ( ! empty( $result['inventory_reserved'] ) ) {
			dtb_order_set_fulfillment_substate( $order_id, 'inventory_reserved' );
		}

		if ( function_exists( 'dtb_veeqo_order_status_poll_schedule_if_needed' ) ) {
			dtb_veeqo_order_status_poll_schedule_if_needed( 2 * MINUTE_IN_SECONDS );
		}
		dtb_order_enqueue_job( 'dtb_order_refresh_tracking_projection', $order_id );
	} catch ( Throwable $e ) {
		$is_retryable = dtb_order_job_exception_retryable( $e );
		dtb_order_update_integration_state( $order_id, 'veeqo', [
			'status'        => 'failed',
			'error'         => $e->getMessage(),
			'retryable'     => $is_retryable,
			'attempt'       => $attempt,
			'last_error_at' => current_time( 'mysql', true ),
		] );
		update_post_meta( $order_id, '_dtb_veeqo_sync_status', 'failed' );
		update_post_meta( $order_id, '_dtb_veeqo_sync_error', substr( sanitize_text_field( $e->getMessage() ), 0, 1000 ) );
		dtb_order_append_event( $order_id, 'integration.veeqo.failed', [
			'source'     => 'cron',
			'actor_type' => 'system',
			'visibility' => 'operator',
			'payload'    => [ 'error_type' => get_class( $e ), 'retryable' => $is_retryable, 'attempt' => $attempt ],
		] );
		error_log( "[DTB Orders] Veeqo sync failed for order {$order_id}: " . $e->getMessage() );
		if ( $is_retryable ) {
			dtb_order_retry_job( 'dtb_order_sync_veeqo', $order_id, $args );
		}
	}
}

add_action( 'dtb_order_sync_quickbooks', 'dtb_order_job_sync_quickbooks', 10, 2 );
function dtb_order_job_sync_quickbooks( int $order_id, array $args = [] ): void {
	$attempt = isset( $args['attempt'] ) ? max( 1, (int) $args['attempt'] ) : 1;
	$action  = sanitize_key( (string) ( $args['action'] ?? 'create' ) );
	$order   = wc_get_order( $order_id );
	if ( $order instanceof WC_Order && '2' === (string) $order->get_meta( '_dtb_marketplace_materialization_contract', true )
		&& ! dtb_order_marketplace_dispatch_execution_ready( $order, 'dtb_order_sync_quickbooks', $args ) ) {
		return;
	}
	if ( ! $order ) {
		return;
	}
	dtb_order_append_event( $order_id, 'integration.quickbooks.queued', [
		'source'     => 'cron',
		'actor_type' => 'system',
		'visibility' => 'operator',
		'payload'    => [ 'action' => $action, 'attempt' => $attempt ],
	] );

	if ( dtb_qbo_exports_paused() ) {
		dtb_order_update_integration_state( $order_id, 'quickbooks', [ 'status' => 'disabled', 'error' => 'DTB QuickBooks writes are disabled; WooCommerce sales sync is managed by the Intuit connector.' ] );
		dtb_order_append_event( $order_id, 'integration.quickbooks.skipped', [ 'source' => 'cron', 'actor_type' => 'system', 'visibility' => 'operator', 'payload' => [ 'reason' => 'quickbooks_exports_paused' ] ] );
		return;
	}
	if ( dtb_order_job_should_skip_order_side_effects( $order, 'dtb_order_sync_quickbooks', $args ) ) {
		dtb_order_append_event( $order_id, 'integration.quickbooks.skipped_duplicate', [ 'source' => 'cron', 'actor_type' => 'system', 'visibility' => 'operator', 'payload' => [ 'reason' => 'order_write_boundary' ] ] );
		return;
	}

	try {
		$result = function_exists( 'dtb_quickbooks_sync_order' )
			? dtb_quickbooks_sync_order( $order_id, $order, $action )
			: [ 'status' => 'not_configured', 'message' => 'QuickBooks sync contract is not configured.', 'retryable' => false ];

		if ( dtb_order_job_result_is_locked( $result ) ) {
			dtb_order_update_integration_state( $order_id, 'quickbooks', [ 'status' => 'queued', 'error' => null, 'attempt' => $attempt ] );
			dtb_order_retry_job( 'dtb_order_sync_quickbooks', $order_id, $args );
			return;
		}

		if ( dtb_order_job_result_is_skipped( $result ) ) {
			dtb_order_update_integration_state( $order_id, 'quickbooks', [
				'status'  => dtb_order_job_result_status( $result ),
				'error'   => $result['message'] ?? null,
				'attempt' => $attempt,
			] );
			dtb_order_append_event( $order_id, 'integration.quickbooks.skipped', [
				'source'     => 'cron',
				'actor_type' => 'system',
				'visibility' => 'operator',
				'payload'    => [ 'action' => $action, 'status' => dtb_order_job_result_status( $result ), 'message' => $result['message'] ?? '' ],
			] );
			return;
		}

		if ( ! dtb_order_job_result_is_success( $result ) ) {
			throw new RuntimeException( (string) ( $result['message'] ?? 'QuickBooks sync did not return a successful status.' ) );
		}

		$entity_id = $result['entity_id'] ?? null;
		dtb_order_update_integration_state( $order_id, 'quickbooks', [
			'status'          => 'synced',
			'source_status'   => dtb_order_job_result_status( $result ),
			'entity_id'       => $entity_id,
			'entity_type'     => $result['entity_type'] ?? null,
			'error'           => null,
			'attempt'         => $attempt,
			'last_success_at' => current_time( 'mysql', true ),
		] );
		dtb_order_append_event( $order_id, 'integration.quickbooks.synced', [
			'source'     => 'cron',
			'actor_type' => 'quickbooks',
			'visibility' => 'operator',
			'payload'    => [ 'action' => $action, 'status' => dtb_order_job_result_status( $result ), 'entity_id' => $entity_id, 'entity_type' => $result['entity_type'] ?? null ],
		] );
	} catch ( Throwable $e ) {
		$is_retryable = dtb_order_job_exception_retryable( $e );
		dtb_order_update_integration_state( $order_id, 'quickbooks', [
			'status'        => 'failed',
			'error'         => $e->getMessage(),
			'retryable'     => $is_retryable,
			'attempt'       => $attempt,
			'last_error_at' => current_time( 'mysql', true ),
		] );
		update_post_meta( $order_id, '_dtb_quickbooks_sync_status', 'failed' );
		update_post_meta( $order_id, '_dtb_quickbooks_sync_error', substr( sanitize_text_field( $e->getMessage() ), 0, 1000 ) );
		dtb_order_append_event( $order_id, 'integration.quickbooks.failed', [
			'source'     => 'cron',
			'actor_type' => 'system',
			'visibility' => 'operator',
			'payload'    => [ 'action' => $action, 'error_type' => get_class( $e ), 'retryable' => $is_retryable, 'attempt' => $attempt ],
		] );
		error_log( "[DTB Orders] QB sync failed for order {$order_id}: " . $e->getMessage() );
		if ( $is_retryable ) {
			dtb_order_retry_job( 'dtb_order_sync_quickbooks', $order_id, $args );
		}
	}
}

add_action( 'dtb_order_issue_rewards', 'dtb_order_job_issue_rewards', 10, 2 );
function dtb_order_job_issue_rewards( int $order_id, array $args = [] ): void {
	// Rewards are intentionally disabled until the account rewards program is fully implemented and audited.
	return;
}

add_action( 'dtb_order_send_notification', 'dtb_order_job_send_notification', 10, 2 );
function dtb_order_job_send_notification( int $order_id, array $args = [] ): void {
	$template = sanitize_key( $args['template'] ?? '' );
	if ( '' === $template ) {
		return;
	}
	if ( 'order-shipped' === $template && function_exists( 'dtb_veeqo_order_automation_is_paused' ) && dtb_veeqo_order_automation_is_paused() ) {
		return;
	}
	if ( 'order-shipped' === $template && empty( $args['admin_approved'] ) ) {
		// Older Veeqo jobs may still be queued. Shipment mail now requires a
		// deliberate operator action in the order workbench.
		return;
	}
	$order = wc_get_order( $order_id );
	if ( ! $order || dtb_order_job_should_skip_order_side_effects( $order ) ) {
		return;
	}
	if ( ! dtb_order_claim_notification_send( $order_id, $template ) ) {
		error_log( "[DTB Orders] Skipping duplicate notification '{$template}' for order {$order_id}." );
		return;
	}
	$delivery_attempted        = false;
	$transport_confirmed_failed = false;
	try {
		$sent         = false;
		$wc_email_map = [
			'order-confirmation' => [
				'WC_Email_Customer_Processing_Order' => 'customer_processing_order',
				'WC_Email_New_Order'                  => 'new_order',
			],
			'order-shipped'      => [ 'WC_Email_Customer_Completed_Order' => 'customer_completed_order' ],
			'order-cancelled'    => [ 'WC_Email_Customer_Note' => 'customer_note' ],
		];
		if ( isset( $wc_email_map[ $template ] ) ) {
			$mailer          = WC()->mailer();
			$emails          = $mailer->get_emails();
			$required_emails = $wc_email_map[ $template ];
			$completed       = 0;
			foreach ( $required_emails as $email_class => $email_id ) {
				foreach ( $emails as $email ) {
					if ( ! is_a( $email, $email_class ) ) {
						continue;
					}
					if ( function_exists( 'dtb_order_email_was_successfully_sent' ) && dtb_order_email_was_successfully_sent( $order, $email_id ) ) {
						$completed++;
						break;
					}
					/*
					 * Once trigger() begins, delivery is uncertain if a later
					 * callback throws. Retrying an uncertain email is less safe
					 * than retaining the durable claim: SMTP may already have
					 * accepted the message.
					 */
					$delivery_attempted = true;
					$email->trigger( $order_id, $order );
					if ( function_exists( 'dtb_order_email_was_successfully_sent' ) && dtb_order_email_was_successfully_sent( $order, $email_id ) ) {
						$completed++;
					}
					break;
				}
			}
			$sent = count( $required_emails ) === $completed;
			if ( ! $sent ) {
				$transport_confirmed_failed = true;
				throw new RuntimeException( "WooCommerce did not report successful transport for every '{$template}' recipient." );
			}
		}
		$notification_type = 'notification.' . str_replace( '-', '_', $template ) . '.sent';
		dtb_order_append_event( $order_id, $notification_type, [ 'source' => 'cron', 'actor_type' => 'system', 'visibility' => 'customer', 'payload' => [ 'template' => $template, 'sent' => $sent ] ] );
		dtb_order_update_integration_state( $order_id, 'notifications', [ 'template' => $template, 'sent' => $sent ] );
	} catch ( Throwable $e ) {
		error_log( "[DTB Orders] Notification '{$template}' failed for order {$order_id}: " . $e->getMessage() );
		if ( ! $delivery_attempted || $transport_confirmed_failed ) {
			dtb_order_release_notification_send( $order_id, $template );
			dtb_order_retry_job( 'dtb_order_send_notification', $order_id, $args );
		} else {
			error_log( "[DTB Orders] Notification '{$template}' delivery is uncertain for order {$order_id}; retained idempotency claim and did not retry." );
		}
	}
}

add_action( 'dtb_order_refresh_tracking_projection', 'dtb_order_job_refresh_tracking_projection', 10, 2 );
function dtb_order_job_refresh_tracking_projection( int $order_id, array $args = [] ): void {
	$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
	if ( $order instanceof WC_Order && '2' === (string) $order->get_meta( '_dtb_marketplace_materialization_contract', true )
		&& ! dtb_order_marketplace_dispatch_execution_ready( $order, 'dtb_order_refresh_tracking_projection', $args ) ) {
		return;
	}
	if ( dtb_order_job_should_skip_order_side_effects( $order, 'dtb_order_refresh_tracking_projection', $args ) ) {
		return;
	}
	if ( function_exists( 'dtb_order_build_tracking_projection' ) ) {
		$projection = dtb_order_build_tracking_projection( $order_id );
		update_post_meta( $order_id, '_dtb_tracking_projection', $projection );
		delete_transient( 'dtb_order_tracking_' . $order_id );
		set_transient( 'dtb_order_tracking_' . $order_id, $projection, 5 * MINUTE_IN_SECONDS );
	}
}

add_action( 'dtb_order_reconcile_payment', 'dtb_order_job_reconcile_payment', 10, 2 );
function dtb_order_job_reconcile_payment( int $order_id, array $args = [] ): void {
	$order = wc_get_order( $order_id );
	if ( ! $order || dtb_order_job_should_skip_order_side_effects( $order ) ) {
		return;
	}
	if ( 'pending' === $order->get_status() ) {
		dtb_order_append_event( $order_id, 'order.payment_review_required', [ 'source' => 'cron', 'actor_type' => 'system', 'visibility' => 'operator', 'payload' => [ 'reason' => 'pending_past_reconcile_window' ] ] );
	}
}

add_action( 'dtb_order_handle_refund', 'dtb_order_job_handle_refund', 10, 2 );
function dtb_order_job_handle_refund( int $order_id, array $args = [] ): void {
	$order = wc_get_order( $order_id );
	if ( ! $order || dtb_order_job_should_skip_order_side_effects( $order ) ) {
		return;
	}
	// Rewards are intentionally disabled until the account rewards program is fully implemented and audited.
	dtb_order_enqueue_job( 'dtb_order_send_notification', $order_id, [ 'template' => 'refunded' === $order->get_status() ? 'order-refunded' : 'order-cancelled' ] );
}

add_action( 'dtb_order_archive_completed', 'dtb_order_job_archive_completed', 10, 2 );
function dtb_order_job_archive_completed( int $order_id, array $args = [] ): void {
	$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
	if ( dtb_order_job_should_skip_order_side_effects( $order ) ) {
		return;
	}
	update_post_meta( $order_id, '_dtb_order_archived', current_time( 'mysql', true ) );
	delete_transient( 'dtb_order_tracking_' . $order_id );
}
