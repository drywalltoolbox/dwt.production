<?php
/**
 * Durable, bounded operation-run and lease storage for Schematics admin work.
 *
 * Runs are deliberately stored as individual non-autoloaded options rather
 * than in the activity feed. The activity feed remains append-only history;
 * this store holds the current state and the bounded result payload that an
 * operator needs after a redirect.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_SCHEMATIC_OPERATION_RUN_PREFIX       = 'dtb_schematic_operation_run_';
const DTB_SCHEMATIC_OPERATION_COMMIT_LOCK      = 'dtb_schematic_operation_commit_lock';
const DTB_SCHEMATIC_OPERATION_MAX_RESULT_ITEMS = 100;
const DTB_SCHEMATIC_OPERATION_RUN_INDEX        = 'dtb_schematic_operation_run_index';
const DTB_SCHEMATIC_OPERATION_RUN_RETENTION    = 100;
const DTB_SCHEMATIC_OPERATION_COMMIT_LEASE_SECONDS = 900;

function dtb_schematic_operation_run_create( string $kind, bool $dry_run, int $operator_id, array $request = [] ): array {
	$run_id = strtolower( wp_generate_uuid4() );
	$run    = [
		'id'           => $run_id,
		'kind'         => sanitize_key( $kind ),
		'dry_run'      => $dry_run,
		'operator_id'  => max( 0, $operator_id ),
		'status'       => 'running',
		'request'      => $request,
		'result'       => [],
		'error'        => '',
		'created_at'   => current_time( 'mysql', true ),
		'completed_at' => '',
	];

	add_option( DTB_SCHEMATIC_OPERATION_RUN_PREFIX . $run_id, $run, '', false );
	return $run;
}

function dtb_schematic_operation_run_get( string $run_id ): ?array {
	$run_id = strtolower( sanitize_text_field( $run_id ) );
	if ( ! preg_match( '/^[a-f0-9-]{36}$/', $run_id ) ) {
		return null;
	}
	$run = get_option( DTB_SCHEMATIC_OPERATION_RUN_PREFIX . $run_id, null );
	return is_array( $run ) ? $run : null;
}

/** Return a run only to the operator that initiated it. */
function dtb_schematic_operation_run_get_for_operator( string $run_id, int $operator_id ): ?array {
	$run = dtb_schematic_operation_run_get( $run_id );
	if ( ! $run || (int) $run['operator_id'] !== max( 0, $operator_id ) ) {
		return null;
	}
	return $run;
}

function dtb_schematic_operation_run_complete( array $run, array $result, string $error = '' ): array {
	$result = dtb_schematic_operation_run_bound_result( $result );
	$run['result']       = $result;
	$run['error']        = sanitize_textarea_field( $error );
	$run['status']       = '' === $error ? 'completed' : 'failed';
	$run['completed_at'] = current_time( 'mysql', true );
	update_option( DTB_SCHEMATIC_OPERATION_RUN_PREFIX . $run['id'], $run, false );
	dtb_schematic_operation_run_retain( $run['id'] );
	return $run;
}

/**
 * Keep only a small, known set of completed run options. The index avoids a
 * broad wp_options scan and never contains an in-progress run.
 */
function dtb_schematic_operation_run_retain( string $run_id ): void {
	$index = get_option( DTB_SCHEMATIC_OPERATION_RUN_INDEX, [] );
	$index = is_array( $index ) ? $index : [];
	$index = array_values( array_unique( array_filter( array_map( 'strval', $index ) ) ) );
	$index = array_values( array_diff( $index, [ $run_id ] ) );
	array_unshift( $index, $run_id );

	$expired = array_slice( $index, DTB_SCHEMATIC_OPERATION_RUN_RETENTION );
	$index   = array_slice( $index, 0, DTB_SCHEMATIC_OPERATION_RUN_RETENTION );
	update_option( DTB_SCHEMATIC_OPERATION_RUN_INDEX, $index, false );

	foreach ( $expired as $expired_run_id ) {
		if ( preg_match( '/^[a-f0-9-]{36}$/', $expired_run_id ) ) {
			delete_option( DTB_SCHEMATIC_OPERATION_RUN_PREFIX . $expired_run_id );
		}
	}
}

function dtb_schematic_operation_run_bound_result( array $result ): array {
	foreach ( [ 'assets', 'results', 'items' ] as $key ) {
		if ( isset( $result[ $key ] ) && is_array( $result[ $key ] ) && count( $result[ $key ] ) > DTB_SCHEMATIC_OPERATION_MAX_RESULT_ITEMS ) {
			$result[ $key ] = array_slice( array_values( $result[ $key ] ), 0, DTB_SCHEMATIC_OPERATION_MAX_RESULT_ITEMS );
			$result[ $key . '_truncated' ] = true;
		}
	}
	return $result;
}

/**
 * Acquire a process-wide commit lease. Dry runs never acquire this lease.
 * An expired lease can only be replaced through a compare-and-swap update,
 * preventing two workers from both taking a stale lock.
 */
function dtb_schematic_operation_commit_lease_acquire( string $run_id, int $seconds = DTB_SCHEMATIC_OPERATION_COMMIT_LEASE_SECONDS ) {
	global $wpdb;

	$seconds = max( 30, min( 900, $seconds ) );
	$lease = wp_json_encode( [ 'run_id' => $run_id, 'expires_at' => time() + $seconds ] );
	if ( add_option( DTB_SCHEMATIC_OPERATION_COMMIT_LOCK, $lease, '', false ) ) {
		return true;
	}

	$option_name = DTB_SCHEMATIC_OPERATION_COMMIT_LOCK;
	$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$decoded = json_decode( (string) $current, true );
	if ( ! is_array( $decoded ) || (int) ( $decoded['expires_at'] ?? 0 ) >= time() ) {
		return new WP_Error( 'dtb_schematic_operation_locked', __( 'Another schematic synchronization is currently running. Try again after it completes.', 'drywall-toolbox' ) );
	}

	$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $lease, $option_name, $current ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( 1 !== $updated ) {
		return new WP_Error( 'dtb_schematic_operation_locked', __( 'Another schematic synchronization started first. Try again after it completes.', 'drywall-toolbox' ) );
	}
	wp_cache_delete( $option_name, 'options' );
	return true;
}

function dtb_schematic_operation_commit_lease_release( string $run_id ): void {
	$current = get_option( DTB_SCHEMATIC_OPERATION_COMMIT_LOCK, '' );
	$decoded = json_decode( (string) $current, true );
	if ( is_array( $decoded ) && hash_equals( (string) ( $decoded['run_id'] ?? '' ), $run_id ) ) {
		delete_option( DTB_SCHEMATIC_OPERATION_COMMIT_LOCK );
	}
}

/**
 * Release an owned lease and close its run when PHP terminates fatally.
 *
 * PHP execution-time and memory-limit fatals bypass the operation runner's
 * finally block. Without shutdown cleanup, the dead request leaves every
 * subsequent commit blocked until the full lease interval expires.
 */
function dtb_schematic_operation_register_fatal_lease_cleanup( string $run_id ): void {
	register_shutdown_function(
		static function () use ( $run_id ): void {
			$error = error_get_last();
			if ( ! is_array( $error ) ) {
				return;
			}

			$fatal_error_types = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ];
			if ( ! in_array( (int) ( $error['type'] ?? 0 ), $fatal_error_types, true ) ) {
				return;
			}

			dtb_schematic_operation_commit_lease_release( $run_id );

			$run = dtb_schematic_operation_run_get( $run_id );
			if ( ! $run || 'running' !== (string) ( $run['status'] ?? '' ) ) {
				return;
			}

			$message = __( 'The schematic operation was terminated by PHP before completion. Review the server error log using the operation run ID.', 'drywall-toolbox' );
			$run     = dtb_schematic_operation_run_complete( $run, [ 'fatal_error' => $message ], $message );
			if ( function_exists( 'dtb_schematic_operation_log_activity' ) ) {
				dtb_schematic_operation_log_activity( (string) ( $run['kind'] ?? '' ), $run );
			}
		}
	);
}

/**
 * Extend a lease only while the caller still owns its exact stored value.
 * Losing ownership is terminal for the active run; it must stop writing.
 */
function dtb_schematic_operation_commit_lease_renew( string $run_id, int $seconds = DTB_SCHEMATIC_OPERATION_COMMIT_LEASE_SECONDS ) {
	global $wpdb;

	$option_name = DTB_SCHEMATIC_OPERATION_COMMIT_LOCK;
	$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$decoded = json_decode( (string) $current, true );
	if ( ! is_array( $decoded ) || ! hash_equals( (string) ( $decoded['run_id'] ?? '' ), $run_id ) ) {
		return new WP_Error( 'dtb_schematic_operation_lease_lost', __( 'The schematic synchronization lock was lost. The run stopped before continuing changes.', 'drywall-toolbox' ) );
	}

	$seconds = max( 30, min( 900, $seconds ) );
	// A newly acquired lease already has the full interval remaining. Avoid an
	// identical UPDATE, which MySQL reports as zero changed rows even though
	// ownership is intact.
	if ( (int) ( $decoded['expires_at'] ?? 0 ) >= time() + (int) floor( $seconds / 2 ) ) {
		return true;
	}
	$renewed = wp_json_encode( [ 'run_id' => $run_id, 'expires_at' => time() + $seconds ] );
	$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $renewed, $option_name, $current ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( 1 !== $updated ) {
		return new WP_Error( 'dtb_schematic_operation_lease_lost', __( 'The schematic synchronization lock changed while the run was active. The run stopped before continuing changes.', 'drywall-toolbox' ) );
	}
	wp_cache_delete( $option_name, 'options' );
	return true;
}
