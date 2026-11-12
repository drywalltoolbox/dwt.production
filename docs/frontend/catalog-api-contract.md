# Storefront Catalog Read Contract

## Ownership

WooCommerce owns runtime product, variation, price, and availability records. The `dtb-catalog-platform` REST controllers own the public catalog read projections and exact SKU resolution. React consumes those projections through `frontend/src/services/catalogPlatformCache.js`. Cart and checkout continue through WooCommerce Store API and native WooCommerce checkout.

## Read routes

| Route | Purpose | Result |
| --- | --- | --- |
| `GET /wp-json/dtb/v1/catalog/products` | Paginated product cards, filtering, and text/SKU search | Bounded `{ items, pagination }`; storefront requests use `projection=card`, while omitted projection preserves the full legacy DTO. SKU-shaped search includes visible catalog parents matched through published variation SKUs. |
| `GET /wp-json/dtb/v1/catalog/products/{slug}/detail` | Product detail page | Normalized product, variation, and related-product projections. |
| `GET /wp-json/dtb/v1/catalog/products/{id}/variations` | Product configuration selector | Bounded normalized variation projection without gallery filesystem enrichment. |
| `GET /wp-json/dtb/v1/catalog/resolve-sku?sku={exact}` | Exact product or variation SKU lookup, including schematic hotspots | `{ type, product, variation }`; `variation` is null for a product match. A variation match carries the parent product DTO and the exact variation DTO. |
| `GET /wp-json/dtb/v1/catalog/facets` | Catalog navigation and filters | Backend-owned category, brand, and filter projections. |

## Active frontend callers

| Surface | Adapter path | Read contract |
| --- | --- | --- |
| Product listing, category selectors, product rails, builder choices, search/autocomplete | `fetchCatalogProducts` in `catalogPlatformCache.js` (search compatibility wrapper in `services/api.js`) | Paginated compact card projection; search stays server-side and bounded. |
| Product detail and preload | `fetchCatalogProductDetail` | Full detail projection; no list/search fallback. |
| Variation selector and toolset builder | `fetchCatalogProductVariations` | Bounded parent variation projection. |
| Schematic hotspots and missing order-item image fallback | `resolveCatalogProductSku` | Exact published WooCommerce SKU; variation result includes the authoritative parent and exact variation. |
| Header/category navigation | `fetchCatalogFacets` and `fetchCatalogCategory` | Server-owned facet and category metadata projections. |

Order pages prefer the image embedded in the authorized order response. Only missing images trigger a bounded set of exact-SKU lookups, with at most four in flight. They do not download a second full catalog or match products by display name.

SKU resolution preserves the submitted SKU identity; it does not fuzzy-match names or normalize one SKU into another. A public, published, catalog-visible product or variation returns `200`. A confirmed absent or non-public SKU returns the canonical `404` envelope with code `not_found`. The frontend treats only that envelope as a miss; a missing route, proxy/static 404, or other REST error remains an integration failure. A WooCommerce runtime, database, or normalization failure returns a server error (`503` for unavailable catalog runtime) and must not be converted to `404` or cached as a miss.

Product search is intentionally different from identity resolution: text and SKU-token search can return a parent product for a partial child-variation SKU match. It does not claim that a partial token identifies one exact variation.

## Compatibility transition

The frontend catalog callers use the canonical routes. Existing `drywall/v1` product lookup routes remain available during the frontend rollout so the currently deployed application is not broken. Remove an individual compatibility route only after its canonical replacement has been deployed and read-back/consumer checks show parity. Keep unrelated `drywall/v1` routes, including commerce and operational integrations.

## Failure and cache rules

- Search page size is capped at 100; SKU and search inputs are bounded.
- The frontend coalesces concurrent catalog reads only when no caller-owned abort signal is supplied. Aborted consumers do not cancel another consumer's request.
- Exact-SKU results are coalesced in flight only. Misses and runtime failures are not persisted by the adapter.
- Public product responses may be cached by the catalog query cache. Cart, checkout, account, and other session-bound responses do not belong in that cache.
