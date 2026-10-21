# USG Sheetrock Tools — WooCommerce publication readiness (2026-10-20)

## Scope and live changes

- Confirmed the WordPress/MCP target was `https://drywalltoolbox.com`, not staging.
- Scoped to the **82 USG Sheetrock Tools draft product parents/simple products**, WordPress IDs 18272–18355 (excluding two existing variation IDs).
- Populated or refined 82 short descriptions from existing verified product long-form descriptions. Where a description contained only the product name, used the existing product title and SKU rather than fabricating technical specifications.
- Assigned sensible WooCommerce product categories; created `Drywall Screws & Fasteners` (4548), `Drywall Hand Tools` (4549), and `Drywall Sealants` (4550). Used existing Replacement Parts (4044), Taping & Finishing Tools (4036), and Stilts (4418) where appropriate. Retained the magnesium-stilts parent in existing Stilts category.
- Preserved existing primary optimized/upscaled WebP images (81 simple products) and the parent variable stilt gallery (9 existing images), long-form descriptions, brand metadata, product identity, regular/sale prices, shipping measures, stock values, and variation relationships.
- Updated canonical `products/launch/official/dtb_official_catalog.csv`: short-description content for 82 USG parent/simple SKUs, category cells for 81 simple products; no other CSV fields changed. Private verified CSV backups exist in ignored local evidence.

## Publication blocker: do not publish

Live WooCommerce status and availability audit detected:
- **55 of 82 parent/simple draft products have empty retail prices**. Publishing would expose products without a valid selling price. The remaining 27 have a storefront price, but pricing validity is not established across all products.
- SKU **340800**: existing WooCommerce sale price **$16.99**, existing COGS field **$83.15** from historical supplier data, an extreme negative-margin/unit-of-measure conflict. Do not re-price based on old data; verify actual supplier pack quantity, wholesale unit and sellable package.
- WooCommerce reports `manage_stock=true`, quantity `10`, and `instock` for multiple USG SKUs, including products without a retail price. Those values appear initialized uniformly, not independently verified per SKU as available-to-promise.
- DTB/Veeqo live integration health reports `inventory_initialized=true`, `inventory_stale=false`, but its latest sync had **108 unmapped SKUs** and **0 updated SKU records**. A direct Veeqo read attempt returned `link_id must identify an eligible linked account`; therefore a positive mapping/warehouse stock audit for these specific USG SKUs could not be completed.
- Because this is a live storefront taking customer payments and shipping orders, these conflicts are **go/no-go requirements**. No status was changed to `publish` in this operation.

## Required launch gate

1. Obtain fresh, authenticated supplier costs and **manufacturer pack-unit/quantity** for all 82 sellables, resolve SKU 340800's cost-versus-retail mismatch, and set approved WooCommerce retail pricing for the 55 blank-priced SKUs. Preserve historical COGS separately until reconciled.
2. Verify Veeqo mappings for each SKU/variation, available inventory in warehouse 527795, and availability gating/reconciliation; remove or correct any artificial default quantities.
3. Confirm all products have correct descriptions, taxonomy, images, tax/shipping classification, SKU/GTIN identities, and (for variable stilts) valid variation prices/stock and accessible product selectors.
4. After all gates pass, publish only the verified subset through WooCommerce, perform REST readback and real storefront discovery/cart checks without placing live test payments or shipments.

## Current state

**82 enriched drafts, 0 newly published**. This is a data-readiness hold, not a connectivity failure. Image updates from the earlier work remain live, and all enhanced descriptions/categories are saved in WordPress.
