# Catalog merchandising architecture

## Purpose

Drywall Toolbox uses a contractor-intent merchandising layer to improve product discovery without creating a second catalog taxonomy, compatibility source, product store, pricing source, inventory source, or bundle authority.

The merchandising layer answers presentation questions such as:

- what job is the contractor trying to perform;
- what tool family should they inspect next;
- what type of system or set fits the buying intent;
- which authoritative product details should the contractor review for a high-consideration purchase.

It does not redefine canonical catalog identity.

## Authority

The existing ownership model remains unchanged:

- `products/` owns canonical catalog source material, taxonomy, compatibility, brands, identifiers, and enrichment inputs.
- WooCommerce owns runtime products, variations, prices, and commerce persistence.
- Veeqo owns inventory, allocation, fulfillment, shipping, and tracking truth.
- `dtb-catalog-platform` owns DTB catalog-domain normalization, compatibility, catalog APIs, and catalog projections.
- `frontend/` owns customer-facing merchandising composition and interaction state only.

Merchandising metadata must never become the authority for SKU, MPN, GTIN, category identity, compatibility, product composition, pricing, availability, or warranty truth.

## Frontend composition

Dedicated category routes retain the existing composition:

```text
CategoryHero
  -> CategoryMerchandising (only when an explicit presentation profile with substantive intent/guide content exists)
  -> ShopByToolType (authoritative WooCommerce child categories)
  -> existing search / filters / sorting
  -> existing product grid
```

`frontend/src/data/categoryMerchandising.js` contains bounded presentation metadata for contractor intent. It may define labels, explanations, buying-guide copy, and candidate category slugs used to resolve links against category metadata already supplied by the catalog.

Where a contractor-intent card resolves to an authoritative child category with a routable slug, navigation uses the existing category URL builder. If no authoritative child category is present, the card remains informational rather than inventing a category, synthesizing a route, or changing catalog filtering.

Automatic-finishing child categories do not render a standalone workflow-orientation strip between the hero and catalog controls. These focused product-category routes proceed directly from the hero into authoritative child-category navigation when present and the existing filtering/product-selection experience. Workflow-oriented discovery remains appropriate at broader merchandising entry points such as the Automatic Tools category rather than being repeated as a non-actionable card on focused child categories.

## Tool-set merchandising

Tool Sets are treated as a high-consideration buying context, not as a separate product authority. The storefront may explain purchasing intents such as complete automatic systems, taping sets, flat-finishing sets, corner-finishing sets, starter systems, and production systems.

The Tool Sets page links workflow choices to existing catalog routes where a matching category exists. Hand finishing currently has no dedicated catalog category, so its entry points to the general product catalog without claiming a curated hand-tool assortment. The full-system entry points to an on-page comparison of currently returned Tool Set products.

The comparison uses only the returned catalog products and structured included-item metadata. It shows exact listed contents and extracts named taper, loading, flat-box, handle, and corner items for scanning; a missing row means “not listed in set contents,” not proof of absence. Current price comes from the live catalog projection. Warranty terms are not present in the compared set data and are shown as unspecified with a support path. Parts and repair links are service navigation, not claims that every component is stocked, compatible, or repairable. The comparison does not create synthetic filters, inferred set membership, or frontend-owned bundle composition.

Product cards may summarize set contents only from the structured `_includes_<n>_name` / `_includes_<n>_sku` metadata already carried by the canonical catalog DTO. `frontend/src/utils/productMerchandising.js` parses that structured metadata and refuses to infer set contents from product names, descriptions, brand, or category proximity. If structured includes data is absent, no set-content claim is rendered.

Actual set contents, current price, availability, savings, warranty, and compatibility must come from authoritative product/catalog data before those facts are rendered as product-specific claims. Contractors are directed to the live catalog and product details for those commerce facts.

## Compatibility-aware PDP merchandising

### Public compatibility lookup contract

`GET /dtb/v1/products/{sku}/compatible-parts` and `GET /dtb/v1/parts/{sku}/compatible-tools` accept one URL path segment containing letters, digits, periods, underscores, and hyphens. This includes catalog SKUs such as `CP-26`. The route validates that syntax; WooCommerce remains the source of product identity.

The product-to-parts lookup uses a bounded metadata search only to find candidates. It decodes each candidate's stored compatibility list and requires exact, case-insensitive SKU membership before returning a product. Comma-separated text, JSON arrays, and WordPress array metadata are supported. A substring hit is never fitment evidence.

An empty compatibility response means no published matching relationship was found in the current metadata. It does not establish that a part cannot fit a tool. Customer-facing fitment claims require verified canonical relationships and a visible product projection; schematic brand/group metadata alone is insufficient.

The existing product-detail `relatedProducts` rail remains the single PDP recommendation surface.

`dtb-catalog-platform` now prioritizes products that are explicitly connected through canonical compatibility metadata before falling back to the existing WooCommerce upsell/related-product relationships. It does not infer compatibility from product names, brands, descriptions, taxonomy proximity, or fuzzy matching.

For tool products, compatibility candidates are parts whose canonical `COMPATIBLE_TOOL_SKUS` or `REPLACEMENT_PART_FOR` metadata contains the exact tool SKU. The initial meta lookup is bounded and every candidate is re-read and exact-membership checked before it can enter the rail, preventing substring matches from becoming compatibility claims.

For part products, compatible tool SKUs already present in the normalized compatibility DTO are resolved through WooCommerce's indexed SKU lookup. Candidate count and the rendered rail remain bounded by the existing PDP related-product limit.

If no visible compatibility-backed candidate exists, the PDP retains its existing WooCommerce merchandising fallback. This preserves useful related products without creating a second recommendation service or weakening compatibility ownership.

The product-detail computed envelope may identify whether the selected rail is compatibility-backed or generic merchandising context. That flag is presentation context only and is not a persisted compatibility relationship.

## Repair merchandising relationship

Repair marketing is presentation-only. `frontend/src/pages/RepairLanding.jsx` explains existing repair capabilities and links into the existing repair package, intake, and tracking routes.

The repair landing page must not introduce service states, turnaround promises, approval semantics, warranty decisions, shipping truth, or pricing that the `dtb-repair-service` domain cannot produce or enforce.

Repair-specific visual refinements used by `RepairLanding.jsx` are loaded after the base repair stylesheet and before responsive overrides so accessibility/contrast fixes participate in the active cascade without changing repair-domain behavior.

## Accessibility and performance

Contractor-intent navigation must preserve semantic links and headings, visible keyboard focus, mobile-first layouts, reduced-motion behavior, and non-hover access to information.

Tool-set contents summaries are supplemental text inside existing product cards and require no additional requests. Category merchandising renders from data already available to the route.

The merchandising layer must not introduce per-card network requests. Category intent and Tool Set summaries render from data already available to the route. Compatibility-aware PDP recommendations are assembled inside the existing product-detail request rather than adding a second browser request.

## Scope boundary

These enhancements deliberately do not introduce:

- a new merchandising service or persistence table;
- a second taxonomy or contractor-intent taxonomy;
- frontend-owned compatibility;
- a bundle/composition engine;
- product comparison infrastructure;
- recommendation machine learning or fuzzy product matching;
- additional PDP recommendation endpoints;
- new global frontend state.

Future shopping features should extend these existing contracts only when an authoritative data source already exists and the customer value justifies the additional complexity.
