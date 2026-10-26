# DTB Official Catalog Production-Readiness Audit

Generated: `2026-10-26T12:39:16.959169+00:00`

Catalog: `products/launch/official/dtb_official_catalog.csv`  
Catalog SHA-256 (working bytes): `38b3933e29f442aeaeaef6bea109b49d953ac9067f4e69842dc7fba3785f36cf`

Catalog SHA-256 (normalized LF): `7c5d1f1c91485215973d984d77735c1bd7a91b1bcc758cccebb4edfe2c1a4a77`

## Verdict

**NOT READY FOR PRODUCTION IMPORT** — 1402 blocker finding rows across 1134 product SKUs.

The CSV is structurally valid, but it is not approved for production import until production URL and commerce-mode blockers are resolved and the approved result is revalidated.

## Evidence summary

- Rows: 1134
- Columns: 151
- Structural validator: passed
- Owner rows: 820
- Variation rows: 314
- Total consolidated findings: 7321
- Blocker findings: 1402
- Review findings: 5919
- Catalog file mutated by this audit: no
- Existing sibling backup matches the audited catalog: false

## Highest-volume findings

| Finding | Count |
| --- | ---: |
| `missing_slug` | 1134 |
| `catalog_quantity_requires_veeqo_authority_review` | 1028 |
| `description_long_for_class` | 702 |
| `claim_needs_evidence:oem_or_genuine` | 560 |
| `repetitive_language:job_site` | 427 |
| `compatibility_proposal_exact` | 422 |
| `shippable_missing_weight` | 347 |
| `part_family_without_compatibility_or_replacement` | 314 |
| `claim_needs_evidence:industrial_grade` | 302 |
| `repetitive_language:downtime` | 275 |
| `claim_needs_evidence:precision_manufacturing` | 267 |
| `claim_needs_evidence:material_quality` | 262 |
| `claim_needs_evidence:guarantee` | 221 |
| `repetitive_language:professional_drywall` | 218 |
| `repetitive_language:vital` | 148 |
| `invalid_import_enum` | 137 |
| `seo_title_long` | 87 |
| `unsupported_commerce_mode` | 81 |
| `claim_needs_evidence:performance_superlative` | 69 |
| `claim_needs_evidence:productivity_claim` | 68 |

## Previous-version consolidation

- Baseline commit: `c28749d057865a1a0a736847a8f4933c73e3b563`
- Catalog-change commit: `76d0fdeab59f6d679315fef518c5e8e4a56162aa`
- Added/removed SKUs: 0/0
- Changed SKUs / field values: 462/778
- Protected identifier changes: 67
- Field changes: {"Categories": 409, "Description": 1, "Images": 89, "Meta: _dtb_category_key": 55, "Meta: _dtb_display_category_key": 62, "Meta: _dtb_is_parts": 66, "Meta: _dtb_product_kind": 67, "Name": 28, "Short description": 1}

The historical enrichment baseline was taxonomy-only. The current comparison additionally includes the bounded P0 schematic-URL, attribute-default, and image-inheritance corrections; row count, schema, SKU population, and protected identifiers remain preserved. Current taxonomy preview reports zero changes and zero unresolved rows.

## Production evidence and ownership gaps

- Catalog image assignments: 2233 across 1271 unique URLs; all populated assignments still use `elliottm4.sg-host.com`.
- Production media candidates: not run valid and not run invalid by HTTP status/content type.
- Local media coverage: 1271 of 1271 catalog basenames present; 115 local files unused.
- Physical data coverage: 679 rows with weight; 390 rows with all dimensions; shipping class is unconfirmed catalog-wide.
- Generated Veeqo bootstrap projection comparison: 1028 shared, 0 catalog-only, 106 projection-only; direct source-field differences: {"price": 98, "primary_image": 378}.
- `veeqo_inventory.csv` is a generated bootstrap/import projection, not a live Veeqo export or synchronization authority. Do not import it over the already-configured live Veeqo catalog; validate bundle composition and stock through read-only live reconciliation.
- Compatibility research: 422 exact proposals across 236 parts/27 schematics; 2764 unresolved evidence rows remain.
- Content review: 1192 accuracy findings across 637 SKUs; 1962 editorial findings; no automatic copy application is approved.

## Exact bounded exception sets

- `nonpositive_gross_margin` (25 SKUs): `340800`, `BH11`, `BH26`, `CFB15A`, `CR-3`, `CR1`, `CT101`, `CT104`, `CT76`, `FFB25-10`, `FFB25-12`, `FFB32A`, `FFB7`, `FFB7A`, `FFB9-14`, `FFB9-5.5`, `FFB9-7`, `FFB9-8`, `HNS10`, `HNS10A`, `HNS13`, `HP46`, `MP23`, `MP32`, `MP39`
- `structured_part_number_identity_mismatch` (1 SKUs): `PT-GN`
- `variation_gallery_equals_parent_without_inheritance` (22 SKUs): `340925`, `DSS1422US`, `S1-A-1624`, `S1-A-2030`, `S1-A-2640`, `S1-M-1624`, `S1-M-2030`, `S1-M-2640`, `S1X-A-2131`, `S1X-A-2640`, `S1X-M-2131`, `S1X-M-2640`, `S2-A-1624`, `S2-A-2030`, `S2-A-2640`, `S2-M-1624`, `S2-M-2030`, `S2-M-2640`, `S2X-A-2640`, `S2X-A-3852`, `S2X-M-2131`, `S2X-M-2640`
- `sibling_variations_share_explicit_gallery` (8 SKUs): `SP-S1-A`, `SP-S1-M`, `SP-S1X-A`, `SP-S1X-M`, `SP-S2-A`, `SP-S2-M`, `SP-S2X-A`, `SP-S2X-M`

## Mutation-workflow safety

The bounded P0 correction workflow created and hash-verified the required sibling `dtb_official_catalog.csv.bak` immediately before its write. The generic manifest apply runner remains unauthorized until its separate backup and semantic-validation gaps are addressed.
The sibling backup is the pre-P0 rollback snapshot and is therefore expected not to match the successfully mutated current catalog.
- `manifest_blank_erasure_risk`: The generic manifest applier can accept a blank proposed value and overwrite a populated catalog cell without field-specific evidence or an explicit clear-value operation.
- `mutation_runner_backup_contract_gap`: The runner uses a disposable rollback directory and invokes child appliers with --no-backup; it does not retain the user-required sibling dtb_official_catalog.csv.bak.
- `apply_taxonomy_validation_gap`: The non-taxonomy mutation path does not make the taxonomy validator a mandatory post-write gate.
- `generated_output_containment_gap`: The enrichment runner removes and recreates generated output paths without an explicit resolved-path containment assertion.
- `manifest_semantic_validation_gap`: The generic applier validates compatibility semantics but does not enforce equivalent media, shipping, commerce-mode, pricing, or editorial field contracts before writing.

## Required disposition order

1. Decide and document the canonical `_dtb_commerce_mode` mapping, including whether priced `quote_only` parts are intended to be purchasable.
2. Verify every referenced media asset on the production host, then replace staging-host URLs through an exact-SKU approved manifest.
3. Resolve objective price/MAP/publication blockers and confirm shipping/inventory ownership and runtime projections.
4. Resolve the single missing-image SKU using authoritative media.
5. Complete claim-accuracy review before editorial/SEO rewriting.
6. Review compatibility proposals; do not apply generated proposals without approval.
7. Re-run the full audit, structural validation, taxonomy preview, and catalog test suite before any WooCommerce import.

## Scope boundaries

This audit establishes CSV and repository evidence only. It does not prove WooCommerce import behavior, live payment/cart behavior, Veeqo synchronization, deployment, or production rendering.
