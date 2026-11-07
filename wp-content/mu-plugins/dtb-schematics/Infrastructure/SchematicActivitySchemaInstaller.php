<?php
/**
 * DTB Schematics — SchematicActivitySchemaInstaller
 *
 * Creates `wp_dtb_schematic_activity`, the bounded/paginated operation-history
 * table backing the control center's operation history and the
 * Overview screen's "recent operations" feed. This is the simplest
 * WordPress-compatible mechanism for recording pipeline operations (source
 * scans, reconciliation runs, publication/retirement, product-projection
 * refreshes, hotspot dataset association) — a dedicated table rather than a
 * CPT/postmeta blob, because these records are append-only, queried by
 * type/operator/date/result/schematic, and unrelated to any single
 * `dtb_schematic` post's own postmeta.
 *
 * Only Application/RecordSchematicActivity.php writes to this table.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DTB_SCHEMATIC_ACTIVITY_DB_VERSION' ) ) {
	define( 'DTB_SCHEMATIC_ACTIVITY_DB_VERSION', '1.0.0' );
}

add_action( 'plugins_loaded', 'dtb_schematic_activity_maybe_create_table', 5 );

function dtb_schematic_activity_table_name(): string {
	global $wpdb;
	return $wpdb->prefix . 'dtb_schematic_activity';
}

function dtb_schematic_activity_maybe_create_table(): void {
	$installed = (string) get_option( 'dtb_schematic_activity_db_version', '' );
	if ( $installed === DTB_SCHEMATIC_ACTIVITY_DB_VERSION ) {
		return;
	}

	global $wpdb;
	$table           = dtb_schematic_activity_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		operation_type varchar(64) NOT NULL,
		operator_id bigint(20) unsigned DEFAULT NULL,
		schematic_id bigint(20) unsigned DEFAULT NULL,
		schematic_canonical_id varchar(191) DEFAULT NULL,
		dry_run tinyint(1) NOT NULL DEFAULT 1,
		result varchar(32) NOT NULL DEFAULT 'ok',
		examined_count int(10) unsigned NOT NULL DEFAULT 0,
		changed_count int(10) unsigned NOT NULL DEFAULT 0,
		skipped_count int(10) unsigned NOT NULL DEFAULT 0,
		unresolved_count int(10) unsigned NOT NULL DEFAULT 0,
		source_version varchar(64) DEFAULT NULL,
		catalog_version bigint(20) unsigned DEFAULT NULL,
		summary text,
		detail_json longtext,
		started_at datetime NOT NULL,
		completed_at datetime NOT NULL,
		PRIMARY KEY (id),
		KEY operation_type (operation_type),
		KEY operator_id (operator_id),
		KEY schematic_id (schematic_id),
		KEY result (result),
		KEY completed_at (completed_at),
		KEY catalog_version (catalog_version)
	) ENGINE=InnoDB {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'dtb_schematic_activity_db_version', DTB_SCHEMATIC_ACTIVITY_DB_VERSION );
}
