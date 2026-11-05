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

Prepare proposals for all brands with:

```powershell
python .\scripts\catalog\prepare_schematic_compatibility_proposals.py
```

The script is read-only and combines:

1. canonical product identity from `products/launch/official/dtb_official_catalog.csv`;
2. exact part-to-schematic occurrences from `products/launch/universal_parts/references/all_brands_schematic_parts_master.csv`;
3. purchasable product-to-schematic identity from `frontend/src/data/productSchematicLinks.generated.js`.

Canonical part identity follows the same semantic contract as the enrichment audit: `Meta: _dtb_product_kind=part` is authoritative, while `Meta: _dtb_is_parts` remains supported as a legacy/import signal. The workflow never infers part identity from names or fuzzy taxonomy.

It never fuzzy-matches part names. Schematic-linked tool variations remain exact target SKUs. When a variable parent and one or more exact child variation SKUs share the same canonical schematic, the candidate index drops the broader parent target and keeps the variations. A parent target remains only when no exact child variation is linked to that schematic. Multiple exact tool SKUs remain review-only; they are not collapsed into one family claim. Proposals are classified as:

- `proposal_exact` — an exact canonical part SKU and one exact tool product/variation SKU share a schematic identity;
- `review_multi_tool` — the schematic maps to multiple exact tool product/variation SKUs;
- `review_no_tool` — the part occurrence is known but no canonical non-part tool SKU resolves for that schematic;
- `review_part_unpublished` — the exact part identity is in the catalog but is not published, so it cannot be a current projection owner;
- `review_part_variation_owner` — the exact part identity is a variation; it remains exact and review-only because the current projection requires a published parent-product owner;
- `already_populated` — all exact schematic tool SKUs already exist on the canonical part; partially populated parts still receive proposals for missing exact tool SKUs.

Generated artifacts live under:

```text
products/dev/catalog-enrichment/compatibility/
├── schematic-compatibility-proposals.csv
├── schematic-compatibility-summary.json
├── schematic-compatibility-unresolved.csv
└── schematic-cross-brand-identity-candidates.csv
```

These are proposals only. The unresolved report preserves raw source identifiers and source-file provenance. The cross-brand identity report surfaces exact part-identifier collisions against a different catalog brand as review-only conflicts; it does not map identities or propose fitment. A separate reviewed apply step must validate target tool SKUs and allowlist only `Meta: _dtb_compatible_tool_skus` / `Meta: _dtb_replacement_part_for` before catalog mutation.

Generate a canonical-source coverage and conflict summary after refreshing proposals:

```powershell
python .\scripts\catalog\audit_product_relationship_coverage.py
```

The schema-v12 report defines published part and non-part product denominators from the canonical catalog and separately reports structured-fitment presence by exact catalog brand and catalog-visibility value, unresolved exact targets, noncanonical SKU spelling, surrounding whitespace, blank target references, malformed relationships, overlapping fitment fields, maximum published-part owner fan-out for an exact target SKU, native upsell/cross-sell source coverage, schematic proposal states, unresolved schematic inputs, and exact cross-brand identity conflicts. The fan-out metric counts each published part owner once per exact, uniquely resolved target spelling and helps check consumer result bounds; it is a conservative count and does not itself validate target type or approve fitment. The visibility breakdown distinguishes direct-only hidden parts from parts available in ordinary catalog placements; it does not change visibility or infer incompatibility. It validates both review manifests against current catalog and row digests, reports stale/invalid approvals and owner/target identity issues, and breaks manufacturer evidence references down by `supports`, `context_only`, or `unclassified`. Its coverage pipeline intersects current digest-bound review candidates with published parts that have no structured fitment and lists missing-fitment SKUs that have no current review candidate, grouped by brand. It also lists uncovered exact part SKUs found in unresolved schematic rows where the target tool schematic identity is missing, without assigning a target or promoting those occurrences to fitment candidates. Candidate presence means a review item exists, not that fitment is true or approved; stale manifest rows are excluded from this intersection. It explicitly marks live WooCommerce projection state as unaudited; runtime reconciliation requires a separate bounded live export. Missing relationships are reported as missing structured data, never as incompatibility.

To convert `proposal_exact` and `review_multi_tool` rows into a digest-bound review manifest, run:

```powershell
python .\scripts\catalog\build_schematic_compatibility_review_manifest.py
```

This groups exact schematic occurrences by canonical part SKU, unions exact tool SKU targets without dropping existing values, and sets every row to `needs_review`. Existing compatibility targets and proposal/evidence SKUs must already match their exact canonical spelling; the builder fails closed on case changes, whitespace, duplicates, or detached identities instead of normalizing them. Multi-tool schematic occurrences remain explicitly labeled `multi_tool_schematic_review` and every target stays `needs_review`; shared schematic identity is a candidate signal, not proof that each tool variant fits. Optional manufacturer evidence in `products/dev/catalog-enrichment/compatibility/schematic-manufacturer-evidence.csv` is merged into the same row only when its exact part/tool SKU pair already exists in a schematic review candidate. Each source is classified as `supports` or `context_only`; contextual sources help reviewers resolve scope but never count as support for the proposed target. The builder validates source URLs and rejects duplicate or detached references; it never creates another relationship proposal or edits the catalog. Review all cited sources, then record one approval with:

```powershell
$manifest = Resolve-Path <path>
$manifestSha256 = (Get-FileHash $manifest -Algorithm SHA256).Hash.ToLower()
python .\scripts\catalog\approve_catalog_change_manifest_row.py --manifest $manifest --expected-manifest-sha256 $manifestSha256 --sku <part-sku> --reviewer <name> --reviewed-at 2026-11-04T12:00:00Z
```

The approval tool binds catalog/row digests, field, current/proposed values, evidence, reviewer, timestamp, and approval status into `approval_sha256`. It also compares the supplied manifest digest while holding a per-manifest process lock and prints the new manifest digest after approval. Re-read and review the updated manifest, then calculate a fresh digest before approving another row; stale concurrent approvals fail without replacing the newer file. Editing any approval-bound payload afterward invalidates approval. Preview the resulting change set with `apply_official_catalog_changes.py --manifest <path>` before any explicit apply. On apply, the exact manifest bytes are archived beside the source as `<name>.approved-<sha-prefix>.csv`, and the JSON report records both the original path and archive digest. Projection artifact creation verifies and uses that immutable archive if the working manifest has since been regenerated. Compatibility target values must match the exact canonical SKU spelling; case-insensitive lookup is used to find the candidate record, then exact stored identity is required before catalog apply and again before artifact creation. No-tool/unresolved proposal rows remain in the proposal and diagnostics artifacts. Multi-tool rows can enter only as visibly labeled review candidates and cannot be projected without explicit approval.

Manufacturer documentation can support a separate exact-identity review queue when the source document itself names the model and replacement part numbers. Record one evidence row per part/tool edge in `products/dev/catalog-enrichment/compatibility/documented-compatibility-evidence.csv`, then run:

```powershell
python .\scripts\catalog\build_documented_compatibility_review_manifest.py
```

The builder validates both exact canonical SKU spellings, publication state, part/tool classification, evidence URL, and required source locator/note against the canonical catalog. It preserves existing target identities and rejects noncanonical existing values rather than rewriting them. It creates only digest-bound `needs_review` rows and never mutates the catalog. A human must inspect the cited document and approve rows with `approve_catalog_change_manifest_row.py` before the standard preview/apply/projector workflow. The current USG brochure review queue records hopper SKU `340311` with gate SKUs `340316`, `340317`, and `340318`; it is not applied to canonical or runtime product data.

The documented-evidence builder has focused tests for review-only output, existing-edge preservation, owner/target eligibility, duplicate evidence pairs, and HTTPS source validation: `python -m pytest scripts/catalog/tests/test_build_documented_compatibility_review_manifest.py`.

### Narrow WooCommerce fitment projection

Fitment publication uses a narrow projection artifact. First apply only explicitly approved manifest rows and preserve the JSON report:

```powershell
python .\scripts\catalog\apply_official_catalog_changes.py --manifest products/dev/catalog-enrichment/compatibility/schematic-compatibility-review-manifest.csv --apply --report products/dev/catalog-enrichment/compatibility/compatibility-catalog-apply-report.json
python .\scripts\catalog\build_compatibility_projection_artifact.py --manifest products/dev/catalog-enrichment/compatibility/schematic-compatibility-review-manifest.csv --catalog-apply-report products/dev/catalog-enrichment/compatibility/compatibility-catalog-apply-report.json
```

The builder prints a release ID, release-manifest path, and one or more release-scoped artifact paths with SHA-256 digests. Review those exact batch files and move the selected batch to a private path readable by the production WordPress CLI. Batch filenames include the release ID so generating another release cannot replace an artifact referenced by an older manifest; a repeated release build refuses to replace different bytes at an existing release path. Preview first; apply only with the exact reviewed file digest:

```sh
wp dtb relationships project-fitment /private/release/compatibility-projection-artifact.json --sha256=<reviewed-sha256>
wp dtb relationships project-fitment /private/release/compatibility-projection-artifact.json --sha256=<reviewed-sha256> --apply
```

The live operation is update-existing only, limited to 25 parts and 50 unique tool targets per artifact. It checks each source and target against the exact canonical SKU string (not only a case-insensitive WooCommerce lookup), requires a published part owner and either a published tool product or exact published variation whose parent is a published non-part tool, refuses runtime drift, locks source, target, and variation-parent products, writes rollback evidence before the first mutation, reads values back through WooCommerce CRUD, and verifies both public compatibility routes against the exact target SKU. Reports default to `dirname(realpath($_SERVER['DOCUMENT_ROOT']))/.dtb-private/product-relationships`; production may override this with `DTB_RELATIONSHIP_PRIVATE_DIR`. The projector creates and permission-restricts the directory, then fails closed unless it resolves outside both the WordPress and web document roots. Rollback reports are private and include a SHA-256. Roll back only with that report and its current digest:

```sh
wp dtb relationships rollback-fitment /path/printed/by/the/command.json --sha256=<current-report-sha256>
```

Read release reconciliation after processing all generated shards:

```sh
wp dtb relationships release-status <release-id>
```

Before the first production projection, verify the private report path with `wp dtb relationships check-storage`.

The private report marks a release `complete` only after every expected shard is applied or already applied; it records failed and rolled-back shard outcomes as well.

Run the focused release-ledger transition check locally with:

```powershell
php .\scripts\catalog\tests\product_relationship_projection_state.php
```

No `Upsells` or `Cross-sells` field is writable through the catalog manifest workflow or fitment projection. WooCommerce CRUD remains the merchandising authority.

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
