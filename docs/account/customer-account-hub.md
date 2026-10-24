# Customer Account Hub and My Jobs — implementation contract

## Authority and composition
- `frontend/src/components/dashboard/AccountHub.jsx` is the canonical `/dashboard` account workspace.
- `frontend/src/components/account/AccountHubSheet.jsx` is the header's compact account drawer. It links to full dashboard destinations; it is not a second account page.
- Both surfaces consume `frontend/src/api/accountWorkspace.js`, which normalizes the existing authenticated `/wp-json/dtb/v1/account/history` read model. Orders, repairs, returns and support remain owned by their existing domains. Details and mutations use domain-specific endpoints on their respective tabs.
- My Jobs uses `frontend/src/api/jobs.js`, exclusively through the existing same-origin `apiClient`. Jobs are personal notes, **not** WooCommerce products/orders, job scheduling, repair states or project management records.

## My Jobs backend
- Owner: `dtb-platform/Rest/CustomerJobsController.php` loaded by `dtb-platform/bootstrap.php`.
- Internal, non-public WordPress post types: `dtb_customer_job` and `dtb_customer_note`.
- A job has: `name`, optional `location`, archive boolean and timestamps.
- A note belongs to a job: multiline plain text and timestamps; no uploads.
- All endpoints require existing `dtb_jwt_permission`; each read/write resolves the authenticated account and enforces ownership on both job and note IDs. Writes use origin enforcement and the existing rate limiter.
- `GET|POST /wp-json/dtb/v1/account/jobs`; `GET|PATCH|DELETE /wp-json/dtb/v1/account/jobs/{id}`; `GET|POST /wp-json/dtb/v1/account/jobs/{id}/notes`; `PATCH|DELETE /wp-json/dtb/v1/account/jobs/{id}/notes/{note_id}`.
- List pagination max 50 jobs per page; notes 30 per page. Server-side job search includes name/location. No global/public account cache.

## Responsive and interaction contract
- Reuse existing storefront navigation, blue accent, light cards and NavbarTabs.
- Account tab navigation is URL-driven using `?tab=` (Overview, My Jobs, Orders, Repairs, Returns, Support, Settings); `?tab=addresses` remains a Settings alias.
- Account activity panels distinguish loading, empty and API failure. Errors are not misreported as zero records.
- Jobs support creating, editing, archiving/restoring and deleting jobs; notes support creating, editing and deleting. Destructive deletes require explicit confirmation.
- Layout is intrinsic with fluid widths, scrolling tab strip, keyboard focus and reduced-motion handling. No duplicated desktop/mobile React trees or complex project workflow engine.

## Validation and rollout
1. PR pipeline: PHP 8.2 syntax checks, scoped ESLint and staging build.
2. Check REST route availability and 401 for anonymous clients after backend deployment, then use an authorized non-production test customer to verify create/list/update/archive/retrieve/delete and cross-user denial. Do not create test jobs under live customer accounts.
3. Verify logged-in staging account UI at 320, 375, 768, 1024 and 1440 px, 200% zoom, keyboard traversal, long addresses, empty/error/loading and note CRUD.
4. Coordinate backend code deployment and frontend staging publish. GitHub merge alone is not proof of SiteGround deployment. Do not publish an account UI that calls absent backend endpoints.
