> **Current scope:** The manufacturer references now contain 1,006 products after excluding 46 manufacturer-classified part kits and two product packs. Counts below describe the earlier 1,054-product audit and are retained as historical evidence; see [manufacturer-exclusion-scope.md](../../review/manufacturer-exclusion-scope.md) for current counts.

# TSW shipping measurements in the Columbia manufacturer-first reference

Audit date: 2026-10-26. The manufacturer-first working CSV was enriched using `docs/catalog/TSW Product Data - prefixes removed.csv`. The original prefixed TSW export was used solely to recover the `CTT` Columbia supplier prefix and establish brand-scoped identity; the four measurement values were read from the requested prefix-stripped file after field-by-field equality checks.

## Results

- 1,054 non-merchandise products were reviewed. 426 have a unique exact manufacturer/TSW identifier without a detected identity conflict; 61 have a unique punctuation-normalized identifier and equivalent normalized product-name tokens. The latter are high-confidence candidate matches, not a manufacturer-confirmed alias registry.
- 162 products gained one or more fields: 162 weights and 90 values in each of length, width and height. Of those products, 120 used exact identifiers and 42 used the strict normalized/name method. Existing populated values were not changed; blank or zero supplier values were not copied. Supplier weights under 0.001 were held for review.
- The working CSV now has 441 populated weights and 163 products with length, width and height, compared with 279 and 73 before this update. The 1054 rows and all fields other than the four shipping fields are unchanged.
- 123 rows are held for identity review and 444 have no candidate match by the implemented rules. In total 126 rows have review flags, including ambiguous SKUs, numeric or product-name contradictions, pack-quantity issues, and unusually small source weights. A held row can retain existing DTB values; it did not receive new values from the held TSW match.

## Interpretation and limitations

The existing repository TSW migration maps supplier `weight`, `Length`, `Width` and `Height` directly to WooCommerce pounds and inches. This update follows that project convention; the supplied source file does not itself explicitly state measurement units or whether dimensions are product or packed-shipping dimensions. These numbers are source-supported reference values, not independently verified package measurements. Confirm units, product/packaging meaning and abnormal values with TSW before relying on them for live shipping.

Exact same identifiers are not accepted automatically when product descriptions conflict, including FA379, FA380, FA256, FA302, MP36, FA262, FA308, FA208, FA222, FA259, FA279 and PMH. Formatting-only matches require uniqueness on both sides and matching name tokens; similarly shaped identifiers with different descriptions, such as HNS1-2/HNS12, remain in review. No COGS, price, inventory, image, product title, identifiers, specifications JSON, category, parent or variation relationship was changed. Physical dimensions from the shipping data were not promoted into product specification JSON because their package-versus-product meaning is not established.

[tsw-manufacturer-shipping-audit.csv](tsw-manufacturer-shipping-audit.csv) contains all 1,054 dispositions and original source values; [tsw-manufacturer-shipping-review.csv](tsw-manufacturer-shipping-review.csv) contains the flagged subset; [tsw-manufacturer-shipping-changes.csv](tsw-manufacturer-shipping-changes.csv) records every field populated and the TSW source row. A timestamped copy of the working reference was saved before the update. The official DTB catalog was not changed and this working CSV is not import-ready.
