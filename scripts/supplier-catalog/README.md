# TSW authenticated supplier catalog scraper

This tool discovers products from TSW Fast brand catalog pages, opens every
individual product detail page, and exports the authoritative detail-page name,
part number, account-specific cost, manufacturer, and full product description.
It uses a dedicated local Chromium profile, so credentials and cookies are never
written to source files or the CSV.

Only use it with an account and catalog data you are authorized to access.
Keep request rates conservative and comply with the supplier's terms.

## Results layout

- `results/cost/` contains supplier-cost exports and cost-migration reports.
- `results/shipping/` contains TSW product-data extraction reports, catalog-match
  analysis, confirmed/review/unmatched subsets, and shipping-spec migration
  reports.

The source workbook and filtered DTB-brand product data remain under
`docs/reference/data/TSW/`; the results directories contain derived artifacts
only.

## Set up

From `D:\AMD\projects\launch-dtb`:

```powershell
python -m venv scripts\supplier-catalog\.venv
scripts\supplier-catalog\.venv\Scripts\python -m pip install -r scripts\supplier-catalog\requirements.txt
scripts\supplier-catalog\.venv\Scripts\python -m playwright install chromium
```

The tracked `catalog-sources.json` contains the requested Columbia Tools,
TapeTech, Dura-Stilts, SurPro, USG Sheetrock® Tools, and filtered TapeTech
EasyClean Automatic Taper Parts sources. Each source has a stable `source_name`,
an exported `brand`, and the complete TSW category `url`.

`exclude_name_contains` is an optional array of case-insensitive product-name
substrings. The EasyClean source uses `["Kit"]`; filtering happens only after
the individual product page supplies the authoritative product name.

## Sign in

```powershell
scripts\supplier-catalog\.venv\Scripts\python scripts\supplier-catalog\scrape_tsw_catalog.py --login
```

A browser opens at TSW's login page. Sign in normally, including any challenge
TSW requires. The script detects the authenticated session and stores it only
inside the ignored `.browser-profile` directory. Do not use a normal personal
browser profile for this tool.

## Scrape

```powershell
scripts\supplier-catalog\.venv\Scripts\python scripts\supplier-catalog\scrape_tsw_catalog.py
```

The live CSV is written to `scripts\supplier-catalog\results\cost\tsw-costs.csv` by
default and atomically refreshed after every processed product. It is always a
valid, readable CSV containing all successfully included rows completed so far.
The command exits nonzero if a session is logged out, a page fails, a duplicate
SKU conflicts, or a product price remains unresolved. `Call for price` is kept
as a valid nonnumeric supplier response and is reported separately.

Progress is also atomically checkpointed beside the live CSV after every
individual product, including products excluded by source rules. If TSW fails
or the session expires, rerun the same command after signing in; completed
products are resumed rather than requested again. The checkpoint is removed
only after the final CSV is written successfully.
Malformed supplier detail links automatically retry through TSW's stable
`/product/{part-number}` route.

Useful options:

```text
--headed                 show the browser while scraping
--delay-seconds 0.5      pause between individual product pages
--timeout-seconds 30     per-page/authentication timeout
--allow-missing-prices   export unresolved prices instead of failing
```

Overlapping catalog sources are collapsed by normalized brand and SKU only when
their supplier cost and currency agree. The exported CSV intentionally contains
only `brand`, normalized `sku`, `product_name`, `supplier_cost`, and
`product_description_html`. The scraper retains the additional supplier fields
internally for validation and duplicate detection but does not export them.
`supplier_cost` is serialized as an unquoted two-decimal USD number; the text
fields remain quoted for CSV safety.
Costs are supplier account data; keep generated output private.
Products whose names contain `kit`, `trowel`, `knife`, `knives`, `sand`,
`sponge`, or `smoothing blade` (case-insensitive) are excluded from every
supplier source and are not written to the CSV.

Every product is written as exactly one physical CSV line. Newlines embedded in
supplier descriptions and description HTML are converted to spaces at export;
the HTML elements themselves remain intact.

## Supplier cost evidence

`scripts/supplier-catalog/results/cost/tsw-costs.csv` is raw supplier evidence and is
not a WooCommerce import file. Do not rewrite its supplier SKUs or copy its TSW
namespace prefixes into canonical product identifiers.

The scraper removes TSW distributor namespace prefixes before writing the CSV.
Prefix removal is allowlisted by exported brand, never inferred:
`CTT` for Columbia Tools, `TTT` for current TapeTech products, `AME` for legacy
AMES products grouped into TSW's TapeTech category, `DSS` for Dura-Stilts,
`SUR` for SurPro, and `USG` for USG Sheetrock® Tools. The supplier CSV therefore
stores manufacturer part numbers, and the catalog analyzer never mutates canonical
SKU or MPN values. Cost evidence is projected only through confirmed catalog
mappings; it is not authority for retail price, product identity, inventory, or
fulfillment.

TSW currently exposes USG Sheetrock Tools through the dedicated
`https://www.tswfast.com/category/brand_USG` catalog route. Supplier listing
identifiers use the `USG` distributor namespace (for example `USG340506`), so
the cost export normalizes that evidence to the manufacturer identifier
(`340506`) before catalog matching. The global product-name exclusions still
apply; adding the USG source does not make knives, trowels, sanding products,
kits, sponges, or smoothing blades eligible for the cost export.

## Launch catalog matching analysis

Cross-reference the cleaned supplier catalog with the canonical launch catalog:

```powershell
scripts\supplier-catalog\.venv\Scripts\python scripts\supplier-catalog\analyze_launch_catalog_matches.py
```

The analysis confirms matches only when the supplier SKU uniquely resolves to a
brand-compatible protected catalog identifier. Exact, likely, and possible name
matches are emitted as review candidates and never promoted to confirmed product
identity. Results are written to `results/shipping/tsw-launch-catalog-match-analysis.csv`
with summary counts in `results/shipping/tsw-launch-catalog-match-report.json`.
Reviewed identifier exceptions are stored explicitly in
`approved-launch-catalog-matches.json`; the analyzer validates that each mapping
resolves to exactly one brand-compatible launch-catalog row.

If multiple confirmed TSW rows resolve to one catalog SKU, duplicate targets are
blocked from automatic shipping-spec migration. A unique direct identifier match
retains precedence over an overlapping manual approval; other duplicate targets
remain ambiguous for review.

For focused review, every analysis run also writes three temporary subsets under
`results/shipping/`: confirmed products, products with no match, and products requiring
review. Each subset retains the complete analysis schema and is regenerated from
the authoritative analysis statuses.

Preview TSW description and supplier-cost reconciliation against protected
catalog identifiers and explicit reviewed mappings with:

```powershell
scripts\supplier-catalog\.venv\Scripts\python scripts\supplier-catalog\migrate_confirmed_supplier_costs.py
```

Apply the reviewed exact/reconciled projection with the explicit `--apply` flag.
The migrator writes TSW `supplier_cost` to both WooCommerce's `Cost of goods` CSV
field (`cogs_value` when native Cost of Goods Sold is enabled) and BrikPanel's
`Meta: _brikpanel_cogs` field. It can also write supplier descriptions to
WooCommerce's `Description` field. Use `--costs-only` to update the two cost
fields without changing descriptions. `--identity` accepts the TSW product-data
crosswalk, which maps a cleaned cost SKU to its exact distributor `supplier_sku`
when the official catalog retains the distributor prefix. Matching prefers a
unique brand-scoped protected identifier, then that exact TSW crosswalk, then an
explicit reviewed mapping. Duplicate identical supplier rows are collapsed;
ambiguous source identities and conflicting supplier records targeting one
catalog SKU are reported and left unchanged. The audit report records matched,
unmatched, and conflicting rows without copying cost or description payloads
into the report.

The identity crosswalk is not a name-matching rule. It is valid only when the
TSW cleaned SKU uniquely identifies a TSW product row and that row's exact
`supplier_sku` uniquely resolves to a brand-compatible catalog identifier.
Mappings that contradict product names or dimensions must be removed from the
reviewed mapping file and retained as rejected evidence, not used to force a
cost or description onto a different product.

The official catalog and Veeqo export can only receive source-backed values for
products present in `tsw-costs.csv` and resolvable by these rules. Products with
no source row or unresolved identity remain unchanged and are listed in the
report; do not fill their cost or description by inference. The Veeqo projection
uses the official catalog `Description` and `Cost of goods` fields for
`description` and `cost_price` respectively.

The current full catalog COG reconciliation is emitted to
`results/cost/official-catalog-cogs-reconciliation.csv` and its summary to
`results/cost/official-catalog-cogs-reconciliation.json`. It accounts for all
simple products, variations, and variable parents, distinguishing source-verified
costs from existing costs that were not matched to the inspected source files,
unverified BrikPanel-only values, and products with no verified cost source.
The row-level file records the source SKU and match basis for verified values;
it does not treat an unverified existing cost as incorrect or change it.

## TSW shipping and product specifications

Extract the requested DTB brands from the read-only TSW product-data workbook:

```powershell
.\scripts\supplier-catalog\extract_tsw_product_data.ps1
```

The extractor validates the workbook schema and selects only explicit `TTT`
(TapeTech), `CTT` (Columbia Tools), `DSS` (Dura-Stilts), and `SUR` (SurPro)
product prefixes. It preserves every source shipping/specification field, adds a
derived `Brand` column, rejects duplicate product identifiers, and writes the
filtered CSV beside the source workbook with an audit report under `results/shipping/`.
The output also excludes trowels, knives, kits, sanders/sanding products, and
apparel while retaining tool-part uses of words such as `cap` and `short`.

After regenerating and reviewing the confirmed launch-catalog mappings, preview
the shipping/specification projection with:

```powershell
scripts\supplier-catalog\.venv\Scripts\python scripts\supplier-catalog\migrate_confirmed_shipping_specs.py
```

Apply the validated projection with the explicit `--apply` flag. The migrator
updates only the WooCommerce `Weight (lbs)`, `Length (in)`, `Width (in)`, and
`Height (in)` fields for unique confirmed catalog SKUs. Positive TSW values
replace catalog values; blank or zero TSW measurements clear unsupported values
for that exact confirmed product. Zero is an unavailable measurement sentinel,
not a physical shipping specification. Negative, nonnumeric, or nonfinite
measurements, duplicate targets, or missing targets stop the run. The audit also
reports catalog rows with no confirmed TSW identity match, by brand. An audit
report is written to `results/shipping/tsw-shipping-spec-migration-report.json`.

## Scoped USG draft-product enrichment evidence

For USG-only collection, use the existing tracked source definition instead of
scraping all configured brands. The `--full-evidence` option retains the exact
supplier product URL, image URL, price unit, currency, description HTML, and
UTC observation time in addition to the existing cost-export fields.
`--include-excluded-names` is explicit because the standard *cost-only*
workflow intentionally excludes knives, trowels, kits, sanding items, and
similar products. The default export behavior remains unchanged.

```powershell
scripts\supplier-catalog\.venv\Scripts\python scripts\supplier-catalog\scrape_tsw_catalog.py `
  --only-source "USG Sheetrock Tools" `
  --full-evidence --include-excluded-names `
  --output scripts\supplier-catalog\results\cost\usg-refresh.csv
```

Supplier authentication is required for account-specific costs. If the session
has expired, reauthenticate with `--login`; do not bypass login or infer a
current cost from public retail prices. The supplier output must be reconciled
by exact, brand-compatible SKU against existing WooCommerce draft products and
their variation SKUs. Parent product SKUs are not substitutes for variation
manufacturer part numbers. Check image URLs can be downloaded by WordPress
before linking/importing media. Treat missing supplier images, costs, or
descriptions as unresolved evidence, not as blank values to overwrite.
Preserve existing SKU/MPN/GTIN, retail pricing, inventory, publication status,
and all protected catalog identities. Generated USG account evidence under
`results/cost/usg*` is ignored and must never be committed to Git.

The public image-reference harvester works without TSW account access and does
**not** retrieve or infer wholesale costs. It lists every configured USG brand
catalog page and records supplier image and detail URLs, normalized by the
existing explicit `USG` prefix rule:

```powershell
python scripts\supplier-catalog\scrape_tsw_public_images.py `
  --source-name "USG Sheetrock Tools"
```

This writes `results/cost/usg-public-images.csv`. In the 2026-10-20 USG
reconciliation, the public brand listing exposed 126 supplier SKUs, and
81 source images uniquely matched the 81 existing simple WooCommerce draft
products. All 81 image URLs were independently checked for HTTP 200 and an
image content type. Existing stilt parent and variation galleries were retained.
The official launch catalog's `Images` field was reconciled by exact SKU to
the **WooCommerce-hosted** imported media URLs, not third-party hotlinks.
The preexisting long-form catalog descriptions for all 81 simple products
were independently compared against the live public TSW descriptions and
found text-equivalent; those long descriptions were intentionally preserved.
No supplier cost update was attempted with expired authentication evidence.

## TapeTech parts image export

Download the public TSW images for every exact SKU in the enriched TapeTech
parts source:

```powershell
python scripts/supplier-catalog/export_tapetech_part_images.py
```

The script reads `docs/reference/data/TSW/TSW Product Data - TapeTech Parts - Enriched.csv`,
matches its SKUs against the TSW TapeTech brand listing, then checks off-list
SKUs through TSW's stable part-number route. It downloads images only from the
observed TSW supplier asset namespaces and writes the original supplier image
filenames plus `results/images/tapetech-parts/tapetech-parts-image-manifest.csv`.
The manifest has one row per input SKU, source image and product URLs, and an
`image_status` of `downloaded`, `placeholder`, `unavailable`, or
`download_failed`. Placeholder images are not saved as product photography;
HTTP failures are retained in `download_error` for review. This is public image
evidence only and does not update WooCommerce or the official catalog.

## TapeTech image normalization and launch mapping

After exporting the public images, prepare the optimized local media set and a
narrow update-existing import file:

```powershell
python scripts/supplier-catalog/prepare_tapetech_media_migration.py
```

The normalizer prefers a public TSW `_L` image when that exact variant was
verified; otherwise it uses the downloaded listing image. It converts valid
images to WebP at their source dimensions without upscaling, preserves the
supplier image basename, and adds the unique assets to
`products/launch/media/media-10-23-2026/media`. It maps products by exact
TapeTech SKU, records unmatched and unavailable cases in
`results/images/tapetech-parts/tapetech-parts-migration-audit.csv`, and creates
`products/launch/in-works/tapetech_parts_images_update_existing_20261025.csv`
with only `Type`, `SKU`, and `Images` for exact existing launch products that
have no image assigned. It does not rewrite the official catalog or apply the
WooCommerce import.

If enhanced generated part images are available in
`products/launch/media/regenerated_images_webp`, replace the corresponding
TSW-derived media files after normalization with:

```powershell
python scripts/supplier-catalog/replace_tapetech_images_with_regenerated.py
```

The replacement step requires an exact supplier image-basename match, then
uses the audited SKU-to-media mapping so `_M` and `_L` destination names remain
correct. It validates WebP integrity and dimensions, backs up each original
file under `results/images/tapetech-parts/original_webp_before_generated`, and
writes a replacement audit. Other media files and WooCommerce records are not
changed.

## Catalog photo-coming-soon placeholder

Normalize the source placeholder, place it in the active launch media folder,
and map it to catalog products with no image:

```powershell
python scripts/supplier-catalog/prepare_catalog_image_placeholders.py
```

The script writes a 1200x1200 WebP, updates only blank `Images` cells in the
official catalog, and creates a narrow `Type`, `SKU`, `Images` update-existing
CSV. A variation with a populated parent gallery is left blank so the
storefront continues to use the parent image. The pre-update catalog and a
row-level assignment audit are saved under `results/images/placeholder/`.
