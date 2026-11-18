<?php
/**
 * dtb-visual-designer — bootstrap.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/Domain/PropertyTypes.php';
require_once __DIR__ . '/Domain/ConfigSchema.php';
require_once __DIR__ . '/Domain/SurfaceRegistry.php';
require_once __DIR__ . '/Domain/TokenRegistry.php';
require_once __DIR__ . '/Infrastructure/DesignSchemaInstaller.php';
require_once __DIR__ . '/Infrastructure/DraftRepository.php';
require_once __DIR__ . '/Infrastructure/RevisionRepository.php';
require_once __DIR__ . '/Infrastructure/PublishedPointerRepository.php';
require_once __DIR__ . '/Infrastructure/PreviewSessionRepository.php';
require_once __DIR__ . '/Application/AuditEvents.php';
require_once __DIR__ . '/Application/ConfigResolver.php';
require_once __DIR__ . '/Application/DraftService.php';
require_once __DIR__ . '/Application/PublishService.php';
require_once __DIR__ . '/Application/RollbackService.php';
require_once __DIR__ . '/Application/PreviewAuthService.php';
require_once __DIR__ . '/Application/ImportExportService.php';
require_once __DIR__ . '/Registrations/TokenDefinitions.php';
require_once __DIR__ . '/Registrations/SurfaceDefinitions.php';
require_once __DIR__ . '/Rest/RestHelpers.php';
require_once __DIR__ . '/Rest/RegistryController.php';
require_once __DIR__ . '/Rest/PublishedConfigController.php';
require_once __DIR__ . '/Rest/DraftController.php';
require_once __DIR__ . '/Rest/PreviewController.php';
require_once __DIR__ . '/Rest/RevisionController.php';
require_once __DIR__ . '/Rest/ImportExportController.php';
require_once __DIR__ . '/Rest/EmailStudioController.php';

if ( is_admin() ) {
	require_once __DIR__ . '/Admin/DesignerPage.php';
}
