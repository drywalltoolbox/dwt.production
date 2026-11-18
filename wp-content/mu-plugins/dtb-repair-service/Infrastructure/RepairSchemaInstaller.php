<?php
/**
 * Infrastructure — RepairSchemaInstaller: creates wp_dtb_repair_events table.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'plugins_loaded', 'dtb_repair_events_maybe_create_table', 5 );

/**
 * Create or upgrade the wp_dtb_repair_events table using dbDelta().
 */
function dtb_repair_events_maybe_create_table(): void {
$installed = (string) get_option( 'dtb_repair_events_db_version', '' );

if ( $installed === DTB_REPAIR_EVENTS_DB_VERSION ) {
return;
}

global $wpdb;

$table           = $wpdb->prefix . 'dtb_repair_events';
$charset_collate = $wpdb->get_charset_collate();

$sql = "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
repair_id bigint(20) unsigned NOT NULL,
event_type varchar(100) NOT NULL,
from_status varchar(50) DEFAULT NULL,
to_status varchar(50) DEFAULT NULL,
actor_type varchar(50) NOT NULL DEFAULT 'system',
actor_id bigint(20) DEFAULT NULL,
source varchar(100) NOT NULL DEFAULT 'system',
visibility varchar(50) NOT NULL DEFAULT 'operator',
payload_json longtext,
created_at datetime NOT NULL,
PRIMARY KEY (id),
KEY repair_id (repair_id),
KEY event_type (event_type),
KEY visibility (visibility),
KEY created_at (created_at)
) ENGINE=InnoDB {$charset_collate};";

require_once ABSPATH . 'wp-admin/includes/upgrade.php';
dbDelta( $sql );

update_option( 'dtb_repair_events_db_version', DTB_REPAIR_EVENTS_DB_VERSION );
}
