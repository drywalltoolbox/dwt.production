---
status: derived
owner: repository-governance
scope: product-purpose-summary
source_paths:
  - AGENTS.md
  - README.md
review_triggers:
  - product-positioning-change
  - major-domain-change
  - system-authority-change
---
# DTB Product Context

Drywall Toolbox (DTB) is a contractor-focused ecommerce and service-operations
platform for professional drywall tools, replacement parts, compatible-part
discovery, schematics, repair services, returns, customer accounts, order
visibility, fulfillment, accounting projection, integrations, marketplaces,
SEO, media, catalog operations, and operator tooling.

The customer promise depends on accurate product and part identity, useful
compatibility information, clear purchase and service workflows, and reliable
post-purchase visibility. Do not fabricate product attributes, stock, prices,
compatibility, provider status, or operational outcomes to make an experience
appear complete.

## Product and authority boundaries

- The storefront presents products and customer workflows; WooCommerce remains
  authoritative for runtime commerce products, cart/session, checkout, totals,
  orders, payment state, and refunds.
- Canonical product definitions, protected identifiers, taxonomy,
  compatibility, schematic source material, and related catalog enrichment
  inputs belong to `products/`. WooCommerce owns runtime commerce records
  projected from those sources.
- DTB backend modules own bounded domain workflows such as schematics, repairs,
  returns, support, events, queues, and operator tooling, as defined in
  `AGENTS.md`.
- Veeqo remains the inventory and fulfillment authority; QuickBooks remains the
  accounting projection authority; payment providers own payment collection and
  provider-sensitive lifecycle behavior.

Build for contractors doing practical work under time pressure. Favor clear
product identity, fitment evidence, legible pricing/availability from their
authoritative sources, accessible controls, responsive layouts, and explicit
loading, validation, error, pending, recovery, and success states where the
workflow requires them. Avoid manipulative conversion patterns or unsupported
trust claims.

This file describes purpose and stable boundaries only. For current workflows,
product-specific facts, audience evidence, routes, campaign terms, and live
behavior, consult the owning catalog/domain source, current implementation,
and relevant durable documentation. It does not establish that a capability is
currently enabled or deployed.
