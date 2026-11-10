# SiteGround staging preview

The `/staging/` frontend and `frontend/.env.staging` remain available for
development and update previews. The production launch pipeline is the only
GitHub Actions storefront publisher: every push to `main` builds the production
configuration and publishes to the `public_html` root.

The previous staging build, publish, and rollback workflows are retired. A
production push does not update or delete `/staging/`. Build a staging preview
locally with `npm --prefix frontend run build:staging`; publish a future staging
preview only through a separately reviewed workflow when that need is defined.

The existing deployed `/staging/` preview remains in place. Its cleanup is a
separate operation and is not part of the production storefront deployment.
See `docs/deployment/siteground-production-frontend.md` for production
deployment and rollback details.
