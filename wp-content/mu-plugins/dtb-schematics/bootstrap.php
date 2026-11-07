<?php
/**
 * DTB Schematics bootstrap.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

// Data.
dtb_module_require( 'dtb-schematics/Data/SkuSchematicMap.php' );

// Domain.
dtb_module_require( 'dtb-schematics/Domain/Schematic.php' );
dtb_module_require( 'dtb-schematics/Domain/SchematicAsset.php' );
dtb_module_require( 'dtb-schematics/Domain/SchematicBrand.php' );
dtb_module_require( 'dtb-schematics/Domain/SchematicPart.php' );
dtb_module_require( 'dtb-schematics/Domain/SchematicLifecycleStatus.php' );
dtb_module_require( 'dtb-schematics/Domain/SchematicPageDefinition.php' );
dtb_module_require( 'dtb-schematics/Domain/SchematicPreviewPolicy.php' );
dtb_module_require( 'dtb-schematics/Domain/SchematicPartRelationship.php' );
dtb_module_require( 'dtb-schematics/Domain/SchematicRecordEntity.php' );
dtb_module_require( 'dtb-schematics/Domain/SchematicPublicationRules.php' );
dtb_module_require( 'dtb-schematics/Domain/SchematicAssetDisposition.php' );
dtb_module_require( 'dtb-schematics/Domain/SchematicHotspotDataset.php' );

// Infrastructure.
dtb_module_require( 'dtb-schematics/Infrastructure/WordPressMediaStore.php' );
dtb_module_require( 'dtb-schematics/Infrastructure/SchematicPostType.php' );
dtb_module_require( 'dtb-schematics/Infrastructure/SchematicRecordRepository.php' );
dtb_module_require( 'dtb-schematics/Infrastructure/SchematicAttachmentRepository.php' );
dtb_module_require( 'dtb-schematics/Infrastructure/SchematicSourceManifestReader.php' );
dtb_module_require( 'dtb-schematics/Infrastructure/SchematicReconciliationStateStore.php' );
dtb_module_require( 'dtb-schematics/Infrastructure/SchematicHotspotDatasetReader.php' );
dtb_module_require( 'dtb-schematics/Infrastructure/SchematicHotspotDatasetRepository.php' );
dtb_module_require( 'dtb-schematics/Infrastructure/SchematicActivitySchemaInstaller.php' );
dtb_module_require( 'dtb-schematics/Infrastructure/SchematicOperationRunStore.php' );

// Validation and services.
dtb_module_require( 'dtb-schematics/Validation/SchematicBrandValidator.php' );
dtb_module_require( 'dtb-schematics/Services/SchematicFallbackResolver.php' );
dtb_module_require( 'dtb-schematics/Services/SchematicPartResolver.php' );
dtb_module_require( 'dtb-schematics/Services/SchematicAttachmentProcessor.php' );

// Application.
dtb_module_require( 'dtb-schematics/Application/RegisterSchematicUploads.php' );
dtb_module_require( 'dtb-schematics/Application/ResolveSchematicParts.php' );
dtb_module_require( 'dtb-schematics/Application/ManageSchematicRecord.php' );
dtb_module_require( 'dtb-schematics/Application/EnrichSchematicMetadata.php' );
dtb_module_require( 'dtb-schematics/Application/EvaluateSchematicReadiness.php' );
dtb_module_require( 'dtb-schematics/Application/GenerateSchematicResponse.php' );
dtb_module_require( 'dtb-schematics/Application/ReconcileSchematicSource.php' );
dtb_module_require( 'dtb-schematics/Application/ReconcileSchematicSourceCli.php' );
dtb_module_require( 'dtb-schematics/Application/ResolveSchematicPartOccurrences.php' );
dtb_module_require( 'dtb-schematics/Application/DiagnoseSchematicHotspots.php' );
dtb_module_require( 'dtb-schematics/Application/MigrateSchematicHotspotDatasets.php' );
dtb_module_require( 'dtb-schematics/Application/AuditSchematicHotspotSources.php' );
dtb_module_require( 'dtb-schematics/Application/MigrateSchematicHotspotDatasetsCli.php' );
dtb_module_require( 'dtb-schematics/Application/OptimizeSchematicHotspots.php' );
dtb_module_require( 'dtb-schematics/Application/BuildHotspotResolutionPlan.php' );
dtb_module_require( 'dtb-schematics/Application/ExportHotspotResolutionWorkbook.php' );
dtb_module_require( 'dtb-schematics/Application/RecordSchematicActivity.php' );
dtb_module_require( 'dtb-schematics/Application/ConvergeSchematicProjection.php' );
dtb_module_require( 'dtb-schematics/Application/RunSchematicOperation.php' );

// REST.
dtb_module_require( 'dtb-schematics/Rest/SchematicPublicApiController.php' );

// Admin control center. Rendering stays transport-only; every mutation
// delegates to the application services loaded above.
if ( dtb_is_admin_or_ajax_request() ) {
	dtb_module_require( 'dtb-schematics/Admin/SchematicAdminMenu.php' );
	dtb_module_require( 'dtb-schematics/Admin/Workspace/Workspace.php' );
	dtb_module_require( 'dtb-schematics/Admin/Diagnostics/HotspotResolver.php' );
	dtb_module_require( 'dtb-schematics/Admin/Diagnostics/HotspotSourceAuditPanel.php' );
	dtb_module_require( 'dtb-schematics/Admin/Diagnostics/HotspotOptimizerPanel.php' );
	dtb_module_require( 'dtb-schematics/Admin/Diagnostics/HotspotWorkflow.php' );
	dtb_module_require( 'dtb-schematics/Admin/Diagnostics/HotspotResolutionPipeline.php' );
}
