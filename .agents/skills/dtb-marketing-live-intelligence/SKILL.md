---
name: dtb-marketing-live-intelligence
description: Read-only business evidence orchestration for DTB marketing, catalog-accurate campaigns and storefront launches.
---
# DTB Marketing — Live Evidence & Launch Campaign Intelligence

## Authority and scope
Use repository `AGENTS.md`, `.agents/registry.json`, active implementation, runtime evidence and existing research workflow. This is a routed specialist method, **not** a replacement doctrine or a deployable analytics service. GitHub `drywalltoolbox/dwt.production` is canonical source; MCP_DTB must confirm `https://drywalltoolbox.com` before any business read or action. No other WordPress connector is authoritative.

## Required evidence collection — always read fresh
1. GitHub: identify active `products/` catalog CSV(s), their generation provenance, SKU/MPN/GTIN, product assets and brand system. Resolve storefront routing in `frontend/src/App.jsx` and `frontend/src/routing/routeModules.js`; repair pages in current React and MU repair domain. Inspect `drywalltoolbox/wp/wp-content/mu-plugins/dtb-marketing/bootstrap.php` to identify implemented marketing APIs, not assumed ones.
2. MCP_DTB: read `core/get-site-info` and verify canonical URL. Read paginated `wp_wc_list_products` and categories; fetch full product/variation for each advertised SKU via `wp_wc_get_product` and variation query. Verify permalink, availability, current price, currency, image identity, discount dates, and SKU/MPN/brand. Reconcile with repo `products/` first-party catalog. Never use a generated frontend fixture as price/stock authority.
3. Repair service: inspect source routing, current runtime route and repair workflow status; no actual paid repair test in production.
4. Analytics/ad providers (GA4, Search Console, Merchant Center, Google Ads, Meta, email ESP): discover authorized integrations explicitly. If unavailable, label NOT CONNECTED and do not invent impressions, audiences, attribution, ROAS or consent status.
5. Competitor ad intelligence: use dated public ad-library evidence; company websites do not confirm active ads or spend.

## Evidence record
For each marketing claim store `claim_id`, source system, source identifier (product ID/variation ID and SKU), observed timestamp, verification status, approved wording, material conflicts, claim owner and expiry/recheck condition. Price, stock and promotions require pre-publish refresh; manufacturer compatibility needs authoritative fitment evidence. Distinguish observed Woo price from approved advertised offer.

## Initial verified read — 2026-10-29 (historical, NOT live cache)
- MCP_DTB core site URL: `https://drywalltoolbox.com` and WP URL `https://drywalltoolbox.com/wp`.
- WooCommerce product ID 27778: USG Sheetrock Tools Paper Face Bead Hopper, SKU 340311, simple/published/in-stock, price 198.88 USD as returned by product endpoint, permalink `https://drywalltoolbox.com/product/usg-sheetrock-tools-paper-face-bead-hopper/`. Contains an image at `/wp/wp-content/uploads/2026/media/usg_sheetrock_tools_340311_01.webp`. Brand labeling and technical capacities require independent manufacturer verification before advertising as factual claims.
- WooCommerce product ID 27768, SKU `3SDSZ2M`, returned published/in-stock with an empty price in a sampled product list. Block price-led creative until product eligibility is investigated.
- Category ID 5608 Automatic Taping Tools; category 5971 Automatic Tapers; category 6039 Automatic Tool Sets & Kits.
- GitHub main read confirmed `.agents/registry.json`, `frontend/src/App.jsx`, `frontend/src/routing/routeModules.js`, and `dtb-marketing/bootstrap.php` with ComingSoon, ProductSeo and GeminiStorefront modules.
Historical observations must NEVER be treated as ongoing synchronized state.

## Campaign outputs and controls
A campaign must contain: approved brief; claim-evidence ledger; product/variation list; landing route and real WP permalink mapping; campaign copy and production manifest; approval ledger; channel asset spec; UTM plan; metric/event definitions; consent handling; experiment register; publishing calendar; QA, and rollback.
Default is DRAFT. Publishing ads, website modifications, outbound marketing, coupons, spending budget or changing tracking require independent authorization. Never alter WooCommerce checkout/order/payment/refund authority; preserve native Checkout Block and provider lifecycle.

## Run-time decision
If any source has stale or conflicting product identity, price, compatibility, image rights or route, mark that asset BLOCKED. If ad reporting and analytics connectors have not been authorized, report integration missing and propose provider-specific setup; don't call a static CSV "live sync".
