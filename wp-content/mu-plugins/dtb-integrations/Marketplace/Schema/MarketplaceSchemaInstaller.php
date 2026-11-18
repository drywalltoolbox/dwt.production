<?php
/**
 * Marketplace Schema Installer
 *
 * Creates all first-class marketplace read-model tables:
 *   wp_dtb_marketplace_channels
 *   wp_dtb_marketplace_orders
 *   wp_dtb_marketplace_conversations
 *   wp_dtb_marketplace_messages
 *   wp_dtb_marketplace_events
 *   wp_dtb_marketplace_exceptions
 *   wp_dtb_marketplace_audit
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DTB_MARKETPLACE_DB_VERSION' ) ) {
	define( 'DTB_MARKETPLACE_DB_VERSION', '1' );
}

add_action( 'plugins_loaded', 'dtb_marketplace_maybe_install_schema', 6 );

/**
 * Run schema install/upgrade when stored version is behind.
 */
function dtb_marketplace_maybe_install_schema(): void {
	if ( (string) get_option( 'dtb_marketplace_db_version', '' ) === DTB_MARKETPLACE_DB_VERSION ) {
		return;
	}
	global $wpdb;
	$c = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	// ── Channels ────────────────────────────────────────────────────────────
	dbDelta( "CREATE TABLE {$wpdb->prefix}dtb_marketplace_channels (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  channel_key varchar(50) NOT NULL DEFAULT '',
  account_label varchar(120) NOT NULL DEFAULT '',
  marketplace_id varchar(80) NOT NULL DEFAULT '',
  external_account_id varchar(120) NOT NULL DEFAULT '',
  is_sandbox tinyint(1) NOT NULL DEFAULT 0,
  is_enabled tinyint(1) NOT NULL DEFAULT 0,
  auth_state varchar(30) NOT NULL DEFAULT 'disconnected',
  health_state varchar(30) NOT NULL DEFAULT 'unknown',
  last_sync_at datetime DEFAULT NULL,
  last_error_at datetime DEFAULT NULL,
  last_error_msg varchar(500) NOT NULL DEFAULT '',
  veeqo_channel_id varchar(80) NOT NULL DEFAULT '',
  settings_json longtext DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY channel_key (channel_key),
  KEY auth_state (auth_state),
  KEY health_state (health_state),
  KEY is_enabled (is_enabled)
) ENGINE=InnoDB {$c};" );

	// ── Orders ───────────────────────────────────────────────────────────────
	dbDelta( "CREATE TABLE {$wpdb->prefix}dtb_marketplace_orders (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  channel_key varchar(50) NOT NULL DEFAULT '',
  marketplace_order_id varchar(191) NOT NULL DEFAULT '',
  woo_order_id bigint(20) unsigned DEFAULT NULL,
  veeqo_order_id varchar(80) NOT NULL DEFAULT '',
  buyer_ref_hash varchar(64) NOT NULL DEFAULT '',
  payment_state varchar(50) NOT NULL DEFAULT 'unknown',
  fulfillment_state varchar(50) NOT NULL DEFAULT 'unshipped',
  tracking_state varchar(50) NOT NULL DEFAULT 'none',
  message_state varchar(50) NOT NULL DEFAULT 'none',
  sla_due_at datetime DEFAULT NULL,
  exception_count int(11) NOT NULL DEFAULT 0,
  raw_payload_hash varchar(64) NOT NULL DEFAULT '',
  first_synced_at datetime DEFAULT NULL,
  last_synced_at datetime DEFAULT NULL,
  order_placed_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY channel_order (channel_key, marketplace_order_id),
  KEY woo_order_id (woo_order_id),
  KEY channel_key (channel_key),
  KEY fulfillment_state (fulfillment_state),
  KEY payment_state (payment_state),
  KEY sla_due_at (sla_due_at),
  KEY last_synced_at (last_synced_at)
) ENGINE=InnoDB {$c};" );

	// ── Conversations ────────────────────────────────────────────────────────
	dbDelta( "CREATE TABLE {$wpdb->prefix}dtb_marketplace_conversations (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  channel_key varchar(50) NOT NULL DEFAULT '',
  external_conversation_id varchar(191) NOT NULL DEFAULT '',
  external_order_id varchar(191) NOT NULL DEFAULT '',
  external_listing_id varchar(191) NOT NULL DEFAULT '',
  external_item_id varchar(191) NOT NULL DEFAULT '',
  woo_order_id bigint(20) unsigned DEFAULT NULL,
  marketplace_order_id bigint(20) unsigned DEFAULT NULL,
  buyer_ref_hash varchar(64) NOT NULL DEFAULT '',
  subject varchar(500) NOT NULL DEFAULT '',
  status varchar(30) NOT NULL DEFAULT 'open',
  sla_due_at datetime DEFAULT NULL,
  sla_state varchar(20) NOT NULL DEFAULT 'ok',
  last_inbound_at datetime DEFAULT NULL,
  last_outbound_at datetime DEFAULT NULL,
  unread_count int(11) NOT NULL DEFAULT 0,
  assigned_user_id bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY channel_conv (channel_key, external_conversation_id),
  KEY channel_key (channel_key),
  KEY woo_order_id (woo_order_id),
  KEY status (status),
  KEY sla_due_at (sla_due_at),
  KEY buyer_ref_hash (buyer_ref_hash),
  KEY assigned_user_id (assigned_user_id)
) ENGINE=InnoDB {$c};" );

	// ── Messages ─────────────────────────────────────────────────────────────
	dbDelta( "CREATE TABLE {$wpdb->prefix}dtb_marketplace_messages (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  conversation_id bigint(20) unsigned NOT NULL,
  external_message_id varchar(191) NOT NULL DEFAULT '',
  direction varchar(10) NOT NULL DEFAULT 'inbound',
  sender_type varchar(20) NOT NULL DEFAULT 'buyer',
  body_preview varchar(500) NOT NULL DEFAULT '',
  body_encrypted longtext DEFAULT NULL,
  attachment_meta_json longtext DEFAULT NULL,
  message_status varchar(30) NOT NULL DEFAULT 'received',
  operator_id bigint(20) unsigned DEFAULT NULL,
  idempotency_key varchar(191) DEFAULT NULL,
  platform_action varchar(80) NOT NULL DEFAULT '',
  send_attempt_count int(11) NOT NULL DEFAULT 0,
  sent_at datetime DEFAULT NULL,
  failed_at datetime DEFAULT NULL,
  failure_reason varchar(500) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (id),
  KEY conversation_id (conversation_id),
  KEY direction (direction),
  KEY message_status (message_status),
  KEY idempotency_key (idempotency_key),
  KEY created_at (created_at)
) ENGINE=InnoDB {$c};" );

	// ── Events ───────────────────────────────────────────────────────────────
	dbDelta( "CREATE TABLE {$wpdb->prefix}dtb_marketplace_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  channel_key varchar(50) NOT NULL DEFAULT '',
  event_type varchar(100) NOT NULL DEFAULT '',
  external_event_id varchar(191) NOT NULL DEFAULT '',
  linked_order_id bigint(20) unsigned DEFAULT NULL,
  linked_conversation_id bigint(20) unsigned DEFAULT NULL,
  linked_message_id bigint(20) unsigned DEFAULT NULL,
  idempotency_key varchar(191) DEFAULT NULL,
  safe_payload_json longtext DEFAULT NULL,
  payload_hash varchar(64) NOT NULL DEFAULT '',
  processing_status varchar(30) NOT NULL DEFAULT 'pending',
  processed_at datetime DEFAULT NULL,
  error_message varchar(500) NOT NULL DEFAULT '',
  retry_count int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY channel_key (channel_key),
  KEY event_type (event_type),
  KEY processing_status (processing_status),
  KEY linked_order_id (linked_order_id),
  KEY created_at (created_at)
) ENGINE=InnoDB {$c};" );

	// ── Exceptions ───────────────────────────────────────────────────────────
	dbDelta( "CREATE TABLE {$wpdb->prefix}dtb_marketplace_exceptions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  category varchar(80) NOT NULL DEFAULT '',
  severity varchar(20) NOT NULL DEFAULT 'error',
  channel_key varchar(50) NOT NULL DEFAULT '',
  linked_record_type varchar(50) NOT NULL DEFAULT '',
  linked_record_id bigint(20) unsigned DEFAULT NULL,
  error_code varchar(80) NOT NULL DEFAULT '',
  error_message varchar(1000) NOT NULL DEFAULT '',
  is_retryable tinyint(1) NOT NULL DEFAULT 1,
  resolution_state varchar(30) NOT NULL DEFAULT 'open',
  resolved_at datetime DEFAULT NULL,
  resolved_by bigint(20) unsigned DEFAULT NULL,
  retry_count int(11) NOT NULL DEFAULT 0,
  next_retry_at datetime DEFAULT NULL,
  context_json longtext DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (id),
  KEY category (category),
  KEY channel_key (channel_key),
  KEY resolution_state (resolution_state),
  KEY severity (severity),
  KEY next_retry_at (next_retry_at),
  KEY linked_record_type (linked_record_type),
  KEY created_at (created_at)
) ENGINE=InnoDB {$c};" );

	// ── Audit ────────────────────────────────────────────────────────────────
	dbDelta( "CREATE TABLE {$wpdb->prefix}dtb_marketplace_audit (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  actor_id bigint(20) unsigned DEFAULT NULL,
  actor_type varchar(30) NOT NULL DEFAULT 'operator',
  action varchar(120) NOT NULL DEFAULT '',
  object_type varchar(80) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned DEFAULT NULL,
  channel_key varchar(50) NOT NULL DEFAULT '',
  before_json longtext DEFAULT NULL,
  after_json longtext DEFAULT NULL,
  ip_hash varchar(64) NOT NULL DEFAULT '',
  ua_hash varchar(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY (id),
  KEY actor_id (actor_id),
  KEY action (action),
  KEY object_type (object_type),
  KEY object_id (object_id),
  KEY channel_key (channel_key),
  KEY created_at (created_at)
) ENGINE=InnoDB {$c};" );

	update_option( 'dtb_marketplace_db_version', DTB_MARKETPLACE_DB_VERSION );
}
