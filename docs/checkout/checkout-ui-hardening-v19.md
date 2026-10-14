# Checkout UI Hardening v19

Date: 2026-08-06

> Historical release note for the retired provider stack. See `woopayments-environment-architecture.md` for current payment ownership and staging rules.

## Architecture

This release hardens the native WooCommerce Checkout Block presentation without changing commerce or payment ownership. WooCommerce remains authoritative for fields, validation, cart/session state, shipping, tax, totals and order creation. Payment Plugins for Stripe remains authoritative for provider UI, wallets, tokenization, authentication, confirmation and capture.

DTB owns only the native checkout document shell, responsive layout, page-level styling, supported Stripe Appearance values and mobile progressive enhancement.

## Implemented changes

### Deterministic asset invalidation

A handle-scoped MU-plugin adapter replaces the static `DTB_VERSION` query parameter for DTB checkout CSS and JavaScript with each local file's `filemtime()`. This prevents browser, CDN and SiteGround caches from serving mixed generations of checkout assets while leaving global theme asset policy unchanged.

### Mobile accordion ownership

The accordion navigation is mounted immediately before the WooCommerce checkout root. It is never inserted into WooCommerce's React-managed inner step parents. Native groups stay in their original DOM locations.

`classifyStepGroups()` remains the sole grouping authority and continues to use WordPress block identity classes. Available sections are derived dynamically, so physical-product carts can render Contact / Shipping / Payment while virtual carts can render Contact / Payment without a missing-array failure.

Collapsed groups remain mounted and inert. Expansion and collapse use measured pixel heights followed by intrinsic `auto` height; no fixed maximum-height ceiling is used. Payment expansion dispatches a post-layout resize event without reaching into provider iframes.

### Validation and focus

Native `invalid` events and form `submit` events reveal the section containing the first invalid native control. Manual header activation preserves focus on the activated header. Controlled progression moves focus only after a deterministic Contact email change or a completed Shipping interaction/rate-calculation transition. Background store changes alone do not move focus.

### Responsive presentation

The base stylesheet now provides one card, typography, input and option language across mobile, tablet and desktop. Tablet has a deliberate 768–1023px single-column layout. Desktop uses the current `--dtb-checkout-*` token namespace and no longer creates an internally scrolling order-summary sidebar.

The payment fieldset legend remains in the accessibility tree. Redundant top-level native step headings are visually hidden only while the mobile accordion enhancement is active and are restored when it unmounts.

### Template simplification

The static progress breadcrumb and unsupported trust-policy claims were removed. A compact Back to cart link remains. No checkout state or policy truth is duplicated in the template.

### Regression gate

`scripts/validate-checkout-ui.mjs` and `.github/workflows/checkout-ui-contract.yml` enforce the architecture on pull requests. The contract rejects React-subtree header insertion, fixed accordion height ceilings, retired CSS tokens, hidden payment legends, nested desktop scrolling, static breadcrumb/trust markup and missing filemtime asset versioning.

## Changed files

- `drywalltoolbox/wp/wp-content/themes/drywall-toolbox/assets/checkout/checkout.js`
- `drywalltoolbox/wp/wp-content/themes/drywall-toolbox/assets/checkout/checkout.css`
- `drywalltoolbox/wp/wp-content/themes/drywall-toolbox/assets/checkout/checkout-desktop.css`
- `drywalltoolbox/wp/wp-content/themes/drywall-toolbox/templates/checkout/native-checkout.php`
- `drywalltoolbox/wp/wp-content/mu-plugins/dtb-checkout-asset-version.php`
- `drywalltoolbox/wp/wp-content/mu-plugins/dtb-commerce/Payment/StripeElementAppearance.php`
- `scripts/validate-checkout-ui.mjs`
- `.github/workflows/checkout-ui-contract.yml`

## Impact

Data/migration impact: none.

Security impact: no authentication, authorization, nonce, CORS, session, payment or webhook boundary changes. Unsupported static security claims were removed from customer-facing markup.

API/queue/integration impact: none. No Store API write behavior, order workflow, Action Scheduler producer/consumer, Veeqo, QuickBooks or provider execution contract changed.

## Required rendered QA

Before production sign-off, test 320, 390, 428, 768, 1024, 1440 and 1920px widths; guest and authenticated sessions; physical and virtual carts; browser autofill; saved addresses; separate billing; coupons; shipping-rate recalculation; card, saved card, 3DS/SCA, Apple Pay, Google Pay, Link and enabled BNPL methods; invalid-field discovery; rotation across 768px; and SiteGround cache purge/deployment behavior.
