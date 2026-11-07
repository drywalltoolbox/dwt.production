<?php
/**
 * Infrastructure — DesignSchemaInstaller: creates the four bounded tables
 * this module owns. No unbounded serialized WordPress option is used as a
 * source of truth; every row has an explicit schema_version and payloads are
 * capped at DTB_VD_MAX_PAYLOAD_BYTES.
 *
 * Tables:
 *   wp_dtb_design_drafts            — working (unpublished) configuration, one row per draft_key.
 *   wp_dtb_design_revisions         — immutable, versioned snapshots created on publish/rollback.
 *   wp_dtb_design_published         — single pointer per draft_key to the active immutable revision.
 *   wp_dtb_design_preview_sessions  — short-lived, scoped preview authorization tokens (hashed).
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

define( 'DTB_VD_DB_VERSION', '1.0.0' );

add_action( 'plugins_loaded', 'dtb_vd_maybe_create_tables', 5 );

function dtb_vd_maybe_create_tables(): void {
	if ( (string) get_option( 'dtb_vd_db_version', '' ) === DTB_VD_DB_VERSION ) {
		return;
	}

	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();

	$drafts      = $wpdb->prefix . 'dtb_design_drafts';
	$revisions   = $wpdb->prefix . 'dtb_design_revisions';
	$published   = $wpdb->prefix . 'dtb_design_published';
	$preview     = $wpdb->prefix . 'dtb_design_preview_sessions';

	$sql = "CREATE TABLE {$drafts} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
draft_key varchar(64) NOT NULL DEFAULT 'default',
schema_version smallint(5) unsigned NOT NULL DEFAULT 1,
base_revision_id bigint(20) unsigned DEFAULT NULL,
revision_seq bigint(20) unsigned NOT NULL DEFAULT 1,
payload_json longtext NOT NULL,
autosave_json longtext DEFAULT NULL,
dirty tinyint(1) NOT NULL DEFAULT 0,
editor_id bigint(20) unsigned DEFAULT NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY (id),
UNIQUE KEY draft_key (draft_key)
) ENGINE=InnoDB {$charset_collate};

CREATE TABLE {$revisions} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
revision_uid varchar(40) NOT NULL,
draft_key varchar(64) NOT NULL DEFAULT 'default',
schema_version smallint(5) unsigned NOT NULL DEFAULT 1,
parent_revision_id bigint(20) unsigned DEFAULT NULL,
rollback_origin_id bigint(20) unsigned DEFAULT NULL,
author_id bigint(20) unsigned DEFAULT NULL,
note varchar(500) DEFAULT NULL,
payload_json longtext NOT NULL,
payload_hash varchar(64) NOT NULL,
affected_surfaces_json text DEFAULT NULL,
source_draft_revision_seq bigint(20) unsigned DEFAULT NULL,
created_at datetime NOT NULL,
published_at datetime DEFAULT NULL,
PRIMARY KEY (id),
UNIQUE KEY revision_uid (revision_uid),
KEY draft_key (draft_key),
KEY created_at (created_at)
) ENGINE=InnoDB {$charset_collate};

CREATE TABLE {$published} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
draft_key varchar(64) NOT NULL DEFAULT 'default',
revision_id bigint(20) unsigned NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY (id),
UNIQUE KEY draft_key (draft_key)
) ENGINE=InnoDB {$charset_collate};

CREATE TABLE {$preview} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
token_hash varchar(64) NOT NULL,
draft_key varchar(64) NOT NULL DEFAULT 'default',
revision_id bigint(20) unsigned DEFAULT NULL,
draft_revision_seq bigint(20) unsigned DEFAULT NULL,
operator_id bigint(20) unsigned NOT NULL,
created_at datetime NOT NULL,
expires_at datetime NOT NULL,
revoked tinyint(1) NOT NULL DEFAULT 0,
used_count int(10) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY (id),
UNIQUE KEY token_hash (token_hash),
KEY expires_at (expires_at)
) ENGINE=InnoDB {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'dtb_vd_db_version', DTB_VD_DB_VERSION );
}
