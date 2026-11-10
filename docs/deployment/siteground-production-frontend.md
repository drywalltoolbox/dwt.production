# SiteGround production frontend release

The production React storefront is mounted at the canonical domain root. Its
build publishes into `/home/customer/www/drywalltoolbox.com/public_html`, where
WordPress core remains in `/wp`. Do not deploy the `dist-staging/` artifact to
the root: its asset URLs use `/staging/`.

## Release workflow

`.github/workflows/production-frontend-publish.yml` runs on every push to
`main` and can also be dispatched manually from `main`. It builds
with `npm run build:production`, validates root asset paths and the production
`.htaccess`, packages a checksum manifest, reads production SSH configuration
from the GitHub environment, stages the artifact outside the webroot, and
publishes the storefront shell last. Add an environment reviewer before the
first release if approval gating is required. The release script updates only the
allowlisted storefront files. It never mirrors or deletes the whole
`public_html` tree, and preserves `/wp`, `/staging`, `/brands`, `/logos`,
`/assets/email`, `.well-known`, uploads, and other unrelated site content.

The first automatic production publish is enabled by the
`SITEGROUND_PRODUCTION_UPDRAFT_BACKUP_VERIFIED=true` environment variable,
which is set based on the owner's confirmation that the Updraft full-site
backup exists. Configure required reviewers on the GitHub environment
`siteground-production` if an additional approval gate is desired.

`.github/workflows/production-frontend-build.yml` runs the same production
build and root-path checks on pull requests that touch frontend or routing
files. It never publishes.

## Required GitHub environment settings

Environment `siteground-production` needs these secrets:

- `SITEGROUND_SSH_HOST`
- `SITEGROUND_SSH_USER`
- `SITEGROUND_SSH_PORT`
- `SITEGROUND_PRODUCTION_SSH_PRIVATE_KEY`
- `SITEGROUND_PRODUCTION_SSH_KEY_PASSPHRASE`

The public key file is not a GitHub secret; it is the matching key authorized
by SiteGround.

It also needs these variables:

- `SITEGROUND_PRODUCTION_PUBLIC_HTML_ROOT`: absolute path ending in `/public_html`
- `SITEGROUND_PRODUCTION_FRONTEND_RELEASE_ROOT`: absolute, writable path outside `public_html`
- `SITEGROUND_PRODUCTION_SSH_KEY_FINGERPRINT`: SHA256 fingerprint of the authorized production deploy key
- `SITEGROUND_PRODUCTION_UPDRAFT_BACKUP_VERIFIED`: `true` after confirming the full-site backup exists

Use the dedicated `dtb-github-production` key whose SiteGround account can
write the storefront allowlist in `public_html` and the private release
directory. Its encrypted private key and passphrase belong in the two
production environment secrets above. The workflow decrypts only a temporary
runner copy and removes it after the job. Like the retired staging publisher,
production SSH uses `StrictHostKeyChecking=no` with `/dev/null` as its
known-hosts file; it does not pin the SiteGround server host key. Do not reuse
the WordPress Git deployment key or a staging-only account.

## Deployment and rollback boundaries

The deployment script snapshots the existing frontend-owned files outside the
webroot, verifies the release checksums, writes static assets first, and swaps
`.htaccess`, manifests, and `index.html` atomically. It keeps a bounded
three-release snapshot history after successful publication. The separate
rollback workflow requires `ROLLBACK_PRODUCTION` and the current source SHA.

These workflow snapshots cover the managed frontend surface. The Updraft
full-site backup is the recovery point for the full WordPress installation,
database, and the rest of the site. SiteGround `public_html` is not a disposable
frontend directory; never use `rsync --delete` against it.

After deployment the workflow verifies the root document, emitted asset URLs,
WordPress REST, native checkout, and remote file checksums. Browser-level
payment acceptance remains a separate WooPayments production-readiness check.
