# Product Specifications: Source, Import, and PDP Projection

## Ownership and flow

- `products/launch/official/dtb_official_catalog.csv` is the canonical launch source for `_dtb_specs_json` and WooCommerce product fields.
- WooCommerce owns persisted products and metadata. Its native CSV importer may ingest canonical `_dtb_specs_json` and legacy `_specs_{n}_label/value` rows.
- `DTB_CatalogSpecificationsCsv` filters prohibited duplicate identity rows before a native CSV import is saved. `DTB_CatalogProductNormalizer` applies the same rule when producing the product-detail DTO.
- The React catalog/API adapters can synthesize specification rows from SKU, product family, series, product type, weight, dimensions, condition, and non-Brand product attributes. The PDP reads canonical specs first, then indexed legacy specs, then a specification table in the description. Includes are enriched from structured include metadata. A schematic link may be appended separately when the product has one. `Product Type` and internal category/variation fields are filtered before display.
- This is a display/import policy. It does not delete the product's real SKU, MPN/manufacturer identity, product name, category, or display-category DTO fields.

## Customer-facing row policy

The following labels are identity/category duplicates and are forbidden inside PDP specification rows, regardless of capitalization, spacing, markup, or HTML entities:

- `Manufacturer SKU`
- `Official Product Name`
- `Display Category`
- `Display Category Key`

SKU and manufacturer identity remain in their WooCommerce-owned product fields. `Part Number`, `Model`, `MPN`, and similar legacy identifiers may be presented as the single `SKU` row by the renderer. `Brand` remains hidden in the technical-specifications table because the PDP presents brand separately.

The official catalog accepts arbitrary descriptive labels rather than a closed global vocabulary. Each row must be either `{ "label", "value" }` or `{ "label", "items" }`; item entries are `{ "name" }` or `{ "name", "sku" }`. This keeps uncommon tool-specific measurements possible while rejecting malformed structures and the prohibited duplicate labels.

## Audit snapshot

The official catalog contains 1,133 products and 4,444 canonical specification rows across 114 distinct labels. Before the exclusion change it contained 43 `Manufacturer SKU`, 22 `Official Product Name`, and 25 `Display Category` rows. The updated source contains none of those labels; all 1,133 products retain at least one specification row. `Brand` occurs 869 times and is intentionally hidden; `Part Number` occurs 1,026 times and remains available for SKU normalization.

## Verification boundary

Catalog validation enforces row shape and the prohibited-label policy. Frontend normalization and rendering enforce it again for canonical metadata, indexed metadata, API-derived attributes, and description-table fallbacks. The live product-detail endpoint is a separate runtime state: it must be checked after deployment because repository changes and catalog edits do not publish themselves.
