# Desktop Checkout Layout Contract

Last updated: 2026-08-09.

## Scope

This document defines the desktop presentation contract for the native WooCommerce Checkout Block at `/checkout/` and records the responsive presentation assets that must ship with it. Desktop layout rules apply at viewport widths of 1024px and wider; shared typography, header, field, and component styling remains mobile-first.

## Authority

Full checkout authority boundaries (WooCommerce, WooPayments, and DTB ownership) are defined in `docs/checkout/woopayments-environment-architecture.md` and `docs/checkout/product-express-checkout.md`. This document adds only the desktop-specific presentation contract: the active Drywall Toolbox theme must not clone, move, reparent, hide, replace, fabricate, or manually remount native checkout fields, payment controls, order-summary data, or the Place Order action.

## Active files

```text
drywalltoolbox/wp/wp-content/themes/drywall-toolbox/templates/checkout/native-checkout.php
drywalltoolbox/wp/wp-content/themes/drywall-toolbox/assets/checkout/checkout.css
drywalltoolbox/wp/wp-content/themes/drywall-toolbox/assets/checkout/checkout-summary.css
drywalltoolbox/wp/wp-content/themes/drywall-toolbox/assets/checkout/checkout.js
drywalltoolbox/wp/wp-content/themes/drywall-toolbox/assets/checkout/checkout-desktop.css
```

`checkout.css` owns shared tokens, typography, fields, controls, and shell presentation. `checkout-summary.css` owns mobile/tablet composition through 1023px. `checkout-desktop.css` owns only the desktop grid and desktop-specific density inside a `min-width: 1024px` media query. `checkout.js` remains a passive no-op boundary; it does not orchestrate WooCommerce DOM or state.

## Desktop architecture

At viewport widths of 1024px and wider:

- `.wc-block-components-sidebar-layout` is the sole two-column layout authority;
- the native main checkout region occupies the flexible left track;
- the native order-summary region occupies a bounded 390–480px right track;
- the order summary remains sticky beneath the branded header;
- the left region remains one vertical document flow;
- native DOM order is preserved;
- Express Checkout remains provider-owned and renders wherever the native Checkout Block/provider configuration places it;
- no CSS `order`, DOM reparenting, cloning, mutation observer, or provider remounting is used;
- no `:has()` selector is used to discover or infer layout ownership;
- no second grid is applied to the form or main checkout region;
- no provider iframe contents are inspected or styled.

## Root cause and correction

The previous implementation created a grid parent but left its direct children vulnerable to WooCommerce's later-loaded percentage widths. WooCommerce assigned 65% to the main child and 35% to the sidebar child; inside the DTB grid, those percentages shrank each child within its already-sized track and reduced a 460px sidebar track to roughly 161px.

The corrected design keeps the published WooCommerce sidebar-layout component as the only desktop grid. Checkout-scoped direct-child rules make both structural children fill their assigned tracks regardless of stylesheet order, the main region remains a normal block flow, and the order summary fills the bounded sidebar track. Only known top-level Checkout Block regions receive width, spacing, and sticky-summary presentation rules.

## Responsive preservation contract

The following native behavior must remain unchanged below 1024px even when presentation is refined:

- mobile Contact → Shipping → Payment wizard;
- progress rail;
- Back/Continue action bar;
- native order-summary disclosure behavior;
- native/provider payment presentation and selection state;
- a shrink-safe header that keeps both the store logo and Stripe attribution within the viewport;
- tablet single-column checkout behavior;
- mobile visibility, inert-state, validation, and navigation logic.

No desktop change is accepted if it alters these behaviors.

## Asset loading and cache behavior

`native-checkout.php` already enqueues `checkout-desktop.css` before `wp_head()` with:

- handle: `dtb-checkout-desktop`;
- dependency: `dtb-checkout`;
- version: the desktop stylesheet's `filemtime()`, falling back to `DTB_VERSION` when unavailable.

This preserves deterministic cascade order and cache invalidation across the shared, mobile/tablet, and desktop presentation assets.

## Breakpoints

- `< 768px`: compact single-column mobile checkout and native order-summary disclosure.
- `768–1023px`: single-column tablet checkout.
- `1024–1439px`: two-column desktop layout with a fluid 410–460px summary rail.
- `1440–1799px`: 460px summary rail.
- `>= 1800px`: 1480px canvas with a 480px summary rail.

## Validation requirements

Before production acceptance, verify at 375, 430, 768, 1024, 1280, 1440, 1600, 1920, and 2560px:

1. Mobile and tablet views are visually and behaviorally unchanged.
2. Desktop main form and order summary render side by side without horizontal overflow.
3. Contact, shipping address, shipping options, payment options, notes, terms, and Place Order remain in one left-column flow.
4. Express Checkout remains mounted, clickable, and provider-controlled when eligible.
5. Order summary remains in the right rail and does not overlap the header or footer.
6. Long product names, SKUs, carrier names, addresses, coupons, and validation messages wrap without clipping.
7. Sidebar scrolling does not trap the page or hide the final total.
8. Guest and authenticated checkout preserve cart/session state.
9. Card, saved card, 3DS/SCA, Apple Pay, Google Pay, Link, and enabled BNPL methods remain functional.
10. No duplicate controls, duplicate payment attempts, duplicate orders, console errors, PHP notices, or provider remount loops occur.
11. Resizing across 1024px does not leave mobile wizard groups hidden or inert.

## Database and integration impact

None. No schema, option, order, customer, HPOS, Action Scheduler, event-ledger, Veeqo, QuickBooks, or payment-provider data migration is introduced.

## Deployment

Transfer the complete reviewed checkout presentation set for responsive changes:

```text
drywalltoolbox/wp/wp-content/themes/drywall-toolbox/functions.php
drywalltoolbox/wp/wp-content/themes/drywall-toolbox/templates/checkout/native-checkout.php
drywalltoolbox/wp/wp-content/themes/drywall-toolbox/assets/checkout/checkout.css
drywalltoolbox/wp/wp-content/themes/drywall-toolbox/assets/checkout/checkout-summary.css
drywalltoolbox/wp/wp-content/themes/drywall-toolbox/assets/checkout/checkout-desktop.css
```

Do not overwrite WordPress core, `wp-config.php`, regular plugins, uploads, caches, logs, runtime secrets, or server-owned state. Clear SiteGround dynamic cache, file cache, CDN cache if enabled, and browser cache after transfer.

## Rollback

Restore the previously deployed versions of the five files above as one presentation-consistent set, clear all applicable caches, and repeat desktop and mobile checkout smoke testing. Do not modify or delete orders created during a failed visual cutover.
