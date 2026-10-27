# Audit: TSW Product Data — prefixes removed

Audit date: 2026-10-26. Input: `docs/catalog/TSW Product Data - prefixes removed.csv`. This file contains 5,937 product rows and 20 fields. It does **not** contain supplier cost, wholesale price, sale price, or retail price; it cannot by itself verify any of the 73 below-cost amounts.

## File integrity and identity

The CSV and `docs/reference/data/TSW/TSW Product Data.xlsx` each contain 5,937 data rows. In the current snapshots, every CSV SKU is a suffix of the corresponding original workbook SKU. All other columns agree row by row after allowing for insignificant floating-point representation differences. The original prefix can therefore be recovered for this pair of snapshots by the matching row position; input hashes are recorded in [tsw-prefix-file-audit-summary.json](tsw-prefix-file-audit-summary.json). This conclusion does not establish a general identity rule for future reordered or independently generated exports.

There are 80 duplicate stripped-SKU groups comprising 163 rows. For example, `RC` refers to `ADARC`, a drywall cart, and `CTTRC`, a Columbia road case. A bare stripped SKU cannot safely serve as a global join key. The original workbook prefix provides supplier identity context: this snapshot has 687 `CTT` records and 872 `TTT` records. The Columbia comparisons below recover the `CTT` prefix from the workbook rather than assuming every matching stripped identifier is Columbia. The complete duplicate list is [tsw-prefix-file-duplicate-identifiers.csv](tsw-prefix-file-duplicate-identifiers.csv).

## Columbia catalog and the 73 products

The official catalog has 566 Columbia rows: 51 variable-family parents and 515 sellable simple/variation rows. Comparing the 515 sellable rows against the recovered `CTT` subset gives:

| Comparison | Count | Interpretation |
| --- | ---: | --- |
| Exact stripped SKU, unique within the recovered CTT subset | 347 | Identifier link; still check product description and unit before a mutation. |
| Punctuation-normalized candidate, unique within the CTT subset | 49 | Review candidate only; normalization can conflate distinct part numbers. |
| No identifier match | 119 | No match by these methods, not proof TSW does not supply the item. |
| **Sellable rows** | **515** | Variable parents are counted separately. |

For the original 73 below-cost products, **37 have unique exact stripped identifiers, eight have unique normalized candidates, and 28 have no identifier match**. The eight normalized candidates include the six FFB9 blade sizes and two FFB25 retainer sizes; the workbook descriptions support those size correspondences, but it provides no costs. Every one of the 45 located rows has stocking/selling unit `EACH` and conversion 1. This does not establish the number of components inside a sellable pack.

The 396 matched or candidate Columbia catalog rows expose TSW weight values, but 77 of those weights are zero. Only 84 include all three populated dimension fields, and none has a populated `Pack Qty` value. A populated or zero field is not independently verified product/shipping data; the export does not document whether dimensions represent the product or packaging. Do not bulk-populate catalog shipping dimensions or infer pack quantities from these fields.

## Cost verification implications

The new file does not change the earlier cost conclusion. The separately supplied canonical TSW cost snapshot contains a unique exact SKU and matching amount for five of the 73 products. Two E-clip SKU/size conflicts, one pack-quantity ambiguity, one conflicting prior CR1-to-CT1 mapping, and 64 products without a matching cost record remain. A TSW identity match in this file does not substantiate a COGS amount.

- `FA379`/`FA380`: the CSV retains TSW's 1/4-inch and 1/8-inch descriptions respectively, opposite to the Columbia manufacturer export. Resolve with supplier/manufacturer evidence.
- `FA262`: TSW's description includes `(2)` although selling unit is `EACH`; verify the quantity inside an each and the corresponding quoted cost.
- `CR1`: no CTT SKU match appears in the workbook. TSW `CT1` is described as a taper head; the existing DTB CR1 record describes a corner-roller head. Verify the part and its prior cost attribution.
- TSW CTT product records support investigation of the FFB9 blade sizes but contain no cost; do not assign the existing DTB costs to TSW without a cost source.

## Evidence files and limitations

- [tsw-prefix-file-73-cross-reference.csv](tsw-prefix-file-73-cross-reference.csv): one row for each of the 73 products, including recovered TSW SKU, descriptions, unit fields, existing cost-evidence disposition, and workbook/CSV row numbers.
- [tsw-prefix-file-columbia-cross-reference.csv](tsw-prefix-file-columbia-cross-reference.csv): all 566 Columbia catalog rows, including the 51 family parents.
- [tsw-prefix-file-duplicate-identifiers.csv](tsw-prefix-file-duplicate-identifiers.csv): all rows in duplicate stripped-SKU groups.
- [tsw-prefix-file-audit-summary.json](tsw-prefix-file-audit-summary.json): counts and source hashes; [audit_tsw_prefix_file.py](../../tools/audit_tsw_prefix_file.py) reproduces the analysis on unchanged inputs.
- [tsw-cost-verification-report.md](tsw-cost-verification-report.md): detailed review of the separate TSW cost snapshot and discrepancies.

The files are local snapshots. No current quote, invoice, supplier login, live inventory, or product-level physical verification was available. No catalog costs, product identities, or prices were changed in this audit.
