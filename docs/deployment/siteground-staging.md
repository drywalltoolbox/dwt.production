# SiteGround staging deployment

GitHub Actions deploys the React storefront to `/staging/` only. Production deployment is out of scope.

## Operator workflows

- `.github/workflows/staging-frontend-publish.yml` runs on pushes to `main` that change files outside `docs/operations/scheduled-workflows/**`, and can be manually dispatched from `main`. Scheduled reports must be committed under that dedicated directory so report-only commits do not publish staging. If a commit also changes any other file, the push still triggers. Its build job builds and validates the triggering commit once, packages a short-lived artifact, then the publish job downloads that exact artifact and deploys it.
- `.github/workflows/staging-frontend-rollback.yml` restores the snapshot from the current release under the same server-side lock.
- `.github/workflows/staging-frontend-build.yml` validates pull requests that change frontend files. Its artifact is not used for deployment because the release must be built from the exact commit being published.

## Release safety

The release identity is derived from the source SHA, workflow run ID, and run attempt. Before publication, the deploy job checks that identity and the build's SHA-256 manifest, validates the artifact paths, and rechecks the payload after extraction. SiteGround verifies the incoming hashes, locks the publish directory, snapshots the current staging tree, and swaps the manifest and HTML shell last. After a successful publish, it removes older release snapshots and the just-published incoming upload, retaining only the snapshot for the latest publish. This provides one server-side rollback; the rollback consumes that snapshot. GitHub remains the long-term source/build history, while old versions must be rebuilt or republished from GitHub. Cleanup failures are warnings and do not invalidate an otherwise successful publish.

The public shell and asset probes retry transient connection/TLS failures up to three times. HTTP errors, incorrect content types, missing HTML, or a missing `X-Robots-Tag: noindex` header still fail the workflow. A SiteGround CAPTCHA challenge on the asset probe is reported as unverified; it does not invalidate the independent artifact and server-side hash checks.

## GitHub staging environment

Configure the protected environment named `staging` with these secrets:

| Secret | Use |
| --- | --- |
| `SITEGROUND_STAGING_SSH_HOST` | SiteGround SSH host |
| `SITEGROUND_STAGING_SSH_PORT` | SSH port; defaults to 22 |
| `SITEGROUND_STAGING_SSH_USER` | Staging-scoped SSH user |
| `SITEGROUND_STAGING_SSH_PRIVATE_KEY` | SSH private key |
| `SITEGROUND_STAGING_PUBLIC_DIR` | Absolute path to `public_html/staging` |
| `SITEGROUND_STAGING_RELEASE_DIR` | Existing private writable path outside `public_html` |

The publish and rollback workflows use OpenSSH with `StrictHostKeyChecking=no` and `UserKnownHostsFile=/dev/null`, matching the previously working staging deployment workflow. This accepts the presented host key on each connection and does not pin or persist it. Keep the SSH private key in the protected `staging` environment; do not put credentials in workflow inputs or source control.

## Server-side behavior and limits

`scripts/deployment/staging-release.sh` requires the target to be the `staging` directory immediately under `public_html`; the private release directory must remain outside the webroot. It validates checksums before mutation, acquires an exclusive lock, snapshots the prior tree, and attempts restoration after a partial publish failure. After successful publication it keeps only that publish's snapshot and deletes the incoming upload; rollback restores and then removes the consumed snapshot. A stale lock is never cleared automatically; inspect it before manual recovery.

`rsync --delete` makes the staging directory a build-owned mirror. Do not keep persistent uploads or operator-edited files there. Old open tabs may need a refresh after hashed assets are replaced. The HTTP check confirms the shell and noindex response; it does not establish visual or end-to-end application correctness.
