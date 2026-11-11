<?php
/**
 * Infrastructure — ReleaseSchemaInstaller: creates wp_dtb_release_events table.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_RELEASE_EVENTS_DB_VERSION = '1.0.0';

add_action( 'plugins_loaded', 'dtb_release_events_maybe_create_table', 5 );

/**
 * Create or upgrade the wp_dtb_release_events table using dbDelta().
 *
 * One row per release lifecycle fact (event-sourced), keyed by release_id.
 * The (release_id, event_type) unique key makes webhook delivery retries
 * idempotent at the database level.
 */
function dtb_release_events_maybe_create_table(): void {
	$installed = (string) get_option( 'dtb_release_events_db_version', '' );

	if ( $installed === DTB_RELEASE_EVENTS_DB_VERSION ) {
		return;
	}

	global $wpdb;

	$table           = $wpdb->prefix . 'dtb_release_events';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		release_id varchar(80) NOT NULL,
		event_type varchar(64) NOT NULL,
		ref_type varchar(20) DEFAULT NULL,
		ref varchar(191) DEFAULT NULL,
		commit_sha varchar(40) DEFAULT NULL,
		actor varchar(191) DEFAULT NULL,
		source varchar(40) NOT NULL DEFAULT 'github_actions',
		workflow_run_id varchar(40) DEFAULT NULL,
		payload_json longtext,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY release_event (release_id, event_type),
		KEY event_type (event_type),
		KEY created_at (created_at)
	) ENGINE=InnoDB {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	// dbDelta() does not reliably surface failures (insufficient privileges,
	// unexpected charset/collation, etc.). Verify the table and its unique
	// key actually exist before marking the schema installed — otherwise a
	// silent failure would be recorded as "installed" and never retried.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$key_exists = $table_exists && (bool) $wpdb->get_row( "SHOW KEYS FROM {$table} WHERE Key_name = 'release_event'" );

	if ( ! $table_exists || ! $key_exists ) {
		error_log( '[DTB Deployment] wp_dtb_release_events schema installation failed — table or unique key missing.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		return;
	}

	update_option( 'dtb_release_events_db_version', DTB_RELEASE_EVENTS_DB_VERSION );
}
