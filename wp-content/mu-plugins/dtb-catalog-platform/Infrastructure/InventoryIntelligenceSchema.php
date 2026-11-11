<?php
/**
 * Inventory Intelligence schema management.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_InventoryIntelligenceSchema {
	private const VERSION = '2026.06.05.1';
	private const OPTION_VERSION = 'dtb_inventory_intelligence_schema_version';

	public static function stock_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'dtb_veeqo_stock';
	}

	public static function rollups_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'dtb_inventory_rollups';
	}

	public static function sync_runs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'dtb_inventory_sync_runs';
	}

	public static function events_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'dtb_inventory_events';
	}

	public static function maybe_install(): void {
		$installed = (string) get_option( self::OPTION_VERSION, '' );
		if ( self::VERSION === $installed ) {
			return;
		}

		self::install();
		update_option( self::OPTION_VERSION, self::VERSION, false );
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$stock_table     = self::stock_table();
		$rollups_table   = self::rollups_table();
		$sync_runs_table = self::sync_runs_table();
		$events_table    = self::events_table();

		$sql = [];
		$sql[] = "CREATE TABLE {$stock_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sku varchar(128) NOT NULL,
			woo_product_id bigint(20) unsigned DEFAULT NULL,
			veeqo_product_id varchar(128) DEFAULT NULL,
			veeqo_variant_id varchar(128) DEFAULT NULL,
			qty_on_hand int(11) NOT NULL DEFAULT 0,
			qty_committed int(11) NOT NULL DEFAULT 0,
			qty_available int(11) NOT NULL DEFAULT 0,
			last_synced_at datetime DEFAULT NULL,
			sync_source varchar(32) NOT NULL DEFAULT 'manual',
			raw_payload longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY sku (sku),
			KEY woo_product_id (woo_product_id),
			KEY qty_available (qty_available),
			KEY last_synced_at (last_synced_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$rollups_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			universal_part_id varchar(128) NOT NULL,
			canonical_name varchar(255) DEFAULT NULL,
			part_family varchar(64) DEFAULT NULL,
			total_qty_on_hand int(11) NOT NULL DEFAULT 0,
			total_qty_committed int(11) NOT NULL DEFAULT 0,
			total_qty_available int(11) NOT NULL DEFAULT 0,
			effective_qty_available int(11) NOT NULL DEFAULT 0,
			active_member_count int(11) NOT NULL DEFAULT 0,
			stocked_member_count int(11) NOT NULL DEFAULT 0,
			brand_breakdown longtext DEFAULT NULL,
			reorder_signal varchar(32) NOT NULL DEFAULT 'none',
			days_of_supply decimal(10,2) DEFAULT NULL,
			last_computed_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY universal_part_id (universal_part_id),
			KEY effective_qty_available (effective_qty_available),
			KEY reorder_signal (reorder_signal),
			KEY part_family (part_family)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$sync_runs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_key varchar(128) NOT NULL,
			status varchar(32) NOT NULL,
			started_at datetime NOT NULL,
			finished_at datetime DEFAULT NULL,
			duration_ms int(11) DEFAULT NULL,
			records_seen int(11) NOT NULL DEFAULT 0,
			records_updated int(11) NOT NULL DEFAULT 0,
			records_failed int(11) NOT NULL DEFAULT 0,
			error_message text DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY job_key (job_key),
			KEY status (status),
			KEY started_at (started_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$events_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(64) NOT NULL,
			sku varchar(128) DEFAULT NULL,
			universal_part_id varchar(128) DEFAULT NULL,
			qty_delta int(11) DEFAULT NULL,
			source varchar(64) NOT NULL,
			source_event_id varchar(191) DEFAULT NULL,
			payload longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY event_type (event_type),
			KEY sku (sku),
			KEY universal_part_id (universal_part_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}
}

add_action( 'admin_init', [ DTB_InventoryIntelligenceSchema::class, 'maybe_install' ] );
add_action( 'rest_api_init', [ DTB_InventoryIntelligenceSchema::class, 'maybe_install' ], 1 );
