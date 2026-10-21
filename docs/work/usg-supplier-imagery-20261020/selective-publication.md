# USG selective publication — 2026-10-21

## Live outcome

At user direction, do not treat Veeqo reconciliation as a publication prerequisite for this specific release. Evaluated 82 USG product parents/simple products (81 simple + variable magnesium-stilts parent); confirmed **magnesium-stilts parent 18272 was already published** with two priced variations.

Published **20 additional USG simple products** to production through the WooCommerce REST batch endpoint. Every write response succeeded. All 20 were individually GET-read back and confirmed `status=publish`, positive current retail price greater than the historical TSW exact-SKU native WooCommerce COG, nonempty description and short description, at least one media attachment and at least one category.

Published SKUs: `114CDWS5`, `114CDWS8M`, `158CDWS5`, `158CDWS5M`, `1CDWS5`, `212CDWS25M`, `212CDWS5`, `340310`, `340311`, `340316`, `340317`, `340318`, `340404`, `340406`, `340413`, `340414`, `340416`, `340417`, `3CDWS5`, `SRS1`.

## Remaining hold (61 simple drafts)

- **55** simple products have **no approved retail price**; historical supplier COG does not establish a retail selling price and is not a substitute for one.
- **1** product (`340800`) is priced **below historical supplier cost**: retail $16.99, native COG $83.15; must resolve pack-unit mismatch.
- **5** products are flagged for commercial review with <25% nominal pre-fee margin on historical costs: `340405`, `340401`, `340402`, `340407`, `340403`. The 25% flag is a conservative review threshold, **not an established merchant rule or authoritative approval policy**.
- The historical 2026 TSW extract is the exact-SKU cost evidence, **not a refreshed authenticated current supplier wholesale quote**. All 83 simple/variation sellable costs previously reconciled, and native WooCommerce cost values verified on representative live products. Do not claim a current supplier cost verification.
- Existing WooCommerce stock quantities were not independently confirmed. At the user's explicit instruction Veeqo was not used as this release gate; stock quantities and purchasability therefore remain an operational risk.
- No new MSRP, retail price, COG, inventory quantity, variation, image asset, or order changes were made in this publication operation.

## Source and deployment

`products/launch/official/dtb_official_catalog.csv`: updated 20 matching simple SKUs' `Published` to `1` and catalog visibility to `visible`; all other rows left unchanged. Verified pre-write backup exists in ignored `scripts/supplier-catalog/results/cost/usg-before-selective-publish.csv`. The 20 product status changes were **applied live** to WooCommerce via MCP DTB, independent of GitHub PR merge. No checkout/payment test was performed. PR #130 remains pending merge until reviewed.
