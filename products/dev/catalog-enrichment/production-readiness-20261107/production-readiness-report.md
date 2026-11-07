# DTB Official Catalog Production-Readiness Audit

Generated: `2026-11-07T12:09:55.254547+00:00`

Catalog: `products/launch/official/dtb_official_catalog.csv`  
Catalog SHA-256 (working bytes): `c1e95a17b5bf9b88616597a081946ade1a8ebdb8aa94b90f9f19ec0100223fcb`

Catalog SHA-256 (normalized LF): `232209d3eab7c180a2427e6318b13d76c71ab501465167996018b9a5d4b72b10`

Margin and Veeqo findings are retired from this audit scope at the user's direction. They are not evaluated or included in blocker, review, or advisory totals.

## Verdict

**NOT READY FOR PRODUCTION IMPORT** — 1385 blocker finding rows across 1133 product SKUs.

The CSV is structurally valid, but it is not ready for production import while any active blocker findings remain. Margin and Veeqo checks are excluded by user direction.

## Evidence summary

- Rows: 1133
- Columns: 151
- Structural validator: passed
- Owner rows: 821
- Variation rows: 312
- Total consolidated findings: 6525
- Blocker findings: 1385
- Review findings: 5140
- Catalog file mutated by this audit: no
- Existing sibling backup matches the audited catalog: false

## Highest-volume findings

| Finding | Count |
| --- | ---: |
| `missing_slug` | 1133 |
| `description_long_for_class` | 702 |
| `compatibility_proposal_exact` | 648 |
| `claim_needs_evidence:oem_or_genuine` | 553 |
| `repetitive_language:job_site` | 426 |
| `shippable_missing_weight` | 346 |
| `part_family_without_compatibility_or_replacement` | 314 |
| `claim_needs_evidence:industrial_grade` | 301 |
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
| `repetitive_language:demanding_environment` | 42 |

## Previous-version consolidation

- Baseline commit: `df5f1f2414bcf8d781ef6b787c397883984f9c4b`
- Catalog-change commit: `e474e1d00d7c5d5bc0acc6c1113e1a27a277aaba`
- Added/removed SKUs: 0/0
- Changed SKUs / field values: 189/189
- Protected identifier changes: 0
- Field changes: {"Cross-sells": 7, "Name": 182}

The historical enrichment baseline was taxonomy-only. The current comparison additionally includes the bounded P0 schematic-URL, attribute-default, and image-inheritance corrections; row count, schema, SKU population, and protected identifiers remain preserved. Current taxonomy preview reports zero changes and zero unresolved rows.

## Production evidence and ownership gaps

- Catalog image assignments: 2575 across 1725 unique URLs; all populated assignments still use `elliottm4.sg-host.com`.
- Production media candidates: not run valid and not run invalid by HTTP status/content type.
- Local media coverage: 1725 of 1725 catalog basenames present; 379 local files unused.
- Physical data coverage: 679 rows with weight; 390 rows with all dimensions; shipping class is unconfirmed catalog-wide.
- Compatibility research: 648 exact proposals across 396 parts/41 schematics; 2733 unresolved evidence rows remain.
- Content review: 1192 accuracy findings across 637 SKUs; 1962 editorial findings; no automatic copy application is approved.

## Exact bounded exception sets

- `structured_part_number_identity_mismatch` (1 SKUs): `PT-GN`
- `variation_gallery_equals_parent_without_inheritance` (21 SKUs): `DSS1422US`, `S1-A-1624`, `S1-A-2030`, `S1-A-2640`, `S1-M-1624`, `S1-M-2030`, `S1-M-2640`, `S1X-A-2131`, `S1X-A-2640`, `S1X-M-2131`, `S1X-M-2640`, `S2-A-1624`, `S2-A-2030`, `S2-A-2640`, `S2-M-1624`, `S2-M-2030`, `S2-M-2640`, `S2X-A-2640`, `S2X-A-3852`, `S2X-M-2131`, `S2X-M-2640`
- `variation_inheritance_gallery_mismatch` (13 SKUs): `3.5CSF`, `42BH`, `4BH`, `5BH`, `6BH`, `CFB7-8`, `CLT32`, `CLT42`, `CLT55`, `CMT32`, `CMT42`, `CMT55`, `OA90-325`
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
3. Resolve objective price/MAP/publication blockers and confirm shipping data and runtime projections.
4. Resolve the single missing-image SKU using authoritative media.
5. Complete claim-accuracy review before editorial/SEO rewriting.
6. Review compatibility proposals; do not apply generated proposals without approval.
7. Re-run the full audit, structural validation, taxonomy preview, and catalog test suite before any WooCommerce import.

## Scope boundaries

This audit establishes CSV and repository evidence only. It does not prove WooCommerce import behavior, live payment/cart behavior, deployment, or production rendering.
