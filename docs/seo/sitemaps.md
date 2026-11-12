# DTB Sitemap Service

## Ownership

DTB MU Plugins own sitemap routing, URL selection, XML rendering, cache invalidation, and the sitemap contract exposed to crawlers. The React storefront owns customer-facing route shapes. WordPress and WooCommerce remain the systems of record for published products and taxonomies.

The canonical public entry point is:

`/sitemap.xml`

The service disables the WordPress core sitemap provider to prevent duplicate sitemap authority.

There is no separately generated frontend sitemap. Production WordPress owns
the only sitemap authority. The staging React surface shares production data
but remains noindex and does not publish or advertise a staging sitemap.

## Sitemap topology

The index emits only child sitemaps that currently contain eligible URLs:

- `/sitemaps/static-1.xml`
- `/sitemaps/products-{page}.xml`
- `/sitemaps/product-categories-{page}.xml`
- `/sitemaps/brands-{page}.xml` when a supported brand taxonomy is registered

Each dynamic child is bounded to 1,000 records. Child pagination is deterministic by product ID or term ID.

## Canonical storefront routes

The service maps records to the active React route contract:

- Products: `/products/{product-slug}`
- Product categories: `/category/{term-slug}`
- Brands: `/products/brands/{term-slug}`
- Static routes: the explicit public allowlist in `DTB_SitemapUrlRepository::static_routes()`

Production Apache routes the indexable public content paths through the
WordPress front controller before serving the React shell. The platform route
renderer supplies route-specific title, description, canonical, and useful
initial HTML from the versioned `storefront-public-routes.json` build artifact.
That artifact contains editorial copy only; it does not own catalog records.
Product and taxonomy detail pages are rendered from published WooCommerce
products and active product taxonomies. `/products` includes a bounded catalog
directory generated from the same eligible products and taxonomies used by the
sitemap, so those canonical URLs have crawlable internal links without adding
a second sitemap or catalog authority. React hydrates the same route for
interactive filtering and commerce behavior.

If the public manifest or shell is unavailable, the renderer returns a
temporary server error instead of serving a misleading homepage response. A
confirmed missing or empty product/taxonomy route returns 404; an upstream
catalog request failure remains temporary and is not reclassified as missing.

Customer, checkout, cart, order, account, tracking, preview, operator, redirect-alias, and variation-specific routes are intentionally excluded.

## Product eligibility

Only published WooCommerce products intended for indexing are included. Products are excluded when either of these conditions applies:

- the product is assigned the WooCommerce `exclude-from-search` visibility term;
- the DTB product SEO flag `_dtb_seo_noindex` is enabled.

Variations are not emitted as independent URLs; the canonical parent product route remains authoritative. Only `/products/{slug}` is a product-page route. Old `/product/{partNumber}` paths are not storefront aliases or sitemap authorities; variation selection stays on the canonical parent URL as query state.

Product sitemap cache invalidation is already tied to `save_post_product`, so changes to the DTB noindex field advance the sitemap generation with the rest of the product mutation.

## Brand taxonomy detection

The repository detects the first registered taxonomy in this allowlist:

1. `product_brand`
2. `pwb-brand`
3. `pa_brand`

No arbitrary taxonomy name is accepted from a request.

## Routing and rewrite lifecycle

The service registers explicit WordPress rewrite rules for the index and paginated child files. A versioned option (`dtb_sitemap_rewrite_version`) causes one soft rewrite flush after a route-contract deployment. Rewrite rules are not flushed on every request.

Because the public document root is a React SPA while WordPress core lives under `/wp/`, the canonical Apache routing sources also contain explicit internal bridges:

- `^sitemap\.xml$` → `wp/index.php?dtb_sitemap=index`
- `^sitemaps/...\.xml$` → `wp/index.php?dtb_sitemap=...`

These rules must execute before the generic missing-static-asset `.xml` 404
guard. `frontend/scripts/assert-build-routing.cjs` enforces that ordering for
production builds. This keeps the MU-plugin service authoritative while making
its public XML endpoints reachable through the storefront document root.

Unknown sitemap types and out-of-range pages return HTTP 404 with an XML error body.

## Security and response policy

Sitemap endpoints are intentionally public read-only resources. They expose only published public URLs and accept no writable fields.

Generated URLs are:

- created from `home_url()` so staging and production domains/mount paths remain environment-owned;
- restricted to HTTP or HTTPS;
- validated against the configured home host;
- XML escaped before output.

Responses include XML content type, ETag support, shared-cache directives, `Vary: Accept-Encoding`, and `X-Robots-Tag: noindex, follow` for the sitemap documents themselves.

## Cache and invalidation

Generated XML is cached in WordPress transients for one hour. Cache keys include:

- the configured site URL;
- sitemap type;
- page number;
- sitemap generation.

The generation option (`dtb_sitemap_generation`) is advanced once per request when a relevant product or taxonomy mutation occurs. Old generations expire naturally without unbounded delete scans.

The full Cache Tools workflow clears WordPress transient storage and therefore removes generated sitemap XML. Product and operations caches remain separate authorities.

## Robots integration

The WordPress virtual `robots.txt` filter removes duplicate `Sitemap:` declarations and appends the environment-derived `/sitemap.xml` URL.

The React build also contains `frontend/public/robots.txt`. Index-enabled
production builds emit the root sitemap declaration. Staging builds emit
`Allow: /` so authorized tools such as Semrush can crawl the staging surface,
but emit no staging sitemap declaration. A physical `robots.txt` takes
precedence over WordPress virtual robots output.

A `robots.txt` served from `/staging/robots.txt` is not the origin-level
robots authority; standard crawler policy is read from `/robots.txt` at the
origin root. The root policy therefore does not disallow the staging mount.
Staging index protection instead uses the React `noindex, nofollow` head policy
and the canonical Apache `X-Robots-Tag: noindex, nofollow` response header. This
allows crawling for audits without allowing search indexing.

For Semrush Site Audit on staging, verify ownership in Semrush and enable its
restriction-bypass option so SiteAuditBot may audit the environment despite
robots and robots-meta/header restrictions. Use an explicit staging crawl URL;
do not publish or submit a staging sitemap.

## Staging contract

The staging mount is `https://drywalltoolbox.com/staging/`. It shares the
production WordPress/WooCommerce runtime but is not a canonical search surface.
Its build permits crawling, its Apache responses emit
`X-Robots-Tag: noindex, nofollow`, and it does not advertise a sitemap. The only
canonical sitemap entry point remains `https://drywalltoolbox.com/sitemap.xml`.

## Admin diagnostics

The DTB SEO Tools sitemap status view checks the same canonical `/sitemap.xml` entry point exposed to crawlers. Admin diagnostics must not introduce alternate sitemap authorities such as `/sitemap_index.xml` or `/wp-sitemap.xml`.

## Route audit contract

The sitemap is an allowlist, not a mirror of every React Router route. The current indexable route families are:

- `/`
- `/products`
- `/products/brands`
- `/products/brands/{brand-slug}`
- `/products/{product-slug}`
- `/parts`
- `/category/{term-slug}`
- `/schematics`
- `/repairs`
- `/repairs/start`
- `/repairs/packages`
- `/help`
- `/calculators`
- `/shipping-policy`
- `/returns`
- `/return-policy`
- `/policies`
- `/contact`

The following route families are deliberately outside the sitemap contract even when they exist in React Router:

- brand/category faceted intersections such as `/products/brands/{brand}/categories/{category}` until they have an explicit canonical/indexability contract;
- variation routes and `?variant=` states;
- retired `/product/{partNumber}` paths (these are not served as storefront aliases);
- repair, return, support, order, and tracking status URLs;
- cart and checkout routes;
- login, registration, password reset, dashboard, account, settings, rewards, and redirect aliases;
- preview and explicit error routes; unmatched paths return 404 and are not sitemap entries.

## Deployment validation

After deploying staging or production:

1. Visit production `/sitemap.xml` and confirm HTTP 200 with a `<sitemapindex>` document.
2. Open every child listed by the index and confirm HTTP 200 with `<urlset>`.
3. Confirm every sitemap URL remains on `https://drywalltoolbox.com/` and never uses the staging mount.
4. Confirm private and utility routes are absent.
5. Confirm product URLs use `/products/{slug}` and resolve through the storefront.
5a. Inspect raw HTML before JavaScript for route-specific metadata and useful content on static, product, brand, and category pages.
5b. Confirm `/products` contains internal links to eligible product and taxonomy URLs from the canonical catalog source.
6. Confirm products carrying `_dtb_seo_noindex = 1` are absent from product sitemaps.
7. Confirm WooCommerce `exclude-from-search` products are absent.
8. Confirm variation URLs and legacy `/product/{partNumber}` aliases are absent.
9. Confirm `/robots.txt` advertises exactly one environment-correct sitemap URL where the origin-level robots policy is deployed.
10. Run a product update and verify a subsequent sitemap request reflects the change.
11. Confirm the DTB SEO Tools sitemap status view checks `/sitemap.xml`.
12. On staging, confirm both the HTML robots meta and `X-Robots-Tag` response header keep indexing disabled, then configure Semrush to bypass those restrictions for the authorized audit.
13. Only after production launch, when DTB is ready to enable search indexing, use the production `/sitemap.xml` for search-engine submission.

## Operational limits

The service does not generate image, video, news, multilingual, alternate-language, or faceted-navigation sitemap extensions. Those should be added only when their canonical data ownership and frontend rendering contracts are established.
