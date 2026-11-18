<?php
/**
 * Durable single-flight Veeqo inventory operation state.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_VEEQO_OPERATIONS_HOOK          = 'dtb_veeqo_inventory_operation';
const DTB_VEEQO_OPERATIONS_ACTIVE_OPTION = 'dtb_veeqo_inventory_operation_active';
const DTB_VEEQO_OPERATIONS_INDEX_OPTION  = 'dtb_veeqo_inventory_operation_index';
const DTB_VEEQO_OPERATIONS_OPTION_PREFIX = 'dtb_veeqo_inventory_operation_';
const DTB_VEEQO_OPERATIONS_RETENTION     = 20;
const DTB_VEEQO_OPERATIONS_MAX_RETRIES   = 3;

final class DTB_Veeqo_Operation_Store {
	public static function option_name( string $operation_id ): string {
		return DTB_VEEQO_OPERATIONS_OPTION_PREFIX . sanitize_key( $operation_id );
	}

	public static function get( string $operation_id ): array {
		$operation_id = sanitize_key( $operation_id );
		return '' === $operation_id ? [] : (array) get_option( self::option_name( $operation_id ), [] );
	}

	public static function save( array $operation ): void {
		$operation_id = sanitize_key( (string) ( $operation['operation_id'] ?? '' ) );
		if ( '' === $operation_id ) {
			return;
		}

		$operation['operation_id'] = $operation_id;
		update_option( self::option_name( $operation_id ), $operation, false );

		$index = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) get_option( DTB_VEEQO_OPERATIONS_INDEX_OPTION, [] ) ) ) ) );
		$index = array_values( array_diff( $index, [ $operation_id ] ) );
		array_unshift( $index, $operation_id );

		$expired = array_slice( $index, DTB_VEEQO_OPERATIONS_RETENTION );
		update_option( DTB_VEEQO_OPERATIONS_INDEX_OPTION, array_slice( $index, 0, DTB_VEEQO_OPERATIONS_RETENTION ), false );
		foreach ( $expired as $expired_id ) {
			delete_option( self::option_name( $expired_id ) );
		}
	}

	public static function summary( array $operation ): array {
		$result = is_array( $operation['result'] ?? null ) ? $operation['result'] : [];
		return [
			'operation_id' => sanitize_key( (string) ( $operation['operation_id'] ?? '' ) ),
			'mode'         => sanitize_key( (string) ( $operation['mode'] ?? '' ) ),
			'status'       => sanitize_key( (string) ( $operation['status'] ?? '' ) ),
			'created_at'   => sanitize_text_field( (string) ( $operation['created_at'] ?? '' ) ),
			'started_at'   => sanitize_text_field( (string) ( $operation['started_at'] ?? '' ) ),
			'heartbeat_at' => sanitize_text_field( (string) ( $operation['heartbeat_at'] ?? '' ) ),
			'completed_at' => sanitize_text_field( (string) ( $operation['completed_at'] ?? '' ) ),
			'action_id'    => absint( $operation['action_id'] ?? 0 ),
			'next_page'    => max( 1, absint( $operation['next_page'] ?? 1 ) ),
			'attempt'      => absint( $operation['attempt'] ?? 0 ),
			'error'        => sanitize_text_field( (string) ( $operation['error'] ?? '' ) ),
			'counts'       => [
				'pages'          => absint( $result['pages'] ?? 0 ),
				'sellables_seen' => absint( $result['sellables_seen'] ?? 0 ),
				'updated'        => absint( $result['updated'] ?? 0 ),
				'unchanged'      => absint( $result['unchanged'] ?? 0 ),
				'unmapped'       => count( (array) ( $result['unmapped_skus'] ?? [] ) ),
				'duplicates'     => count( (array) ( $result['duplicate_skus'] ?? [] ) ),
				'missing_stock'  => count( (array) ( $result['missing_warehouse_entries'] ?? [] ) ),
			],
		];
	}

	public static function recent(): array {
		$rows = [];
		$ids  = array_slice( (array) get_option( DTB_VEEQO_OPERATIONS_INDEX_OPTION, [] ), 0, DTB_VEEQO_OPERATIONS_RETENTION );
		foreach ( $ids as $operation_id ) {
			$operation = self::get( (string) $operation_id );
			if ( ! empty( $operation ) ) {
				$rows[] = self::summary( $operation );
			}
		}
		return $rows;
	}

	public static function release_active( string $operation_id ): void {
		$operation_id = sanitize_key( $operation_id );
		$current      = sanitize_key( (string) get_option( DTB_VEEQO_OPERATIONS_ACTIVE_OPTION, '' ) );
		if ( '' !== $operation_id && '' !== $current && hash_equals( $operation_id, $current ) ) {
			delete_option( DTB_VEEQO_OPERATIONS_ACTIVE_OPTION );
		}
	}

	public static function active(): array {
		$operation_id = sanitize_key( (string) get_option( DTB_VEEQO_OPERATIONS_ACTIVE_OPTION, '' ) );
		$operation    = self::get( $operation_id );
		$status       = sanitize_key( (string) ( $operation['status'] ?? '' ) );
		if ( empty( $operation ) || ! in_array( $status, [ 'queued', 'running' ], true ) ) {
			self::release_active( $operation_id );
			return [];
		}

		$heartbeat = strtotime( (string) ( $operation['heartbeat_at'] ?? $operation['started_at'] ?? $operation['created_at'] ?? '' ) );
		$ttl       = defined( 'DTB_VEEQO_INVENTORY_LOCK_TTL' ) ? (int) DTB_VEEQO_INVENTORY_LOCK_TTL : 20 * MINUTE_IN_SECONDS;
		if ( false === $heartbeat || $heartbeat + max( 5 * MINUTE_IN_SECONDS, 2 * $ttl ) >= time() ) {
			return $operation;
		}

		if ( ! empty( $operation['continuation_pending'] ) && function_exists( 'as_enqueue_async_action' ) ) {
			$action_id = as_enqueue_async_action(
				DTB_VEEQO_OPERATIONS_HOOK,
				[
					$operation_id,
					'dry_run' === (string) ( $operation['mode'] ?? '' ),
					max( 1, absint( $operation['next_page'] ?? 1 ) ),
					is_array( $operation['result'] ?? null ) ? $operation['result'] : [],
					absint( $operation['attempt'] ?? 0 ),
				],
				DTB_VEEQO_INVENTORY_ACTION_GROUP,
				true
			);
			if ( ! empty( $action_id ) ) {
				$operation['status']               = 'queued';
				$operation['action_id']            = absint( $action_id );
				$operation['heartbeat_at']         = gmdate( 'c' );
				$operation['continuation_pending'] = false;
				self::save( $operation );
				return $operation;
			}
		}

		$operation['status']       = 'failed';
		$operation['error']        = 'Operation heartbeat expired and the operation was recovered as stale.';
		$operation['completed_at'] = gmdate( 'c' );
		self::save( $operation );
		self::release_active( $operation_id );
		return [];
	}

	public static function enqueue( bool $dry_run ): array {
		$readiness = function_exists( 'dtb_veeqo_inventory_readiness' )
			? dtb_veeqo_inventory_readiness()
			: [ 'ready' => false, 'missing' => [ 'inventory_projection' ] ];
		if ( empty( $readiness['ready'] ) ) {
			return [ 'ok' => false, 'status' => 503, 'code' => 'veeqo_inventory_not_ready', 'message' => 'Veeqo inventory projection is not ready.', 'missing' => array_values( (array) ( $readiness['missing'] ?? [] ) ) ];
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return [ 'ok' => false, 'status' => 503, 'code' => 'action_scheduler_unavailable', 'message' => 'Action Scheduler is unavailable.' ];
		}

		$active = self::active();
		if ( ! empty( $active ) ) {
			return [ 'ok' => true, 'status' => 202, 'operation' => self::summary( $active ), 'message' => 'A Veeqo inventory operation is already queued or running.' ];
		}

		$operation_id = sanitize_key( wp_generate_uuid4() );
		if ( ! add_option( DTB_VEEQO_OPERATIONS_ACTIVE_OPTION, $operation_id, '', 'no' ) ) {
			$active = self::active();
			if ( empty( $active ) && add_option( DTB_VEEQO_OPERATIONS_ACTIVE_OPTION, $operation_id, '', 'no' ) ) {
				$active = [];
			} else {
				return [ 'ok' => true, 'status' => 202, 'operation' => empty( $active ) ? null : self::summary( $active ), 'message' => 'A Veeqo inventory operation is already queued or running.' ];
			}
		}

		$operation = [
			'operation_id'         => $operation_id,
			'mode'                 => $dry_run ? 'dry_run' : 'reconcile',
			'status'               => 'queued',
			'created_at'           => gmdate( 'c' ),
			'heartbeat_at'         => gmdate( 'c' ),
			'created_by'           => get_current_user_id(),
			'action_id'            => 0,
			'next_page'            => 1,
			'attempt'              => 0,
			'continuation_pending' => false,
			'result'               => [],
			'error'                => '',
		];
		self::save( $operation );

		$action_id = as_enqueue_async_action( DTB_VEEQO_OPERATIONS_HOOK, [ $operation_id, $dry_run, 1, [], 0 ], DTB_VEEQO_INVENTORY_ACTION_GROUP, true );
		if ( empty( $action_id ) ) {
			$operation['status']       = 'failed';
			$operation['error']        = 'Action Scheduler did not return an action ID.';
			$operation['completed_at'] = gmdate( 'c' );
			self::save( $operation );
			self::release_active( $operation_id );
			return [ 'ok' => false, 'status' => 503, 'code' => 'queue_failed', 'message' => $operation['error'] ];
		}

		$operation['action_id'] = absint( $action_id );
		self::save( $operation );
		return [ 'ok' => true, 'status' => 202, 'operation' => self::summary( $operation ), 'message' => 'Veeqo inventory operation queued.' ];
	}

	public static function run( string $operation_id, bool $dry_run = false, int $start_page = 1, array $aggregate = [], int $attempt = 0 ): void {
		$operation_id = sanitize_key( $operation_id );
		$operation    = self::get( $operation_id );
		if ( empty( $operation ) || in_array( (string) ( $operation['status'] ?? '' ), [ 'completed', 'failed' ], true ) ) {
			return;
		}
		if ( $operation_id !== sanitize_key( (string) get_option( DTB_VEEQO_OPERATIONS_ACTIVE_OPTION, '' ) ) ) {
			return;
		}

		$expected_page = max( 1, absint( $operation['next_page'] ?? 1 ) );
		if ( max( 1, $start_page ) !== $expected_page ) {
			return;
		}

		$operation['status']               = 'running';
		$operation['started_at']           = $operation['started_at'] ?? gmdate( 'c' );
		$operation['heartbeat_at']         = gmdate( 'c' );
		$operation['attempt']              = max( 0, $attempt );
		$operation['continuation_pending'] = false;
		self::save( $operation );

		try {
			if ( ! function_exists( 'dtb_veeqo_inventory_reconcile_chunk' ) ) {
				throw new RuntimeException( 'Canonical Veeqo inventory projection service is unavailable.' );
			}

			$result = dtb_veeqo_inventory_reconcile_chunk( $dry_run, $start_page, $aggregate );
			if ( is_wp_error( $result ) ) {
				$error_data = $result->get_error_data();
				$status     = is_array( $error_data ) ? absint( $error_data['status'] ?? 0 ) : 0;
				throw new RuntimeException( $result->get_error_message(), $status );
			}

			$operation['result']       = $result;
			$operation['heartbeat_at'] = gmdate( 'c' );
			$operation['attempt']      = 0;
			$operation['error']        = '';

			if ( ! empty( $result['partial'] ) ) {
				$operation['status']               = 'running';
				$operation['next_page']            = max( 1, absint( $result['next_page'] ?? ( $start_page + 1 ) ) );
				$operation['continuation_pending'] = true;
				self::save( $operation );

				$action_id = as_enqueue_async_action(
					DTB_VEEQO_OPERATIONS_HOOK,
					[ $operation_id, $dry_run, $operation['next_page'], $result, 0 ],
					DTB_VEEQO_INVENTORY_ACTION_GROUP,
					true
				);
				if ( empty( $action_id ) ) {
					throw new RuntimeException( 'Veeqo continuation could not be queued.' );
				}

				$operation['action_id']            = absint( $action_id );
				$operation['continuation_pending'] = false;
				self::save( $operation );
				return;
			}

			$operation['status']               = 'completed';
			$operation['completed_at']         = gmdate( 'c' );
			$operation['next_page']            = 1;
			$operation['continuation_pending'] = false;
			self::save( $operation );
			self::release_active( $operation_id );
		} catch ( Throwable $throwable ) {
			$status        = absint( $throwable->getCode() );
			$message       = sanitize_text_field( $throwable->getMessage() );
			$lock_conflict = 409 === $status && false !== stripos( $message, 'reconciliation' );
			$retryable     = $lock_conflict || 0 === $status || 408 === $status || 425 === $status || 429 === $status || $status >= 500;
			$retry_page    = max( 1, absint( $operation['next_page'] ?? $start_page ) );
			$retry_result  = is_array( $operation['result'] ?? null ) ? $operation['result'] : $aggregate;

			if ( $retryable && $attempt < DTB_VEEQO_OPERATIONS_MAX_RETRIES && function_exists( 'as_schedule_single_action' ) ) {
				$delay     = min( HOUR_IN_SECONDS, ( 2 ** $attempt ) * 5 * MINUTE_IN_SECONDS );
				$action_id = as_schedule_single_action(
					time() + $delay,
					DTB_VEEQO_OPERATIONS_HOOK,
					[ $operation_id, $dry_run, $retry_page, $retry_result, $attempt + 1 ],
					DTB_VEEQO_INVENTORY_ACTION_GROUP,
					true
				);
				if ( ! empty( $action_id ) ) {
					$operation['status']               = 'queued';
					$operation['error']                = $message;
					$operation['heartbeat_at']         = gmdate( 'c' );
					$operation['attempt']              = $attempt + 1;
					$operation['action_id']            = absint( $action_id );
					$operation['continuation_pending'] = false;
					self::save( $operation );
					return;
				}
			}

			$operation['status']       = 'failed';
			$operation['error']        = $message;
			$operation['completed_at'] = gmdate( 'c' );
			self::save( $operation );
			self::release_active( $operation_id );
		}
	}
}

add_action( DTB_VEEQO_OPERATIONS_HOOK, [ 'DTB_Veeqo_Operation_Store', 'run' ], 10, 5 );
