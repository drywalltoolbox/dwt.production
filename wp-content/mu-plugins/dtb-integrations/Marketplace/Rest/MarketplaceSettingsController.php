<?php
/**
 * Marketplace REST — MarketplaceSettingsController
 *
 * Routes:
 *   GET  /dtb/v1/admin/marketplace/settings            — read current settings (no secrets)
 *   POST /dtb/v1/admin/marketplace/settings            — save settings
 *   POST /dtb/v1/admin/marketplace/settings/test       — test connection for a channel
 *   GET  /dtb/v1/admin/marketplace/settings/audit      — recent audit entries
 *
 * Capability: dtb_manage_marketplace_settings
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_MarketplaceSettingsController' ) ) {
	final class DTB_MarketplaceSettingsController extends DTB_AbstractRestController {

		public static function register_routes(): void {
			register_rest_route( 'dtb/v1', '/admin/marketplace/settings', [
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'get_settings' ],
					'permission_callback' => [ self::class, 'check_permission' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ self::class, 'save_settings' ],
					'permission_callback' => [ self::class, 'check_permission' ],
				],
			] );
			register_rest_route( 'dtb/v1', '/admin/marketplace/settings/test', [
				'methods'             => 'POST',
				'callback'            => [ self::class, 'test_connection' ],
				'permission_callback' => [ self::class, 'check_permission' ],
			] );
			register_rest_route( 'dtb/v1', '/admin/marketplace/settings/audit', [
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_audit' ],
				'permission_callback' => [ self::class, 'check_permission' ],
			] );
		}

		public static function check_permission(): bool {
			return current_user_can( 'dtb_manage_marketplace_settings' ) && is_user_logged_in();
		}

		/**
		 * Return settings status — credential presence only, never actual values.
		 */
		public static function get_settings( WP_REST_Request $request ): WP_REST_Response {
			$amazon_fields = [ 'client_id', 'client_secret', 'refresh_token', 'marketplace_id', 'seller_id', 'notification_endpoint' ];
			$ebay_fields   = [ 'client_id', 'client_secret', 'redirect_uri', 'marketplace_id', 'deletion_verify_token' ];

			$channels = DTB_MarketplaceReadModels::channels();

			return new WP_REST_Response( [
				'amazon' => [
					'is_configured'    => DTB_AmazonConfig::is_configured(),
					'is_sandbox'       => DTB_AmazonConfig::get()['sandbox'],
					'marketplace_id'   => DTB_AmazonConfig::get()['marketplace_id'],
					'notification_endpoint' => DTB_AmazonConfig::get()['notification_endpoint'],
					'credential_status' => DTB_MarketplaceCredentialFacade::status( DTB_CHANNEL_AMAZON, $amazon_fields ),
				],
				'ebay' => [
					'is_configured'    => DTB_EbayConfig::is_configured(),
					'is_sandbox'       => DTB_EbayConfig::get()['sandbox'],
					'marketplace_id'   => DTB_EbayConfig::get()['marketplace_id'],
					'credential_status' => DTB_MarketplaceCredentialFacade::status( DTB_CHANNEL_EBAY, $ebay_fields ),
				],
				'channels' => $channels,
			], 200 );
		}

		/**
		 * Save channel settings (non-secret fields + encrypted credentials when provided).
		 */
		public static function save_settings( WP_REST_Request $request ): WP_REST_Response {
			$channel = sanitize_key( $request->get_param( 'channel' ) ?? '' );
			if ( '' === $channel ) {
				return new WP_REST_Response( [ 'ok' => false, 'error' => 'channel is required.' ], 400 );
			}

			// Collect credential fields submitted — only store non-empty values.
			$cred_fields = [
				DTB_CHANNEL_AMAZON => [ 'client_id', 'client_secret', 'refresh_token', 'marketplace_id', 'seller_id', 'notification_endpoint' ],
				DTB_CHANNEL_EBAY   => [ 'client_id', 'client_secret', 'redirect_uri', 'marketplace_id', 'deletion_verify_token' ],
			];

			$fields  = $cred_fields[ $channel ] ?? [];
			$to_save = [];
			foreach ( $fields as $f ) {
				$val = $request->get_param( $f );
				if ( null !== $val && '' !== (string) $val ) {
					$to_save[ $f ] = sanitize_text_field( (string) $val );
				}
			}

			// Also store sandbox/enable flags in channel row.
			$channel_updates = [];
			if ( null !== $request->get_param( 'is_sandbox' ) ) {
				$channel_updates['is_sandbox'] = (int) (bool) $request->get_param( 'is_sandbox' );
			}
			if ( null !== $request->get_param( 'is_enabled' ) ) {
				$channel_updates['is_enabled'] = (int) (bool) $request->get_param( 'is_enabled' );
			}
			if ( null !== $request->get_param( 'account_label' ) ) {
				$channel_updates['account_label'] = sanitize_text_field( $request->get_param( 'account_label' ) );
			}
			if ( null !== $request->get_param( 'veeqo_channel_id' ) ) {
				$channel_updates['veeqo_channel_id'] = sanitize_text_field( $request->get_param( 'veeqo_channel_id' ) );
			}

			if ( ! empty( $to_save ) ) {
				DTB_MarketplaceCredentialFacade::store( $channel, $to_save );

				// Flush config cache.
				if ( DTB_CHANNEL_AMAZON === $channel ) {
					DTB_AmazonConfig::flush_cache();
				} elseif ( DTB_CHANNEL_EBAY === $channel ) {
					DTB_EbayConfig::flush_cache();
				}
			}

			if ( ! empty( $channel_updates ) ) {
				DTB_MarketplaceReadModels::upsert_channel( $channel, $channel_updates );
			}

			DTB_MarketplaceAuditService::write( 'settings.saved', 'marketplace_channel', 0, $channel, [
				'after' => [ 'fields_saved' => array_keys( $to_save ) ],
			] );

			return new WP_REST_Response( [ 'ok' => true ], 200 );
		}

		/**
		 * Test connection for a channel.
		 */
		public static function test_connection( WP_REST_Request $request ): WP_REST_Response {
			$channel = sanitize_key( $request->get_param( 'channel' ) ?? '' );
			$result  = match ( $channel ) {
				DTB_CHANNEL_AMAZON => class_exists( 'DTB_AmazonHealthCheck' ) ? DTB_AmazonHealthCheck::run() : [ 'status' => 'error', 'detail' => 'Amazon module not loaded.' ],
				DTB_CHANNEL_EBAY   => class_exists( 'DTB_EbayHealthCheck' )   ? DTB_EbayHealthCheck::run()   : [ 'status' => 'error', 'detail' => 'eBay module not loaded.' ],
				default            => [ 'status' => 'error', 'detail' => 'Unknown channel.' ],
			};
			return new WP_REST_Response( $result, 200 );
		}

		/**
		 * Return recent marketplace audit entries.
		 */
		public static function get_audit( WP_REST_Request $request ): WP_REST_Response {
			global $wpdb;
			$table   = $wpdb->prefix . 'dtb_marketplace_audit';
			$channel = sanitize_key( $request->get_param( 'channel' ) ?? '' );
			$limit   = min( 200, max( 1, (int) ( $request->get_param( 'limit' ) ?? 50 ) ) );

			if ( '' !== $channel ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT id, actor_id, actor_type, action, object_type, object_id, channel_key, after_json, created_at
					 FROM {$table} WHERE channel_key = %s ORDER BY created_at DESC LIMIT %d",
					$channel, $limit
				), ARRAY_A );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT id, actor_id, actor_type, action, object_type, object_id, channel_key, after_json, created_at
					 FROM {$table} ORDER BY created_at DESC LIMIT %d",
					$limit
				), ARRAY_A );
			}

			return new WP_REST_Response( [ 'items' => $rows ?? [] ], 200 );
		}
	}
}

add_action( 'rest_api_init', [ 'DTB_MarketplaceSettingsController', 'register_routes' ] );
