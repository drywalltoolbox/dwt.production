# SiteGround staging deployment

GitHub Actions is the deployment authority for the React staging storefront only. Production deployment is out of scope.

## One-click operator workflows

- Deploy Latest Frontend to Staging: `.github/workflows/staging-frontend-publish.yml`. Click Run workflow on `main` with no inputs. The workflow checks out that commit, runs `npm ci` and `npm run build:staging`, using `frontend/.env.staging` through the existing webpack loader, validates the resulting `dist-staging/` inventory/checksums, snapshots the previous staging site in the private SiteGround release directory, mirrors the new files into `public_html/staging/`, and checks HTTP response/noindex.
- Rollback Staging to Previous Release: `.github/workflows/staging-frontend-rollback.yml`. Click Run workflow with no inputs. Under the shared remote lock, the server reads the currently published release identity and restores the snapshot created before that publication, deleting files absent from the previous snapshot. Fails if there is no valid previous snapshot.

## Source and release identity

The deployment workflow always compiles the checked-out source rather than downloading an older successful build artifact. The existing `.github/workflows/staging-frontend-build.yml` remains a separate automatic validation-only workflow and is not a dependency of deployment.

The source commit is `GITHUB_SHA`. The release identity is the 40-character SHA-1 digest of the source SHA, GitHub workflow run ID, and attempt. The release identity uniquely identifies a manual build-and-publish attempt, so rerunning the workflow on an unchanged commit still generates a fresh release and a corresponding rollback snapshot.

## Safety and operational behavior

- Shared `siteground-staging-publish` concurrency group prevents simultaneous publish/rollback workflows.
- GitHub staging environment contains the existing SSH secrets; credentials are never entered in the dispatch form or committed to source.
- `scripts/deployment/staging-release.sh` verifies the target is `public_html/staging/`, requires the private release directory outside the webroot, validates checksums before mutation, snapshots before replace, and uses a remote release lock.
- `rsync --delete` makes the staging directory a build-owned mirror. Do not store persistent uploads or operator-edited files in staging. Old browser tabs referencing removed asset filenames may need refreshing.
- HTML and manifest are replaced last; the HTTP check verifies a valid HTML response plus `X-Robots-Tag: noindex`, not complete application functionality.
- If a release fails after preparing the incoming directory, inspect the private state before retrying; the release script refuses ambiguous incoming paths.
- Rollback is explicitly initiated by the operator, never automatic after a health-check failure.
- SSH host-key checking remains disabled in existing workflows and requires separate hardening using a verified SiteGround host key.

## Verification

The original artifact-based publication and SSH preflight previously succeeded. The new build-and-deploy workflow is committed but **has not been executed**. Run the one-click deployment and inspect all job steps, the publish log, HTTP verification, and rendered staging website before asserting the new version is live.
