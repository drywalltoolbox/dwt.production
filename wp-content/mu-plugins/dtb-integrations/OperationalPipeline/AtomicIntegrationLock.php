<?php
/**
 * Atomic integration side-effect lease.
 *
 * Uses WordPress' unique option-name constraint as an atomic compare-and-create
 * primitive. Stale recovery and release use compare-and-delete so an old worker
 * cannot delete a lease that another worker renewed in the meantime.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'dtb_order_integration_lock_key' ) ) {
	function dtb_order_integration_lock_key( string $system, int $order_id ): string {
		return 'dtb_integration_lock_' . sanitize_key( $system ) . '_' . max( 0, $order_id );
	}
}

if ( ! function_exists( 'dtb_order_integration_lock_owners' ) ) {
	/** @return array<string,string> */
	function &dtb_order_integration_lock_owners(): array {
		static $owners = [];
		return $owners;
	}
}

if ( ! function_exists( 'dtb_order_integration_compare_delete' ) ) {
	/** Delete only the exact lease value that this worker previously observed. */
	function dtb_order_integration_compare_delete( string $key, string $expected_value ): bool {
		global $wpdb;
		if ( '' === $key || '' === $expected_value || ! isset( $wpdb->options ) ) {
			return false;
		}

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$key,
				$expected_value
			)
		);
		if ( 1 !== $deleted ) {
			return false;
		}

		wp_cache_delete( $key, 'options' );
		return true;
	}
}

if ( ! function_exists( 'dtb_order_integration_acquire_lock' ) ) {
	function dtb_order_integration_acquire_lock( string $system, int $order_id, int $ttl = 300 ): bool {
		$key       = dtb_order_integration_lock_key( $system, $order_id );
		$ttl       = max( 30, $ttl );
		$token     = wp_generate_uuid4();
		$lease     = wp_json_encode( [ 'token' => $token, 'expires_at' => time() + $ttl ] );
		$owners   =& dtb_order_integration_lock_owners();

		if ( add_option( $key, $lease, '', 'no' ) ) {
			$owners[ $key ] = $token;
			return true;
		}

		$current_raw = (string) get_option( $key, '' );
		$current     = json_decode( $current_raw, true );
		$expired     = is_array( $current ) && (int) ( $current['expires_at'] ?? 0 ) > 0 && (int) $current['expires_at'] < time();
		if ( ! $expired || ! dtb_order_integration_compare_delete( $key, $current_raw ) ) {
			return false;
		}

		// `add_option` is the atomic winner selection if multiple workers observed
		// the same expired lease before compare-and-delete.
		if ( ! add_option( $key, $lease, '', 'no' ) ) {
			return false;
		}

		$owners[ $key ] = $token;
		return true;
	}
}

if ( ! function_exists( 'dtb_order_integration_release_lock' ) ) {
	function dtb_order_integration_release_lock( string $system, int $order_id ): void {
		$key         = dtb_order_integration_lock_key( $system, $order_id );
		$owners     =& dtb_order_integration_lock_owners();
		$token       = (string) ( $owners[ $key ] ?? '' );
		$current_raw = (string) get_option( $key, '' );
		$current     = json_decode( $current_raw, true );
		$held_by     = is_array( $current ) ? (string) ( $current['token'] ?? '' ) : '';

		if ( '' !== $token && '' !== $held_by && hash_equals( $held_by, $token ) ) {
			dtb_order_integration_compare_delete( $key, $current_raw );
		}
		unset( $owners[ $key ] );
	}
}
