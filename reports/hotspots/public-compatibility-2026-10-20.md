# Public compatibility and schematic coverage — 20 October 2026

## Scope and evidence

The companion [JSON snapshot](public-compatibility-2026-10-20.json) was produced by `scripts/catalog/audit_public_compatibility.py` from public GET endpoints only. It covers every page returned by the public parent catalog and parts filters, plus every published schematic detail returned by the public schematic index at the audit time. Counts below are public API projections and schematic **references**, not unique purchasable part counts. No fitment was inferred from names, diagrams, brands, or similar part numbers. The live WordPress environment was not changed.

## Findings

| Surface | Observed coverage | Meaning |
| --- | ---: | --- |
| Published parent products | 464/464 retrieved; 141 hyphenated SKUs | Existing compatibility route pattern rejects a substantial portion of observed SKU syntax. `CP-26` returned `rest_no_route` before the local fix. |
| Published replacement parts | 332/332 retrieved | The public catalog projects zero parts with `compatibleToolSkus` and zero with `replacementPartFor`. No positive fitment claim is supported by these lists today. |
| Parts with schematic brand and group metadata | 312/332 | This is legacy catalog grouping metadata; it is not proof of a resolved schematic-part-to-product relationship. |
| Published schematics | 90/90 details retrieved | 64 have no linked product projection. |
| Schematic part references | 2,138 | 539 are marked `resolved`; 1,599 are `unresolved`. |
| Linked public product references | 424 | All 424 show `available=true`. These are reference occurrences, so a product may be counted more than once. |

| Brand | Schematics | Part references | Resolved references | Linked public products |
| --- | ---: | ---: | ---: | ---: |
| Asgard | 25 | 0 | 0 | 0 |
| Columbia Taping Tools | 28 | 876 | 473 | 423 |
| Dura-Stilts | 1 | 0 | 0 | 0 |
| Level5 | 14 | 414 | 0 | 0 |
| Platinum Drywall Tools | 8 | 145 | 0 | 0 |
| TapeTech | 14 | 703 | 66 | 1 |

The 115 references marked `resolved` without an embedded public product are a distinct state from unresolved identity. Three read-only WooCommerce samples (product IDs 17412, 17982, 17424) were draft and hidden, despite being in stock. That explains the absence of a public purchase projection for those samples; the remaining 112 were not individually checked.

## Release and data sequence

1. **Ship the SKU route correction through normal review and deployment.** The local branch accepts hyphenated SKU path segments and requires exact membership after candidate metadata lookup. The production route still returns 404 for `CP-26` until this code is deployed. A syntactically valid route is not evidence that a compatibility relationship exists.
2. **Build a verified fitment source set.** Reconcile the 332 published replacement parts against canonical product identities and manufacturer evidence. Populate `compatibleToolSkus` and `replacementPartFor` only where exact tool/model evidence exists. Record source, model scope, exclusions, and review state so customer copy can be audited. Treat an empty list as unknown or unpopulated, not incompatible.
3. **Resolve schematic references through the existing deterministic pipeline.** Start with schematics containing part references but few linked products: TapeTech (703 references, one linked), Level5 (414, none), and Platinum (145, none). Inspect source identifiers and catalog identity before mapping. Preserve unresolved, reference-only, and intentionally-not-sold states; do not auto-link by title, brand, or diagram proximity. Columbia has the strongest current linkage and can anchor a reviewed customer journey.
4. **Gate customer claims by evidence state.** Show “Fits model X” only for verified canonical fitment; show a purchase action only when the exact schematic reference has a public, available product projection. Present unresolved callouts as identification help without a product claim. Send ambiguous cases to assisted part identification or repair intake.
5. **Re-run this audit after data work and before wider exposure.** Track the number of published parts with verified fitment, schematic references with visible product links, unresolved disposition coverage, and the count of schematics with no linked products. Review per-brand totals and spot-check high-volume diagrams before release.

## Limits

This audit did not test a customer browser journey or execute a repair request. It did not validate manufacturer fitment, inspect every hidden/draft product, or prove whether unpopulated compatibility fields reflect missing source data versus projection issues. The public catalog snapshot can change after the audit timestamp.
