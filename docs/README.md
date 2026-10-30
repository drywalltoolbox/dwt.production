# docs/ — Drywall Toolbox documentation

This directory is organized by domain. Every doc here has been checked against the current codebase (file paths, function/route names, meta keys) as of its own "Last updated"/"Last verified" line — see the auditing bar below before adding or editing anything.

## Layout

| Folder | Covers |
|---|---|
| `checkout/` | Native WooCommerce checkout: UI/presentation architecture, desktop layout, payment reconciliation, provider migration, product-page express checkout. |
| `auth/` | Cookie/session boundaries — storefront customer auth handoff and the wp-admin/native-WordPress boundary. |
| `admin/` | Operator-facing admin platform: the admin UI shell, order-detail experience, and the self-healing/idempotent-repair audit. |
| `visual-designer/` | The `dtb-visual-designer` module: storefront visual/layout editor, email-studio preview tool, and the email design/copy system. |
| `frontend/` | Storefront React components and layout system: add-to-cart button, catalog search, responsive architecture, typography/navigation. |
| `integrations/` | Third-party integrations and their operator control centers: shared integration-control-center pattern, QuickBooks backend protocol, QuickBooks admin workspace. |
| `operations/` | Ops runbooks: cache tools, WooCommerce HTML email architecture. |
| `seo/` | Sitemap behavior. See also the `dtb-seo` skill for the broader SEO pipeline. |
| `plans/` | Forward-looking roadmaps (not "what shipped" — check the doc's own status before treating it as current). |
| `marketing/` | DTB marketing intelligence knowledge base and dated industry reference. See `marketing/knowledge-base/README.md`; it is research context, not live system truth. |
| `reference/` | Lookup data and reference material, split into `business-cards/`, `email-mockups/`, `data/` (CSVs), `market-research/` — see `reference/README.md`. Not verified against code (mostly non-code data). |
| `company/` | Business/legal documents (e.g. operating agreement). Not an engineering doc; out of scope for code-verification audits. |
| `pricing_engine/` | Pricing import templates and generated backfill/reconciliation reports. Operational data, not narrative docs. |
| `_working/` | Scratch staging for in-progress docs. Never authoritative, never linked to from code — see `_working/README.md`. |

## Conventions

- **Cross-references use full paths from `docs/`**, e.g. `` `docs/checkout/checkout-ui-architecture.md` ``, so links survive greps and don't assume a reader's current directory.
- **Every doc should carry a "Last updated" or "Last verified" line.** If you can't state one, the doc hasn't been checked against current code and shouldn't be treated as authoritative.
- **Don't duplicate content across docs.** If two docs cover related ground, cross-reference instead of restating (see `checkout/checkout-ui-architecture.md` <-> `checkout/checkout-desktop-layout.md` for the pattern).
- **New docs start in `_working/`**, then get merged into an existing doc or promoted into the right category folder above once verified — see `_working/README.md` for the full workflow.
- **CSVs and other non-prose reference data** stay in `reference/` (or `pricing_engine/` for pricing-specific operational data) — don't scatter data files into the narrative-doc folders.