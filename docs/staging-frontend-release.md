# DTB staging frontend release contract

## Authorities

- Canonical frontend source: `elliotttmiller/launch-dtb` at an immutable 40-hex commit SHA.
- Build script: `frontend/package.json` -> `npm run build:staging`.
- Output directory: **repository-root `dist-staging/`** (not `frontend/dist/`).
- Staging webroot: `public_html/staging/`.
- Root `public_html/index.html` is the separate prelaunch page and is never part of staging publication.
- WordPress/WooCommerce and their data are not deployment targets.

## Stage 1: build and validate (implemented)

Manually dispatch `.github/workflows/staging-frontend-build.yml` with an exact source SHA. It checks out canonical source, verifies the commit, runs `npm ci` and `npm run build:staging`, validates generated entrypoint URLs and referenced files, then uploads a tar archive, per-file SHA-256 inventory, and source identity. No SiteGround write occurs.

## Stage 2: controlled SiteGround publication (NOT enabled)

Do not enable publishing merely by setting a secret. Before deploying:

1. Establish an authenticated, staging-only SiteGround transport with an explicitly constrained path and separate authorization. Do not store SSH private keys or server details in this repository.
2. Audit current `public_html/staging/` ownership and exclusions, especially `.htaccess`, `errors/`, and any server-managed content.
3. Unpack the validated archive to a private temporary location and verify the complete file inventory, no symlinks/path traversal, and source SHA.
4. Acquire a staging-scoped deployment lock; back up existing mutable release files outside the webroot; install immutable, content-hashed assets before replacing `index.html` and `asset-manifest.json` last. Never perform an unbounded `rm -rf` on the live webroot.
5. Preserve old hashed assets until a deliberate, bounded retention cleanup. Protect `wp/`, live site root, uploads, WordPress configuration, and commerce state.
6. Verify filesystem hashes **and** HTTP responses for staging HTML, representative CSS/JS/image routes, SPA navigation, noindex headers, and API/checkout routing. A checksum-only verification is not a browser or payment test.
7. Rollback must restore only the previous mutable release snapshot under a lock, refuse stale-over-newer restoration, and verify its hashes afterward.

Production publication and homepage switching remain disabled. Do not reuse the SiteGround Git release engine for the frontend: its protected-path policy authorizes only the WordPress `/wp` tree and explicitly excludes the site-root storefront.

## Known constraints

The server-side `dtb-build-mcp` worker currently reports no configured source, private workspace, or Node/npm/Git/PHP CLI runtime. GitHub Actions provides an external build runner for stage 1, but **does not itself grant SiteGround deployment permission**.

The build workflow requires a real Actions run before we can claim successful dependency installation, compilation, or artifact validation. Publication, smoke testing, and rollback remain unimplemented here until the staging transport and release protocol are verified.
