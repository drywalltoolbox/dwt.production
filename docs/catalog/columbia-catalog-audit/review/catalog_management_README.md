# Columbia catalog management JSON

[`catalog_management.json`](../current-checkpoint/catalog_management.json) is a generated, searchable view of the current [Columbia checkpoint CSV](../current-checkpoint/dtb_columbia_catalog_official.csv). It is not another catalog authority or an approved import. The CSV preserves the selected product rows; this file groups their data for reading and tracking review work.

## Structure

- `catalog` describes the working source, its SHA-256, currency, pricing convention and all 151 CSV column names.
- `summary` counts product rows, product types, match dispositions, category-review states, populated image and shipping fields, recorded-cost comparisons and duplicate SKUs. Counts describe the CSV snapshot, not live WooCommerce or verified shipping data.
- `products` is an array so duplicate SKUs are never collapsed. Each record shows its SKU, name, type, manufacturer page and classification when safely linked, matching and category review status, per-product field-review counts and recorded decisions, TSW shipping evidence where available, flags, grouped configuration, and parsed structured specs/features where available. The raw populated CSV values remain in `configuration`; missing fields are blank/omitted rather than invented.

Manufacturer platform payloads, product/variant platform IDs and scrape timestamps are not included. A manufacturer product page remains as a useful source reference. Existing commerce sale-date fields, if populated, are retained because they are product configuration rather than scrape timestamps.

`CT45-BLACK` has two source records. Both remain in the array and are flagged as duplicate. The source image list distinguishes their reference pages in the current snapshot; this does not make the duplicate SKU import-ready.

`summary.sale_below_recorded_cost_in_checkpoint` compares the price and cost present in this CSV. `summary.flagged_for_supplier_cost_review_in_reconciliation` also includes earlier match-review flags, so the two counts can differ when a candidate row has no cost populated. Neither establishes current supplier cost or actual margin.

## Refresh and safety

From the repository root, check both the checkpoint and management view with:

```powershell
python docs/catalog/columbia-catalog-audit/tools/refresh_current_checkpoint.py
```

Refresh both after updating the manufacturer-first working CSV:

```powershell
python docs/catalog/columbia-catalog-audit/tools/refresh_current_checkpoint.py --refresh
```

The checkpoint refresh generates the management JSON from the refreshed CSV and the matching/category evidence. Its [management manifest](catalog_management-manifest.json) records input and output hashes without adding timestamps to the product file. Matching evidence is marked stale in the JSON summary if its recorded input snapshots no longer match.

The JSON is a generated view. Record field-review decisions in the matched field-review CSV and make durable catalog changes in the working CSV and review sources, then refresh; direct edits to the generated JSON are not merged back. To protect such edits, refresh refuses to overwrite a modified management JSON or checkpoint CSV. A checkpoint mismatch, changed inputs, or missing manifest is reported rather than silently treated as current.
