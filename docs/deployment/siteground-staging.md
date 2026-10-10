# SiteGround staging deployment control plane

## Authority and boundaries

GitHub Actions in `drywalltoolbox/dwt.production` is the sole executor for the React storefront's SiteGround staging publication. ChatGPT agents and MCP integrations may request and inspect a run only through an explicitly authorized GitHub Actions workflow-dispatch integration. Neither the storefront nor WordPress/DTB MCP should accept arbitrary SSH credentials or independently publish frontend files.

- Build: `.github/workflows/staging-frontend-build.yml` (validated `dtb-staging-<sha>` artifact, 14-day retention).
- Publish/rollback: `.github/workflows/staging-frontend-publish.yml`.
- Build resolver: `scripts/deployment/resolve-staging-build.py`.
- Remote staging release executor: `scripts/deployment/staging-release.sh`.
- Read-only SSH preflight: `.github/workflows/staging-ssh-preflight.yml`.

## Publication contract

The publication workflow is manual (`workflow_dispatch`) and uses the protected `staging` environment.

- **Publish, automatic:** select `publish` and leave `source_sha` and `build_run_id` blank. The resolver chooses the most recent `main` staging-build run. It **fails closed** if that run is not successful, its artifact is missing/expired, or its identity/provenance is invalid. It never falls back silently to an older successful run.
- **Publish, pinned:** supply both the 40-character source SHA and successful build run ID. The resolver verifies that the run belongs to the intended staging build workflow, the repository and `main` branch, and that its artifact matches the SHA.
- **Rollback:** select `rollback`, provide the *current published release SHA*, and leave `build_run_id` blank. The remote script requires an existing snapshot and rejects mismatched release identities. A rollback is a deliberate mutation and must never be triggered automatically on a failed health check without operator approval.

The staging directory is **build-owned**: publication mirrors the validated artifact into `public_html/staging/`, deleting files absent from the new build (including old hashed assets). It snapshots the previous contents outside `public_html` before mutation, and rollback mirrors that snapshot back, removing the newer release's files. The manifest and HTML entrypoint are swapped last. Old open tabs may need to reload after stale assets are deleted. Never store operator uploads or persistent content in the staging directory. The workflow checks the remote current-release identity before preparing an incoming release. Re-publishing the same current SHA is a no-op (HTTP/noindex verification still runs). Build provenance, checksums, staging-only target validation, a release lock, snapshots, and HTTP/noindex verification remain enforced by the existing workflow. A GitHub Actions job summary reports the resolved release, action, build run, and outcome. A successful HTTP shell/noindex check is not a comprehensive browser, commerce, or integration test.

## Agent integration contract (not yet connected)

A future agent-facing GitHub integration must expose narrowly scoped **workflow dispatch** for the exact repository and workflow, and read-only workflow run/job/log access. Enforce the connected user's authorization, audit identity, explicit staging-only action allowlist, and approval for destructive rollback. Do not expose a generic arbitrary-workflow dispatcher or long-lived GitHub credential to the storefront, WordPress, logs, or ChatGPT messages.

The current connected GitHub MCP tools can inspect run jobs and logs but **do not expose workflow dispatch**. Thus ChatGPT can review deployment outcomes, but cannot currently initiate a new run directly. Operators can start it at GitHub Actions until an authorized dispatch capability is connected. The automated artifact resolver does not change that limitation.

## Operational verification and recovery

1. Commit frontend changes to `main` (the build workflow runs on frontend changes), or manually dispatch the staging build workflow for the current `main` source. Confirm that the build succeeded and has an unexpired artifact. For automatic publish, ensure the most recent `main` build succeeded.
2. In GitHub Actions, select **Publish or roll back SiteGround staging**, click **Run workflow**, select **publish**, and leave both text inputs blank; the workflow resolves the latest successful staging build. Inspect its run.
3. Verify the explicit `Published staging release: <sha>` log entry and successful HTTP/noindex step.
4. If a publication fails, **inspect before retrying**. The remote `incoming/<sha>` directory may remain after a failed attempt; blindly rerunning the same SHA can be refused by the release script.
5. For rollback, inspect the current release and snapshot, explicitly authorize the rollback, and dispatch with the current release SHA. Verify restoration in the run logs and staging HTTP response.

## Security and residual risks

The staging SSH private key is stored only as a GitHub `staging` environment secret. Never commit or print it. The remote release root is outside `public_html`; the publication target is restricted to `public_html/staging`. The existing SSH commands currently disable host-key verification; pin a verified SiteGround host key in a future security-hardening change. The previously exposed SiteGround key must be retired once its dependencies are checked. A successful initial staging publication does not prove rollback or agent-dispatch behavior.

## Simple manual operator workflow

**No additional plugin is necessary.** ChatGPT can implement frontend changes and commit them to the repository using its authorized GitHub tools; the existing build workflow constructs the staging artifact. An operator then clicks **Run workflow → publish** in the existing publication workflow. If changes were only to deployment code rather than `frontend/`, run the staging build workflow first to produce a new artifact. The GitHub connector currently cannot initiate a new workflow run directly; the operator's click is the intended control plane.

Publication deletes all stale files within the validated staging root. Do not use staging as durable file storage. A new publication creates a private snapshot for explicit rollback; it does not modify the production storefront.
