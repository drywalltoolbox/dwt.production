# Working Contract: Production Catalog Rebuild

## Status

Approved for implementation planning and deterministic migration generation. The official catalog remains the active canonical launch CSV until the rebuilt output passes every gate and is deliberately promoted.

## Objective

Rebuild the complete 755-row DTB catalog into a professional production contract for WooCommerce and read-only reconciliation with the existing Veeqo catalog and kits. Preserve protected identities while correcting taxonomy, schema semantics, controlled vocabularies, relationships, media, commerce metadata, toolset composition, and deterministic generation.

## Target taxonomy

```text
Taping & Finishing Tools
├── Automatic Taping Tools
│   ├── Automatic Tapers
│   ├── Flat Boxes
│   ├── Angle Heads
│   ├── Corner Finishers
│   ├── Angle Boxes & Corner Applicators
│   ├── Corner Rollers
│   ├── Nail Spotters
│   ├── Loading Pumps
│   ├── Goosenecks & Box Fillers
│   ├── Continuous Flow Tools
│   ├── Handles & Extensions
│   └── Tool Sets
└── Semi-Automatic Taping Tools
    ├── Semi-Automatic Tapers
    ├── Compound Tubes
    ├── Compound Applicators
    ├── Corner Flushers
    ├── Handles & Extensions
    └── Tool Sets
```

Replacement Parts, Brands, Stilts, product attributes, compatibility, schematics, and fixed toolset BOMs remain separate data dimensions or domains. They do not become children merely to fit this tool-shopping tree.

### Universal cross-brand taxonomy rule

- The taxonomy registry is universal for every brand. Stable taxon keys, parent relationships, customer labels, slugs, ordering, and publication policy cannot vary by brand.
- Brand is an orthogonal attribute/facet and must never become a product-category parent, duplicate branch, or brand-specific category alias.
- Product classification is based on the product's function and exact evidence, not its manufacturer. Equivalent product types from different brands map to the same taxon key.
- A brand is not required to occupy every category. Genuine assortment gaps remain empty rather than being filled through inferred or inaccurate assignments.
- Validation must emit a brand-by-taxon coverage matrix and reject any category path containing a brand label or any assignment referring to a taxon outside the universal registry.

## Identity invariants

- Preserve every existing SKU and its case unless a separately approved protected-identity correction supplies authoritative evidence.
- Preserve parent/variation relationships and variation SKUs.
- Preserve MPN/manufacturer SKU, GTIN, brand identity, external provider identity, and exact schematic/compatibility identifiers unless explicitly approved.
- Category aliases never resolve protected identity.
- A product may have multiple applicable category paths without duplicating the product row or SKU.
- Veeqo bundle and sellable identities remain unchanged; DTB does not recreate or overwrite the already-configured kits.

## Source and projection boundaries

```text
Approved canonical catalog sources under products/
  -> deterministic catalog assembler and validators
  -> WooCommerce import CSV projection
  -> WooCommerce runtime products and variations
  -> existing Veeqo exact-SKU inventory/order mapping

Canonical fixed toolset BOM source
  -> included-items storefront projection
  -> legacy _includes_* compatibility projection
  -> read-only Veeqo kit drift comparison
```

WooCommerce owns commerce and runtime product records. Veeqo owns inventory, allocation, kit expansion, picking, and fulfillment. The rebuild must not add another runtime catalog database, PIM authority, or component-order implementation.

## Required canonical contracts

1. **Product identity and commercial rows** — simple, variable, and variation identity; parent; protected identifiers; publication state; price/cost source; commerce mode.
2. **Taxonomy manifest** — stable category ID/key, customer label, path, parent, aliases, publishability, and indexability intent.
3. **SKU-category assignment manifest** — one or more exact target paths per owner SKU with evidence and disposition.
4. **Attribute dictionary** — stable key, label, type, unit, allowed values, applicable families, variation use, filterability, and source authority.
5. **Product attribute assignments** — normalized evidence-backed values; missing is preferable to invented.
6. **Media manifest** — exact asset identity, production URL, ownership/inheritance, ordering, content-type verification, and missing-media disposition.
7. **Relationship manifest** — replacement, compatibility, parent family, schematic identity, and exact resolution method.
8. **Toolset BOM manifest** — sellable toolset SKU, component SKU, explicit quantity, display label, position, BOM version, and deterministic hash.
9. **SEO projection** — product title/description/canonical/noindex fields consumed by the existing DTB SEO pipeline.
10. **Shipping/package evidence** — weight, dimensions, shipping-class policy, and source status without inventory authority.

## Controlled-vocabulary rules

- Product types remain Woo-compatible: `simple`, `variable`, `variation`.
- Variable parents are non-order-line presentation containers; variations carry sellable identity where applicable.
- Commerce modes must have one enforced vocabulary and matching Woo purchasability behavior.
- Toolsets remain one customer-facing Woo line and one exact Veeqo kit SKU.
- Automatic/semi-automatic and set type are explicit structured properties even when category paths also expose them.
- `Angle Heads` and `Corner Finishers` are separate customer-facing functional taxa. Historical combined paths are migration inputs only and require exact owner-SKU review.

## Mutation safety

- Preview is always the default.
- Every official CSV mutation requires an exact sibling `dtb_official_catalog.csv.bak` made immediately before writing and hash-verified against the input.
- Writes must be atomic, concurrency-safe, UTF-8 BOM/CRLF preserving, and field-allowlisted.
- A second identical run must produce zero changes.
- Any missing evidence remains unresolved in a review manifest; it cannot be inferred from fuzzy similarity or competitor terminology.
- Generated artifacts never silently replace their owning source.

## Promotion gates

The rebuilt CSV cannot replace the official catalog until:

- ordered schema, row count, SKU uniqueness, and parent/variation validation pass;
- all 755 rows have an explicit migration disposition;
- taxonomy assignment is deterministic and unresolved exceptions are approved or blocked;
- customer-facing category labels and stable keys project consistently;
- no staging/local media URL remains and every production URL returns an image content type, except approved unpublished/quarantined rows;
- commerce modes and Woo purchasability agree;
- prices/costs and nonpositive margins have approved dispositions;
- toolset BOMs reconcile to existing Veeqo kits by exact sellable SKU, component SKU, and quantity;
- generated inventory bootstrap files are excluded from live synchronization;
- shipping/package gaps have approved policy dispositions;
- audit, generator, taxonomy, media, BOM, and import-shape checks pass twice idempotently;
- an independent reviewer confirms no protected-identity or unrelated-field drift.

## P0 platform gates discovered during architecture review

These must be resolved before a rebuilt import artifact can be considered production-safe:

1. **Public full-catalog export exposure** — the current `/dtb/v1/products-csv` surface can stream the full import schema, including cost and internal metadata. The production import artifact must be private/admin/deployment-only. Any public export must use an explicit sanitized allowlist and must not expose cost, internal workflow metadata, protected integration identifiers, or import controls.
2. **Unpinned importer discovery** — catalog import must consume one explicitly selected release artifact and source hash. It must not discover and schedule whichever `product-*.csv` files are newest.
3. **Import concurrency and identity** — one release/import identity, one lease, persisted result, bounded retry semantics, and reconciliation are required. The importer must not broadly unschedule unrelated work.
4. **Veeqo inventory protection** — the rebuilt Woo import must not establish independent stock truth or overwrite live Veeqo projections. Catalog stock columns require a release policy that prevents static quantity/on-hand values from becoming operational authority.
5. **Multiple category membership projection** — the target permits evidence-backed dual placement for Handles & Extensions and Tool Sets. Backend DTO/facet contracts and frontend adapters must represent an ordered, deduplicated set of memberships rather than assuming exactly one group/child membership.
6. **Taxonomy validator cardinality** — deterministic validation must accept multiple approved canonical paths for one owner product while still rejecting ambiguous, unsupported, or conflicting paths. Variations inherit the exact ordered set from their parent.

## Initial migration policy

- Taxonomy label/path changes are allowed because the user approved a rebuild, but they require a complete old-to-new manifest and redirect/canonical/sitemap analysis before live import.
- Handles and toolsets may receive automatic, semi-automatic, or dual category assignments only from exact product, compatibility, or component evidence.
- `Continuous Flow Tools` is contract-valid; empty Woo term publication remains a deployment decision.
- Tool cases are classified at the top level, and Mud Pan Accessories are under Taping & Finishing Tools; neither uses a generic Accessories parent.
- Parts and stilts retain their current separate domains until their own approved taxonomy contracts are defined.
