# Checkout UI Redesign v18 — Mobile Accordion

Date: 2026-08-06

> Historical design note for the retired provider stack. See `woopayments-environment-architecture.md` for current payment ownership and staging rules.

## Architecture

Redesign v18 replaces the mobile-only Contact / Shipping / Payment wizard chrome with a single-open accordion. The WooCommerce Checkout Block remains the only checkout form and order-creation surface. Payment Plugins for Stripe remains the only payment-state and payment-execution authority.

The redesign is progressive enhancement under `max-width: 767px`. Viewports from 768–1023px retain the existing native single-scroll Checkout Block behavior. Viewports at 1024px and above retain the existing `checkout-desktop.css` two-column layout. This change does not introduce a second breakpoint or layout authority.

## Grouping authority

`assets/checkout/checkout.js::classifyStepGroups()` remains the sole authority for assigning rendered WooCommerce block groups to Contact, Shipping, and Payment. It continues to use WordPress block identity classes:

- Contact: `.wp-block-woocommerce-checkout-contact-information-block`
- Shipping: `.wp-block-woocommerce-checkout-shipping-address-block`, `.wp-block-woocommerce-checkout-shipping-method-block`, `.wp-block-woocommerce-checkout-pickup-options-block`, `.wp-block-woocommerce-checkout-billing-address-block`
- Payment: `.wp-block-woocommerce-checkout-payment-block`, `.wp-block-woocommerce-checkout-express-payment-block`, `.wp-block-woocommerce-checkout-order-note-block`, `.wp-block-woocommerce-checkout-terms-block`, `.wp-block-woocommerce-checkout-actions-block`

There is no document-position fallback. If required groups cannot be resolved, the enhancement fails safe to WooCommerce's native single-scroll checkout.

## Interaction contract

- Exactly one accordion section is expanded at a time.
- Expanding a section collapses its siblings.
- Collapsed native groups stay mounted in their original DOM locations. They are height-collapsed, visually hidden, and marked `inert`; no fields, wallets, provider iframes, or submission controls are cloned, moved, replaced, or unmounted.
- Section completion uses native HTML constraint validation scoped to controls inside that section.
- Valid Contact and Shipping sections can auto-advance after a bounded delay. Invalid fields are not surfaced merely because a customer opens another accordion section.
- A Place Order attempt performs checkout-wide invalid-field discovery. The accordion opens the section containing the first invalid native control, then focuses, scrolls to, and invokes native `reportValidity()` for that control.
- The native WooCommerce Place Order button remains the only submit action.

## Stripe mount and layout safeguard

The Stripe Payment Element remains mounted while its containing Payment group is collapsed. The implementation does not apply `display: none` to the provider iframe. On first and subsequent Payment-section expansion, the interaction layer schedules two animation frames and dispatches a window resize event after the section becomes visible so provider layout can repaint against its real width.

Stripe iframe styling remains exclusively owned by `mu-plugins/dtb-commerce/Payment/StripeElementAppearance.php` through the Stripe Appearance API.

## Styling and tokens

`assets/checkout/checkout.css` now owns a complete checkout-specific token layer for color, surfaces, spacing, radii, shadows, touch targets, motion durations, and easing. Token names use the `--dtb-checkout-*` namespace to avoid colliding with similarly named storefront tokens that previously carried different values.

The mobile accordion uses height, opacity, and transform transitions. `prefers-reduced-motion: reduce` normalizes animation and transition duration to `0.01ms`. Focus visibility, forced-colors borders, autofill legibility, safe-area padding, and 48px minimum touch targets remain required.

## Files changed

- `drywalltoolbox/wp/wp-content/themes/drywall-toolbox/assets/checkout/checkout.js`
- `drywalltoolbox/wp/wp-content/themes/drywall-toolbox/assets/checkout/checkout.css`
- `docs/checkout/checkout-ui-redesign-v18.md`

## Ownership and data impact

Owning module: `drywalltoolbox/wp/wp-content/themes/drywall-toolbox/assets/checkout/` presentation layer.

Data or migration impact: none. No schema, option, order, cart, customer, payment, inventory, queue, webhook, or provider-state migration is introduced.

Security impact: no authorization, session, nonce, CORS, origin, payment, or webhook boundary changes. Collapsed controls are inert only as presentation behavior; WooCommerce remains authoritative for validation and submission.

API, queue, and integration impact: none. No Store API writes, order workflow changes, Action Scheduler changes, Veeqo calls, QuickBooks projections, or payment-provider contracts are introduced.

## Required validation before production sign-off

1. Validate 320px and 390px mobile widths with guest and authenticated carts.
2. Confirm only one section is expanded and that opening any header collapses the others without losing field values.
3. Confirm browser autofill and saved WooCommerce addresses remain intact across collapse and expansion.
4. Confirm shipping recalculation completes before Shipping auto-advances.
5. Attempt Place Order with an invalid Contact field and an invalid Shipping field; confirm the correct section opens and the first invalid native control receives focus.
6. Confirm Payment Element, saved cards, 3DS/SCA, Apple Pay, Google Pay, Link, and every enabled BNPL method paint correctly on the first Payment expansion.
7. Rotate or resize from mobile to 768px and above; confirm accordion chrome is removed, every native group is visible, and no element remains inert.
8. Confirm 768–1023px behavior remains single-scroll and unchanged.
9. Confirm 1024px, 1440px, and 1920px desktop layouts remain owned by `checkout-desktop.css` with no horizontal overflow or second grid authority.
10. Confirm no duplicate fields, payment rows, wallets, submit actions, payment attempts, orders, notices, or downstream side effects.

Real payment acceptance and Payment Element repaint must be verified on a real eligible device against staging or production-equivalent HTTPS. Automated verification from this environment remains insufficient where SiteGround's WAF returns a challenge.

## Rollback

Revert the v18 `checkout.js` and `checkout.css` commits together and remove this document. Clear SiteGround page, dynamic, and browser caches, then rerun the mobile checkout and payment validation matrix. Do not alter orders created during a failed presentation cutover.
