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

The BrikPanel order workspace is refined by `dtb-brikpanel/Orders/OrderEditExperience.php`. It moves WooCommerce's existing order-data postbox above BrikPanel's existing tabs and moves the native notes postbox into the right rail, after order actions. Item, customer-history, More-tab, summary and action postboxes retain their native nodes and owners. The adapter does not replace fields or callbacks. Order totals remain in WooCommerce's native order-items postbox, and BrikPanel's right sidebar remains the layout owner.

The visual refinement is controlled by `DTB_ORDER_DETAIL_REFINEMENT` (enabled by default and overridable through the central `dtb_feature_enabled()` helper). Setting the constant to `false` removes the refinement scope class and restores BrikPanel's original order layout styling without disabling WooCommerce or BrikPanel functionality. `DTB_ORDER_EDIT_WORKSPACE_V2` and `DTB_ORDER_QUICK_VIEW_V2` default to enabled and can each be disabled through the same feature-flag mechanism.

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

## Order Quick View V2

The list-page preview remains WooCommerce's native Backbone modal and preview
AJAX flow. The opt-in DTB adapter wraps WooCommerce's existing item HTML with
order date/item-count context and read-only summary/customer cards, then applies
responsive styling to the existing modal. It does not replace the Backbone
template, duplicate its lifecycle or scroll locking, add an AJAX endpoint, or
mutate orders. The native footer and Edit action remain in control.

V2 loads when `DTB_ORDER_QUICK_VIEW_V2` is enabled through
`dtb_feature_enabled()`; its default is `true`. A fraud/risk card is omitted
because no verified, read-only provider data contract is established for the
preview.

## Responsive layout contract

The BrikPanel order-edit grid remains two-column while its main workspace and
operational rail have sufficient room. At 850 CSS pixels and below, the grid
becomes a single column: the native order workspace comes first, followed by
the existing actions, notes, risk, and other sidebar boxes. Billing and
shipping fields stack at the same breakpoint. Tabs remain keyboard reachable
and horizontally scrollable when their full set does not fit. The native
line-item table scrolls within its own region to retain every editing control
without widening the page.

Quick View uses the native modal lifecycle and a viewport-bounded mobile
surface. Its line items change to labeled stacked rows on narrow screens so
product identity and every price/tax/quantity value remain visible without
horizontal table scrolling. The modal footer remains reachable while the
detail content scrolls.

These are layout contracts from the stylesheet; rendered behavior still
requires comparison in authenticated desktop, tablet, and phone browsers.

## Security and ownership

- Assets load only on order-edit requests.
- The module requires order-edit capability before enqueueing. The feature flag controls only DTB's scoped visual refinement.
- No REST route, AJAX action, nonce, order mutation, queue behavior, integration state, or external API call is added or changed.
- No payment, refund, fulfillment, accounting, or tracking authority is moved into the browser.
- The default refinement adds no structural DOM relocation. The gated workspace adapter relocates the existing order-data postbox and tab container within the current form only; it adds no REST route, AJAX action, nonce, order mutation, queue behavior, integration state, or external API call.
- Quick View V2 preserves the native WooCommerce preview template, item table, and modal lifecycle. A scoped MutationObserver composes the native nodes into the responsive layout; no order-management actions are added.

## Validation

Validate both HPOS and legacy order screens where supported:

- paid, unpaid, processing, completed, cancelled, and refunded orders;
- guest and registered customers;
- long notes, many line items, and long integration errors;
- Veeqo and QuickBooks pending, queued, synced, and failed states;
- refund, status, Save, note, and DTB operator-action regression;
- desktop, compact desktop, and narrow wp-admin layouts;
- keyboard focus, forced-colors, and reduced-motion behavior.

After deployment, compare both screens with the supplied mockups at desktop,
compact desktop, tablet and mobile widths. Verify form submission, tab state,
status/save controls, notes, refund display, third-party boxes, native modal
Escape/close/focus restoration and Edit navigation. Do not exercise status,
refund, email or fulfillment operations against real customer orders.

## Deployment and rollback

Deploy the bootstrap, presentation module, stylesheet, and this document as one reviewed change set. No database migration is required.

Rollback by restoring the previous bootstrap and removing the new module and stylesheet. Clear SiteGround and browser caches, then verify native WooCommerce Save, status, notes, refund, and DTB operator actions on representative orders.
