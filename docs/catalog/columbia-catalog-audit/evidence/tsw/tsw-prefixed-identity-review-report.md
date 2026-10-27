# TSW prefixed-product identity review

Audit date: 2026-10-26. Input: `docs/catalog/TSW Product Data - TTT CTT DSS USG.csv`; SHA-256 `29c3fd205070d147b007b2c008445e0bb689acda38c34fdf5328c552cc4cc15e`.

## What this file establishes

The file has 1,800 records: 687 `CTT`, 872 `TTT`, 74 `DSS`, and 167 `USG`. Its rows and values match the corresponding prefix-filtered rows of `docs/reference/data/TSW/TSW Product Data.xlsx` in the same order, allowing for insignificant spreadsheet floating-point representations. It restores the original supplier SKU prefix, allowing a Columbia-specific search within `CTT` while preserving the supplier's identity. It contains no cost or price field and is not a supplier quote.

The identity review addresses all 73 Columbia products identified as below recorded COGS. Results are in [tsw-prefixed-73-identity-review.csv](tsw-prefixed-73-identity-review.csv):

| Result | Count | Meaning |
| --- | ---: | --- |
| Unique exact supplier identifier, no identified conflict in this pass | 33 | Source SKU after the `CTT` prefix equals the DTB SKU. Generic names may still need unit and product confirmation. |
| Exact identifier, size conflict | 2 | FA379 and FA380 have reversed E-clip sizes between TSW and Columbia. |
| Exact identifier, material conflict | 1 | MP36 is steel in the Columbia export and Nylatron in TSW. |
| Exact identifier, pack review | 1 | FA262 includes `(2)` in TSW's description but Columbia's title does not state a quantity. |
| Unique punctuation-normalized identifier candidate | 8 | Six FFB9 blades and two FFB25 retainers; the TSW descriptions also agree on the named size. Check actual SKU and pack before binding identifiers. |
| No supported identifier link | 27 | No exact or punctuation-normalized CTT identifier found by this method. |
| Prior mapping in conflict | 1 | CR1 was attributed to TSW CT1, a taper head; DTB describes a corner-roller head. |
| **Total** | **73** | Every product has a review row. |

The remaining 27 are not necessarily unavailable from TSW. [tsw-prefixed-73-candidates.csv](tsw-prefixed-73-candidates.csv) records the top text/identifier suggestions for each of the 73 products, with source names, identifiers, numerical-disagreement flags and available *separate cost-file* values. Candidate scores rank discovery leads only: they are not calibrated confidence or proof that parts are interchangeable. The file contains 317 candidate rows and does not claim to exhaust all conceivable aliases.

## Concrete findings

- **FA379 and FA380:** Columbia lists FA379 as 1/8-inch and FA380 as 1/4-inch; TSW reverses those sizes under the same numbers. Each TSW cost entry is $1.71, but equal costs do not resolve SKU, size, or part identity. Confirm manufacturer and supplier codes/physical parts before mapping across codes.
- **MP36:** Columbia calls MP36 `Steel Bushing`; TSW `CTTMP36` says `NYLATRON BUSHING`; the existing DTB description also says Nylatron. This is a material contradiction despite an exact identifier. Do not infer that the two materials or records are equivalent. The supplied TSW cost file has no exact MP36 cost.
- **FA249 versus TSW FA256:** Both the manufacturer FA249 and TSW `CTTFA256` descriptions say `8-32 x 3/16 set screw`; the latter has a separate TSW cost of $0.32. However, Columbia identifies its own **FA256** as an `8-32 x 3/16 pan slot`. Thus TSW's candidate identifier conflicts with Columbia's description of FA256. The apparent $0.32 alternative must not be transferred to FA249 without authoritative clarification.
- **FA320 versus TSW FA302:** Columbia FA320 and TSW `CTTFA302` describe a `1/4-28 x 3/8 socket set screw`, and the separate TSW cost file lists FA302 at $0.89. Columbia's own **FA302**, however, is described as a `1/4-28 x 1/4 set screw`. The competing sizes prevent treating FA302 as a verified alias for FA320.
- **CR1:** TSW's `CTTCT1` is explicitly a classic taper head. DTB identifies CR1 as a corner-roller head, while Columbia's export says only `Head Casting`. The earlier $93.08 cost attribution from CT1 remains unsupported; verify the product and obtain a quote for CR1.
- **FFB9 blades and FFB25 retainers:** TSW prefixes and stripped-number formatting differ from DTB, but descriptions and size values support the eight identity candidates. The prefixed source has no costs. The five FFB9 products priced at Columbia's $3.23 in DTB still need actual TSW cost quotes.

## Decision and limitations

This file improves identity tracing but does not prove product interchangeability, source cost, current availability, or purchase-unit pricing. The earlier separate-cost review still contains five exact-SKU snapshot amount agreements, two size conflicts, one pack issue, one unsupported prior attribution and 64 rows without an exact TSW cost. MP36 is an additional identity/material conflict within that last group.

Request TSW/Columbia confirmation of FA379, FA380, FA249, FA256, FA320, FA302 and MP36; request actual current SKU-specific unit costs and pack quantities for unresolved products. Preserve the full TSW supplier identifier and DTB SKU until an explicit identity correction is evidenced. No SKU, cost, price, or product data was changed during this review.
