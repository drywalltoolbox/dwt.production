# USG Sheetrock Tools — TSW native WooCommerce COGS reconciliation

## Scope and source

2026-10-20 production site: `https://drywalltoolbox.com`.

An exact SKU + USG supplier brand join between `scripts/supplier-catalog/results/cost/tsw-costs.csv` and the canonical catalog matched **81 of 81 simple USG Sheetrock Tools draft products**. The TSW extract contains 83 USG source rows. The variable parent `USG-MAGNESIUM-STILTS` does not have an exact matching TSW supplier row and is **not assigned an invented parent COG**; its two sellable variation SKUs 340925 and 340926 require separate cost reconciliation.

The earlier products had `Meta: _brikpanel_cogs` populated but the native WooCommerce **Cost of goods** field was blank, so `cost_of_goods_sold.total_value` returned zero. For all 81 exact-matched simple products the legacy value agreed exactly with the TSW supplier extract. No nonpositive, conflicting or ambiguous cost was accepted.

## Implementation and verification

- Applied `cost_of_goods_sold.values[0].defined_value` using native WooCommerce REST batch updates, with **81/81 successful write responses** in four batches (25 + 25 + 25 + 6), and nonzero COG totals returned.
- Six representative WooCommerce product GET readbacks independently confirmed native COG totals matched existing legacy values (including SKUs `SRS48`, `340800`, `2FDWS5`, `030250`, `340416`, and `SRSSL`). The 81 batch write responses were successful; **not all 81 received a separate GET readback**.
- Synchronized exactly 81 native `Cost of goods` fields in `products/launch/official/dtb_official_catalog.csv`, with 1,134 rows retained and all non-COG CSV fields validated unchanged. The pre-update backup and source match scripts remain in gitignored `scripts/supplier-catalog/results/cost/`.
- No retail prices, product status, stock levels, shipping dimensions, images, SKU, parent/variation relationships or orders changed. All USG products remain drafts.

## Essential data qualifiers

- This TSW supplier-cost extract is a **historical snapshot**, not a freshly authenticated/current supplier quote. No fresh TSW login or pricing refresh was available; these values must be reconfirmed ahead of new retail-price approval.
- SKU **340800** currently has historical native COG **$83.15** versus a storefront sale price of **$16.99**. Treat this as a high-priority supplier packing-unit/price conflict. No automatic margin/retail price update was performed.
- Missing retail prices and unverified Veeqo stock still block publication. Native COG availability resolves only the missing COG field, **not** retail-price or inventory launch gates.
- WooCommerce native COG controller uses `cost_of_goods_sold.values[].defined_value`. Legacy `_brikpanel_cogs` metadata is left untouched for compatibility, but native WooCommerce COG is now the authoritative sales/order COG basis.
