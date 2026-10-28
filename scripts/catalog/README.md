# Catalog Tooling

## Production entrypoint

Use one command for routine official-catalog quality/enrichment preparation:

```powershell
.\scripts\catalog\run-official-catalog-enrichment.ps1
```

Default mode is non-mutating. To apply reviewed deterministic safe fixes before the audit/preparation stages:

```powershell
.\scripts\catalog\run-official-catalog-enrichment.ps1 -ApplySafeFixes
```

`-ApplySafeFixes` applies only bounded deterministic mutations before revalidation:

1. stale explicit SEO canonical overrides are cleared so the active storefront route remains canonical authority.

Category validation is read-only. It checks paths and variation inheritance without rewriting catalog assignments.

## Universal taxonomy contract

Catalog classification is based on product semantics, never manufacturer identity. Every brand uses the same mapping contract.

- `Brands` owns manufacturer identity. Brand names must not become category namespaces.
- `Meta: _dtb_category_key` is the broad DTB functional category.
- `Meta: _dtb_display_category_key` is the customer-facing discovery/filtering class and may also represent a cross-cutting family or merchandising grouping.
- Broad category, display category, product family, and manufacturer are separate dimensions.
- Brand-specific or SKU-specific taxonomy maps are prohibited. New brands must work without taxonomy code changes.
- Unknown or ambiguous classifications remain unchanged and are surfaced for review rather than guessed.
- Category paths use separate hierarchy terms, such as `Drywall Finishing Tools > Semi-Automatic Tools > Compound Tubes`; parent and leaf names are never flattened together.

The runtime backend `DTB_CategoryNormalizer` remains the application-side category resolver. Catalog tooling must stay semantically aligned with that contract; the frontend consumes backend category/display-category DTOs and does not become a classification authority.

## Core internal modules

These files implement the unified run and are not separate product-data authorities:

- `official_catalog_schema.py` — canonical ordered schema and blocking validation rules.
- `validate_official_catalog.py` — CLI entrypoint for structural validation.
- `catalog_taxonomy_policy.py` — read-only taxonomy audit helpers.
- `audit_official_catalog_enrichment.py` — actionable quality audit and taxonomy review classification.
- `catalog_seo_pre_generation.py` — evidence-bounded SEO/content preparation and finding authority.
- `clear_legacy_seo_canonicals.py` — narrow deterministic canonical safe-fix implementation used by the unified runner.
- `official_catalog_schema.py` — shared atomic-write and rollback primitives used by catalog mutation commands.

## Schematic compatibility proposal workflow

Compatibility enrichment is a separate evidence workflow because it depends on authoritative schematic identity and can create many-to-many product relationships. The routine enrichment runner measures the gap but does not invent or write compatibility.

Prepare Columbia proposals with:

```powershell
python .\scripts\catalog\prepare_schematic_compatibility_proposals.py
```

The script is read-only and combines:

1. canonical product identity from `products/launch/official/dtb_official_catalog.csv`;
2. exact part-to-schematic occurrences from `products/launch/universal_parts/references/all_brands_schematic_parts_master.csv`;
3. purchasable product-to-schematic identity from `frontend/src/data/productSchematicLinks.generated.js`.

Canonical part identity follows the same semantic contract as the enrichment audit: `Meta: _dtb_product_kind=part` is authoritative, while `Meta: _dtb_is_parts` remains supported as a legacy/import signal. The workflow never infers part identity from names or fuzzy taxonomy.

It never fuzzy-matches part names. Tool variations collapse to a valid non-part family parent where possible. Proposals are classified as:

- `proposal_exact` — exact canonical part SKU plus one canonical tool family share a schematic identity;
- `review_multi_tool` — the schematic maps to multiple canonical tool families;
- `review_no_tool` — the part occurrence is known but no canonical non-part tool SKU resolves for that schematic;
- `already_populated` — the canonical part already contains compatibility/replacement metadata.

Generated artifacts live under:

```text
products/dev/catalog-enrichment/compatibility/
├── schematic-compatibility-proposals.csv
└── schematic-compatibility-summary.json
```

These are proposals only. A separate reviewed apply step must validate the target tool SKUs and allowlist only `Meta: _dtb_compatible_tool_skus` / `Meta: _dtb_replacement_part_for` before catalog mutation.

## Content review queues

`catalog_seo_pre_generation.py` remains the sole finding classifier. Do not duplicate its claim or editorial detection rules. After a unified catalog run, convert its artifacts into review queues with:

```powershell
python .\scripts\catalog\prepare_content_review_queue.py --workflow accuracy_review
```

Only after accuracy/evidence review has been resolved should the editorial queue be processed:

```powershell
python .\scripts\catalog\prepare_content_review_queue.py --workflow editorial_review
```

The queue generator joins each finding to the existing generation packet so reviewers receive brand, product identity, MPN, schematic identity, compatibility state, specification count, protected-identity digest, and the current source copy. It does not research, rewrite, or mutate the catalog.

Review queues preserve all findings but explicitly segment `generation_eligible` rows from variations/non-indexable rows. Summary workload metrics must use that segmentation so a variation finding is not misreported as an independent customer-facing PDP remediation target. Queue rows also retain product type and parent SKU for family-level review.

Generated artifacts live under:

```text
products/dev/catalog-enrichment/content-review/
├── accuracy-review-queue.csv
├── accuracy-review-summary.json
├── editorial-review-queue.csv
└── editorial-review-summary.json
```

Manufacturer research may validate or reject a claim, but generated/researched text must never modify SKU, MPN, GTIN, brand, taxonomy, variation identity, schematic identity, or other protected fields.

## Category thumbnail background removal

Use `generate-category-thumbnails.py` to rebuild the canonical storefront assets from the selected high-resolution product media. It removes edge-connected studio backgrounds conservatively, trims excess source canvas, preserves each tool's natural aspect ratio, and centers the result on an optimized 512×512 transparent WebP canvas for predictable category-card fitment.

```powershell
python .\scripts\catalog\generate-category-thumbnails.py
```

Use `remove_category_thumbnail_backgrounds.py` only for a reviewed migration of other baked-background inputs. It uses `rembg` with the `isnet-general-use` model by default, reuses one ONNX inference session across the batch, enables alpha matting for metallic/tool edges, trims exterior transparency, preserves each tool's natural aspect ratio, writes files atomically, and emits a JSON QA report.

Install the isolated runtime dependencies:

```powershell
python -m pip install -r .\scripts\catalog\remove_category_thumbnail_backgrounds.requirements.txt
```

Preview the batch without loading the model or writing files:

```powershell
python .\scripts\catalog\remove_category_thumbnail_backgrounds.py --dry-run
```

Generate a non-destructive review batch. By default the source directory is `products/launch/media/categories/thumbnails/` and output goes to the sibling `thumbnails-transparent/` directory:

```powershell
python .\scripts\catalog\remove_category_thumbnail_backgrounds.py
```

Review every output and the generated `products/dev/media/category-thumbnail-background-removal-report.json` before replacing canonical thumbnail media. After approval, the existing WebP files may be processed atomically in place:

```powershell
python .\scripts\catalog\remove_category_thumbnail_backgrounds.py --in-place
```

`--in-place` intentionally accepts only WebP source files. The tool continues through per-image failures, records each failure in the report, and exits non-zero when any image fails. Model-based isolation is not a substitute for visual QA: thin cables, chrome edges, holes, pale components, and cast shadows remain the highest-risk cases.

## Specialized operational tools

Keep specialized tools separate when they have distinct authorities or failure modes, including:

- schematic compatibility proposal/reconciliation;
- evidence-backed content accuracy/editorial review;
- Veeqo shipping/inventory projection tooling;
- competitor price research and endpoint diagnostics;
- media cleanup/conversion/gallery synchronization;
- schematic mapping/reconciliation;
- WooCommerce export/gallery normalization.

These tools are not automatically part of the full enrichment run.

## Lifecycle rule

One-off repair/migration scripts should not accumulate indefinitely in this directory. After a narrowly scoped migration is completed and no active workflow imports or invokes it, remove the script and retain the durable contract in validation, normalization, tests, or documentation as appropriate.

Generated reports and research artifacts belong under `products/dev/` or another documented derived-data location, not in `scripts/catalog/`.
