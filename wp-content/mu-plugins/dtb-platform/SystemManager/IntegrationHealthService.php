<?php
/**
 * DTB Platform — IntegrationHealthService
 *
 * Tests reachability of configured third-party integrations and verifies that
 * DTB MU-plugin modules are loaded using current module contracts.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return true when any function/class/constant/file probe resolves.
 *
 * @param array<string,string[]> $probes Availability probes keyed by type.
 * @return bool
 */
function dtb_integration_health_any_probe_available( array $probes ): bool {
	foreach ( $probes['functions'] ?? [] as $function_name ) {
		if ( function_exists( $function_name ) ) {
			return true;
		}
	}

	foreach ( $probes['classes'] ?? [] as $class_name ) {
		if ( class_exists( $class_name ) ) {
			return true;
		}
	}

	foreach ( $probes['constants'] ?? [] as $constant_name ) {
		if ( defined( $constant_name ) ) {
			return true;
		}
	}

	foreach ( $probes['files'] ?? [] as $relative_file ) {
		$relative_file = ltrim( (string) $relative_file, '/' );
		if ( defined( 'WPMU_PLUGIN_DIR' ) && file_exists( trailingslashit( WPMU_PLUGIN_DIR ) . $relative_file ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Return the DTB integration/module health summary.
 *
 * @return array{integrations: array<int,array{name:string,ok:bool,version:string}>}
 */
function dtb_integration_health_get(): array {
	$cache_key = 'dtb_integration_health_v2';
	$transient = get_transient( $cache_key );
	if ( is_array( $transient ) ) {
		return $transient;
	}

	// Drop the legacy cache key so stale false negatives do not survive deploys.
	delete_transient( 'dtb_integration_health' );

	$integrations = [
		[
			'name'    => 'WooCommerce',
			'ok'      => class_exists( 'WooCommerce' ),
			'version' => defined( 'WC_VERSION' ) ? WC_VERSION : 'n/a',
		],
		[
			'name'    => 'WooPayments',
			'ok'      => class_exists( 'WC_Payments' ),
			'version' => defined( 'WCPAY_VERSION_NUMBER' ) ? WCPAY_VERSION_NUMBER : 'n/a',
		],
		[
			'name'    => 'ShipStation',
			'ok'      => function_exists( 'wc_shipstation' ) || defined( 'WC_SHIPSTATION_VERSION' ),
			'version' => defined( 'WC_SHIPSTATION_VERSION' ) ? WC_SHIPSTATION_VERSION : 'n/a',
		],
	];

	$modules = [
		'dtb-platform' => [
			'functions' => [
				'dtb_admin_shell_open',
				'dtb_register_admin_page',
				'dtb_system_health_get',
			],
			'files' => [ 'dtb-platform/bootstrap.php' ],
		],
		'dtb-catalog' => [
			'functions' => [
				'dtb_catalog_platform_register_routes',
				'dtb_catalog_lookup_product_detail_by_id',
				'dtb_catalog_lookup_product_detail_by_slug',
				'dtb_catalog_lookup_products_by_ids',
			],
			'classes' => [
				'DTB_CatalogProductsController',
				'DTB_ProductDetailController',
				'DTB_CatalogProductNormalizer',
			],
			'files' => [ 'dtb-catalog-platform/bootstrap.php' ],
		],
		'dtb-commerce' => [
			'functions' => [
				'dtb_orders_render_page',
				'dtb_order_rest_register_routes',
				'dtb_order_rest_list_orders',
			],
			'classes' => [
				'DTB_OrderRestController',
			],
			'files' => [
				'dtb-commerce/bootstrap.php',
				'dtb-order-platform/bootstrap.php',
			],
		],
		'dtb-repair-service' => [
			'functions' => [
				'dtb_repairs_count_by_status',
				'dtb_repairs_render_page',
				'dtb_get_allowed_repair_transitions',
			],
			'files' => [ 'dtb-repair-service/bootstrap.php' ],
		],
		'dtb-returns' => [
			'functions' => [
				'dtb_returns_count_by_status',
				'dtb_returns_render_page',
				'dtb_return_get_allowed_transitions',
			],
			'files' => [ 'dtb-returns/bootstrap.php' ],
		],
		'dtb-support' => [
			'functions' => [
				'dtb_support_count_by_status',
				'dtb_support_render_page',
				'dtb_support_query_tickets',
			],
			'files' => [ 'dtb-support/bootstrap.php' ],
		],
		'dtb-schematics' => [
			'functions' => [
				'dtb_register_schematics_public_api_routes',
				'dtb_schematic_record_repo_query',
				'dtb_schematics_resolve_product_ids_for_schematic',
				'dtb_schematics_workspace_render_page',
				'dtb_schematic_run_operation',
			],
			'files' => [ 'dtb-schematics/bootstrap.php' ],
		],
		'dtb-media' => [
			'functions' => [
				'dtb_image_sync_register_routes',
				'dtb_route_sync_images',
				'dtb_route_sync_images_status',
				'dtb_build_image_sync_snapshot',
				'dtb_route_link_registered_images',
				'dtb_register_image_attachment',
				'dtb_link_images_to_product',
			],
			'files' => [ 'dtb-media/bootstrap.php' ],
		],
	];

	foreach ( $modules as $label => $probes ) {
		$integrations[] = [
			'name'    => $label,
			'ok'      => dtb_integration_health_any_probe_available( $probes ),
			'version' => 'mu-plugin',
		];
	}

	$data = [ 'integrations' => $integrations ];

	set_transient( $cache_key, $data, 5 * MINUTE_IN_SECONDS );

	return $data;
}
