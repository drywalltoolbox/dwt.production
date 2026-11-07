<?php
defined( 'ABSPATH' ) || exit;

/**
 * Acquire the DTB image sync lock and register fatal-exit cleanup.
 *
 * @param string $context Short context label for diagnostics.
 * @return string|WP_Error Lock owner token on success.
 */
function dtb_image_sync_acquire_lock( string $context = 'sync' ) {
	if ( get_transient( DTB_SYNC_LOCK_KEY ) ) {
		return new WP_Error(
			'sync_locked',
			'A sync is already in progress. Use /release-lock if the previous run crashed.',
			[ 'status' => 423 ]
		);
	}

	$owner_token = function_exists( 'wp_generate_uuid4' )
		? wp_generate_uuid4()
		: uniqid( 'dtb-image-sync-lock-', true );

	$payload = [
		'token'       => $owner_token,
		'context'     => sanitize_key( $context ),
		'acquired_at' => gmdate( 'c' ),
	];

	if ( ! set_transient( DTB_SYNC_LOCK_KEY, $payload, DTB_SYNC_LOCK_TTL ) ) {
		return new WP_Error(
			'sync_lock_failed',
			'Unable to acquire sync lock. Please retry.',
			[ 'status' => 503 ]
		);
	}

	dtb_image_sync_register_fatal_lock_cleanup( $owner_token );

	return $owner_token;
}

/**
 * Release the lock if owned by this request (or force release with null token).
 *
 * @param string|null $owner_token    Lock owner token.
 * @param bool        $clear_progress Also clear progress transient.
 */
function dtb_image_sync_release_lock( ?string $owner_token = null, bool $clear_progress = true ): void {
	$lock = get_transient( DTB_SYNC_LOCK_KEY );
	if ( ! $lock ) {
		if ( $clear_progress ) {
			delete_transient( DTB_SYNC_PROGRESS_KEY );
		}
		return;
	}

	$can_release = null === $owner_token;
	if ( ! $can_release ) {
		$current_token = dtb_image_sync_get_lock_token( $lock );
		$can_release   = ( '' !== $current_token ) && hash_equals( $current_token, $owner_token );
	}

	if ( $can_release ) {
		delete_transient( DTB_SYNC_LOCK_KEY );
		if ( $clear_progress ) {
			delete_transient( DTB_SYNC_PROGRESS_KEY );
		}
	}
}

/**
 * Register shutdown cleanup for fatal exits only.
 *
 * @param string $owner_token Lock owner token.
 */
function dtb_image_sync_register_fatal_lock_cleanup( string $owner_token ): void {
	register_shutdown_function(
		static function () use ( $owner_token ): void {
			$error = error_get_last();
			if ( ! is_array( $error ) ) {
				return;
			}

			$fatal_error_types = [
				E_ERROR,
				E_PARSE,
				E_CORE_ERROR,
				E_COMPILE_ERROR,
				E_USER_ERROR,
				E_RECOVERABLE_ERROR,
			];
			if ( ! in_array( (int) $error['type'], $fatal_error_types, true ) ) {
				return;
			}

			dtb_image_sync_release_lock( $owner_token, true );
		}
	);
}

/**
 * Extract the owner token from a lock payload.
 *
 * @param mixed $lock Stored lock payload.
 * @return string
 */
function dtb_image_sync_get_lock_token( $lock ): string {
	if ( is_array( $lock ) && isset( $lock['token'] ) && is_string( $lock['token'] ) ) {
		return $lock['token'];
	}
	return '';
}
