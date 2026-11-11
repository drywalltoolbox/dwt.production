<?php
/**
 * Marketplace REST — MarketplaceOverviewController
 *
 * GET /wp-json/dtb/v1/admin/marketplace/overview
 *
 * Returns channel health, order counts, conversation counts, exception totals,
 * and compliance status for the Command Center overview panel.
 *
 * Capability required: dtb_view_marketplace
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_MarketplaceOverviewController' ) ) {
	final class DTB_MarketplaceOverviewController extends DTB_AbstractRestController {

		public static function register_routes(): void {
			register_rest_route( 'dtb/v1', '/admin/marketplace/overview', [
				'methods'             => 'GET',
				'callback'            => [ self::class, 'handle' ],
				'permission_callback' => [ self::class, 'check_permission' ],
			] );
		}

		public static function check_permission(): bool {
			return current_user_can( 'dtb_view_marketplace' ) && is_user_logged_in();
		}

		public static function handle( WP_REST_Request $request ): WP_REST_Response {
			$overview   = DTB_MarketplaceReadModels::overview();
			$channels   = DTB_MarketplaceReadModels::channels();
			$exc_count  = DTB_MarketplaceExceptionService::count_open();

			// Enrich channels with live health data.
			$channel_data = [];
			foreach ( $channels as $ch ) {
				$key = $ch['channel_key'];
				$channel_data[ $key ] = [
					'channel_key'      => $key,
					'account_label'    => $ch['account_label'],
					'marketplace_id'   => $ch['marketplace_id'],
					'is_enabled'       => (bool) $ch['is_enabled'],
					'auth_state'       => $ch['auth_state'],
					'health_state'     => $ch['health_state'],
					'last_sync_at'     => $ch['last_sync_at'],
					'last_error_msg'   => $ch['last_error_msg'],
					'is_sandbox'       => (bool) $ch['is_sandbox'],
					'orders'           => $overview['by_channel'][ $key ]['orders'] ?? [],
					'conversations'    => $overview['by_channel'][ $key ]['conversations'] ?? [],
				];
			}

			// Compliance: eBay deletion endpoint registered?
			$ebay_deletion_ok = ( '' !== DTB_EbayConfig::get()['deletion_verify_token'] );

			return new WP_REST_Response( [
				'channels'         => $channel_data,
				'total_exceptions' => $exc_count,
				'compliance'       => [
					'ebay_deletion_endpoint_configured' => $ebay_deletion_ok,
				],
				'generated_at'     => gmdate( 'c' ),
			], 200 );
		}
	}
}

add_action( 'rest_api_init', [ 'DTB_MarketplaceOverviewController', 'register_routes' ] );
