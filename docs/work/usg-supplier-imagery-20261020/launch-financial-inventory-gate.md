# USG launch price, cost and inventory readiness — 2026-10-21

## Two magnesium stilt variations reconciled

Live WooCommerce product parent 18272, variations:
- **340925**, variation 18273, 18–30 in: native WooCommerce COGS **$255.82**; legacy `_brikpanel_cogs` **$255.82**; existing retail sale price **$359.00** and regular price **$394.90**.
- **340926**, variation 18274, 24–40 in: native WooCommerce COGS **$263.68**; legacy `_brikpanel_cogs` **$263.68**; existing retail sale price **$369.00** and regular price **$405.90**.
- Both native COGS were **already correctly persisted in live WooCommerce**. The only discrepancy was a blank native `Cost of goods` cell for SKU **340926** in the source CSV, now filled from the exact-SKU historical TSW supplier extract. Neither live variation COG nor retail pricing was modified.
- Public TSW product pages corroborate product identity (supplier part numbers `USG340925` and `USG340926`) but do not expose authenticated wholesale purchasing cost; these do not verify the currency or freshness of the historical cost values.

## Financial audit

- 81 USG simple products plus two stilt variations and the stilt variable parent.
- **55 simple products have no retail price**. Both stilt variation sellables have existing retail prices. Their price existence does not independently verify an approved price policy.
- USG simple SKU **340800**: retail sale **$16.99** versus historical TSW COGS **$83.15**, representing a -389.4% nominal gross margin before shipping/payment charges. Stop-sale / unit-pack investigation required.
- Additional below-25%-nominal-margin SKUs from the historical cost extract: `340405` (~4.7%), `340401` (~13.5%), `340402` (~15.3%), `340407` (~20.0%), `340403` (~5.9%). Threshold is an **audit flag, not an imposed pricing rule**.
- No automatic price computation, margins, changes to WooCommerce retail prices, or publication took place; 55 missing prices require an approved markup policy and current supplier cost verification.

## Supplier-cost freshness

- Inspected the historical file `scripts/supplier-catalog/results/cost/tsw-costs.csv`, matching 83 USG sellables with exact supplier SKUs; both stilt costs are included.
- Public TSW pages provide parts and features, but **not the private account-specific wholesale prices**. A confirmed authenticated TSW supplier current-price export or login is still required before stating these are today's wholesale costs.
- Do not represent public MSRP/distributor competitor retail pricing as our TSW wholesale cost.

## Inventory integration

- Production DTB integration diagnostics show inventory projection initialized and not stale, configured Veeqo warehouse **527795**, channel **1219095**.
- Last sync (2026-10-21 07:15:15 UTC) has **0 inventory updates and 108 unmapped records**; global readiness flags do not demonstrate per-USG-SKU mapping or real warehouse availability.
- Direct Veeqo connected tool required user approval/login, declined for this session; per-SKU warehouse available stock could not be independently verified through that route. Browser TSW account access likewise not available in this context.
- WooCommerce still has `manage_stock=true`, quantity **10**, for many USG simple products and both stilt variations; treat these values as unverified seeded defaults, not confirmed stock.

## Publication gate

Keep 82 parent/simple USG products unpublished until verified TSW supplier wholesale and packaging, 55 missing approved retail prices, SKU 340800 anomaly, and actual Veeqo per-SKU inventory/available-to-promise are resolved. First publish only separately verified price+inventory approved SKUs; test storefront/add-to-cart without real payment/fulfillment.
