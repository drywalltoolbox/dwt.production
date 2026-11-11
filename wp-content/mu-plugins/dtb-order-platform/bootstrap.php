<?php
/**
 * DTB Order Platform bootstrap.
 *
 * Loads all module-layer files in dependency order. No Legacy/ references.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

$_dtb_order = __DIR__;

require_once $_dtb_order . '/Domain/OrderEvent.php';
require_once $_dtb_order . '/Domain/OrderLifecycleStatus.php';
require_once $_dtb_order . '/Domain/OrderTransition.php';
require_once $_dtb_order . '/Email/OrderEmailIdempotency.php';
require_once $_dtb_order . '/Infrastructure/OrderSchemaInstaller.php';
require_once $_dtb_order . '/Infrastructure/OrderEventRepository.php';
require_once $_dtb_order . '/Infrastructure/WooOrderStatusStore.php';
require_once $_dtb_order . '/Infrastructure/OrderIntegrationStateStore.php';
require_once $_dtb_order . '/Infrastructure/OrderQueue.php';
require_once $_dtb_order . '/Infrastructure/OrderWriteBoundary.php';
require_once $_dtb_order . '/Services/OrderTrackingUrlService.php';
require_once $_dtb_order . '/Services/OrderTypeService.php';
require_once $_dtb_order . '/Services/OrderProjectionService.php';
require_once $_dtb_order . '/Services/OrderWorkflowService.php';
require_once $_dtb_order . '/Application/TransitionOrderStatus.php';
require_once $_dtb_order . '/Application/BuildOrderTrackingProjection.php';
require_once $_dtb_order . '/Application/RefreshOrderProjection.php';
require_once $_dtb_order . '/Application/UpdateOrderTracking.php';
require_once $_dtb_order . '/Validation/OrderAccessValidator.php';
require_once $_dtb_order . '/Tracking/OrderCustomerTimeline.php';
require_once $_dtb_order . '/Tracking/OrderStatusProjector.php';
require_once $_dtb_order . '/Tracking/OrderOperatorTimeline.php';
require_once $_dtb_order . '/Payment/CheckoutPaymentLifecycle.php';
require_once $_dtb_order . '/Payment/RefundLifecycle.php';
require_once $_dtb_order . '/Rest/OrderListController.php';
require_once $_dtb_order . '/Rest/OrderDetailController.php';
require_once $_dtb_order . '/Rest/OrderTrackingController.php';
require_once $_dtb_order . '/Rest/OrderEventStreamController.php';
require_once $_dtb_order . '/Rest/OrderHealthController.php';
require_once $_dtb_order . '/Rest/CheckoutConfirmationIntegrity.php';
require_once $_dtb_order . '/Admin/OrderAdminColumns.php';
require_once $_dtb_order . '/Admin/OrderAdminMenu.php';
require_once $_dtb_order . '/Admin/OrderTimelinePanel.php';
require_once $_dtb_order . '/Admin/OrderQueuePanel.php';
require_once $_dtb_order . '/Admin/OrderDetailPage.php';
require_once $_dtb_order . '/Admin/OrderDetailExperience.php';
require_once $_dtb_order . '/Admin/OrderBulkActions.php';
require_once $_dtb_order . '/Admin/OrderDashboardPanel.php';
require_once $_dtb_order . '/Admin/ProductOrderDashboardPanel.php';
require_once $_dtb_order . '/Admin/ProductOrderBulkActions.php';
