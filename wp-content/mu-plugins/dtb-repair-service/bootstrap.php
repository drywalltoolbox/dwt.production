<?php
/**
 * dtb-repair-service — bootstrap.
 *
 * Loads all module files in dependency order.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

// Domain
require_once __DIR__ . '/Domain/RepairEvent.php';
require_once __DIR__ . '/Domain/RepairStatus.php';
require_once __DIR__ . '/Domain/RepairTransition.php';

// Infrastructure — schema, repo, post-type, meta (no inter-deps)
require_once __DIR__ . '/Infrastructure/RepairSchemaInstaller.php';
require_once __DIR__ . '/Infrastructure/RepairEventRepository.php';
require_once __DIR__ . '/Infrastructure/RepairPostType.php';
require_once __DIR__ . '/Infrastructure/RepairMetaRepository.php';
require_once __DIR__ . '/Infrastructure/RepairMediaStorage.php';
require_once __DIR__ . '/Infrastructure/RepairStatusStore.php';
require_once __DIR__ . '/Infrastructure/RepairFrontendRouting.php';

// Services (TransitionMap first — WorkflowService depends on it)
require_once __DIR__ . '/Services/RepairWorkflowTransitionMap.php';
require_once __DIR__ . '/Services/RepairIdempotencyService.php';
require_once __DIR__ . '/Services/RepairProjectionService.php';
require_once __DIR__ . '/Services/RepairQuoteService.php';
require_once __DIR__ . '/Services/RepairShippingQuoteService.php';
require_once __DIR__ . '/Services/RepairPublicTokenService.php';
require_once __DIR__ . '/Services/RepairSlaService.php';
require_once __DIR__ . '/Services/RepairOpsQueryService.php';
require_once __DIR__ . '/Services/RepairOpsProjectionService.php';
require_once __DIR__ . '/Services/RepairWorkflowService.php';
require_once __DIR__ . '/Services/RepairSchematicResolver.php';

// Infrastructure — queue and notifications (depend on Services)
require_once __DIR__ . '/Infrastructure/RepairQueue.php';
require_once __DIR__ . '/Infrastructure/RepairNotificationDispatcher.php';

// Application
require_once __DIR__ . '/Application/SubmitRepairRequest.php';
require_once __DIR__ . '/Application/TransitionRepairStatus.php';
require_once __DIR__ . '/Application/BuildRepairStatusProjection.php';

// Validation
require_once __DIR__ . '/Validation/RepairSubmitValidator.php';
require_once __DIR__ . '/Validation/RepairMediaValidator.php';
require_once __DIR__ . '/Validation/RepairAccessValidator.php';

// Tracking
require_once __DIR__ . '/Tracking/RepairCustomerTimeline.php';
require_once __DIR__ . '/Tracking/RepairStatusProjector.php';
require_once __DIR__ . '/Tracking/RepairEventStream.php';
require_once __DIR__ . '/Tracking/RepairOperatorTimeline.php';

// REST
require_once __DIR__ . '/Rest/SubmitRepairController.php';
require_once __DIR__ . '/Rest/RepairShippingQuoteController.php';
require_once __DIR__ . '/Rest/RepairCustomerListController.php';
require_once __DIR__ . '/Rest/RepairStatusController.php';
require_once __DIR__ . '/Rest/RepairMediaController.php';
require_once __DIR__ . '/Rest/RepairCommentController.php';
require_once __DIR__ . '/Rest/RepairQuoteActionController.php';
require_once __DIR__ . '/Rest/RepairEventStreamController.php';
require_once __DIR__ . '/Rest/RepairHealthController.php';
require_once __DIR__ . '/Rest/RepairAdminQueueController.php';
require_once __DIR__ . '/Rest/RepairAdminDetailController.php';
require_once __DIR__ . '/Rest/RepairAdminActionController.php';
require_once __DIR__ . '/Rest/RepairAdminWorkbenchController.php';

// Admin query/filter helpers — loaded for both admin and REST API contexts
// so that REST endpoints can use dtb_repairs_query, dtb_repairs_normalize_status_filter, etc.
require_once __DIR__ . '/Admin/RepairsPage.php';

// Admin UI (only in admin context)
if ( is_admin() ) {
require_once __DIR__ . '/Admin/RepairAdminMenu.php';
require_once __DIR__ . '/Admin/RepairListTable.php';
require_once __DIR__ . '/Admin/RepairMetaBoxes.php';
require_once __DIR__ . '/Admin/RepairIntegrationPanel.php';
require_once __DIR__ . '/Admin/RepairBulkActions.php';
require_once __DIR__ . '/Admin/RepairTimelinePanel.php';
require_once __DIR__ . '/Admin/RepairDetailPage.php';
require_once __DIR__ . '/Admin/RepairDashboardPanel.php';
require_once __DIR__ . '/Admin/RepairQueuePanel.php';
require_once __DIR__ . '/Admin/RepairSlaPanel.php';
}
