<?php
/**
 * Infrastructure: Order Schema Installer — creates wp_dtb_order_events table.
 *
 * @package drywall-toolbox
 */
defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DTB_ORDER_EVENTS_DB_VERSION' ) ) {
define( 'DTB_ORDER_EVENTS_DB_VERSION', '1.0.0' );
}

add_action( 'plugins_loaded', 'dtb_order_events_maybe_create_table', 5 );

function dtb_order_events_maybe_create_table(): void {
$installed = (string) get_option( 'dtb_order_events_db_version', '' );
if ( $installed === DTB_ORDER_EVENTS_DB_VERSION ) { return; }
global $wpdb;
$table           = $wpdb->prefix . 'dtb_order_events';
$charset_collate = $wpdb->get_charset_collate();
$sql = "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
order_id bigint(20) unsigned NOT NULL,
event_type varchar(100) NOT NULL,
from_status varchar(50) DEFAULT NULL,
to_status varchar(50) DEFAULT NULL,
actor_type varchar(50) NOT NULL DEFAULT 'system',
actor_id bigint(20) DEFAULT NULL,
source varchar(100) NOT NULL DEFAULT 'system',
visibility varchar(50) NOT NULL DEFAULT 'operator',
idempotency_key varchar(191) DEFAULT NULL,
payload_json longtext,
created_at datetime NOT NULL,
PRIMARY KEY (id),
UNIQUE KEY idempotency_key (idempotency_key),
KEY order_id (order_id),
KEY event_type (event_type),
KEY visibility (visibility),
KEY created_at (created_at)
) ENGINE=InnoDB {$charset_collate};";
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
dbDelta( $sql );
update_option( 'dtb_order_events_db_version', DTB_ORDER_EVENTS_DB_VERSION );
}
