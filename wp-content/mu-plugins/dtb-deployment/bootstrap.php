<?php
/**
 * DTB Deployment Bootstrap
 *
 * Release Management for the production WordPress application tree.
 * GitHub remains the implementation source of truth; the official
 * SiteGround Git repository (production /wp) remains the sanctioned
 * deployment backend. This module owns:
 *
 *   - the release event log (wp_dtb_release_events) and its derived
 *     history/current-production/drift projections;
 *   - the signed webhook that records lifecycle events reported by
 *     .github/workflows/release-siteground.yml;
 *   - the GitHub API bridge used to dispatch deploy/rollback, detect
 *     repository drift, and browse branches/commits/pull requests/workflow
 *     runs/releases/tags read-only;
 *   - runtime deployment integrity guards for tracked WordPress application
 *     dependencies such as the canonical DTB theme;
 *   - the Deployment/Repository/Pull Requests/Workflow Runs/Releases & Tags/
 *     Release History/Rollback/Deploy Settings tab content surfaced inside
 *     the unified System Manager console (Drywall Toolbox > System Manager),
 *     owned by dtb-platform/SystemManager/SystemManagerPage.php. See
 *     Admin/GitControlCenterTabs.php.
 *
 * This module never holds SiteGround Git/SSH credentials and never writes
 * to SiteGround directly — all production file changes happen inside the
 * GitHub Actions workflow, which is the only place those secrets live.
 *
 * Loaded by: 00-dtb-loader.php (after dtb-integrations).
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

$_dtb_deployment = __DIR__;

// 1. Domain.
require_once $_dtb_deployment . '/Domain/ReleaseStatus.php';

// 2. Infrastructure.
require_once $_dtb_deployment . '/Infrastructure/ThemeRuntimeGuard.php';
require_once $_dtb_deployment . '/Infrastructure/ReleaseSchemaInstaller.php';
require_once $_dtb_deployment . '/Infrastructure/ReleaseEventRepository.php';

// 3. Services.
require_once $_dtb_deployment . '/Services/ProtectedPathPolicy.php';
require_once $_dtb_deployment . '/Services/DeploymentLockService.php';
require_once $_dtb_deployment . '/Services/GitHubReleaseClient.php';
require_once $_dtb_deployment . '/Services/GitHubRepositoryClient.php';
require_once $_dtb_deployment . '/Services/ReleaseHistoryService.php';

// 4. Validation.
require_once $_dtb_deployment . '/Validation/DeploymentWebhookValidator.php';
require_once $_dtb_deployment . '/Validation/DeploymentActionValidator.php';

// 5. Rest.
require_once $_dtb_deployment . '/Rest/DeploymentWebhookController.php';
require_once $_dtb_deployment . '/Rest/DeploymentAdminController.php';
require_once $_dtb_deployment . '/Rest/GitControlCenterController.php';

// 6. Admin. Tab renderers only — surfaced by dtb-platform's SystemManagerPage.php.
require_once $_dtb_deployment . '/Admin/GitControlCenterTabs.php';

unset( $_dtb_deployment );
