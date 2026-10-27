# Columbia manufacturer export and DTB catalog audit

Audit date: 2026-10-26. Manufacturer export generated 2026-10-26 16:50 UTC. The files are snapshots; this report does not establish live storefront state. Input SHA-256 hashes and machine-readable counts are in [audit-summary.json](audit-summary.json).

## Executive findings

The manufacturer snapshot has **1,056 products and 1,060 variant rows**. The canonical DTB catalog has **566 Columbia rows**: 515 sellable simple/variation rows and 51 variable parents. Unique exact SKU matching identifies **434 manufacturer variants and 434 DTB sellable rows**. Four additional manufacturer-identifier links require identity review. On the manufacturer side, 622 variants lack a unique exact or manufacturer-identifier link; on the DTB side, 77 sellable rows lack such a link. Those counts are coverage observations, not automatic recommendations to add, delete, or unpublish products.

The strongest actionable finding is **two identifier collisions**. Manufacturer SKU `FA308` is found both as the SKU of DTB `FA308` and as the schema MPN of DTB `FA3`; manufacturer `FA208` similarly points to DTB `FA208` and the schema MPN of `FA2`. Manufacturer describes `FA308` as a 1/2 × 7/8 flat washer; DTB `FA3` uses those dimensions, while DTB `FA308` specifies 33/64 × 7/8. Manufacturer `FA208` and DTB `FA2`/`FA208` have similar 4-40 × 1/8 binder-slot descriptions. **Review source documentation and the physical part, including exact dimensions, before deciding whether these are duplicate listings, aliases, or distinct products.** Do not merge or rewrite protected identifiers based on this comparison alone.

The manufacturer JSON image paths are unusable as local paths: all 1,563 point to absent locations. In contrast, all 1,563 distinct image paths in the variant CSV exist in the export directory, match the 1,563 files present, and pass Pillow decoding. The CSV contains 1,567 image references because some files are referenced by multiple variants. Use the CSV mapping for local investigation, and correct the export's JSON/CSV path consistency in its owning exporter or downstream manifest only after determining how the files were produced. A valid local image does not establish public reachability or reuse rights.

## Coverage and matching

| Manufacturer variant status | Count | Interpretation |
| --- | ---: | --- |
| Unique exact SKU | 434 | Same nonblank SKU, case-insensitive and trimmed; no other Columbia DTB SKU/MPN claimant and no duplicate source SKU. |
| Manufacturer identifier review | 4 | Source SKU uniquely matches a DTB manufacturer/schema identifier but the DTB SKU differs. Confirm actual identity. |
| Ambiguous identifier | 2 | Multiple DTB identifiers claim the source SKU: `FA308` and `FA208`. |
| Formatting-normalized candidate | 15 | Possible match only; punctuation removal is not proof of identity. |
| Missing source SKU | 6 | No SKU supplied for the variant; five are Black T-Shirt size variants. |
| No identifier match | 599 | Includes products with or without ranked name candidates. |
| **Total** | **1,060** | Every source variant is represented in the reconciliation. |

DTB coverage reconciles to 434 unique exact SKU matches, four manufacturer-identifier review links, 77 unmatched sellable rows, and 51 family parents. Parents are reported separately and are not treated as sellable SKU matches. [family-coverage.csv](family-coverage.csv) lists their children and match coverage.

`CT45-BLACK` appears on two manufacturer product pages, `predator-connecting-rod` and `predator-connecting-rod-1`; investigate whether these represent duplicate source listings. There are six blank source SKUs and no duplicate source product IDs. All 28 collection counts reconcile against product memberships, and the 1,060 product/variant-ID and SKU pairs reconcile between JSON and CSV as multisets. Basic product metadata and uniquely resolvable variant prices also agree across those files.

The candidate file contains 617 ranked suggestions for 289 unresolved source variants; 333 unresolved variants have no suggestion above the chosen threshold. Candidate matching uses title sequence similarity, token overlap, and formatting-normalized identifiers. It flags differing numeric tokens and does not establish fitment, equivalence, or product identity. For example, `FA269` (#10 lock washer) suggests `FA229` (#6 lock washer); the numeric conflict makes it unsuitable as an automatic match. Candidates are bounded to the top three and are discovery aids, not an exhaustive list of possible aliases. Scores are ranking signals, not calibrated probabilities.

The four manufacturer-identifier links are `CR3` → `CR-3`, `HFFB1A` → `HFFB1A-10`, `HPA48` → `HPA-48`, and `HNSA14` → `HNSA-14`. The second is especially sensitive: DTB identifies a 10-inch mud seal strip while the source calls `HFFB1A` a box T seal. Confirm dimensions and manufacturer part relationships before applying source content or imagery.

## Prioritized recommendations

1. **Resolve identity conflicts first.** Review `FA308`/`FA3` and `FA208`/`FA2`, then the four manufacturer-identifier links and 15 normalized candidates. Preserve SKU, MPN and existing records until a verified mapping and any needed migration are approved. Check all fields that can claim the same identifier, including `schema_mpn`.
2. **Repair the image-path manifest.** JSON's 1,563 stale local paths and the valid CSV paths describe the same export at different locations. Trace exporter behavior and establish a single consistent local-path contract. Do not blindly substitute paths by SKU where identifiers collide or are blank.
3. **Prioritize exact-SKU image opportunities.** Of the 438 linked rows including four requiring identity review, 33 DTB image fields reference placeholders; 16 also have a manufacturer CSV image and 17 have none. Examples with local manufacturer images include `FA201`, `FA285`, and `CT34R`. Verify the product identity, image relevance, rights, and intended variation/parent ownership before proposing an image-only update. The manufacturer source has no CSV image for 481 variant rows, predominantly parts; missing source imagery does not imply the DTB image is wrong. Local inspection does not verify DTB URLs or deployed image sync.
4. **Triage assortment gaps by product type.** The 622 unresolved manufacturer variants include 433 parts, 46 parts kits, 40 joint knives, 23 smoothing blades, and 13 hand trowels. Review against existing identifiers and purchasing/assortment policy before creating any products. Conversely, review the 77 unmatched DTB sellable rows for aliases, legacy products, sourcing and intentional assortment; absence from a snapshot is not proof of discontinuation.
5. **Review field differences in context.** [matched-field-comparison.csv](matched-field-comparison.csv) exposes source and DTB titles, descriptions, classification, variation labels, specs, image configuration, prices, and availability side by side for all 438 linked rows. There are 310 literal title differences, many expected from DTB brand and variation naming; this is a review signal, not an error count. Of linked source descriptions, 345 contain 50 or fewer characters and 93 exceed 150, so do not assume the export supplies complete enrichment for every part. Preserve existing verified attributes and avoid unsupported compatibility claims.
6. **Investigate pricing separately.** Among 438 linked rows, DTB regular price is numerically higher for 396, equal for 31, lower for 8, and missing or nonnumeric on at least one side for 3. The manufacturer snapshot does not establish currency, tax treatment, pricing policy, or whether values are wholesale, MAP, or comparable retail; do not bulk-reprice from these figures. The field comparison includes DTB sale prices for separate review and does not determine an active sale or live WooCommerce price.
7. **Resolve the taxonomy validation discrepancy before catalog import.** Running `python scripts/catalog/validate_official_catalog.py` on the existing catalog reported 17 taxonomy errors across brands. The Columbia entry `SACS` is assigned `Taping & Finishing Tools > Semi-Automatic Tool Sets & Kits` while the validator expects `Taping & Finishing Tools > Tool Sets & Kits`. Review the current category policy and intended navigation before changing either source data or validation; this audit does not establish which is correct.

## Files and interpretation

- [source-reconciliation.csv](source-reconciliation.csv): one row per manufacturer variant, including exact status, source page and local image mapping.
- [dtb-reconciliation.csv](dtb-reconciliation.csv): one row per Columbia catalog row, including parent and manufacturer identifiers.
- [match-candidates.csv](match-candidates.csv): ranked possibilities for unresolved source rows, with explicit numeric-conflict flag.
- [matched-field-comparison.csv](matched-field-comparison.csv): paired product details for 434 exact SKU matches plus four manufacturer-identifier review links.
- [findings.csv](findings.csv): item-level price, placeholder, identity and unresolved coverage review queue. Finding rows can overlap and are not independent product counts.
- [family-coverage.csv](family-coverage.csv), [collection-coverage.csv](collection-coverage.csv), [image-mapping.csv](image-mapping.csv): family, collection and image traceability. [source-integrity-findings.csv](source-integrity-findings.csv) and [image-integrity-findings.csv](image-integrity-findings.csv) record checks.
- [audit-summary.json](audit-summary.json): counts, input hashes and snapshot timestamp. [build_audit.py](../../tools/build_audit.py) and [inspect_details.py](../../tools/inspect_details.py) reproduce the primary reconciliation and field/image inspection from the current input files; family, collection, image mapping and this narrative are audit artifacts for this run.

Source titles, field differences, name candidates, and price differences are automatically surfaced for review; they have not all been individually adjudicated. No live product pages, public media URLs, WooCommerce runtime records, actual warehouse stock, image rights, or pricing currency were verified. No official catalog data was changed.
