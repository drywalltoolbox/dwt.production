<?php
/**
 * DTB Platform — AuditLogService
 *
 * Lightweight legacy audit log plus System Manager read model for canonical
 * admin actions, order workflow events, integration jobs, and queue activity.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Write a legacy audit event.
 *
 * New workbench mutations should prefer dtb_admin_audit_write(), but this helper
 * remains for older platform/system actions.
 *
 * @param string $action  Short action slug, e.g. 'order.status_changed'.
 * @param array  $context Key-value context data.
 */
function dtb_audit_log_write( string $action, array $context = [] ): void {
	global $wpdb;

	$entry = wp_json_encode( [
		'ts'      => current_time( 'mysql', true ),
		'user_id' => get_current_user_id(),
		'action'  => sanitize_text_field( $action ),
		'ctx'     => $context,
	] );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert(
		$wpdb->prefix . 'dtb_audit_log',
		[
			'created_at_utc' => current_time( 'mysql', true ),
			'user_id'        => get_current_user_id(),
			'action'         => sanitize_text_field( $action ),
			'context_json'   => $entry,
		],
		[ '%s', '%d', '%s', '%s' ]
	);
}

/**
 * Check whether a database table exists.
 *
 * @param string $table Fully qualified table name.
 * @return bool
 */
function dtb_audit_log_table_exists( string $table ): bool {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
}

/**
 * Normalize a timestamp for display/sorting.
 *
 * @param mixed $value Timestamp value.
 * @return string UTC mysql timestamp or empty string when invalid.
 */
function dtb_audit_log_normalize_ts( $value ): string {
	$value = trim( (string) $value );
	if ( '' === $value || '0000-00-00 00:00:00' === $value || '0000-00-00T00:00:00' === $value ) {
		return '';
	}

	$ts = strtotime( $value );
	if ( false === $ts || $ts <= 0 ) {
		return '';
	}

	return gmdate( 'Y-m-d H:i:s', $ts );
}

/**
 * Decode JSON payload safely.
 *
 * @param mixed $json JSON string.
 * @return array
 */
function dtb_audit_log_decode_payload( $json ): array {
	$decoded = json_decode( (string) $json, true );
	return is_array( $decoded ) ? $decoded : [];
}

/**
 * Produce a compact operator-readable event summary.
 *
 * @param string $action Event/action identifier.
 * @return string
 */
function dtb_audit_log_humanize_action( string $action ): string {
	$action = trim( $action );
	if ( '' === $action ) {
		return __( 'Event', 'drywall-toolbox' );
	}

	if ( function_exists( 'dtb_admin_timeline_summary_from_type' ) ) {
		$summary = dtb_admin_timeline_summary_from_type( $action );
		if ( is_string( $summary ) && '' !== trim( $summary ) && $summary !== $action ) {
			return $summary;
		}
	}

	$known = [
		'integrationveeqoqueued'       => __( 'Veeqo Sync Queued', 'drywall-toolbox' ),
		'integrationveeqosynced'       => __( 'Veeqo Synced', 'drywall-toolbox' ),
		'integrationveeqofailed'       => __( 'Veeqo Sync Failed', 'drywall-toolbox' ),
		'integrationveeqoskipped'      => __( 'Veeqo Sync Skipped', 'drywall-toolbox' ),
		'integrationquickbooksqueued'  => __( 'QuickBooks Sync Queued', 'drywall-toolbox' ),
		'integrationquickbookssynced'  => __( 'QuickBooks Synced', 'drywall-toolbox' ),
		'integrationquickbooksfailed'  => __( 'QuickBooks Sync Failed', 'drywall-toolbox' ),
		'integrationquickbooksskipped' => __( 'QuickBooks Sync Skipped', 'drywall-toolbox' ),
		'orderinventoryreserved'       => __( 'Inventory Reserved', 'drywall-toolbox' ),
		'ordertransition'              => __( 'Order Transition', 'drywall-toolbox' ),
		'paymentcaptured'              => __( 'Payment Captured', 'drywall-toolbox' ),
		'paymentfailed'                => __( 'Payment Failed', 'drywall-toolbox' ),
	];

	$key = strtolower( preg_replace( '/[^a-z0-9]+/i', '', $action ) );
	if ( isset( $known[ $key ] ) ) {
		return $known[ $key ];
	}

	$label = preg_replace( '/([a-z])([A-Z])/', '$1 $2', $action );
	$label = str_replace( [ '.', '_', '-' ], ' ', $label );
	$label = preg_replace( '/\s+/', ' ', $label );
	return ucwords( trim( (string) $label ) );
}

/**
 * Fetch recent canonical admin-audit entries.
 *
 * @param int $limit Max rows.
 * @return array<int,array>
 */
function dtb_audit_log_get_recent_admin_events( int $limit ): array {
	global $wpdb;

	$table = $wpdb->prefix . 'dtb_admin_audit_log';
	if ( ! dtb_audit_log_table_exists( $table ) ) {
		return [];
	}

	$limit = max( 1, min( 500, $limit ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, event_type, module, record_id, actor_user_id, actor_label, visibility, source, payload_json, created_at
			   FROM {$table}
			  WHERE event_type <> ''
			    AND created_at IS NOT NULL
			    AND created_at <> '0000-00-00 00:00:00'
			  ORDER BY created_at DESC, id DESC
			  LIMIT %d",
			$limit
		),
		ARRAY_A
	);

	$events = [];
	foreach ( (array) $rows as $row ) {
		$ts = dtb_audit_log_normalize_ts( $row['created_at'] ?? '' );
		if ( '' === $ts ) {
			continue;
		}

		$payload = dtb_audit_log_decode_payload( $row['payload_json'] ?? '{}' );
		$module  = sanitize_key( (string) ( $row['module'] ?? '' ) );
		$record  = absint( $row['record_id'] ?? 0 );
		$action  = sanitize_text_field( (string) ( $row['event_type'] ?? '' ) );

		$events[] = [
			'id'          => 'admin-' . absint( $row['id'] ?? 0 ),
			'ts'          => $ts,
			'user_id'     => absint( $row['actor_user_id'] ?? 0 ),
			'actor_label' => sanitize_text_field( (string) ( $row['actor_label'] ?? '' ) ),
			'action'      => $action,
			'summary'     => dtb_audit_log_humanize_action( $action ),
			'module'      => $module,
			'record_id'   => $record,
			'visibility'  => sanitize_key( (string) ( $row['visibility'] ?? 'internal' ) ),
			'source'      => sanitize_key( (string) ( $row['source'] ?? 'admin_modal' ) ),
			'context'     => $payload,
		];
	}

	return $events;
}

/**
 * Fetch recent durable order workflow and integration events.
 *
 * This is the canonical order-event ledger used by checkout, Veeqo, QuickBooks,
 * notifications, inventory, tracking, and order-state workflows.
 *
 * @param int $limit Max rows.
 * @return array<int,array>
 */
function dtb_audit_log_get_recent_order_events( int $limit ): array {
	global $wpdb;

	$table = $wpdb->prefix . 'dtb_order_events';
	if ( ! dtb_audit_log_table_exists( $table ) ) {
		return [];
	}

	$limit = max( 1, min( 500, $limit ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, order_id, event_type, actor_type, actor_id, source, visibility, payload_json, created_at
			   FROM {$table}
			  WHERE event_type <> ''
			    AND created_at IS NOT NULL
			    AND created_at <> '0000-00-00 00:00:00'
			  ORDER BY created_at DESC, id DESC
			  LIMIT %d",
			$limit
		),
		ARRAY_A
	);

	$events = [];
	foreach ( (array) $rows as $row ) {
		$ts = dtb_audit_log_normalize_ts( $row['created_at'] ?? '' );
		if ( '' === $ts ) {
			continue;
		}

		$action = sanitize_text_field( (string) ( $row['event_type'] ?? '' ) );
		$actor  = sanitize_text_field( (string) ( $row['actor_type'] ?? 'system' ) );

		$events[] = [
			'id'          => 'order-event-' . absint( $row['id'] ?? 0 ),
			'ts'          => $ts,
			'user_id'     => absint( $row['actor_id'] ?? 0 ),
			'actor_label' => $actor ?: 'system',
			'action'      => $action,
			'summary'     => dtb_audit_log_humanize_action( $action ),
			'module'      => 'order',
			'record_id'   => absint( $row['order_id'] ?? 0 ),
			'visibility'  => sanitize_key( (string) ( $row['visibility'] ?? 'operator' ) ),
			'source'      => sanitize_key( (string) ( $row['source'] ?? 'order_event' ) ),
			'context'     => dtb_audit_log_decode_payload( $row['payload_json'] ?? '{}' ),
		];
	}

	return $events;
}

/**
 * Fetch recent Action Scheduler activity for DTB queues.
 *
 * @param int $limit Max rows.
 * @return array<int,array>
 */
function dtb_audit_log_get_recent_queue_events( int $limit ): array {
	global $wpdb;

	$actions = $wpdb->prefix . 'actionscheduler_actions';
	$logs    = $wpdb->prefix . 'actionscheduler_logs';
	$groups  = $wpdb->prefix . 'actionscheduler_groups';

	if ( ! dtb_audit_log_table_exists( $actions ) || ! dtb_audit_log_table_exists( $logs ) || ! dtb_audit_log_table_exists( $groups ) ) {
		return [];
	}

	$limit = max( 1, min( 200, $limit ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT l.log_id, l.action_id, l.message, l.log_date_gmt, a.hook, a.status, g.slug AS group_slug
			   FROM {$logs} l
			   JOIN {$actions} a ON a.action_id = l.action_id
			   LEFT JOIN {$groups} g ON g.group_id = a.group_id
			  WHERE (a.hook LIKE 'dtb\_%' OR g.slug LIKE 'dtb%')
			    AND l.log_date_gmt IS NOT NULL
			    AND l.log_date_gmt <> '0000-00-00 00:00:00'
			  ORDER BY l.log_date_gmt DESC, l.log_id DESC
			  LIMIT %d",
			$limit
		),
		ARRAY_A
	);

	$events = [];
	foreach ( (array) $rows as $row ) {
		$ts = dtb_audit_log_normalize_ts( $row['log_date_gmt'] ?? '' );
		if ( '' === $ts ) {
			continue;
		}

		$hook = sanitize_text_field( (string) ( $row['hook'] ?? '' ) );
		$msg  = sanitize_text_field( (string) ( $row['message'] ?? '' ) );

		$events[] = [
			'id'          => 'queue-' . absint( $row['log_id'] ?? 0 ),
			'ts'          => $ts,
			'user_id'     => 0,
			'actor_label' => 'Action Scheduler',
			'action'      => $hook,
			'summary'     => $msg ?: dtb_audit_log_humanize_action( $hook ),
			'module'      => 'queue',
			'record_id'   => absint( $row['action_id'] ?? 0 ),
			'visibility'  => 'internal',
			'source'      => sanitize_key( (string) ( $row['group_slug'] ?? 'action_scheduler' ) ),
			'context'     => [ 'status' => sanitize_key( (string) ( $row['status'] ?? '' ) ) ],
		];
	}

	return $events;
}

/**
 * Fetch recent legacy audit entries.
 *
 * @param int $limit Max rows.
 * @return array<int,array>
 */
function dtb_audit_log_get_recent_legacy_events( int $limit ): array {
	global $wpdb;

	$table = $wpdb->prefix . 'dtb_audit_log';
	if ( ! dtb_audit_log_table_exists( $table ) ) {
		return [];
	}

	$limit = max( 1, min( 500, $limit ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, created_at_utc, user_id, action, context_json
			   FROM {$table}
			  WHERE action <> ''
			    AND created_at_utc IS NOT NULL
			    AND created_at_utc <> '0000-00-00 00:00:00'
			  ORDER BY created_at_utc DESC, id DESC
			  LIMIT %d",
			$limit
		),
		ARRAY_A
	);

	$events = [];
	foreach ( (array) $rows as $row ) {
		$ts = dtb_audit_log_normalize_ts( $row['created_at_utc'] ?? '' );
		if ( '' === $ts ) {
			continue;
		}

		$ctx = dtb_audit_log_decode_payload( $row['context_json'] ?? '{}' );
		if ( isset( $ctx['ctx'] ) && is_array( $ctx['ctx'] ) ) {
			$ctx = $ctx['ctx'];
		}

		$action = sanitize_text_field( (string) ( $row['action'] ?? '' ) );
		$events[] = [
			'id'          => 'legacy-' . absint( $row['id'] ?? 0 ),
			'ts'          => $ts,
			'user_id'     => absint( $row['user_id'] ?? 0 ),
			'actor_label' => '',
			'action'      => $action,
			'summary'     => dtb_audit_log_humanize_action( $action ),
			'module'      => sanitize_key( (string) ( $ctx['module'] ?? '' ) ),
			'record_id'   => absint( $ctx['record_id'] ?? 0 ),
			'visibility'  => sanitize_key( (string) ( $ctx['visibility'] ?? 'internal' ) ),
			'source'      => 'legacy_audit',
			'context'     => $ctx,
		];
	}

	return $events;
}

/**
 * Fetch recent audit log entries from all operational observability ledgers.
 *
 * @param int $limit Max rows.
 * @return array<int, array>
 */
function dtb_audit_log_get_recent( int $limit = 50 ): array {
	$limit = max( 1, min( 200, $limit ) );
	$events = array_merge(
		dtb_audit_log_get_recent_order_events( $limit ),
		dtb_audit_log_get_recent_admin_events( $limit ),
		dtb_audit_log_get_recent_legacy_events( $limit ),
		dtb_audit_log_get_recent_queue_events( min( 50, $limit ) )
	);

	usort(
		$events,
		static function ( array $a, array $b ): int {
			$cmp = strcmp( (string) ( $b['ts'] ?? '' ), (string) ( $a['ts'] ?? '' ) );
			if ( 0 !== $cmp ) {
				return $cmp;
			}
			return strcmp( (string) ( $b['id'] ?? '' ), (string) ( $a['id'] ?? '' ) );
		}
	);

	return array_slice( $events, 0, $limit );
}

/** Current legacy audit-log schema version. */
const DTB_AUDIT_LOG_SCHEMA_VERSION = '1.0.0';

/** Option containing the installed legacy audit-log schema version. */
const DTB_AUDIT_LOG_SCHEMA_VERSION_OPTION = 'dtb_audit_log_schema_version';

/** Option used as an atomic, short-lived schema migration lock. */
const DTB_AUDIT_LOG_SCHEMA_LOCK_OPTION = 'dtb_audit_log_schema_lock';

/**
 * Ensure the legacy audit log table exists.
 *
 * Schema work is version-gated and protected by an atomic option lock so
 * concurrent wp-admin and admin-AJAX requests cannot run dbDelta together.
 */
function dtb_audit_log_maybe_create_table(): void {
	if ( DTB_AUDIT_LOG_SCHEMA_VERSION === get_option( DTB_AUDIT_LOG_SCHEMA_VERSION_OPTION, '' ) ) {
		return;
	}

	$lock_started = time();
	$lock_acquired = add_option( DTB_AUDIT_LOG_SCHEMA_LOCK_OPTION, $lock_started, '', false );
	if ( ! $lock_acquired ) {
		$existing_lock = (int) get_option( DTB_AUDIT_LOG_SCHEMA_LOCK_OPTION, 0 );
		if ( $existing_lock <= 0 || ( $lock_started - $existing_lock ) <= 600 ) {
			return;
		}

		delete_option( DTB_AUDIT_LOG_SCHEMA_LOCK_OPTION );
		$lock_acquired = add_option( DTB_AUDIT_LOG_SCHEMA_LOCK_OPTION, $lock_started, '', false );
		if ( ! $lock_acquired ) {
			return;
		}
	}

	global $wpdb;
	$table   = $wpdb->prefix . 'dtb_audit_log';
	$charset = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE {$table} (
		id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		created_at_utc  DATETIME        NOT NULL,
		user_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
		action          VARCHAR(120)    NOT NULL,
		context_json    LONGTEXT,
		PRIMARY KEY (id),
		KEY action (action),
		KEY user_id (user_id),
		KEY created_at_utc (created_at_utc)
	) {$charset};";

	try {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Confirm the required columns exist before recording the migration.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
		$required_columns = [ 'id', 'created_at_utc', 'user_id', 'action', 'context_json' ];
		if ( empty( array_diff( $required_columns, $columns ) ) ) {
			update_option( DTB_AUDIT_LOG_SCHEMA_VERSION_OPTION, DTB_AUDIT_LOG_SCHEMA_VERSION, false );
		} else {
			error_log( '[DTB] Audit log schema migration did not produce the required columns.' );
		}
	} finally {
		delete_option( DTB_AUDIT_LOG_SCHEMA_LOCK_OPTION );
	}
}
add_action( 'admin_init', 'dtb_audit_log_maybe_create_table' );
