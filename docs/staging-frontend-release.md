# Staging frontend release

The staging publisher builds the exact `GITHUB_SHA` once. It validates `dist-staging/`, records a SHA-256 manifest, packages the output as a short-lived GitHub Actions artifact, then passes that artifact to the deploy job in the same workflow run. The deploy job verifies the source and release identities and checks the manifest before transfer and again on the published SiteGround webroot.

The server-side release implementation is `scripts/deployment/staging-release.sh`. It enforces the staging-only target, private release directory, incoming checksum, exclusive lock, previous-release snapshot, and explicit rollback behavior. The operator workflows and required secrets are documented in [SiteGround staging deployment](deployment/siteground-staging.md).

The public HTTP shell probe uses bounded retries for transport/TLS failures only. A non-200 response, malformed HTML response, or missing noindex header fails immediately. Public JS/CSS probes apply the same bounded retry policy to transport failures and verify response content types.

The PR workflow validates prospective frontend changes; its output is not reused for deployment because the published artifact must match the exact post-merge source commit. No production root, WordPress runtime, or commerce data is changed by these staging workflows.
