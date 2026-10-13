<?php
/**
 * DTB Catalog Platform — Bootstrap
 *
 * Loads catalog-platform module files in dependency order. Runtime hook wiring
 * lives in Application/* files so this bootstrap remains a composition root.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'DTB_CATALOG_PLATFORM_ENABLED' ) && ! DTB_CATALOG_PLATFORM_ENABLED ) {
	return;
}

if (
	! dtb_is_admin_or_rest_request()
	&& ! ( defined( 'WP_CLI' ) && WP_CLI )
	&& ! ( defined( 'DOING_CRON' ) && DOING_CRON )
) {
	return;
}

$_dtb_cp = __DIR__;

// Domain.
require_once $_dtb_cp . '/Domain/ProductMeta.php';
require_once $_dtb_cp . '/Domain/Brand.php';
require_once $_dtb_cp . '/Domain/ToolFamilies.php';
require_once $_dtb_cp . '/Domain/CatalogProduct.php';
require_once $_dtb_cp . '/Domain/ProductVariation.php';
require_once $_dtb_cp . '/Domain/ToolsetData.php';
require_once $_dtb_cp . '/Domain/CatalogHealthIssue.php';

// Services and infrastructure.
require_once $_dtb_cp . '/Services/BrandNormalizer.php';
require_once $_dtb_cp . '/Services/CategoryNormalizer.php';
require_once $_dtb_cp . '/Services/ToolFamilyResolver.php';
require_once $_dtb_cp . '/Services/CatalogProductNormalizer.php';
require_once $_dtb_cp . '/Infrastructure/CatalogCache.php';
require_once $_dtb_cp . '/Services/CatalogNavigationService.php';
require_once $_dtb_cp . '/Infrastructure/CatalogTaxonomyMigration.php';
require_once $_dtb_cp . '/Infrastructure/CatalogProductRepository.php';
require_once $_dtb_cp . '/Infrastructure/CatalogHealthRepository.php';
require_once $_dtb_cp . '/Infrastructure/WooProductRepository.php';
require_once $_dtb_cp . '/Infrastructure/WordPressProductMetaStore.php';
require_once $_dtb_cp . '/Infrastructure/ProductVariationRepository.php';
require_once $_dtb_cp . '/Infrastructure/ProductRelationshipRepository.php';
require_once $_dtb_cp . '/Infrastructure/InventoryIntelligenceSchema.php';
require_once $_dtb_cp . '/Infrastructure/InventoryStockRepository.php';
require_once $_dtb_cp . '/Infrastructure/InventoryRollupRepository.php';
require_once $_dtb_cp . '/Services/VariationReadModelService.php';
require_once $_dtb_cp . '/Services/DefaultVariationResolver.php';
require_once $_dtb_cp . '/Services/CatalogFacetService.php';
require_once $_dtb_cp . '/Services/ProductLookupService.php';
require_once $_dtb_cp . '/Services/CatalogHealthService.php';
require_once $_dtb_cp . '/Services/ToolsetEligibilityService.php';
require_once $_dtb_cp . '/Services/ToolsetValidationService.php';
require_once $_dtb_cp . '/Services/ProductMappingService.php';
require_once $_dtb_cp . '/Services/CompatiblePartsService.php';
require_once $_dtb_cp . '/Services/ProductRelationshipService.php';
require_once $_dtb_cp . '/Services/UniversalPartsProjectionService.php';
require_once $_dtb_cp . '/Services/VeeqoStockSyncService.php';
require_once $_dtb_cp . '/Services/InventoryRollupService.php';
require_once $_dtb_cp . '/Services/InventoryIntelligenceService.php';
require_once $_dtb_cp . '/Services/PricingPolicy.php';
require_once $_dtb_cp . '/Services/PricingManagerService.php';
require_once $_dtb_cp . '/Admin/PricingBulkOptimizer.php';

// REST controllers.
require_once $_dtb_cp . '/Rest/CatalogFacetsController.php';
require_once $_dtb_cp . '/Rest/CatalogProductsController.php';
require_once $_dtb_cp . '/Rest/ProductDetailController.php';
require_once $_dtb_cp . '/Rest/CompatiblePartsController.php';
require_once $_dtb_cp . '/Rest/ToolsetTemplatesController.php';
require_once $_dtb_cp . '/Rest/ToolsetOptionsController.php';
require_once $_dtb_cp . '/Rest/ToolsetValidationController.php';
require_once $_dtb_cp . '/Rest/InventoryIntelligenceController.php';
require_once $_dtb_cp . '/Rest/PricingManagerController.php';

// Validation.
require_once $_dtb_cp . '/Validation/CatalogValidationService.php';
require_once $_dtb_cp . '/Validation/ProductMetaValidator.php';
require_once $_dtb_cp . '/Validation/VariationValidator.php';
require_once $_dtb_cp . '/Validation/ToolsetEligibilityValidator.php';
require_once $_dtb_cp . '/Validation/PricingValidator.php';
require_once $_dtb_cp . '/Validation/ImageValidator.php';
require_once $_dtb_cp . '/Validation/SeoValidator.php';
require_once $_dtb_cp . '/Validation/ProductMappingValidator.php';

// Application hook wiring and use cases.
require_once $_dtb_cp . '/Application/RegisterCategoryHeroImageSize.php';
require_once $_dtb_cp . '/Application/RegisterCatalogMeta.php';
require_once $_dtb_cp . '/Application/RegisterCatalogRoutes.php';
require_once $_dtb_cp . '/Application/RegisterCatalogHooks.php';
require_once $_dtb_cp . '/Application/CatalogContentCsv.php';
require_once $_dtb_cp . '/Application/NormalizeCatalogProduct.php';
require_once $_dtb_cp . '/Application/ResolveDefaultVariation.php';
require_once $_dtb_cp . '/Application/ValidateCatalogProduct.php';
require_once $_dtb_cp . '/Application/BuildCatalogFacets.php';
require_once $_dtb_cp . '/Application/RunCatalogHealthScan.php';
require_once $_dtb_cp . '/Application/BackfillProductMeta.php';
require_once $_dtb_cp . '/Application/RunProductMappingMutation.php';
require_once $_dtb_cp . '/Application/ResolveCompatibleParts.php';
require_once $_dtb_cp . '/Application/RunInventoryIntelligenceJobs.php';

// Rest.
require_once $_dtb_cp . '/Rest/ProductPlaceholderImageFilter.php';

// Admin / CLI tools.
if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	require_once $_dtb_cp . '/Admin/ProductListTable.php';
	require_once $_dtb_cp . '/Admin/CatalogToolsPage.php';
	require_once $_dtb_cp . '/Admin/MetaBackfillTool.php';
	require_once $_dtb_cp . '/Admin/CatalogHealthRenderer.php';
	require_once $_dtb_cp . '/Admin/CatalogHealthActions.php';
	// Page-shell file is only needed for normal wp-admin page loads.
	// Exclude admin-ajax requests to avoid loading UI-only code there.
	if ( ! ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
		require_once $_dtb_cp . '/Admin/CatalogHealthPage.php';
		require_once $_dtb_cp . '/Admin/PricingManagerPage.php';
	}
	require_once $_dtb_cp . '/Admin/ProductMappingRenderer.php';
	require_once $_dtb_cp . '/Admin/ProductMappingPage.php';
	require_once $_dtb_cp . '/Admin/ProductMappingActions.php';
	require_once $_dtb_cp . '/Admin/PartsManagerPage.php';
	require_once $_dtb_cp . '/Admin/PartsManagerActions.php';
	require_once $_dtb_cp . '/Admin/InventoryIntelligencePage.php';
	require_once $_dtb_cp . '/Admin/InventoryIntelligenceActions.php';
	require_once $_dtb_cp . '/Admin/ImportExportPage.php';
	require_once $_dtb_cp . '/Admin/CategoryHeroImageField.php';
}

unset( $_dtb_cp );
