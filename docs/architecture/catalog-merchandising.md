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

The Tool Sets page links workflow choices to existing catalog routes where a matching category exists. Hand finishing currently has no dedicated catalog category, so its entry opens an assisted-selection inquiry without claiming a curated hand-tool assortment. The full-system entry points to an on-page comparison of currently returned Tool Set products.

The comparison uses returned catalog products and structured included-item metadata. It shows exact listed contents and component SKUs where recorded, with named taper, loading, flat-box, handle, and corner items separated for scanning; “not listed in set contents” is not proof of absence. Current price and stock status come from the live catalog projection. `products/launch/official/toolset_warranty_evidence.json` holds manufacturer-sourced, reviewed warranty evidence keyed by exact set SKU. The comparison shows the qualified term and primary-source links, or the source conflict where the manufacturer publishes inconsistent terms. These are component-level manufacturer statements, not a bundle guarantee; customer support remains available to confirm specific coverage. Parts and repair links are service navigation, not claims that every component is stocked, compatible, or repairable. The comparison does not create synthetic filters, inferred set membership, or frontend-owned bundle composition.

Product cards may summarize set contents only from the structured `_includes_<n>_name` / `_includes_<n>_sku` metadata already carried by the canonical catalog DTO. `frontend/src/utils/productMerchandising.js` parses that structured metadata and refuses to infer set contents from product names, descriptions, brand, or category proximity. If structured includes data is absent, no set-content claim is rendered.

Actual set contents, current price, availability, savings, and compatibility must come from authoritative product/catalog data before those facts are rendered as product-specific claims. Warranty statements require exact-SKU manufacturer evidence with source links, scope qualifications, and review dates. Contractors are directed to the live catalog and product details for commerce facts.

## Compatibility-aware PDP merchandising

### Operator relationship workbench

The Product Relationships wp-admin workbench is an operator interface over the existing domain owners. It must not make an exact SKU selection equivalent to evidence of fitment. `_dtb_compatible_tool_skus` and `_dtb_replacement_part_for` on WooCommerce products are runtime projections of approved canonical compatibility from `products/`; legacy numeric `_dtb_compatible_tools` / `_dtb_compatible_parts` metadata is unverified and must remain visibly separate until exact identities and evidence are reviewed. Schematic occurrences can generate exact candidates, but only the digest-bound catalog manifest workflow may approve source changes. Multi-tool schematic candidates are surfaced as explicitly labeled review work, while shared schematic identity alone does not establish each target fitment; no fuzzy matching or operator convenience action may publish them as fitment.

Catalog Operations is the entry point for the combined Product & Parts workspace. Operators with `dtb_manage_product_mapping` are routed to `dtb-product-mapping`, where Parts Manager, schematic, and universal-part controls are embedded only when they also have `dtb_manage_parts`. Parts-only operators retain the `dtb-parts-manager` route. The legacy Parts Manager URL redirects operators with relationship access to the combined workspace; it does not create a second editable interface for the same operator.

The read-only integrity summary classifies fitment metadata by owner type and publication state: published parts define the coverage denominator, unpublished part owners remain separate review items, variations are reported separately, and non-part products are not conflated with unpublished parts. It preserves raw fitment SKU values, including blank tokens, flags empty values, malformed JSON-shaped values, whitespace and non-exact target SKU spellings, counts fitment self-links, and requires a variation target's exact parent SKU to resolve uniquely to the same product ID recorded as its parent. It reports effective WooCommerce catalog visibility separately for products and variation parents, and marks resolved hidden targets as excluded from public discovery rather than as invalid fitment. The schema-v12 coverage report intersects digest-current review candidates with published parts that have no structured fitment and identifies exact part SKUs present in unresolved schematic-source rows whose tool schematic identity has not resolved. These source occurrences remain research gaps: the report does not assign a target tool or propose fitment. Candidate presence does not mean compatibility is approved. WooCommerce describes `Hidden` products as accessible only on their single product page ([catalog visibility behavior](https://woocommerce.com/document/managing-products/product-editor-settings/)); Product Relationships reports that state and does not change visibility automatically. Missing relationship data is not a compatibility conflict by itself.

New fitment authoring uses `Meta: _dtb_compatible_tool_skus`. `Meta: _dtb_replacement_part_for` remains a legacy source field consumed by the public compatibility API; both currently project the same public compatibility claim, and a SKU present in both is deduplicated at read time and reported as a source-field overlap. Do not add new values to `replacement_part_for` until its legacy semantics and migration are reviewed. The runtime workbench labels which projection field supplied each link and does not write either field.

Approved fitment source changes are not live until a narrow, update-existing projection is executed and reconciled. The required release path is: approved digest-bound manifest; atomic official catalog apply and before/after hashes; release artifact containing only exact SKU plus compatibility fields; exact, unique WooCommerce SKU resolution; bounded update-existing projection; metadata read-back; verification through both public compatibility routes; and a source/runtime drift report with per-SKU result and rollback artifact. Compatibility references must preserve the canonical target SKU spelling; case-insensitive lookup may locate a candidate but cannot authorize a differently cased stored value. A full catalog import is not a substitute and must not be used for fitment-only changes.

MCP DTB may expose the existing projector through the WordPress Abilities API as an operator transport, not as a second writer. The [Abilities API REST contract](https://developer.wordpress.org/apis/abilities-api/rest-api-endpoints/) carries destructive ability input in the request query, so the adapter first accepts an exact artifact payload through a bounded POST and stores it by SHA-256 in the projector's private directory for up to 24 hours. Preview and apply then accept only the release ID, batch index, artifact SHA-256, and (for apply) explicit release-ID confirmation. The adapter delegates all approval, identity, before-state, conflict, locking, idempotency, rollback-report, and public-route read-back behavior to `DTB_ProductRelationshipProjectionService`. Staged artifacts expire after 24 hours and are removed when accessed or during a subsequent staging cleanup; the projector remains the only product writer. Status and rollback use the private release ledger, and rollback requires the current report digest and explicit confirmation. The adapter requires an authenticated administrator, rejects oversized or digest-mismatched payloads, exposes no server filesystem paths, and never accepts product IDs or arbitrary metadata as write input. WooCommerce CRUD remains the runtime product writer and `products/` remains canonical fitment authority.

The Product Relationships screen may edit WooCommerce upsell and cross-sell relationships through CRUD, which remain directional merchandising records and WooCommerce's operational authority. Source and target search includes every registered WooCommerce product type; variation records remain excluded because the save contract accepts only product records. Optional type filters are intersected with WooCommerce's registered type list and fail closed when no requested type is registered. The editor must keep selected targets visible, reject self-links, unpublished products, duplicates, overlapping types, and stale edits, and expose any pre-existing overlap for repair. Product search results and saved placements disclose catalog visibility, including products hidden from ordinary catalog listings, so operators can review storefront reach before saving. For a selected tool, the editor may show parts already returned by the exact public compatible-parts route as cross-sell candidates. The operator must explicitly add a candidate and save; this creates a WooCommerce merchandising placement only and cannot approve, edit, or broaden fitment. Candidate discovery is bounded and must state that no returned candidate does not prove incompatibility. A save is successful only after a fresh WooCommerce CRUD read confirms both requested lists; if CRUD throws, the handler still performs read-back to distinguish an exception after commit from a failed write, returns a generic retry-safe error when state cannot be verified, and logs only the stable source product ID and exception class. Catalog Operations presents one capability-aware Product & Parts Relationships entry and shared navigation for the relationship and parts tools; the underlying routes and write permissions remain owned by their respective tools. The Product Relationships workspace embeds the existing Parts Manager interface for operators who hold `dtb_manage_parts`; parts operations continue through the Parts Manager owner APIs and capability checks. The standalone `dtb-parts-manager` route remains available for compatibility and parts-only operators. Universal seed projection runs through `UniversalPartsProjectionService` from both Parts Manager and Inventory Intelligence; required `parts.csv` and `members.csv` inputs must be readable and nonempty before projection, and the Inventory Intelligence full rebuild stops before stock/rollup work if projection inputs fail. Exact WooCommerce SKU lookup is preferred, `product_kind=part` and `_dtb_is_parts=1` are both eligible part classifications, and ambiguous manufacturer-SKU fallback fails closed with candidate IDs and a reason in the dry-run conflict report. The official catalog CSV's `Upsells` and `Cross-sells` columns are legacy/export placeholders, not an import writer. Any full-catalog update artifact must omit them or hydrate them from a fresh authorized WooCommerce export and verify they remain unchanged; blank cells must never clear live merchandising. SKU, MPN, GTIN, part number, schematic identity, and universal-part identity are never writable there. Parts Manager may maintain part content, but existing SKU and manufacturer SKU values are immutable in that workflow.

Coverage reports must define the eligible published part/tool denominator and distinguish canonical verified fitment, unresolved legacy links, missing relationship data, invalid targets, and source-to-runtime projection drift. Missing data is not incompatibility. Schematic lifecycle and universal equivalence continue to link to their owning tools rather than being rewritten through Product Relationships.

### Controlled exact-fitment cart cross-sell import

The official catalog CSV remains a catalog/import artifact, not the general source of WooCommerce merchandising. A narrow exception is the digest-guarded `project_exact_fitment_cross_sells.py` release projection: it may add directional tool-to-part cart cross-sells derived only from exact canonical part fitment. It requires published, catalog-visible tool and part records, exact SKU resolution, and simple part targets. A variable tool parent receives a part only when that exact part fits every published, visible child variation. This cross-sell projection does not create upsells or use shared brand, family, category, schematic, or name similarity as fitment evidence. The deterministic candidate order prefers parts with fewer public tool fitments and then exact SKU; this is a relevance heuristic, not observed co-purchase evidence.

The projection is additive, writes only `Cross-sells`, creates an exact `.bak`, and emits a two-column `SKU,Cross-sells` update file. Import only that delta with WooCommerce's update-existing products option and map only SKU and Cross-sells. This avoids blank catalog cells clearing WooCommerce-owned placements. A full-catalog import does not follow from this exception. Reconcile every changed SKU through WooCommerce readback before treating the projection as live. Future operator-authored relationships remain WooCommerce-owned and must be read back before any later replacement or reconciliation.

### Public compatibility lookup contract

`GET /dtb/v1/products/{sku}/compatible-parts` and `GET /dtb/v1/parts/{sku}/compatible-tools` accept one URL path segment containing letters, digits, periods, underscores, and hyphens. `GET /dtb/v1/products/compatible-parts?skus=SKU1,SKU2` accepts a comma-separated list of 1–20 exact cart SKUs and an optional bounded result limit. The route validates the SKU syntax; WooCommerce remains the source of product identity.

The product-to-parts lookups use a bounded, case-insensitive metadata search only to find candidates. They then decode raw stored compatibility values and require byte-exact equality with the resolved WooCommerce tool SKU before returning a product owned by a published part. Request casing is resolved through WooCommerce identity; a case-only or whitespace target mismatch is not fitment evidence. Comma-separated text, JSON arrays, and WordPress array metadata are supported. A substring hit or a non-part owner is never fitment evidence.

When the requested tool SKU is a variation, the reverse lookup searches both that exact variation SKU and its published, visible, non-part parent SKU. A variation-specific edge remains exact to that variation; a parent-SKU edge is an explicit family-level claim and may be used only when approved evidence supports the relationship across the family. The parent is added as a second lookup identity, never substituted for or written in place of the selected variation SKU. A request for a parent product alone does not expand to its child variations.

An empty compatibility response means no published, catalog-visible matching relationship was returned from the current metadata. Published-but-hidden parts may still carry exact stored fitment links, so an empty response does not establish that no relationship is stored or that a part cannot fit a tool. Customer-facing fitment claims require verified canonical relationships and a visible product projection; the operator audit separates stored fitment from catalog visibility. Schematic brand/group metadata alone is insufficient.

The Product Detail page keeps family discovery, category discovery, operator-curated merchandising, kit contents, and compatibility as separate relationships. Tool-set products use their exact included-tool SKUs. Other products use explicit WooCommerce upsell IDs for operator-curated PDP placements. A `More from this product family` rail may show published, visible tool/toolset siblings when brand key, product kind, and `product_family` match exactly; series and category may differ. A separate `More in this category` rail may show published, visible non-part tools, toolsets, stilts, and accessories when brand key, product kind, and display-category key match exactly. Both are bounded, SKU-sorted, read-only discovery; neither represents a fitment, substitute, upgrade, or curated commercial recommendation. Parts are excluded from category peers, and part-family peers are never presented as replacement alternatives. The Product Relationships workbench exposes exact family matches as a read-only list, separate from editable upsell/cross-sell IDs. On tool pages, a separate `Compatible parts` rail reads approved canonical relationships through the existing compatible-parts endpoint. This is one bounded request per tool page, not a request per card. On part pages, the corresponding surface shows compatible tools. None of these discovery surfaces mutates WooCommerce upsell state.

`dtb-catalog-platform` returns exact compatibility candidates through the dedicated compatibility parts surface. The Product Detail `relatedProducts` value contains exact included-tool products for tool sets and explicit WooCommerce upsells for other products; `familyRelatedProducts` and `categoryRelatedProducts` are separate discovery projections. The family projection requires exact brand, kind, and product-family values. The category projection requires exact brand, kind, and display-category values and excludes parts. Neither asserts fitment, equivalence, replacement, or upgrade, and neither infers relationships from product names, descriptions, or fuzzy matching. The detail route does not fall back to generated `wc_get_related_products()` results. The catalog relationship-tree export records these edges as `same_product_family` and `same_catalog_category`, each with explicit source fields and a non-fitment discovery note. Manufacturer evidence that directly identifies a canonical part callout and tool model supports exact compatibility review. A variable part parent may inherit a tool fitment only when every published child variation is covered by that same manufacturer evidence. Exact canonical SKUs, evidence provenance, digest-bound approval, and the guarded MCP DTB runtime projector remain required.

For tool products, compatibility candidates are parts whose canonical `COMPATIBLE_TOOL_SKUS` or `REPLACEMENT_PART_FOR` metadata contains the exact tool SKU. The initial meta lookup is bounded and case-insensitive only for candidate discovery; every raw target value is re-read and byte-exact membership is required before it can enter the compatibility surface. This prevents substring matches and case-only target drift from becoming compatibility claims.

For part products, compatible tool SKUs already present in the normalized compatibility DTO are resolved through WooCommerce's indexed SKU lookup. Candidate count and the rendered compatibility surface remain bounded by the existing limit.

If no explicit PDP upsell or exact tool-set inclusion exists, the curated PDP recommendation rail is empty; a separate exact-fitment rail may still appear for a tool or part. Cart cross-sells remain WooCommerce-owned: the Store API cart response supplies native `cross_sells`. In addition, React requests a bounded batch of compatible parts for exact SKUs in the current cart through `GET /dtb/v1/products/compatible-parts`; the endpoint accepts at most 20 source SKUs, requires exact published source identity, skips part products as source tools, and returns only published catalog-visible part owners with the exact matched cart SKUs. Variable part families may include `compatible_part_variations`, each retaining its exact child ID, SKU, attributes, selection URL, price, availability, and exact matching cart SKU. A variable part is recommended only when an exact child SKU is verified; it links to that child selection and is never added to cart as its parent. The client requests up to 100 candidates before filtering cart duplicates and unavailable parts, then gives native cross-sells precedence and caps the rail at four. The current canonical catalog has at most 71 published part owners for any one tool SKU, so 100 covers current exact candidates while remaining within the endpoint bound. The cart makes one batch request, not one request per cart item, and does not persist recommendation state or relationship data. Add-to-cart actions re-enter the existing WooCommerce Store API cart flow.

The product-detail computed envelope may identify whether the recommendation is an exact tool-set inclusion or explicit upsell. That flag is presentation context only and is not a persisted compatibility relationship.

## Repair merchandising relationship

Repair marketing is presentation-only. `frontend/src/pages/RepairLanding.jsx` explains existing repair capabilities and links into the existing repair package, intake, and tracking routes.

The repair landing page must not introduce service states, turnaround promises, approval semantics, warranty decisions, shipping truth, or pricing that the `dtb-repair-service` domain cannot produce or enforce.

Repair-specific visual refinements used by `RepairLanding.jsx` are loaded after the base repair stylesheet and before responsive overrides so accessibility/contrast fixes participate in the active cascade without changing repair-domain behavior.

## Accessibility and performance

Contractor-intent navigation must preserve semantic links and headings, visible keyboard focus, mobile-first layouts, reduced-motion behavior, and non-hover access to information.

Tool-set contents summaries are supplemental text inside existing product cards and require no additional requests. Category merchandising renders from data already available to the route.

The merchandising layer must not introduce per-card network requests. Category intent and Tool Set summaries render from data already available to the route. Compatibility surfaces reuse the existing bounded compatible-parts endpoint: one request for a tool or part PDP, or one batch request for the cart. They do not add route-specific compatibility calls per card.

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

Relationship editor query bounds: the operator interface caps each upsell and cross-sell list at 50 targets. Loading and validating a source product's combined target IDs must use bounded WooCommerce CRUD batch reads, preserve saved list order, and retain missing records as visible conflicts. Validation errors fail closed before saving; WooCommerce CRUD remains the only merchandising writer.

Universal-part identity and membership are owned by the canonical `products/**/universal_parts/{parts,members,compatibility}.csv` seed records and `DTB_UniversalPartsProjectionService`. WooCommerce product metadata is a projection of those records, written only through WooCommerce product CRUD during the reviewed projection sync. Generic Parts Manager edits and generic parts CSV imports must not write those fields; populated legacy universal projection columns in an import are rejected with a row-level explanation. Generic exports omit projection fields so they cannot be accidentally round-tripped as editable source. Schematic mappings remain owned by the schematic workflow. Product/part merchandising recommendations (WooCommerce upsell/cross-sell IDs) remain separate from compatibility, universal equivalence, and schematic relationships.

## Variation-specific part fitment

WooCommerce part variations can have distinct protected SKUs and size/model identities. Current canonical evidence includes part-variation rows such as `AH3-2`, `AH3-2.5`, `AH3-3`, and `AH3-3.5` under part parent `AH3`. The review manifest marks candidate variation rows `review_part_variation_owner`; the current artifact builder rejects `Type=variation`, the live projection validator requires the relationship owner to be a parent product, and the compatible-parts metadata lookup searches only `product` records. A parent-wide edge cannot safely replace a child-specific edge because it would claim every sibling variation fits.

**Decision:** extend the existing `dtb-catalog-platform` fitment workflow to support an exact part variation as a relationship owner. The canonical catalog SKU remains the source identity. A variation-owned edge is stored only on that WooCommerce variation's existing `_dtb_compatible_tool_skus` metadata through WooCommerce CRUD, after verifying the exact variation SKU, published variation status, exact published part parent, and each exact published tool or tool-variation target. The existing approved artifact, digest, before-state conflict, per-record lock, private rollback report, and read-back contract apply to variation rows unchanged. Parent-owned fitment continues to mean an evidence-approved family-level claim; the engine must not copy parent edges to variations or infer a parent edge from one child.

The read APIs must query bounded published part parents and variations, verify raw SKU membership byte-for-byte after indexed candidate lookup, and retain the exact matched part variation identity when normalizing the display result to its WooCommerce parent. DTOs may expose matching variation SKU/ID/attributes so the customer PDP can select the exact compatible variant; variable products remain a product-page choice and must not be added to cart as a parent. Product-page and cart consumers must distinguish parent-family fitment from exact child fitment and keep the exact tool SKU that produced each match. Cross-sells remain directional WooCommerce records on parent products. Universal-part and schematic records retain their current owning services.

The coverage audit must report parent-part and part-variation denominators separately, detect child-level and parent-level overlaps/conflicts, and intersect review candidates against the appropriate exact source SKU. Existing runtime links are not migrated or rewritten. Rollback acts on the exact variation record that the release names. No variation relationship is approved or projected by this contract change alone.

Evidence for the implementation gap: `scripts/catalog/build_compatibility_projection_artifact.py` rejects variation source rows; `ProductRelationshipProjectionService::validate_runtime_records()` requires a `product` source; `DTB_CompatiblePartsController::find_products_with_skus_in_meta()` searches only `product`; and `normalize_product_ids()` collapses variations to parents without retaining the matched variation. The current workbench's part search returns only `product` owners. This is a contract extension within the existing catalog-platform owner, using WooCommerce's existing variation metadata and CRUD lifecycle; no new table, service owner, compatibility identity, or alternate writer is introduced.
