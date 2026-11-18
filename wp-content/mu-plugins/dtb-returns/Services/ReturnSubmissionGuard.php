<?php
/**
 * DTB Returns — submission concurrency guard.
 *
 * Uses WordPress's unique option name constraint as a short-lived cross-request
 * mutex around one order + idempotency key. This closes the race between the
 * duplicate lookup and CPT creation without introducing another persistence
 * authority.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_returns_submission_lock_name( int $order_id, string $idempotency_key ): string {
	return 'dtb_return_submit_lock_' . md5( $order_id . '|' . $idempotency_key );
}

function dtb_returns_acquire_submission_lock( int $order_id, string $idempotency_key ) {
	$name     = dtb_returns_submission_lock_name( $order_id, $idempotency_key );
	$now      = time();
	$existing = get_option( $name, false );

	if ( false !== $existing && ( $now - (int) $existing ) > 60 ) {
		delete_option( $name );
		$existing = false;
	}

	if ( false === $existing && add_option( $name, $now, '', false ) ) {
		return true;
	}

	return new WP_Error(
		'dtb_returns_submission_in_progress',
		__( 'This return request is already being processed. Please wait a moment before trying again.', 'drywall-toolbox' ),
		[ 'status' => 409 ]
	);
}

function dtb_returns_release_submission_lock( int $order_id, string $idempotency_key ): void {
	delete_option( dtb_returns_submission_lock_name( $order_id, $idempotency_key ) );
}
