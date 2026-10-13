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

function dtb_order_enqueue_job( string $job_type, int $order_id, array $args = [], int $delay = 0 ): string|int|false {
	if ( '' === $job_type || $order_id <= 0 ) {
		return false;
	}
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
		return as_schedule_single_action( $timestamp, $job_type, $scheduled_args, 'dtb-orders' );
	}

	wp_schedule_single_event( $timestamp, $job_type, $scheduled_args );
	return $timestamp;
}

function dtb_order_retry_job( string $job_type, int $order_id, array $args = [] ): bool {
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

function dtb_order_job_should_skip_order_side_effects( $order ): bool {
	return function_exists( 'dtb_order_write_boundary_order_should_skip_side_effects' )
		&& dtb_order_write_boundary_order_should_skip_side_effects( $order );
}

add_action( 'dtb_order_sync_veeqo', 'dtb_order_job_sync_veeqo', 10, 2 );
function dtb_order_job_sync_veeqo( int $order_id, array $args = [] ): void {
	$attempt = isset( $args['attempt'] ) ? max( 1, (int) $args['attempt'] ) : 1;
	dtb_order_append_event( $order_id, 'integration.veeqo.queued', [
		'source'     => 'cron',
		'actor_type' => 'system',
		'visibility' => 'operator',
		'payload'    => [ 'attempt' => $attempt ],
	] );

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		error_log( "[DTB Orders] dtb_order_job_sync_veeqo: order {$order_id} not found." );
		return;
	}
	if ( dtb_order_job_should_skip_order_side_effects( $order ) ) {
		dtb_order_append_event( $order_id, 'integration.veeqo.skipped_duplicate', [ 'source' => 'cron', 'actor_type' => 'system', 'visibility' => 'operator', 'payload' => [ 'reason' => 'order_write_boundary' ] ] );
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
			dtb_order_enqueue_job( 'dtb_order_send_notification', $order_id, [ 'template' => 'order-shipped' ] );
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
	dtb_order_append_event( $order_id, 'integration.quickbooks.queued', [
		'source'     => 'cron',
		'actor_type' => 'system',
		'visibility' => 'operator',
		'payload'    => [ 'action' => $action, 'attempt' => $attempt ],
	] );

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}
	if ( dtb_qbo_exports_paused() ) {
		dtb_order_update_integration_state( $order_id, 'quickbooks', [ 'status' => 'disabled', 'error' => 'QuickBooks exports paused for checkout validation.' ] );
		dtb_order_append_event( $order_id, 'integration.quickbooks.skipped', [ 'source' => 'cron', 'actor_type' => 'system', 'visibility' => 'operator', 'payload' => [ 'reason' => 'quickbooks_exports_paused' ] ] );
		return;
	}
	if ( dtb_order_job_should_skip_order_side_effects( $order ) ) {
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
	if ( dtb_order_job_should_skip_order_side_effects( $order ) ) {
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
