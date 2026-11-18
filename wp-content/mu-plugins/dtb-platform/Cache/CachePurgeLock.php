<?php
/**
 * DTB Platform — CachePurgeLock
 *
 * Serializes destructive cache purge runs so concurrent operators, repeated
 * browser clicks, and retried AJAX requests cannot execute overlapping full
 * flushes. The lock is intentionally short-lived and self-healing.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_CachePurgeLock' ) ) {
	return;
}

final class DTB_CachePurgeLock {
	private const OPTION_NAME = 'dtb_cache_purge_lock';
	private const TTL_SECONDS = 120;

	/**
	 * Acquire the purge lock.
	 *
	 * @return array{acquired:bool, token:string, message:string}
	 */
	public static function acquire(): array {
		$now      = time();
		$current  = get_option( self::OPTION_NAME, [] );
		$expires  = isset( $current['expires_at'] ) ? (int) $current['expires_at'] : 0;

		if ( $expires > $now ) {
			return [
				'acquired' => false,
				'token'    => '',
				'message'  => __( 'Another cache purge is already running. Retry after it completes.', 'drywall-toolbox' ),
			];
		}

		$token = wp_generate_uuid4();
		$lock  = [
			'token'      => $token,
			'actor'      => get_current_user_id(),
			'acquired_at'=> $now,
			'expires_at' => $now + self::TTL_SECONDS,
		];

		if ( false === add_option( self::OPTION_NAME, $lock, '', false ) ) {
			update_option( self::OPTION_NAME, $lock, false );
		}

		$stored = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $stored ) || ! hash_equals( $token, (string) ( $stored['token'] ?? '' ) ) ) {
			return [
				'acquired' => false,
				'token'    => '',
				'message'  => __( 'Could not acquire the cache purge lock.', 'drywall-toolbox' ),
			];
		}

		return [ 'acquired' => true, 'token' => $token, 'message' => '' ];
	}

	/**
	 * Release a lock owned by this request.
	 */
	public static function release( string $token ): void {
		$current = get_option( self::OPTION_NAME, [] );
		if ( is_array( $current ) && hash_equals( $token, (string) ( $current['token'] ?? '' ) ) ) {
			delete_option( self::OPTION_NAME );
		}
	}
}
