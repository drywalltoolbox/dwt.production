<?php
/**
 * DTB Platform Bootstrap
 *
 * Loads all platform modules in dependency order.
 * This file is required by 00-dtb-loader.php.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

$_dtb_platform = __DIR__;

// 1. Configuration.
require_once $_dtb_platform . '/Config/Constants.php';
require_once $_dtb_platform . '/Config/Environment.php';
require_once $_dtb_platform . '/Config/FeatureFlags.php';
require_once $_dtb_platform . '/Config/RuntimeConfig.php';

// 2. Low-level support primitives.
require_once $_dtb_platform . '/Support/Http.php';
require_once $_dtb_platform . '/Support/Arr.php';
require_once $_dtb_platform . '/Support/Json.php';
require_once $_dtb_platform . '/Support/Sanitize.php';
require_once $_dtb_platform . '/Support/Url.php';
require_once $_dtb_platform . '/Support/Str.php';
require_once $_dtb_platform . '/Support/DateTime.php';
require_once $_dtb_platform . '/Support/Money.php';
require_once $_dtb_platform . '/Support/Email.php';
require_once $_dtb_platform . '/Support/Email/Deliverability.php';
require_once $_dtb_platform . '/Support/Email/OrderItemPresentation.php';

// 3. Security and request boundaries.
require_once $_dtb_platform . '/Security/OriginAllowlist.php';
require_once $_dtb_platform . '/Security/ApiSecurity.php';
require_once $_dtb_platform . '/Security/AdminRestTopology.php';
require_once $_dtb_platform . '/Security/WooAdminRestNonceCompatibility.php';
require_once $_dtb_platform . '/Security/FrontendSecurity.php';
require_once $_dtb_platform . '/Security/AdminSecurity.php';
require_once $_dtb_platform . '/Security/CorsPolicy.php';
require_once $_dtb_platform . '/Security/RateLimiter.php';
require_once $_dtb_platform . '/Security/StoreApiCheckoutRateLimiter.php';
require_once $_dtb_platform . '/Security/RequestFingerprint.php';
require_once $_dtb_platform . '/Security/LegacyCommerceRouteHardening.php';

// 4. Authentication and session policy.
require_once $_dtb_platform . '/Auth/JwtService.php';
require_once $_dtb_platform . '/Auth/SessionService.php';
require_once $_dtb_platform . '/Auth/CurrentUserResolver.php';
require_once $_dtb_platform . '/Auth/NativeCheckoutIdentityBridge.php';
require_once $_dtb_platform . '/Auth/CheckoutSessionContinuityGuard.php';
require_once $_dtb_platform . '/Auth/AuthCookieCandidates.php';
require_once $_dtb_platform . '/Auth/AuthRoutes.php';
require_once $_dtb_platform . '/Auth/AuthCookieCanonicalization.php';
require_once $_dtb_platform . '/Auth/AuthCookieRuntimeHardening.php';
require_once $_dtb_platform . '/Auth/AdminLoginIdentityBoundary.php';

// 5. Cache.
require_once $_dtb_platform . '/Cache/CacheKeyBuilder.php';
require_once $_dtb_platform . '/Cache/CacheService.php';
require_once $_dtb_platform . '/Cache/CacheHeaders.php';
require_once $_dtb_platform . '/Cache/CacheInvalidationService.php';
require_once $_dtb_platform . '/Cache/CachePurgeLock.php';
require_once $_dtb_platform . '/Cache/CacheOperationsService.php';
require_once $_dtb_platform . '/Cache/CacheAdminPage.php';

// 5b. Public search and discovery.
require_once $_dtb_platform . '/Seo/SitemapXmlRenderer.php';
require_once $_dtb_platform . '/Seo/SitemapUrlRepository.php';
require_once $_dtb_platform . '/Seo/SitemapService.php';

// 6. Health.
require_once $_dtb_platform . '/Health/HealthRegistry.php';
require_once $_dtb_platform . '/Health/DependencyHealthCheck.php';
require_once $_dtb_platform . '/Health/ApiHealthController.php';
require_once $_dtb_platform . '/Health/ApiHealthMonitor.php';

// 7. Observability.
require_once $_dtb_platform . '/Observability/FriendlyLogWriter.php';
require_once $_dtb_platform . '/Observability/Logger.php';
require_once $_dtb_platform . '/Observability/EventLogger.php';
require_once $_dtb_platform . '/Observability/Diagnostics.php';
require_once $_dtb_platform . '/Observability/AdminNoticeService.php';
require_once $_dtb_platform . '/Observability/Metrics.php';
require_once $_dtb_platform . '/Observability/OpsAuditLog.php';
require_once $_dtb_platform . '/Observability/OrderOperationsPermissionService.php';
require_once $_dtb_platform . '/Observability/OrderOperationsAssetManager.php';
require_once $_dtb_platform . '/Observability/OrderOperationsQueueInspector.php';
require_once $_dtb_platform . '/Observability/OrderOperationsDashboard.php';
require_once $_dtb_platform . '/Observability/OrderOperationsController.php';
require_once $_dtb_platform . '/Observability/OrderOperationsAuditService.php';
require_once $_dtb_platform . '/Observability/OpsDashboard.php';
require_once $_dtb_platform . '/Observability/OrderOperationsKpiService.php';

// 8. REST infrastructure and controllers.
require_once $_dtb_platform . '/Rest/AbstractRestController.php';
require_once $_dtb_platform . '/Rest/RestSchema.php';
require_once $_dtb_platform . '/Rest/RestResponseFactory.php';
require_once $_dtb_platform . '/Rest/OpsAuditController.php';
require_once $_dtb_platform . '/Rest/OpsOrderOverviewController.php';
require_once $_dtb_platform . '/Rest/OpsLocalQueueController.php';
require_once $_dtb_platform . '/Rest/OpsProductOrdersController.php';
require_once $_dtb_platform . '/Rest/OpsRepairOrdersController.php';
require_once $_dtb_platform . '/Rest/OpsSettingsController.php';
require_once $_dtb_platform . '/Rest/AccountController.php';
require_once $_dtb_platform . '/Rest/CustomerJobsController.php';
require_once $_dtb_platform . '/Rest/HistoryController.php';
require_once $_dtb_platform . '/Rest/ProxyRoutes.php';

// 8b. Shared admin-workbench services.
require_once $_dtb_platform . '/Services/AdminCustomerContextService.php';
require_once $_dtb_platform . '/Services/AdminLinkedRecordService.php';
require_once $_dtb_platform . '/Services/AdminWorkloadIntelligenceService.php';
require_once $_dtb_platform . '/Services/AdminActionAuditService.php';
require_once $_dtb_platform . '/Services/AdminWorkflowRegistry.php';
require_once $_dtb_platform . '/Services/AdminIntegrationStateService.php';
require_once $_dtb_platform . '/Services/AdminTimelineService.php';
require_once $_dtb_platform . '/Services/AdminWorkbenchContract.php';
require_once $_dtb_platform . '/Services/AdminExceptionQueueService.php';

// 9. Admin UI.
require_once $_dtb_platform . '/Admin/AdminCapabilities.php';
require_once $_dtb_platform . '/Admin/AdminPageRegistry.php';
require_once $_dtb_platform . '/Admin/AdminMenuRegistry.php';
require_once $_dtb_platform . '/Admin/AdminAssets.php';
require_once $_dtb_platform . '/Admin/AdminAssetPolicy.php';
require_once $_dtb_platform . '/Admin/AdminCacheToolbar.php';
require_once $_dtb_platform . '/Admin/AdminGlobalNavigation.php';
require_once $_dtb_platform . '/Admin/AdminShell.php';
require_once $_dtb_platform . '/Admin/AdminUi.php';
require_once $_dtb_platform . '/Admin/OperationsMenu.php';
require_once $_dtb_platform . '/Admin/CatalogOperationsPage.php';
require_once $_dtb_platform . '/Admin/ToolLibraryMenu.php';
require_once $_dtb_platform . '/Admin/SettingsPage.php';
require_once $_dtb_platform . '/Admin/CacheToolsPage.php';
require_once $_dtb_platform . '/Admin/SeoToolsPage.php';
require_once $_dtb_platform . '/Admin/ConfigReferencePage.php';
require_once $_dtb_platform . '/Admin/RecordCleanupPage.php';

// 10. Command Center.
require_once $_dtb_platform . '/CommandCenter/CommandCenterReadModel.php';
require_once $_dtb_platform . '/CommandCenter/CommandCenterService.php';
require_once $_dtb_platform . '/CommandCenter/CommandCenterPage.php';
require_once $_dtb_platform . '/CommandCenter/Rest/CommandCenterController.php';

// 11. System Manager.
require_once $_dtb_platform . '/SystemManager/SystemHealthService.php';
require_once $_dtb_platform . '/SystemManager/QueueHealthService.php';
require_once $_dtb_platform . '/SystemManager/CronHealthService.php';
require_once $_dtb_platform . '/SystemManager/IntegrationHealthService.php';
require_once $_dtb_platform . '/SystemManager/WebhookHealthService.php';
require_once $_dtb_platform . '/SystemManager/AuditLogService.php';
require_once $_dtb_platform . '/SystemManager/IntegrationSettingsTab.php';
require_once $_dtb_platform . '/SystemManager/SystemManagerPage.php';
require_once $_dtb_platform . '/SystemManager/Rest/SystemManagerController.php';
require_once $_dtb_platform . '/SystemManager/Rest/IntegrationSettingsController.php';

unset( $_dtb_platform );
