# Staging frontend build and deployment contract

## Source and ownership

The `main` branch of `drywalltoolbox/dwt.production` currently contains the imported frontend source. The staging workflow builds **its own triggering commit**, not an independently pinned copy from another repository. The canonical application architecture and commerce ownership remain governed by the existing implementation contracts. If repository source ownership changes, update this workflow deliberately.

- Build: `cd frontend && npm ci && npm run build:staging`
- Output: repository-root `dist-staging/`
- Publication target: SiteGround `public_html/staging/` only.
- Never overwrite `public_html/index.html` or deploy frontend artifacts into `public_html/wp/`.
- The checked-out source identity is `github.sha`; the artifact contains `staging-source-sha.txt` and a SHA-256 inventory.

## Implemented: staging artifact build

`.github/workflows/staging-frontend-build.yml` runs on `main` changes to the workflow or `frontend/**`, and supports manual `workflow_dispatch`. It checks out the triggering commit, installs locked dependencies using Node 22, runs the canonical staging build, verifies required files and staging asset entrypoints, and uploads a validated distribution archive plus source/checksum metadata for 14 days.

It performs **no hosting writes**. A passing workflow establishes that the distribution was built and its declared entrypoint assets exist, not that the live storefront works.

## Not yet enabled: controlled staging publication

A separate staging-only release publisher must be implemented and authorized before uploading artifacts:

1. Verify artifact provenance and SHA-256 inventory. Reject missing files, symlinks, traversal paths, or mismatched source SHA.
2. Limit the deployment credential to staging and prevent writes to site root, WordPress, customer uploads, and server-owned files.
3. Acquire a release lock. Prepare and retain a private rollback snapshot before modifying mutable release files.
4. Upload content-hashed assets first, keeping old hashed assets available for active clients. Replace mutable manifests and `index.html` last, only after verification.
5. Perform both filesystem and HTTP checks of HTML, representative assets, SPA routing, cache headers, noindex policy, shared REST integration routes, and checkout ownership. Do not mutate real orders to smoke-test a deployment.
6. Rollback under the same lock, restoring only the previous release's mutable files; reject restoration over an unrelated newer release and reverify the result.
7. Maintain bounded artifact/backup retention with an operator-controlled cleanup policy.

The existing SiteGround Git release engine covers the WordPress `/wp` tree. It does not authorize frontend staging publication.

**Production publication, homepage switching, and server-side builds remain disabled.** Do not infer deployment from a successful Actions build.
