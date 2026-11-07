<?php
/**
 * WooCommerce integration health check.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_WooCommerceHealthCheck' ) ) {
	return;
}

final class DTB_WooCommerceHealthCheck {
	/** Register with the platform health registry. */
	public static function register(): void {
		if ( class_exists( 'DTB_HealthRegistry' ) ) {
			DTB_HealthRegistry::register( 'woocommerce', [ self::class, 'run' ] );
		}
	}

	/**
	 * Return a passive WooCommerce health snapshot.
	 *
	 * @return array<string,mixed>
	 */
	public static function run( bool $include_mutating_checks = false ): array {
		$loaded = class_exists( 'WooCommerce' ) || function_exists( 'WC' );
		$status = [
			'ok'                 => $loaded,
			'woocommerce_loaded' => $loaded,
			'wc_version'         => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'rest_url_rewrite'   => function_exists( 'dtb_wc_admin_rest_url' ),
			'webhook_manager'    => function_exists( 'dtb_wc_ensure_webhooks' ),
			'webhooks'           => [
				'status' => 'not_checked',
				'reason' => 'passive_snapshot',
			],
		];

		if ( $include_mutating_checks && function_exists( 'dtb_wc_ensure_webhooks' ) ) {
			$status['webhooks'] = dtb_wc_ensure_webhooks();
		}

		return $status;
	}
}

add_action( 'plugins_loaded', [ 'DTB_WooCommerceHealthCheck', 'register' ], 20 );

/**
 * Backward-compatible functional accessor for integration health snapshots.
 *
 * @return array<string,mixed>
 */
function dtb_integrations_woo_health( bool $include_mutating_checks = false ): array {
	return DTB_WooCommerceHealthCheck::run( $include_mutating_checks );
}
