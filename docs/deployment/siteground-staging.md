# SiteGround staging deployment

GitHub Actions is the single deployment executor for the frontend staging site at `https://drywalltoolbox.com/staging/`. The staging directory `public_html/staging/` is build-owned and **not persistent storage**. The production storefront is not a deployment target.

## Operator actions: zero inputs

Both workflows run from GitHub Actions on branch `main`, using the existing `staging` environment and SiteGround SSH secrets. No source SHA, artifact ID, or SSH key needs to be entered.

- **[Deploy Latest Frontend to Staging](https://github.com/drywalltoolbox/dwt.production/actions/workflows/staging-frontend-publish.yml)**: click **Run workflow**. Resolves the newest `main` staging build, validates the successful build and unexpired artifact, verifies SHA/checksums, snapshots the current staging content privately, mirrors the artifact to staging, and checks the HTTP shell and `noindex` header. Duplicate publication of the current release is skipped. If the newest staging build failed or has expired, deployment fails instead of silently using an older build.
- **[Rollback Staging to Previous Release](https://github.com/drywalltoolbox/dwt.production/actions/workflows/staging-frontend-rollback.yml)**: click **Run workflow**. Under the remote release lock, automatically reads the currently published SHA and restores its immediately preceding snapshot. Fails closed if no current release, snapshot, or restorable HTML/manifest exists. This is an explicit manual operation and may change the visible staging website.

**Typical process:** ChatGPT commits frontend changes to `main` → `.github/workflows/staging-frontend-build.yml` builds validated staging artifacts for `frontend/**` changes → operator waits for build success → operator runs **Deploy Latest Frontend to Staging**. If only build/deployment tooling changed, run the staging build workflow manually first to produce an up-to-date artifact.

## Owning files and invariants

- `.github/workflows/staging-frontend-build.yml`: deterministic build and short-lived, SHA-named artifact.
- `scripts/deployment/resolve-staging-build.py`: most recent build selection, source identity, provenance, and artifact availability.
- `.github/workflows/staging-frontend-publish.yml`: manually dispatchable deploy with **no inputs**.
- `.github/workflows/staging-frontend-rollback.yml`: manually dispatchable rollback with **no inputs**.
- `scripts/deployment/staging-release.sh`: shared server-side release executor. `publish` requires an exact SHA; `rollback` accepts `auto` and resolves the current SHA **under the same remote lock** before validating the snapshot. The older explicit SHA rollback path is also supported by the release script.
- `.github/workflows/staging-ssh-preflight.yml`: independent read-only SSH verification.

Both deployment workflows use `siteground-staging-publish` concurrency with no automatic cancellation, the same `staging` environment, the existing SSH key, the same private release directory, and the same staging-only server-side root checks. They do not create additional deployment services or integrations.

Publication snapshots the old staging tree outside `public_html`, removes stale build files using `rsync --delete`, then replaces the manifest and index last. Rollback restores the snapshot, also removing newer release files. Previously open browser tabs may require a reload. Do not put operator uploads or persistent data in the staging folder.

The post-deployment check verifies HTML and the `X-Robots-Tag: noindex` response header; it is not a full browser test. Review the GitHub Actions job logs and summary before asserting success.

## Failure handling and limitations

If a publication fails after creating `incoming/<sha>`, inspect the private incoming directory and snapshot before retrying; the release executor intentionally rejects ambiguous repeats. If rollback fails because the snapshot predates the release mechanism or lacks critical files, do not force a destructive restore; inspect the server state first.

ChatGPT's connected GitHub tool currently **cannot dispatch** new workflows. The operator's GitHub **Run workflow** button is the deliberate trigger. No plugin, private GitHub App, or agent dispatch service is needed.

SSH host-key verification is currently disabled by the existing workflows; pin a verified SiteGround host key in a separate hardening change. Retire the previously exposed SSH key after confirming it has no remaining dependencies. The first deployment succeeded before the new zero-input rollback workflow was added; newly changed code still needs a workflow run to validate real SiteGround behavior.
