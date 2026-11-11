<?php
/**
 * Amazon — AmazonHealthCheck
 *
 * Health-registry adapter for Amazon SP-API connectivity.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_AmazonHealthCheck' ) ) {
	final class DTB_AmazonHealthCheck {

		/**
		 * Register with the DTB health registry.
		 */
		public static function register(): void {
			if ( ! function_exists( 'dtb_health_register' ) ) {
				return;
			}
			dtb_health_register( 'amazon_sp_api', [ self::class, 'run' ] );
		}

		/**
		 * Execute the health check.
		 *
		 * @return array{status: string, label: string, detail: string, last_checked_at: string}
		 */
		public static function run(): array {
			$label   = __( 'Amazon SP-API', 'drywall-toolbox' );
			$checked = gmdate( 'c' );

			if ( ! DTB_AmazonConfig::is_configured() ) {
				return [
					'status'          => 'unconfigured',
					'label'           => $label,
					'detail'          => __( 'Amazon credentials not configured.', 'drywall-toolbox' ),
					'last_checked_at' => $checked,
				];
			}

			// Try to get a token without network call first.
			$token = DTB_AmazonLwaTokenService::get_access_token();
			if ( '' === $token ) {
				return [
					'status'          => 'error',
					'label'           => $label,
					'detail'          => __( 'LWA token refresh failed. Check credentials.', 'drywall-toolbox' ),
					'last_checked_at' => $checked,
				];
			}

			// Light API ping — list orders with 1 result and a recent date.
			$cfg      = DTB_AmazonConfig::get();
			$response = DTB_AmazonSpApiClient::request( 'GET', '/orders/v0/orders', [
				'MarketplaceIds' => $cfg['marketplace_id'],
				'CreatedAfter'   => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '-1 hour' ) ),
				'MaxResultsPerPage' => 1,
			] );

			if ( $response['ok'] ) {
				DTB_MarketplaceReadModels::upsert_channel( DTB_CHANNEL_AMAZON, [
					'health_state'   => 'ok',
					'auth_state'     => 'connected',
					'last_sync_at'   => current_time( 'mysql', true ),
					'last_error_msg' => '',
				] );
				return [
					'status'          => 'ok',
					'label'           => $label,
					'detail'          => __( 'Amazon SP-API reachable and authenticated.', 'drywall-toolbox' ),
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
