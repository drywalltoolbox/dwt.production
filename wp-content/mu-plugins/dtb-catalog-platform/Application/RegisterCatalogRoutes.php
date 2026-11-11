<?php
/**
 * Catalog REST route registration.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_catalog_platform_register_routes', 15 );

/**
 * Register catalog-platform REST routes.
 */
function dtb_catalog_platform_register_routes(): void {
	DTB_CatalogFacetsController::register_routes();
	DTB_CatalogProductsController::register_routes();
	DTB_ProductDetailController::register_routes();
	DTB_CompatiblePartsController::register_routes();
	DTB_ToolsetTemplatesController::register_routes();
	DTB_ToolsetOptionsController::register_routes();
	DTB_ToolsetValidationController::register_routes();
	DTB_InventoryIntelligenceController::register_routes();
}
