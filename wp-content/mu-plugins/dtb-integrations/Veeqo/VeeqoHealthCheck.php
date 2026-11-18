<?php
/**
 * Veeqo integration health check.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_VeeqoHealthCheck' ) ) {
	return;
}

final class DTB_VeeqoHealthCheck {
	public static function register(): void {
		if ( class_exists( 'DTB_HealthRegistry' ) ) {
			DTB_HealthRegistry::register( 'veeqo', [ self::class, 'run' ] );
		}
	}

	/** @return array<string,mixed> */
	public static function run(): array {
		$config      = class_exists( 'DTB_VeeqoConfig' ) ? DTB_VeeqoConfig::redacted() : [];
		$order_ready = function_exists( 'dtb_veeqo_production_readiness' )
			? dtb_veeqo_production_readiness()
			: [ 'ready' => false, 'missing' => [ 'production_configuration_module' ] ];
		$inventory_ready = function_exists( 'dtb_veeqo_inventory_readiness' )
			? dtb_veeqo_inventory_readiness()
			: [ 'ready' => false, 'missing' => [ 'inventory_projection_module' ] ];
		$inventory_diagnostics = defined( 'DTB_VEEQO_INVENTORY_DIAGNOSTICS' )
			? (array) get_option( DTB_VEEQO_INVENTORY_DIAGNOSTICS, [] )
			: [];
		$last_inventory_sync = class_exists( 'DTB_VeeqoSyncJob' ) ? DTB_VeeqoSyncJob::last_timestamp( 'inventory' ) : 0;
		$inventory_initialized = $last_inventory_sync > 0;
		$inventory_stale = ! $inventory_initialized || ( time() - $last_inventory_sync ) > 2 * HOUR_IN_SECONDS;

		return [
			'ok'                       => ! empty( $order_ready['ready'] ) && ! empty( $inventory_ready['ready'] ) && ! $inventory_stale,
			'configured'               => $config,
			'production_readiness'     => $order_ready,
			'inventory_readiness'      => $inventory_ready,
			'inventory_initialized'    => $inventory_initialized,
			'inventory_last_sync_at'   => $last_inventory_sync,
			'inventory_stale'          => $inventory_stale,
			'inventory_last_run'       => [
				'completed_at'       => sanitize_text_field( (string) ( $inventory_diagnostics['completed_at'] ?? '' ) ),
				'updated'            => absint( $inventory_diagnostics['updated'] ?? 0 ),
				'unmapped_count'     => count( (array) ( $inventory_diagnostics['unmapped_skus'] ?? [] ) ),
				'duplicate_count'    => count( (array) ( $inventory_diagnostics['duplicate_skus'] ?? [] ) ),
				'missing_stock_count'=> count( (array) ( $inventory_diagnostics['missing_warehouse_entries'] ?? [] ) ),
			],
			'request_function'          => function_exists( 'dtb_veeqo_request' ),
			'route_registration'        => function_exists( 'dtb_veeqo_register_routes' ),
			'webhook_controller'        => function_exists( 'dtb_operational_pipeline_veeqo_webhook_order' ),
			'webhooks_enabled'          => function_exists( 'dtb_veeqo_verified_webhooks_enabled' ) && dtb_veeqo_verified_webhooks_enabled(),
			'shipping_rate_handler'     => function_exists( 'dtb_veeqo_route_shipping_rates' ),
			'inventory_projection'      => function_exists( 'dtb_veeqo_inventory_reconcile_all' ),
			'cart_availability_handler' => function_exists( 'dtb_veeqo_route_cart_availability' ),
		];
	}
}

add_action( 'plugins_loaded', [ 'DTB_VeeqoHealthCheck', 'register' ], 20 );

function dtb_integrations_veeqo_healthcheck(): array {
	return DTB_VeeqoHealthCheck::run();
}
