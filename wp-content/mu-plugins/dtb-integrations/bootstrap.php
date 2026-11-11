<?php
/**
 * DTB Integrations bootstrap.
 *
 * Composition root for external-system integrations.
 *
 * @package drywalltoolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DTB_LAUNCH_EXTERNAL_INTEGRATIONS_DISABLED' ) ) {
	define( 'DTB_LAUNCH_EXTERNAL_INTEGRATIONS_DISABLED', true );
}

if ( ! function_exists( 'dtb_integrations_require_files' ) ) {
	function dtb_integrations_require_files( array $relative_paths ): void {
		foreach ( $relative_paths as $path ) {
			dtb_module_require( $path );
		}
	}
}

if ( ! function_exists( 'dtb_integrations_register_health_checks' ) ) {
	function dtb_integrations_register_health_checks(): void {
		foreach ( [ 'DTB_WooCommerceHealthCheck', 'DTB_VeeqoHealthCheck', 'DTB_QuickBooksHealthCheck', 'DTB_AmazonHealthCheck', 'DTB_EbayHealthCheck' ] as $class ) {
			if ( class_exists( $class ) ) {
				$class::register();
			}
		}
	}
}

dtb_integrations_require_files( [
	'dtb-integrations/WooCommerce/WooCommerceBridge.php',
	'dtb-integrations/Veeqo/VeeqoClient.php',
	'dtb-integrations/QuickBooks/QuickBooksClient.php',
] );

dtb_integrations_require_files( [
	'dtb-integrations/WooCommerce/ProductLookupService.php',
	'dtb-integrations/WooCommerce/WooWebhookManager.php',
	'dtb-integrations/WooCommerce/ProductWebhookHandler.php',
	'dtb-integrations/WooCommerce/RepairOrderService.php',
	'dtb-integrations/WooCommerce/WooCommerceHealthCheck.php',
] );

dtb_integrations_require_files( [
	'dtb-integrations/Veeqo/VeeqoConfig.php',
	'dtb-integrations/Veeqo/VeeqoProductionConfiguration.php',
	'dtb-integrations/Veeqo/VeeqoInventoryService.php',
	'dtb-integrations/Veeqo/VeeqoInventoryProjectionServiceV3.php',
	'dtb-integrations/Veeqo/VeeqoCatalogWeightSyncService.php',
	'dtb-integrations/Veeqo/VeeqoRuntimePolicy.php',
	'dtb-integrations/Veeqo/Services/VeeqoOperationStore.php',
	'dtb-integrations/Veeqo/Services/VeeqoAdminReadModel.php',
	'dtb-integrations/Veeqo/Rest/VeeqoAdminController.php',
	'dtb-integrations/Veeqo/Rest/VeeqoCompatibilityController.php',
	'dtb-integrations/Veeqo/Services/VeeqoKitImportService.php',
	'dtb-integrations/Veeqo/Rest/VeeqoKitReadController.php',
	'dtb-integrations/Veeqo/Rest/VeeqoKitMcpAbilities.php',
	'dtb-integrations/Veeqo/Admin/VeeqoAdminPage.php',
	'dtb-integrations/Veeqo/VeeqoOrderProjectionContract.php',
	'dtb-integrations/Veeqo/VeeqoInventoryBoundary.php',
	'dtb-integrations/Veeqo/VeeqoShippingService.php',
	'dtb-integrations/Veeqo/VeeqoSyncJob.php',
	'dtb-integrations/Veeqo/VeeqoFulfillmentProjector.php',
	'dtb-integrations/Veeqo/VeeqoOrderStatusApplier.php',
	'dtb-integrations/Veeqo/VeeqoOrderStatusPoller.php',
	'dtb-integrations/Veeqo/VeeqoHealthCheck.php',
] );

dtb_integrations_require_files( [
	'dtb-integrations/QuickBooks/Accounting/AccountingMath.php',
	'dtb-integrations/QuickBooks/Accounting/AccountingLedger.php',
	'dtb-integrations/QuickBooks/Accounting/AccountingService.php',
	'dtb-integrations/QuickBooks/Accounting/AccountingOperations.php',
	'dtb-integrations/QuickBooks/QuickBooksConfig.php',
	'dtb-integrations/QuickBooks/QuickBooksCustomerMapper.php',
	'dtb-integrations/QuickBooks/QuickBooksInvoiceService.php',
	'dtb-integrations/QuickBooks/QuickBooksItemMappingService.php',
	'dtb-integrations/QuickBooks/QuickBooksOAuthController.php',
	'dtb-integrations/QuickBooks/QuickBooksAdminController.php',
	'dtb-integrations/QuickBooks/QuickBooksSyncAdminController.php',
	'dtb-integrations/QuickBooks/QuickBooksEnterpriseController.php',
	'dtb-integrations/QuickBooks/QuickBooksWebhookController.php',
	'dtb-integrations/QuickBooks/Admin/QuickBooksAdminPage.php',
	'dtb-integrations/QuickBooks/QuickBooksSyncJob.php',
	'dtb-integrations/QuickBooks/QuickBooksHealthCheck.php',
] );

dtb_integrations_require_files( [
	'dtb-integrations/OperationalPipeline/AtomicIntegrationLock.php',
	'dtb-integrations/OperationalPipeline/QuickBooksAccountingPipeline.php',
	'dtb-integrations/OperationalPipeline/OrderIntegrationContracts.php',
	'dtb-integrations/OperationalPipeline/OrderPipelineHookOverrides.php',
	'dtb-integrations/OperationalPipeline/QuickBooksJobOverride.php',
	'dtb-integrations/OperationalPipeline/VeeqoWebhookEchoGuard.php',
	'dtb-integrations/OperationalPipeline/VeeqoWebhookPipelineController.php',
	'dtb-integrations/OperationalPipeline/QuickBooksQueueController.php',
	'dtb-integrations/OperationalPipeline/PipelinePayloadPreview.php',
] );

dtb_integrations_require_files( [
	'dtb-integrations/Notifications/NotificationTemplateRepository.php',
	'dtb-integrations/Notifications/EmailTemplateRenderer.php',
	'dtb-integrations/Notifications/NotificationDispatcher.php',
	'dtb-integrations/Notifications/NotificationJob.php',
	'dtb-integrations/Notifications/SmsGateway.php',
] );

dtb_integrations_require_files( [
	'dtb-integrations/Marketplace/Schema/MarketplaceSchemaInstaller.php',
	'dtb-integrations/Marketplace/ChannelContract.php',
	'dtb-integrations/Marketplace/CredentialFacade.php',
	'dtb-integrations/Marketplace/RateLimitState.php',
	'dtb-integrations/Marketplace/OrderNormalizer.php',
	'dtb-integrations/Marketplace/MessageNormalizer.php',
	'dtb-integrations/Marketplace/ActionPolicyValidator.php',
	'dtb-integrations/Marketplace/ExceptionService.php',
	'dtb-integrations/Marketplace/EventService.php',
	'dtb-integrations/Marketplace/AuditService.php',
	'dtb-integrations/Marketplace/ReadModels.php',
	'dtb-integrations/Marketplace/OrderMaterializationService.php',
	'dtb-integrations/Amazon/AmazonConfig.php',
	'dtb-integrations/Amazon/AmazonLwaTokenService.php',
	'dtb-integrations/Amazon/AmazonSpApiClient.php',
	'dtb-integrations/Amazon/AmazonOrdersService.php',
	'dtb-integrations/Amazon/AmazonMessagingService.php',
	'dtb-integrations/Amazon/AmazonNotificationsService.php',
	'dtb-integrations/Amazon/AmazonHealthCheck.php',
	'dtb-integrations/Amazon/AmazonWebhookController.php',
	'dtb-integrations/Ebay/EbayConfig.php',
	'dtb-integrations/Ebay/EbayOAuthTokenService.php',
	'dtb-integrations/Ebay/EbayRestClient.php',
	'dtb-integrations/Ebay/EbayFulfillmentService.php',
	'dtb-integrations/Ebay/EbayMessageService.php',
	'dtb-integrations/Ebay/EbayDeletionController.php',
	'dtb-integrations/Ebay/EbayHealthCheck.php',
	'dtb-integrations/Marketplace/Jobs/MarketplaceQueueJobs.php',
	'dtb-integrations/Marketplace/Jobs/MarketplaceMaterializationQueue.php',
	'dtb-integrations/Marketplace/Rest/MarketplaceOverviewController.php',
	'dtb-integrations/Marketplace/Rest/MarketplaceOrdersController.php',
	'dtb-integrations/Marketplace/Rest/MarketplaceMessagesController.php',
	'dtb-integrations/Marketplace/Rest/AmazonMessagingController.php',
	'dtb-integrations/Marketplace/Rest/EbayInboxController.php',
	'dtb-integrations/Marketplace/Rest/MarketplaceExceptionsController.php',
	'dtb-integrations/Marketplace/Rest/MarketplaceSettingsController.php',
	'dtb-integrations/Marketplace/Admin/MarketplaceAdminHelpers.php',
	'dtb-integrations/Marketplace/Admin/MarketplaceOverviewPage.php',
	'dtb-integrations/Marketplace/Admin/MarketplaceOrdersPage.php',
	'dtb-integrations/Marketplace/Admin/MarketplaceMessagesPage.php',
	'dtb-integrations/Marketplace/Admin/AmazonCommunicationPage.php',
	'dtb-integrations/Marketplace/Admin/EbayInboxPage.php',
	'dtb-integrations/Marketplace/Admin/MarketplaceExceptionsPage.php',
	'dtb-integrations/Marketplace/Admin/MarketplaceSettingsPage.php',
] );

dtb_integrations_register_health_checks();
