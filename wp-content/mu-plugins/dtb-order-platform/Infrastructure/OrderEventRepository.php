<?php
/**
 * Infrastructure: Order Event Repository — all DB read/write for the event ledger.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_order_rewards_events_disabled(): bool {
	return defined( 'DTB_REWARDS_ENABLED' ) && false === DTB_REWARDS_ENABLED;
}

function dtb_order_is_rewards_event( string $event_type ): bool {
	$normalized = strtolower( str_replace( [ '_', '-' ], '.', $event_type ) );
	return str_starts_with( $normalized, 'integration.rewards.' ) || str_starts_with( $normalized, 'rewards.' );
}

function dtb_order_append_event( int $order_id, string $event_type, array $args = [] ): int|false {
	if ( $order_id <= 0 || '' === $event_type ) {
		return false;
	}

	if ( dtb_order_rewards_events_disabled() && dtb_order_is_rewards_event( $event_type ) ) {
		return false;
	}

	global $wpdb;
	$idempotency_key = isset( $args['idempotency_key'] ) ? sanitize_text_field( (string) $args['idempotency_key'] ) : null;
	if ( $idempotency_key && dtb_order_event_idempotency_exists( $idempotency_key ) ) {
		return false;
	}

	$visibility   = isset( $args['visibility'] ) ? sanitize_text_field( (string) $args['visibility'] ) : dtb_order_event_default_visibility( $event_type );
	$payload      = isset( $args['payload'] ) && is_array( $args['payload'] ) ? $args['payload'] : [];
	$safe_payload = dtb_order_sanitize_event_payload( $payload );
	$row          = [
		'order_id'        => (int) $order_id,
		'event_type'      => sanitize_key( $event_type ),
		'from_status'     => isset( $args['from_status'] ) ? sanitize_text_field( (string) $args['from_status'] ) : null,
		'to_status'       => isset( $args['to_status'] ) ? sanitize_text_field( (string) $args['to_status'] ) : null,
		'actor_type'      => isset( $args['actor_type'] ) ? sanitize_text_field( (string) $args['actor_type'] ) : 'system',
		'actor_id'        => isset( $args['actor_id'] ) ? (int) $args['actor_id'] : null,
		'source'          => isset( $args['source'] ) ? sanitize_text_field( (string) $args['source'] ) : 'system',
		'visibility'      => $visibility,
		'idempotency_key' => $idempotency_key ?: null,
		'payload_json'    => wp_json_encode( $safe_payload ),
		'created_at'      => current_time( 'mysql', true ),
	];
	$formats      = [ '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ];

	$wpdb->insert( $wpdb->prefix . 'dtb_order_events', $row, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( $wpdb->last_error ) {
		if ( 1062 === (int) $wpdb->errno || str_contains( (string) $wpdb->last_error, 'Duplicate entry' ) ) {
			return false;
		}
		error_log( '[DTB Orders] dtb_order_append_event error: ' . $wpdb->last_error );
		return false;
	}

	return $wpdb->insert_id > 0 ? (int) $wpdb->insert_id : false;
}

function dtb_order_get_events( int $order_id, array $args = [] ): array {
	if ( $order_id <= 0 ) {
		return [];
	}

	global $wpdb;
	$table = $wpdb->prefix . 'dtb_order_events';
	$limit = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 200;
	$sort  = isset( $args['order'] ) && 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';
	$where = [ $wpdb->prepare( 'order_id = %d', $order_id ) ]; // phpcs:ignore WordPress.DB.PreparedSQL
	if ( ! empty( $args['visibility'] ) ) {
		$where[] = $wpdb->prepare( 'visibility = %s', $args['visibility'] ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
	if ( ! empty( $args['event_type'] ) ) {
		$where[] = $wpdb->prepare( 'event_type = %s', $args['event_type'] ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
	$where_sql = 'WHERE ' . implode( ' AND ', $where );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return (array) $wpdb->get_results( "SELECT * FROM {$table} {$where_sql} ORDER BY created_at {$sort}, id {$sort} LIMIT {$limit}" );
}

function dtb_order_get_last_event( int $order_id, ?string $event_type = null ): ?object {
	if ( $order_id <= 0 ) {
		return null;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'dtb_order_events';
	$where = [ $wpdb->prepare( 'order_id = %d', $order_id ) ]; // phpcs:ignore WordPress.DB.PreparedSQL
	if ( $event_type ) {
		$where[] = $wpdb->prepare( 'event_type = %s', $event_type ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
	$where_sql = 'WHERE ' . implode( ' AND ', $where );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return $wpdb->get_row( "SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC, id DESC LIMIT 1" );
}

function dtb_order_event_idempotency_exists( string $key ): bool {
	if ( '' === $key ) {
		return false;
	}

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(1) FROM ' . $wpdb->prefix . 'dtb_order_events WHERE idempotency_key = %s', $key ) );
	return $count > 0;
}

function dtb_order_get_integration_state( int $order_id ): array {
	$defaults = [
		'veeqo'         => [
			'status'               => 'pending',
			'order_id'             => null,
			'tracking'             => null,
			'error'                => null,
			'retryable'            => null,
			'attempt'              => 0,
			'source_status'        => null,
			'last_success_at'      => null,
			'last_error_at'        => null,
			'updated_at'           => null,
		],
		'quickbooks'    => [
			'status'               => 'pending',
			'entity_id'            => null,
			'entity_type'          => null,
			'error'                => null,
			'retryable'            => null,
			'attempt'              => 0,
			'source_status'        => null,
			'last_success_at'      => null,
			'last_error_at'        => null,
			'updated_at'           => null,
		],
		'rewards'       => [ 'status' => 'disabled', 'points_issued' => null, 'error' => null, 'updated_at' => null ],
		'notifications' => [],
	];
	$stored   = get_post_meta( $order_id, '_dtb_integration_state', true );
	return is_array( $stored ) ? array_replace_recursive( $defaults, $stored ) : $defaults;
}

function dtb_order_update_integration_state( int $order_id, string $slice, array $data ): void {
	if ( dtb_order_rewards_events_disabled() && 'rewards' === sanitize_key( $slice ) ) {
		return;
	}

	$state = dtb_order_get_integration_state( $order_id );
	$slice = sanitize_key( $slice );

	if ( 'notifications' === $slice ) {
		$state['notifications'][] = array_merge( [ 'sent_at' => current_time( 'mysql', true ) ], $data );
	} else {
		$state[ $slice ] = array_merge( $state[ $slice ] ?? [], $data, [ 'updated_at' => current_time( 'mysql', true ) ] );
	}

	update_post_meta( $order_id, '_dtb_integration_state', $state );
}
