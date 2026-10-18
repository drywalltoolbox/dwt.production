# WooCommerce Order Admin Experience

## Scope

The DTB order-detail experience is a presentation-only enhancement of the native WooCommerce order editor. WooCommerce remains authoritative for HPOS order persistence, status transitions, customer and address fields, line items, taxes, totals, notes, payments, refunds, and save behavior.

DTB continues to own only its existing order-platform panels: event timeline, integration state, tracking projection, rewards state, and queue-backed operator recovery actions.

## Implementation

The scoped module is loaded from:

```text
drywalltoolbox/wp/wp-content/mu-plugins/dtb-order-platform/Admin/OrderDetailExperience.php
```

It adds `dtb-order-detail-screen` only to valid WooCommerce order-edit requests and enqueues:

```text
drywalltoolbox/wp/wp-content/mu-plugins/dtb-order-platform/Admin/assets/order-detail.css
```

The stylesheet does not replace WooCommerce templates, controls, save handlers, or metabox callbacks. It refines BrikPanel's existing tabbed workspace and two-column grid: a wider operational rail on desktop, compact order-item presentation, clearer notes and totals, modernized controls, and scoped DTB timeline/integration/action styling. BrikPanel continues to own the tab behavior and placement of native WooCommerce metaboxes.

The visual refinement is controlled by `DTB_ORDER_DETAIL_REFINEMENT` (enabled by default and overridable through the central `dtb_feature_enabled()` helper). Setting the constant to `false` removes the refinement scope class and restores BrikPanel's original order layout styling without disabling WooCommerce or BrikPanel functionality.

## BrikPanel custom CSS contract

BrikPanel custom CSS loads after the repository stylesheet. Operators may tune the supported variables on `.dtb-order-detail-screen` without copying the structural stylesheet into wp-admin settings.

```css
.dtb-order-detail-screen {
    --dtb-order-card-radius: 12px;
    --dtb-order-page-gap: 18px;
    --dtb-order-accent: #2563eb;
}
```

The order screen must remain usable and visually complete when the BrikPanel custom CSS field is empty.

## Security and ownership

- Assets load only on order-edit requests.
- The module requires order-edit capability before enqueueing. The feature flag controls only DTB's scoped visual refinement.
- No REST route, AJAX action, nonce, order mutation, queue behavior, integration state, or external API call is added or changed.
- No payment, refund, fulfillment, accounting, or tracking authority is moved into the browser.
- No JavaScript, structural DOM relocation, REST route, AJAX action, nonce, order mutation, queue behavior, integration state, or external API call is added or changed.

## Validation

Validate both HPOS and legacy order screens where supported:

- paid, unpaid, processing, completed, cancelled, and refunded orders;
- guest and registered customers;
- long notes, many line items, and long integration errors;
- Veeqo and QuickBooks pending, queued, synced, and failed states;
- refund, status, Save, note, and DTB operator-action regression;
- desktop, compact desktop, and narrow wp-admin layouts;
- keyboard focus, forced-colors, and reduced-motion behavior.

## Deployment and rollback

Deploy the bootstrap, presentation module, stylesheet, and this document as one reviewed change set. No database migration is required.

Rollback by restoring the previous bootstrap and removing the new module and stylesheet. Clear SiteGround and browser caches, then verify native WooCommerce Save, status, notes, refund, and DTB operator actions on representative orders.
