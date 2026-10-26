# TSW cost verification: 73 Columbia products below recorded cost

Audit date: 2026-10-26. This audit compares the 73-row below-cost list with the current official CSV, the canonical `products/catalog/source/tsw-costs.csv`, the TSW Product Data workbook, the TSW brand extract, and the earlier cost migration report. File hashes are in `tsw-cost-audit-summary.json`. No supplier login, live quotation, invoice, or current purchase order was available; snapshot agreement is not proof of current invoiced cost.

## Results

| Disposition | Products | Meaning |
| --- | ---: | --- |
| TSW cost snapshot agrees with DTB | 5 | Unique Columbia SKU and matching product description, same amount as the catalog. Confirm currency, current cost and unit terms with TSW. |
| TSW cost record exists, product size conflicts | 2 | `FA379` and `FA380`: TSW and Columbia manufacturer assign opposite E-clip sizes to these SKUs. Both sources record the same supplier cost amount, but product identity must be resolved. |
| TSW cost record exists, pack quantity unclear | 1 | `FA262`: TSW's product workbook says `(2)` while the manufacturer title does not specify quantity. TSW lists selling and stocking unit `EACH`, conversion 1; that does not establish whether an each contains two screws. |
| Previous cost attribution conflicts with product type | 1 | `CR1`: the former cost migration mapped the corner-roller head to TSW `CT1`, a taper head. The $93.08 cost is not verified for `CR1`. |
| No matching TSW cost record in supplied cost snapshot | 64 | Existing catalog costs have not been substantiated by the inspected TSW price file. |
| **Total** | **73** | Each product has a row in `tsw-cost-cross-reference.csv`. |

The five amount agreements are:

| DTB SKU | Columbia sale price | TSW snapshot cost | Product |
| --- | ---: | ---: | --- |
| FA300 | $1.10 | $1.63 | 1/4-20 × 2-inch hex bolt |
| FA294 | $1.10 | $1.21 | 1/4-20 × 3/8-inch socket head |
| FA274 | $1.10 | $1.21 | 10-24 × 3/8-inch socket head |
| FA306 | $1.10 | $1.63 | 5/16-18 × 1-1/4-inch hex bolt |
| FA305 | $1.10 | $1.63 | 5/16-18 × 3/4-inch hex bolt |

These values agree with the local source snapshot; the cost file itself does not establish the date of the supplier's offer, currency, tax or shipping treatment, or an individual unit quote. The TSW workbook identifies these entries as `EACH`, with selling conversion 1, but it contains **no cost column**. Seek a current TSW quote or invoice before calling a cost current.

## Important discrepancies

- **FA379 and FA380:** the Columbia export describes FA379 as 1/8-inch and FA380 as 1/4-inch. Both the TSW cost export and the TSW product workbook describe FA379 as 1/4-inch and FA380 as 1/8-inch. Do not swap identifiers, images, descriptions, or costs based on either file alone. Ask TSW and Columbia to confirm the SKU, physical size, UPC, and pack. The 1/4-inch manufacturer title lacks a quoted unit, but its SKU and relative size conflict with TSW's entry.
- **FA262:** the price file records $1.11 and DTB records $1.11. The workbook product name says `8-32 X 1/2 SOCKET HEAD ... (2)` while the manufacturer product title does not state a quantity. Clarify whether the supplier cost is per individual fastener or per two-piece sellable unit before comparison with a single-product retail price.
- **CR1:** the current DTB product and schematic metadata describe a corner-roller head casting. The earlier source reconciliation attributed its $93.08 cost to TSW `CT1`; the TSW workbook describes `CT1` as a classic taper head. This is incompatible evidence, not a trustworthy cost match. Obtain a quote for the actual CR1 corner-roller part and correct the prior mapping through a reviewed catalog update. The manufacturer export itself calls CR1 only `Head Casting`, so the corner-roller detail comes from DTB's existing metadata and should also be verified against an authoritative schematic.
- **Finishing-box blades:** TSW's product workbook has SKU-format-normalized, matching-size entries for FFB9-10, FFB9-12, FFB9-5.5, FFB9-7, FFB9-8, and FFB9-14. It has no costs. Five currently have the Columbia export's $3.23 sale price and catalog costs between $12.19 and $21.13. A matching TSW identity does not verify those recorded costs; obtain unit quotes and confirm the manufacturer price and sellable quantity.

## Coverage and method

The canonical TSW cost file has 301 Columbia SKU rows; across all 73 audited products it contains eight exact-SKU cost records. The TSW workbook identifies 37 products by an exact `CTT`-prefixed supplier SKU and another eight by punctuation-normalized identifier candidates whose names/sizes are shown in the cross-reference. Twenty-eight have no such SKU match. The workbook's SKU matches do not supply costs or independently prove a product mapping. The 64 missing-cost group includes items present in the workbook without price as well as items not found by the identifier check.

The prior COGS reconciliation itself labeled those 64 existing costs `EXISTING_COST_NOT_MATCHED_TO_INSPECTED_SOURCES`. It reported CR1 as a reviewed TSW mapping; this audit identifies the product-type conflict described above. All 73 rows currently have equal `Cost of goods` and `_brikpanel_cogs` fields, which demonstrates internal agreement only, not factual correctness.

The other local TSW cost snapshots were checked: the supplier-results and reference copies have the same 301 Columbia SKU/cost/name entries as the canonical source. The older validated copy has 297 Columbia SKUs and differences elsewhere; the eight exact-match cost amounts above agree across the two copies. Local timestamps and historical reports do not prove any of them current.

`tsw-cost-cross-reference.csv` contains every product, Columbia and TSW identifiers, source names, workbook row/unit evidence, prices, costs, prior-mapping information and disposition. `tsw-unmatched-candidates.csv` contains identifier-similarity leads only: similar SKUs are not evidence of equivalent parts and must not be used to change costs automatically. `tsw-cost-audit-summary.json` records input hashes and reconciled counts.

No costs, SKU identities, retail prices, or supplier records were changed by this audit. The 64 unsupported costs and the three conflict/quantity cases cannot be verified without additional authoritative supplier evidence. WooCommerce's documented cost-floor behavior can affect imported sale prices; runtime behavior was not tested here.
