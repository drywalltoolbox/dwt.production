# SiteGround staging build and publication

## Authority and scope

The `main` branch of `drywalltoolbox/dwt.production` currently contains the imported React frontend. `.github/workflows/staging-frontend-build.yml` builds its triggering commit using `frontend/package-lock.json`, Node 22, and `npm run build:staging`. The output is repository-root `dist-staging/` mounted at `/staging/`.

The GitHub artifact includes `staging-dist.tar.gz`, `staging-artifact-sha256.txt`, and `staging-source-sha.txt`. Build run **38022157842** completed successfully; artifact **11658768062**, source **8998ace04c6a5caa2cf7f0414f084050bbf60949**, was confirmed non-expired at inspection.

## Staging-only publish and rollback (implemented, not yet exercised)

The manually dispatched `.github/workflows/staging-frontend-publish.yml` uses `actions/download-artifact` to retrieve a selected successful build run and matches its artifact to an exact source SHA. Before transferring, it validates archive member types, boundaries and size, staging-required files, source identity, and all recorded SHA-256 hashes.

It uses pinned SSH host identification and a staging-specific SSH private key. It copies the validated payload into a private incoming release directory **outside `public_html`**, then calls `scripts/deployment/staging-release.sh`. On the server, the script enforces that the target is exactly the immediate `public_html/staging` directory; prohibits private release roots under `public_html`; checks incoming hashes; acquires an exclusive directory lock; snapshots the previous staging tree privately; retains old assets; and replaces the mutable manifest and HTML shell last. On an error during publication, a best-effort restoration is attempted. Rollback requires that the requested release is currently published and restores its private snapshot under the same lock. A follow-up HTTP check asserts an HTML response and `X-Robots-Tag: noindex`.

**Important:** This is a first implementation, not a proven end-to-end release. Do not assume SiteGround's SSH account supports `rsync`, `sha256sum`, `install`, and `bash` until tested. Snapshot creation requires sufficient private disk space. Existing `staging/.htaccess` and other mutable files must be audited before first publication. If the first publication fails and the original staging HTML lacked required files, manual restoration may be necessary.

## Required GitHub staging environment

Create a protected GitHub Actions environment named `staging` and provide the following **environment secrets** (never commit values):

| Secret | Description |
| --- | --- |
| `SITEGROUND_STAGING_SSH_HOST` | SSH host |
| `SITEGROUND_STAGING_SSH_PORT` | SSH port (optional; defaults to 22) |
| `SITEGROUND_STAGING_SSH_USER` | Staging-scoped SSH user |
| `SITEGROUND_STAGING_SSH_PRIVATE_KEY` | SSH private key |
| `SITEGROUND_STAGING_SSH_KNOWN_HOSTS` | Prevalidated `known_hosts` entry or entries |
| `SITEGROUND_STAGING_PUBLIC_DIR` | Absolute path to existing `public_html/staging` |
| `SITEGROUND_STAGING_RELEASE_DIR` | Absolute path to an **existing private writable directory outside `public_html`** |

For first publish, run the manual workflow with `action=publish`, `source_sha` equal to the selected successful build's source SHA, and `build_run_id` equal to that GitHub Actions run ID. For rollback, run with `action=rollback` and `source_sha` equal to the **currently deployed** release SHA. Rollback does not require `build_run_id`.

The private directory contains incoming payloads, snapshots, release state and a lock. A failed or interrupted release can leave a lock that must be inspected and removed manually; do **not** automatically override it. If a failure happens after publication but before HTTP verification, the manual rollback workflow can restore the previous snapshot.

## Boundaries and outstanding verification

- No frontend publication to production root, `public_html/index.html`, WordPress `/wp`, or commerce persistence.
- The existing WordPress SiteGround Git release engine is intentionally unrelated.
- No production publisher or homepage switching workflow is included.
- Publisher has **not** been executed against SiteGround: the staging SSH secrets, capabilities, and disk layout have not yet been verified.
- SiteGround-side runtime still needs checking, especially the first-release rollback behavior, HTTP routing, cache headers, and absence of stale assets. The HTML/noindex smoke test is necessary but not sufficient.
- Do not promote a release to production based on this staging workflow alone.
