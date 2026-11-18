<?php
/**
 * DTB Admin — ToolLibraryMenu
 *
 * Registers all DTB Tool Library pages.
 * Pages are registered via dtb_register_admin_page() — the AdminMenuRegistry
 * consumes this at admin_menu time.
 *
 * Library: 'tools'
 * Visible menus:
 *   DTB Tool Library
 *     ├─ Catalog Operations
 *     ├─ Schematics
 *     └─ Visual Designer (registered by its owning module)
 *
 * Individual catalog and platform tools remain registered as hidden,
 * capability-protected routes. Catalog Operations and System Manager surface
 * those routes without changing their domain ownership or endpoint contracts.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'dtb_tool_library_menu_register_pages', 5 );

function dtb_tool_library_menu_register_pages(): void {
	// Unified catalog operations landing page. Individual domain pages remain
	// registered as hidden, capability-protected routes so their assets,
	// endpoints, bookmarks, and operational contracts remain stable.
	dtb_register_admin_page( [
		'library'    => 'tools',
		'slug'       => 'dtb-catalog-operations',
		'title'      => __( 'Catalog Operations', 'drywall-toolbox' ),
		'menu_title' => __( 'Catalog Operations', 'drywall-toolbox' ),
		'capability' => 'dtb_view_catalog_operations',
		'callback'   => 'dtb_catalog_operations_render_page',
		'position'   => 5,
		'template'   => 'dashboard',
		'section'    => 'Catalog Operations',
		'assets'     => [
			'css' => [
				[ 'id' => 'dtb-catalog-operations', 'dir' => __DIR__ . '/assets/', 'url' => plugin_dir_url( __FILE__ ) . 'assets/', 'file' => 'dtb-catalog-operations.css' ],
			],
		],
	] );

	// Schematics.
	dtb_register_admin_page( [
		'library'    => 'tools',
		'slug'       => 'dtb-schematics',
		'title'      => __( 'Schematics', 'drywall-toolbox' ),
		'menu_title' => __( 'Schematics', 'drywall-toolbox' ),
		'capability' => 'dtb_manage_schematics',
		'callback'   => 'dtb_schematics_workspace_render_page',
		'position'   => 10,
		'template'   => 'tool',
		'section'    => 'Catalog Maintenance',
		'menu_visible' => true,
	] );

	// Image Sync.
	dtb_register_admin_page( [
		'library'    => 'tools',
		'slug'       => 'dtb-image-sync',
		'title'      => __( 'Image Sync', 'drywall-toolbox' ),
		'menu_title' => __( 'Image Sync', 'drywall-toolbox' ),
		'capability' => 'dtb_manage_image_sync',
		'callback'   => 'dtb_image_sync_render_page',
		'position'   => 20,
		'template'   => 'tool',
		'section'    => 'Catalog Maintenance',
		'menu_visible' => false,
	] );

	// Product Mapping.
	dtb_register_admin_page( [
		'library'    => 'tools',
		'slug'       => 'dtb-product-mapping',
		'title'      => __( 'Product Mapping', 'drywall-toolbox' ),
		'menu_title' => __( 'Product Mapping', 'drywall-toolbox' ),
		'capability' => 'dtb_manage_product_mapping',
		'callback'   => 'dtb_product_mapping_render_page',
		'position'   => 30,
		'template'   => 'tool',
		'section'    => 'Catalog Maintenance',
		'menu_visible' => false,
	] );

	// Catalog Health.
	dtb_register_admin_page( [
		'library'    => 'tools',
		'slug'       => 'dtb-catalog-health',
		'title'      => __( 'Catalog Health', 'drywall-toolbox' ),
		'menu_title' => __( 'Catalog Health', 'drywall-toolbox' ),
		'capability' => 'dtb_manage_catalog_health',
		'callback'   => 'dtb_catalog_health_render_page',
		'position'   => 40,
		'template'   => 'tool',
		'section'    => 'Catalog Maintenance',
		'menu_visible' => false,
	] );

	// Parts Manager.
	dtb_register_admin_page( [
		'library'    => 'tools',
		'slug'       => 'dtb-parts-manager',
		'title'      => __( 'Parts Manager', 'drywall-toolbox' ),
		'menu_title' => __( 'Parts Manager', 'drywall-toolbox' ),
		'capability' => 'dtb_manage_parts',
		'callback'   => 'dtb_parts_manager_render_page',
		'position'   => 45,
		'template'   => 'tool',
		'section'    => 'Catalog Maintenance',
		'menu_visible' => false,
	] );

	// Inventory Intelligence.
	dtb_register_admin_page( [
		'library'    => 'tools',
		'slug'       => 'dtb-inventory-intelligence',
		'title'      => __( 'Inventory Intelligence', 'drywall-toolbox' ),
		'menu_title' => __( 'Inventory Intelligence', 'drywall-toolbox' ),
		'capability' => 'dtb_manage_inventory_intelligence',
		'callback'   => 'dtb_inventory_intelligence_render_page',
		'position'   => 47,
		'template'   => 'tool',
		'section'    => 'Catalog Maintenance',
		'menu_visible' => false,
	] );

	// Catalog Pricing.
	dtb_register_admin_page( [
		'library'    => 'tools',
		'slug'       => 'dtb-pricing-manager',
		'title'      => __( 'Catalog Pricing', 'drywall-toolbox' ),
		'menu_title' => __( 'Catalog Pricing', 'drywall-toolbox' ),
		'capability' => 'dtb_manage_catalog_pricing',
		'callback'   => 'dtb_pricing_manager_render_page',
		'position'   => 48,
		'template'   => 'tool',
		'section'    => 'Catalog Maintenance',
		'menu_visible' => false,
		'assets'     => [
			'css' => [
				[
					'id'   => 'dtb-pricing-manager',
					'dir'  => WP_CONTENT_DIR . '/mu-plugins/dtb-catalog-platform/Admin/assets/',
					'url'  => content_url( '/mu-plugins/dtb-catalog-platform/Admin/assets/' ),
					'file' => 'dtb-pricing-manager.css',
				],
			],
			'js' => [
				[
					'id'   => 'dtb-pricing-manager',
					'dir'  => WP_CONTENT_DIR . '/mu-plugins/dtb-catalog-platform/Admin/assets/',
					'url'  => content_url( '/mu-plugins/dtb-catalog-platform/Admin/assets/' ),
					'file' => 'dtb-pricing-manager.js',
				],
			],
		],
	] );

	// Cache Tools.
	dtb_register_admin_page( [
		'library'    => 'tools',
		'slug'       => 'dtb-cache-tools',
		'title'      => __( 'Cache Tools', 'drywall-toolbox' ),
		'menu_title' => __( 'Cache Tools', 'drywall-toolbox' ),
		'capability' => 'dtb_manage_cache_tools',
		'callback'   => 'dtb_cache_tools_render_page',
		'position'   => 50,
		'template'   => 'tool',
		'section'    => 'Platform',
		'menu_visible' => false,
	] );

	// API Health.
	dtb_register_admin_page( [
		'library'    => 'tools',
		'slug'       => 'dtb-api-health',
		'title'      => __( 'API Health', 'drywall-toolbox' ),
		'menu_title' => __( 'API Health', 'drywall-toolbox' ),
		'capability' => 'dtb_view_api_health',
		'callback'   => 'dtb_api_health_render_page',
		'position'   => 55,
		'template'   => 'tool',
		'section'    => 'Platform',
		'menu_visible' => false,
	] );

	// SEO Tools.
	dtb_register_admin_page( [
		'library'    => 'tools',
		'slug'       => 'dtb-seo-tools',
		'title'      => __( 'SEO Tools', 'drywall-toolbox' ),
		'menu_title' => __( 'SEO Tools', 'drywall-toolbox' ),
		'capability' => 'dtb_manage_seo_tools',
		'callback'   => 'dtb_seo_tools_render_page',
		'position'   => 60,
		'template'   => 'tool',
		'section'    => 'Catalog Maintenance',
		'menu_visible' => false,
	] );

	// Import / Export.
	dtb_register_admin_page( [
		'library'    => 'tools',
		'slug'       => 'dtb-import-export',
		'title'      => __( 'Import / Export', 'drywall-toolbox' ),
		'menu_title' => __( 'Import / Export', 'drywall-toolbox' ),
		'capability' => 'dtb_manage_import_export',
		'callback'   => 'dtb_import_export_render_page',
		'position'   => 65,
		'template'   => 'tool',
		'section'    => 'Data',
		'menu_visible' => false,
	] );

	// Config Reference.
	dtb_register_admin_page( [
		'library'    => 'tools',
		'slug'       => 'dtb-config-reference',
		'title'      => __( 'Config Reference', 'drywall-toolbox' ),
		'menu_title' => __( 'Config Reference', 'drywall-toolbox' ),
		'capability' => 'dtb_view_config_reference',
		'callback'   => 'dtb_config_reference_render_page',
		'position'   => 70,
		'template'   => 'tool',
		'section'    => 'Data',
		'menu_visible' => false,
	] );

	dtb_register_admin_page( [
		'library'    => 'tools',
		'slug'       => 'dtb-record-cleanup',
		'title'      => __( 'Record Cleanup', 'drywall-toolbox' ),
		'menu_title' => __( 'Record Cleanup', 'drywall-toolbox' ),
		'capability' => 'dtb_manage_system',
		'callback'   => 'dtb_record_cleanup_render_page',
		'position'   => 75,
		'template'   => 'tool',
		'section'    => 'Data',
		'menu_visible' => false,
		'assets'     => [
			'css' => [
				[
					'id'   => 'dtb-record-cleanup',
					'dir'  => __DIR__ . '/assets/',
					'url'  => plugin_dir_url( __FILE__ ) . 'assets/',
					'file' => 'dtb-record-cleanup.css',
				],
			],
			'js' => [
				[
					'id'   => 'dtb-record-cleanup',
					'dir'  => __DIR__ . '/assets/',
					'url'  => plugin_dir_url( __FILE__ ) . 'assets/',
					'file' => 'dtb-record-cleanup.js',
				],
			],
		],
	] );
}
