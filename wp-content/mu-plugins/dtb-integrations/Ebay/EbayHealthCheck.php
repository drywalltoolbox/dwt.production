<?php
/**
 * eBay — EbayHealthCheck
 *
 * Health-registry adapter for eBay API connectivity.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_EbayHealthCheck' ) ) {
	final class DTB_EbayHealthCheck {

		public static function register(): void {
			if ( ! function_exists( 'dtb_health_register' ) ) {
				return;
			}
			dtb_health_register( 'ebay_api', [ self::class, 'run' ] );
		}

		/**
		 * Execute the health check.
		 *
		 * @return array{status: string, label: string, detail: string, last_checked_at: string}
		 */
		public static function run(): array {
			$label   = __( 'eBay API', 'drywall-toolbox' );
			$checked = gmdate( 'c' );

			if ( ! DTB_EbayConfig::is_configured() ) {
				return [
					'status'          => 'unconfigured',
					'label'           => $label,
					'detail'          => __( 'eBay credentials not configured.', 'drywall-toolbox' ),
					'last_checked_at' => $checked,
				];
			}

			$token = DTB_EbayOAuthTokenService::get_access_token();
			if ( '' === $token ) {
				return [
					'status'          => 'error',
					'label'           => $label,
					'detail'          => __( 'eBay OAuth token refresh failed. Check credentials.', 'drywall-toolbox' ),
					'last_checked_at' => $checked,
				];
			}

			// Light ping — list recent orders (limit 1).
			$response = DTB_EbayRestClient::request( 'GET', '/sell/fulfillment/v1/order', [
				'filter' => 'creationdate:[' . gmdate( 'Y-m-d\TH:i:s.000\Z', strtotime( '-1 hour' ) ) . '..]',
				'limit'  => 1,
			] );

			if ( $response['ok'] ) {
				DTB_MarketplaceReadModels::upsert_channel( DTB_CHANNEL_EBAY, [
					'health_state'   => 'ok',
					'auth_state'     => 'connected',
					'last_sync_at'   => current_time( 'mysql', true ),
					'last_error_msg' => '',
				] );
				return [
					'status'          => 'ok',
					'label'           => $label,
					'detail'          => __( 'eBay API reachable and authenticated.', 'drywall-toolbox' ),
					'last_checked_at' => $checked,
				];
			}

			return [
				'status'          => $response['rate_limited'] ? 'degraded' : 'error',
				'label'           => $label,
				'detail'          => $response['error'],
				'last_checked_at' => $checked,
			];
		}
	}
}
