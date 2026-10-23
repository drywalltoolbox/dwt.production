# DTB — Preliminary published-catalog shipping classification inventory

Source: read-only **live WooCommerce product listing** on `drywalltoolbox.com`, 2026-10-23. All 5 product pages retrieved (100/100/100/100/83). Dataset: **483 published products**, **483 nonvirtual products**, **0 blank SKU values**.

This is a **preliminary SKU-keyed triage dataset**, not an approved shipping-class import. A product *name* is **not proof** of actual carton size, packaging, shipping eligibility or service type. The proposals are strictly conservative name heuristics. Shipping weight/dimensions are deliberately blank because the lightweight live products listing does not expose them. Actual Woo product/variation shipping attributes require a second, per-product read pass or the `wp dtb shipping audit` WP-CLI script. DO NOT import this file into WooCommerce or auto-assign shipping classes from it.

Counts by tentative group:
- complete-toolsets: 12
- long-bulky-tools: 8
- small-parts: 130
- standard-tools: 26
- unclassified: 307

## Full-audit completion gates

1. Execute `scripts/wordpress/audit-shipping-packaging.php` read-only against a verified WooCommerce environment and merge weight/dimension/class gaps into this SKU sheet.
2. Manually verify ambiguous class assignments, especially kit vs replacement-component names. Confirm published variations have complete inherited/own metadata.
3. Record **measured ready-to-ship** carton(s), packed weight, service restrictions and warehouse review separately from manufacturer product dimensions.
4. Approve free-shipping applicability and pricing by category; do not assume that $50 free shipping covers oversize freight or multi-box sets.
5. Configure native WooCommerce methods after merchant-approved rates, international destination policy and Veeqo service mapping are resolved.
6. Run Store API/Checkout Block smoke tests in isolation before production cutover. No live change has been performed.

## High-risk SKU examples requiring physical packing review

- `TTBTS` — TapeTech Basic Taping Set
- `TTCFS` — TapeTech Corner Finishing Set
- `TTCFS-M` — TapeTech MudRunner Corner Finishing Set
- `TTPPS` — TapeTech Pro Performance Set
- `TTPSS` — TapeTech Professional Starter Set
- `PT-FIN` — Platinum Drywall Starter Finishing Set
- `4-677P` — LEVEL5 The Ultimate Taping & Finishing Set
- `LV5-ENTRY-FINISHING-SET` — LEVEL5 Entry to Automatic Finishing Set
- `4-630` — LEVEL5 The Starter Taping Set

Full 483-product matrix: `products/launch/shipping-readiness-inventory-20261023.csv`.
