<?php
/**
 * Infrastructure — SupportSchemaInstaller: custom database table creation via dbDelta.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DTB_SUPPORT_DB_VERSION' ) ) {
	define( 'DTB_SUPPORT_DB_VERSION', '2' );
}

add_action( 'plugins_loaded', 'dtb_support_maybe_install_schema', 5 );

/**
 * Create or upgrade schema tables when the stored db version is out of date.
 */
function dtb_support_maybe_install_schema(): void {
	if ( dtb_support_db_version() === DTB_SUPPORT_DB_VERSION ) {
		return;
	}

	global $wpdb;
	$charset = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$tickets_table = $wpdb->prefix . 'dtb_support_tickets';
	$sql_tickets   = "CREATE TABLE {$tickets_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ticket_number varchar(20) NOT NULL DEFAULT '',
  status varchar(50) NOT NULL DEFAULT 'open',
  ticket_type varchar(50) NOT NULL DEFAULT 'contact',
  priority varchar(20) NOT NULL DEFAULT 'normal',
  subject varchar(255) NOT NULL DEFAULT '',
  customer_name varchar(120) NOT NULL DEFAULT '',
  customer_email varchar(120) NOT NULL DEFAULT '',
  customer_phone varchar(40) NOT NULL DEFAULT '',
  company varchar(120) NOT NULL DEFAULT '',
  message longtext NOT NULL,
  assigned_user_id bigint(20) unsigned DEFAULT NULL,
  source varchar(80) NOT NULL DEFAULT 'website',
  order_id bigint(20) unsigned DEFAULT NULL,
  tags varchar(500) NOT NULL DEFAULT '',
  internal_notes longtext NOT NULL,
  first_reply_at datetime DEFAULT NULL,
  resolved_at datetime DEFAULT NULL,
  closed_at datetime DEFAULT NULL,
  sla_first_response_due datetime DEFAULT NULL,
  sla_resolution_due datetime DEFAULT NULL,
  sla_state varchar(20) NOT NULL DEFAULT 'ok',
  last_customer_reply_at datetime DEFAULT NULL,
  last_staff_reply_at datetime DEFAULT NULL,
  priority_score int(11) NOT NULL DEFAULT 0,
  metadata_json longtext DEFAULT NULL,
  snooze_until datetime DEFAULT NULL,
  snooze_reason varchar(255) NOT NULL DEFAULT '',
  followup_due_at datetime DEFAULT NULL,
  notification_status varchar(50) NOT NULL DEFAULT '',
  notification_fail_count int(11) NOT NULL DEFAULT 0,
  notification_last_sent_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY ticket_number (ticket_number),
  KEY status (status),
  KEY ticket_type (ticket_type),
  KEY priority (priority),
  KEY customer_email (customer_email),
  KEY assigned_user_id (assigned_user_id),
  KEY created_at (created_at),
  KEY updated_at (updated_at),
  KEY priority_score (priority_score),
  KEY sla_state (sla_state),
  KEY snooze_until (snooze_until),
  KEY last_customer_reply_at (last_customer_reply_at)
) ENGINE=InnoDB {$charset};";

	dbDelta( $sql_tickets );
	dtb_support_install_ticket_v2_columns( $tickets_table );
	dtb_support_install_ticket_v2_indexes( $tickets_table );

	$events_table = $wpdb->prefix . 'dtb_support_events';
	$sql_events   = "CREATE TABLE {$events_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ticket_id bigint(20) unsigned NOT NULL,
  event_type varchar(100) NOT NULL DEFAULT '',
  from_status varchar(50) DEFAULT NULL,
  to_status varchar(50) DEFAULT NULL,
  actor_type varchar(50) NOT NULL DEFAULT 'system',
  actor_id bigint(20) unsigned DEFAULT NULL,
  source varchar(100) NOT NULL DEFAULT 'system',
  visibility varchar(50) NOT NULL DEFAULT 'operator',
  body longtext NOT NULL,
  payload_json longtext DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY ticket_id (ticket_id),
  KEY event_type (event_type),
  KEY visibility (visibility),
  KEY created_at (created_at)
) ENGINE=InnoDB {$charset};";

	dbDelta( $sql_events );
	dtb_support_install_auxiliary_support_tables( $charset );

	update_option( 'dtb_support_db_version', DTB_SUPPORT_DB_VERSION );
}

/**
 * Add missing v2 columns safely without depending on dbDelta.
 */
function dtb_support_install_ticket_v2_columns( string $tickets_table ): void {
	global $wpdb;

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$tickets_table}" );
	$existing = [];
	foreach ( (array) $columns as $column ) {
		$existing[ strtolower( (string) $column->Field ) ] = true;
	}

	$definitions = [
		'sla_first_response_due'     => 'datetime DEFAULT NULL',
		'sla_resolution_due'         => 'datetime DEFAULT NULL',
		'sla_state'                  => "varchar(20) NOT NULL DEFAULT 'ok'",
		'last_customer_reply_at'     => 'datetime DEFAULT NULL',
		'last_staff_reply_at'        => 'datetime DEFAULT NULL',
		'priority_score'             => 'int(11) NOT NULL DEFAULT 0',
		'metadata_json'              => 'longtext DEFAULT NULL',
		'snooze_until'               => 'datetime DEFAULT NULL',
		'snooze_reason'              => "varchar(255) NOT NULL DEFAULT ''",
		'followup_due_at'            => 'datetime DEFAULT NULL',
		'notification_status'        => "varchar(50) NOT NULL DEFAULT ''",
		'notification_fail_count'    => 'int(11) NOT NULL DEFAULT 0',
		'notification_last_sent_at'  => 'datetime DEFAULT NULL',
	];

	foreach ( $definitions as $column => $definition ) {
		if ( isset( $existing[ strtolower( $column ) ] ) ) {
			continue;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$tickets_table} ADD COLUMN {$column} {$definition}" );
	}
}

/**
 * Add missing queue indexes safely without depending on dbDelta.
 */
function dtb_support_install_ticket_v2_indexes( string $tickets_table ): void {
	global $wpdb;

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$indexes = $wpdb->get_results( "SHOW INDEX FROM {$tickets_table}" );
	$existing = [];
	foreach ( (array) $indexes as $index ) {
		$existing[ strtolower( (string) $index->Key_name ) ] = true;
	}

	$definitions = [
		'priority_score'         => 'priority_score',
		'sla_state'              => 'sla_state',
		'snooze_until'           => 'snooze_until',
		'last_customer_reply_at' => 'last_customer_reply_at',
	];

	foreach ( $definitions as $index_name => $column_name ) {
		if ( isset( $existing[ strtolower( $index_name ) ] ) ) {
			continue;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$tickets_table} ADD INDEX {$index_name} ({$column_name})" );
	}
}

/**
 * Ensure auxiliary support tables exist.
 */
function dtb_support_install_auxiliary_support_tables( string $charset ): void {
	global $wpdb;

	$outbox_table     = $wpdb->prefix . 'dtb_support_email_outbox';
	$automation_table = $wpdb->prefix . 'dtb_support_automation_rules';
	$macros_table     = $wpdb->prefix . 'dtb_support_macros';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$outbox_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ticket_id bigint(20) unsigned DEFAULT NULL,
  recipient_email varchar(120) NOT NULL DEFAULT '',
  recipient_name varchar(120) NOT NULL DEFAULT '',
  subject varchar(255) NOT NULL DEFAULT '',
  body_html longtext NOT NULL,
  body_text longtext NOT NULL,
  headers longtext NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  attempts int(11) NOT NULL DEFAULT 0,
  next_attempt_at datetime DEFAULT NULL,
  sent_at datetime DEFAULT NULL,
  last_error text DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (id),
  KEY ticket_id (ticket_id),
  KEY status (status),
  KEY next_attempt_at (next_attempt_at),
  KEY created_at (created_at)
) ENGINE=InnoDB {$charset};" );

	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$automation_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  rule_name varchar(120) NOT NULL DEFAULT '',
  trigger_event varchar(100) NOT NULL DEFAULT '',
  conditions longtext NOT NULL,
  actions longtext NOT NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  sort_order int(11) NOT NULL DEFAULT 0,
  run_count int(11) NOT NULL DEFAULT 0,
  last_run_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (id),
  KEY trigger_event (trigger_event),
  KEY is_active (is_active),
  KEY sort_order (sort_order)
) ENGINE=InnoDB {$charset};" );

	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$macros_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  macro_name varchar(120) NOT NULL DEFAULT '',
  category varchar(80) NOT NULL DEFAULT 'general',
  subject_template varchar(255) NOT NULL DEFAULT '',
  body_template longtext NOT NULL,
  variables longtext NOT NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  sort_order int(11) NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (id),
  KEY category (category),
  KEY is_active (is_active)
) ENGINE=InnoDB {$charset};" );
	// phpcs:enable
}

/**
 * Force-reinstall schema (e.g. after a manual reset). Clears the version flag.
 */
function dtb_support_force_reinstall_schema(): void {
	delete_option( 'dtb_support_db_version' );
	dtb_support_maybe_install_schema();
}

// Support capability grants (dtb_manage_support, dtb_read_support_tickets,
// dtb_manage_support_automation, etc.) are owned centrally by
// `dtb-platform/Admin/AdminCapabilities.php` (`dtb_admin_all_capabilities()`)
// — see that file's header comment. This used to independently re-grant the
// same capability subset to `administrator` on every `init`; removed as a
// redundant duplicate of that canonical grant.

/**
 * Return the stored support schema version.
 */
function dtb_support_db_version(): string {
	return (string) get_option( 'dtb_support_db_version', '0' );
}
