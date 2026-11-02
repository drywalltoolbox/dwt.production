---
status: derived
owner: repository-governance
scope: system-architecture-summary
source_paths:
  - AGENTS.md
  - drywalltoolbox/wp/wp-content/mu-plugins/00-dtb-loader.php
  - .agents/registry.json
  - .agents/context/connected-systems.md
review_triggers:
  - ownership-change
  - composition-change
  - checkout-contract-change
  - persistence-contract-change
  - integration-contract-change
---
# DTB Architecture Context

This is a concise navigation aid, not an authority. Active implementation and
current composition/routing outrank this summary. `AGENTS.md` defines durable
ownership and safety invariants; `.agents/registry.json` defines deterministic
task routing. Resolve substantial or cross-domain work through the registry.
The connected-system map describes operational interfaces and their boundaries;
it does not grant authority or replace the owning systems.

## System boundaries

```text
React storefront
  -> same-origin WordPress REST / WooCommerce Store API
  -> WooCommerce cart/session and commerce persistence
  -> native WooCommerce checkout and Checkout Block
  -> payment-provider-owned payment lifecycle
  -> WooCommerce order/payment state
  -> DTB event ledger and Action Scheduler queues
  -> Veeqo / QuickBooks / notifications / marketplaces
```

- **React** owns customer-facing presentation, routing, local interaction state,
  accessibility, and API consumption. It does not own commerce truth.
- **WooCommerce** owns runtime products/variations, customer and cart/session
  state, checkout, shipping/tax/totals, orders, payment state, and refunds.
- **DTB MU Plugins** own backend domain policy, authorization, event/queue
  boundaries, integration orchestration, and the domain capabilities defined in
  `AGENTS.md`.
- **Action Scheduler** is the asynchronous execution mechanism for DTB backend
  work. Do not add a parallel domain queue.
- **Veeqo** owns inventory, allocation, fulfillment, shipping, shipment state,
  and tracking. WooCommerce/DTB may hold bounded projections only.
- **QuickBooks** owns accounting projection; it is not commerce or fulfillment
  authority.
- **Payment providers** own sensitive payment collection, tokenization,
  authentication, provider UI, confirmation/capture semantics, and provider
  webhook behavior.

## Storefront checkout contract

```text
React cart / Checkout Now
  -> authoritative WooCommerce Store API cart/session
  -> full-document checkout handoff
  -> native WooCommerce checkout / Checkout Block
  -> provider-owned payment lifecycle
  -> WooCommerce order/payment lifecycle
  -> DTB qualifying event observation and ledger
  -> dtb-orders Action Scheduler queue
  -> downstream integrations/projections
```

WooCommerce creates storefront orders and refunds. React `/checkout` is a
handoff/compatibility surface, not an independent payment application. DTB must
not create a parallel order, refund, or provider-sensitive payment path. Only
qualifying authoritative order/payment evidence may trigger downstream effects;
retries and repeated callbacks must be contained by stable identity and
idempotency.

## Backend composition

`drywalltoolbox/wp/wp-content/mu-plugins/00-dtb-loader.php` is the active MU
plugin composition root. Its current explicit order is:

1. `dtb-platform`
2. `dtb-catalog-platform`
3. `dtb-commerce`
4. `dtb-order-platform`
5. `dtb-schematics`
6. `dtb-media`
7. `dtb-marketing`
8. `dtb-repair-service`
9. `dtb-integrations`
10. `dtb-support`
11. `dtb-returns`
12. `dtb-deployment`
13. `dtb-visual-designer`

Preserve order unless a demonstrated dependency change requires otherwise.
Domain ownership is semantic: for example, catalog identity belongs to
`dtb-catalog-platform`, checkout policy to `dtb-commerce`, order event and
refund identity to `dtb-order-platform`, and provider transport to
`dtb-integrations`. The complete ownership map is in `AGENTS.md` §8; inspect
active module code before changing behavior.

## Identity and mutation invariants

- Keep one authoritative writer per durable concern; preserve stable business,
  order, refund, event, and provider identities across boundaries.
- Refund-event identity is `order_id + refund_id`; distinct partial refunds are
  distinct events.
- External mutations need correlation, idempotency, bounded retry, explicit
  terminal failure, redacted diagnostics, and a recovery/reconciliation path.
- Validate server-side identity, capability, ownership, and writable fields.
  Never trust caller-supplied customer/order identity or unsigned session data.
- Preserve Store API cookie/session and nonce behavior for same-origin cart
  traffic. `Cart-Token` is only compatibility behavior for genuine cross-origin
  clients.
- Do not introduce parallel authorities for inventory, fulfillment, refunds,
  accounting, payment, or asynchronous work.

For any material change to ownership, checkout, persistence, queue identity,
provider boundaries, or module composition, use the architecture workflow and
update the owning durable documentation. Verify mutable runtime details in
active source; this summary does not assert deployment state or provider
activation.

For current live WordPress, Veeqo, QuickBooks, source-control, research, and
deployment connections, follow [connected systems](connected-systems.md).
