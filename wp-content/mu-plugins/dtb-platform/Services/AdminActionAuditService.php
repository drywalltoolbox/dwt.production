<?php
/**
 * DTB Platform — AdminActionAuditService
 *
 * Canonical shared service that writes durable, immutable audit/event records
 * for every admin workbench action across support, returns, and repair modules.
 *
 * Events are stored in the dtb_admin_audit_log table, created on first use.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'dtb_admin_audit_ensure_table', 20 );

/**
 * Ensure the audit log table exists (idempotent).
 */
function dtb_admin_audit_ensure_table(): void {
	global $wpdb;

	$table   = $wpdb->prefix . 'dtb_admin_audit_log';
	$charset = $wpdb->get_charset_collate();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $exists ) {
		return;
	}

	$sql = "CREATE TABLE {$table} (
		id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		event_type    VARCHAR(120)    NOT NULL,
		module        VARCHAR(40)     NOT NULL,
		record_id     BIGINT UNSIGNED NOT NULL,
		actor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		actor_label   VARCHAR(200)    NOT NULL DEFAULT '',
		visibility    ENUM('internal','customer','system') NOT NULL DEFAULT 'internal',
		source        VARCHAR(60)     NOT NULL DEFAULT 'admin_modal',
		payload_json  LONGTEXT,
		created_at    DATETIME        NOT NULL,
		PRIMARY KEY (id),
		KEY module_record (module, record_id),
		KEY actor (actor_user_id),
		KEY created_at (created_at)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Write an audit event.
 *
 * @param string $module      'support' | 'returns' | 'repair'
 * @param int    $record_id
 * @param string $event_type  Dot-namespaced: e.g. 'support.status_changed'
 * @param array  $payload     Arbitrary key/value data.
 * @param array  $opts {
 *   visibility string  'internal'|'customer'|'system'
 *   source     string  'admin_modal'|'admin_bulk'|'system'
 * }
 * @return int|false  Inserted row ID, or false on failure.
 */
function dtb_admin_audit_write( string $module, int $record_id, string $event_type, array $payload = [], array $opts = [] ) {
	global $wpdb;

	$actor_id    = get_current_user_id();
	$actor_label = '';
	if ( $actor_id ) {
		$user = get_user_by( 'id', $actor_id );
		$actor_label = $user ? $user->display_name : '';
	} elseif ( ! empty( $payload['actor_label'] ) ) {
		$actor_label = sanitize_text_field( $payload['actor_label'] );
	}

	$visibility = sanitize_key( $opts['visibility'] ?? 'internal' );
	if ( ! in_array( $visibility, [ 'internal', 'customer', 'system' ], true ) ) {
		$visibility = 'internal';
	}

	$source = sanitize_key( $opts['source'] ?? 'admin_modal' );

	$table = $wpdb->prefix . 'dtb_admin_audit_log';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$result = $wpdb->insert(
		$table,
		[
			'event_type'    => sanitize_text_field( $event_type ),
			'module'        => sanitize_key( $module ),
			'record_id'     => $record_id,
			'actor_user_id' => $actor_id,
			'actor_label'   => $actor_label,
			'visibility'    => $visibility,
			'source'        => $source,
			'payload_json'  => wp_json_encode( $payload ),
			'created_at'    => current_time( 'mysql', true ),
		],
		[ '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
	);

	if ( false === $result ) {
		dtb_log_error( 'dtb_admin_audit_write: DB insert failed for ' . $event_type, [
			'module'    => $module,
			'record_id' => $record_id,
		] );
		return false;
	}

	return (int) $wpdb->insert_id;
}

/**
 * Retrieve audit log entries for a given module record.
 *
 * @param string $module
 * @param int    $record_id
 * @param int    $limit  Max rows to return.
 * @return array<int, array{
 *   id: int,
 *   event_type: string,
 *   actor_label: string,
 *   visibility: string,
 *   source: string,
 *   payload: array,
 *   created_at: string,
 * }>
 */
function dtb_admin_audit_get_events( string $module, int $record_id, int $limit = 50 ): array {
	global $wpdb;

	$table = $wpdb->prefix . 'dtb_admin_audit_log';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( ! $exists ) {
		return [];
	}

	$limit = min( 200, max( 1, $limit ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, event_type, actor_label, visibility, source, payload_json, created_at
			   FROM {$table}
			  WHERE module = %s AND record_id = %d
			  ORDER BY created_at DESC
			  LIMIT %d",
			$module,
			$record_id,
			$limit
		)
	);

	if ( ! is_array( $rows ) ) {
		return [];
	}

	$events = [];
	foreach ( $rows as $row ) {
		$events[] = [
			'id'          => (int) $row->id,
			'event_type'  => $row->event_type,
			'actor_label' => $row->actor_label,
			'visibility'  => $row->visibility,
			'source'      => $row->source,
			'payload'     => json_decode( $row->payload_json ?? '{}', true ) ?: [],
			'created_at'  => $row->created_at,
		];
	}

	return $events;
}
