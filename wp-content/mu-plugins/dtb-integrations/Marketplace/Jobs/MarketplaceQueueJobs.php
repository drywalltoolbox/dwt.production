<?php
/**
 * Marketplace Queue Jobs
 *
 * Registers all Action Scheduler / WP-Cron hooks for marketplace background processing.
 * Each job is idempotent, retry-safe, and logs events on completion/failure.
 *
 * Job hooks registered:
 *   dtb_amazon_order_sync            — full Amazon order sync
 *   dtb_amazon_order_sync_single     — single-order re-sync (from notification)
 *   dtb_ebay_order_sync              — full eBay order sync
 *   dtb_ebay_message_sync            — eBay message sync for open conversations
 *   dtb_amazon_message_send          — execute Amazon outbound message (dequeued from table)
 *   dtb_ebay_message_send            — execute eBay outbound reply
 *   dtb_marketplace_health_refresh   — refresh channel health states
 *   dtb_marketplace_reconcile        — reconcile marketplace orders with Woo
 *   dtb_marketplace_exception_retry  — retry retryable open exceptions
 *   dtb_marketplace_token_refresh    — proactively refresh tokens near expiry
 *   dtb_marketplace_retention        — redact expired conversation/message bodies
 *   dtb_marketplace_tracking_sync    — sync tracking updates from Veeqo to marketplaces
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

// ── Amazon Order Sync ─────────────────────────────────────────────────────────

add_action( 'dtb_amazon_order_sync', static function (): void {
	if ( ! class_exists( 'DTB_AmazonOrdersService' ) ) {
		return;
	}
	DTB_AmazonOrdersService::sync();
} );

add_action( 'dtb_amazon_order_sync_single', static function ( string $amazon_order_id ): void {
	if ( ! class_exists( 'DTB_AmazonOrdersService' ) || '' === $amazon_order_id ) {
		return;
	}
	// Fetch and re-import a single order using its SP-API endpoint.
	$response = DTB_AmazonSpApiClient::request( 'GET', '/orders/v0/orders/' . rawurlencode( $amazon_order_id ) );
	if ( $response['ok'] && ! empty( $response['data']['payload'] ) ) {
		DTB_AmazonOrdersService::import_single( $response['data']['payload'] );
	}
} );

// ── eBay Order Sync ───────────────────────────────────────────────────────────

add_action( 'dtb_ebay_order_sync', static function (): void {
	if ( ! class_exists( 'DTB_EbayFulfillmentService' ) ) {
		return;
	}
	DTB_EbayFulfillmentService::sync();
} );

// ── eBay Message Sync ─────────────────────────────────────────────────────────

add_action( 'dtb_ebay_message_sync', static function (): void {
	if ( ! class_exists( 'DTB_EbayMessageService' ) || ! class_exists( 'DTB_MarketplaceReadModels' ) ) {
		return;
	}

	// Process open eBay conversations that may have new messages.
	$result = DTB_MarketplaceReadModels::conversations( [ 'channel_key' => DTB_CHANNEL_EBAY, 'status' => 'open' ], 1, 50 );
	foreach ( $result['items'] as $conv ) {
		DTB_EbayMessageService::sync_messages(
			(string) $conv['external_item_id'],
			'', // buyer username not stored in conversation; in production this would be retrieved from conv record
			(int) $conv['id']
		);
	}
} );

// ── Amazon Message Send ───────────────────────────────────────────────────────

add_action( 'dtb_amazon_message_send', static function ( array $args ): void {
	if ( ! class_exists( 'DTB_AmazonMessagingService' ) ) {
		return;
	}
	[ $message_id, $amazon_order_id, $action, $payload, $idempotency_key ] = array_pad( $args, 5, '' );
	DTB_AmazonMessagingService::execute_send( (int) $message_id, (string) $amazon_order_id, (string) $action, (array) $payload, (string) $idempotency_key );
} );

// ── eBay Message Send ─────────────────────────────────────────────────────────

add_action( 'dtb_ebay_message_send', static function ( array $args ): void {
	if ( ! class_exists( 'DTB_EbayMessageService' ) ) {
		return;
	}
	[ $message_id, $buyer_username, $item_id, $order_id, $body, $idempotency_key ] = array_pad( $args, 6, '' );
	DTB_EbayMessageService::execute_reply(
		(int) $message_id,
		(string) $buyer_username,
		(string) $item_id,
		(string) $order_id,
		(string) $body,
		(string) $idempotency_key
	);
} );

// ── Health Refresh ────────────────────────────────────────────────────────────

add_action( 'dtb_marketplace_health_refresh', static function (): void {
	if ( class_exists( 'DTB_AmazonHealthCheck' ) && DTB_AmazonConfig::is_configured() ) {
		DTB_AmazonHealthCheck::run();
	}
	if ( class_exists( 'DTB_EbayHealthCheck' ) && DTB_EbayConfig::is_configured() ) {
		DTB_EbayHealthCheck::run();
	}
} );

// ── Reconciliation ────────────────────────────────────────────────────────────

add_action( 'dtb_marketplace_reconcile', static function (): void {
	if ( ! class_exists( 'DTB_MarketplaceReadModels' ) ) {
		return;
	}
	global $wpdb;
	$table = $wpdb->prefix . 'dtb_marketplace_orders';

	// Find marketplace orders with no linked Woo order and try to auto-link.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$unlinked = $wpdb->get_results(
		"SELECT id, channel_key, marketplace_order_id FROM {$table}
		 WHERE woo_order_id IS NULL ORDER BY created_at DESC LIMIT 50",
		ARRAY_A
	) ?? [];

	foreach ( $unlinked as $mp_order ) {
		if ( DTB_CHANNEL_AMAZON === $mp_order['channel_key'] ) {
			DTB_AmazonOrdersService::try_auto_link_woo( (int) $mp_order['id'], $mp_order['marketplace_order_id'] );
		}
	}
} );

// ── Exception Retry ───────────────────────────────────────────────────────────

add_action( 'dtb_marketplace_exception_retry', static function (): void {
	if ( ! class_exists( 'DTB_MarketplaceExceptionService' ) ) {
		return;
	}
	global $wpdb;
	$table = $wpdb->prefix . 'dtb_marketplace_exceptions';
	$now   = current_time( 'mysql', true );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$retryable = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$table}
		 WHERE resolution_state = 'open' AND is_retryable = 1
		   AND (next_retry_at IS NULL OR next_retry_at <= %s)
		   AND retry_count < 10
		 ORDER BY created_at ASC LIMIT 20",
		$now
	), ARRAY_A ) ?? [];

	foreach ( $retryable as $exc ) {
		DTB_MarketplaceExceptionService::mark_retry_scheduled( (int) $exc['id'], 300 * ( (int) $exc['retry_count'] + 1 ) );

		// Re-trigger appropriate sync for known categories.
		$hook = match ( $exc['category'] ) {
			'order_import'    => DTB_CHANNEL_AMAZON === $exc['channel_key'] ? 'dtb_amazon_order_sync' : 'dtb_ebay_order_sync',
			'message_send'    => DTB_CHANNEL_AMAZON === $exc['channel_key'] ? null : null,
			'token_refresh'   => 'dtb_marketplace_token_refresh',
			default           => null,
		};

		if ( $hook ) {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + 60, $hook, [], 'dtb-marketplace' );
			} else {
				wp_schedule_single_event( time() + 60, $hook );
			}
		}
	}
} );

// ── Token Refresh ─────────────────────────────────────────────────────────────

add_action( 'dtb_marketplace_token_refresh', static function (): void {
	if ( class_exists( 'DTB_AmazonLwaTokenService' ) && DTB_AmazonConfig::is_configured() ) {
		DTB_AmazonLwaTokenService::refresh();
	}
	if ( class_exists( 'DTB_EbayOAuthTokenService' ) && DTB_EbayConfig::is_configured() ) {
		DTB_EbayOAuthTokenService::refresh_access_token();
	}
} );

// ── Retention / Redaction ─────────────────────────────────────────────────────

add_action( 'dtb_marketplace_retention', static function (): void {
	if ( ! class_exists( 'DTB_MarketplaceReadModels' ) ) {
		return;
	}
	global $wpdb;
	$msg_table = $wpdb->prefix . 'dtb_marketplace_messages';
	$retention_days = (int) apply_filters( 'dtb_marketplace_message_retention_days', 180 );
	$cutoff         = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare(
		"UPDATE {$msg_table} SET body_encrypted = NULL WHERE created_at < %s AND body_encrypted IS NOT NULL",
		$cutoff
	) );
} );

// ── Tracking Sync ─────────────────────────────────────────────────────────────

add_action( 'dtb_marketplace_tracking_sync', static function (): void {
	// Sync Veeqo-sourced tracking to eBay fulfillment records.
	if ( ! class_exists( 'DTB_EbayFulfillmentService' ) ) {
		return;
	}
	// Implementation: query marketplace_orders where tracking_state = 'none' and
	// veeqo_order_id is set, then call create_fulfillment with tracking details.
	// Specifics depend on Veeqo shipment data — left as hook for VeeqoShippingService to call.
	do_action( 'dtb_marketplace_tracking_sync_ebay' );
	do_action( 'dtb_marketplace_tracking_sync_amazon' );
} );

// ── Scheduled Registration ────────────────────────────────────────────────────

add_action( 'wp', 'dtb_marketplace_schedule_recurring_jobs' );

function dtb_marketplace_schedule_recurring_jobs(): void {
	$schedules = [
		[ 'hook' => 'dtb_amazon_order_sync',           'interval' => 'hourly' ],
		[ 'hook' => 'dtb_ebay_order_sync',             'interval' => 'hourly' ],
		[ 'hook' => 'dtb_ebay_message_sync',           'interval' => 'twicedaily' ],
		[ 'hook' => 'dtb_marketplace_health_refresh',  'interval' => 'twicedaily' ],
		[ 'hook' => 'dtb_marketplace_reconcile',       'interval' => 'daily' ],
		[ 'hook' => 'dtb_marketplace_exception_retry', 'interval' => 'hourly' ],
		[ 'hook' => 'dtb_marketplace_token_refresh',   'interval' => 'twicedaily' ],
		[ 'hook' => 'dtb_marketplace_retention',       'interval' => 'daily' ],
		[ 'hook' => 'dtb_marketplace_tracking_sync',   'interval' => 'hourly' ],
	];

	foreach ( $schedules as $s ) {
		if ( ! wp_next_scheduled( $s['hook'] ) ) {
			wp_schedule_event( time(), $s['interval'], $s['hook'] );
		}
	}
}
