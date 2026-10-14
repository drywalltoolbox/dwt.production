# Checkout UI Hardening v20 — mobile runtime defect fixes

Date: 2026-08-06

> Historical release note for the retired provider stack. See `woopayments-environment-architecture.md` for current payment ownership and staging rules.

## Scope

This pass fixes seven mobile (390x844) defects confirmed against a live-site runtime diagnostic capture of `/checkout/` taken after v19 shipped. None of these are architecture changes: WooCommerce Checkout Block remains the only order-creation surface, Payment Plugins for Stripe remains the only payment-execution authority, and the wizard/accordion grouping contract documented in `checkout-ui-redesign-v18.md` (Contact / Shipping / Payment, express payment groups with Payment) is unchanged in intent. These are CSS-specificity corrections and two selector-accuracy bugs against the actual rendered WooCommerce 11.0.0 Blocks markup on this install.

## Fixes

### 1. Place Order button lost its branded styling (specificity)

`.wc-block-components-checkout-place-order-button` (checkout.css) was a single-class selector (specificity 0,1,0). Two WooCommerce/WordPress-core rules beat it:

- `body:not(.woocommerce-block-theme-has-button-styles) .wc-block-components-button:not(.is-link) { min-height: 3em; }` — specificity 0,3,1 — always wins outright regardless of load order.
- WordPress core `global-styles-inline-css`: `:root :where(.wp-element-button, .wp-block-button__link) { background-color: ...; padding: ...; }` — specificity 0,1,0 (`:where()` contributes zero), tying the old DTB rule; `global-styles-inline-css` is emitted later in `<head>` than `dtb-checkout.css`, so it won the tie.

Added a second, higher-specificity (0,4,1) restatement: `body.woocommerce-checkout .wc-block-checkout__form .wc-block-checkout__actions .wc-block-components-checkout-place-order-button`, using only WooCommerce's own real ancestor classes (`body.woocommerce-checkout` is WooCommerce core's checkout body class; `.wc-block-checkout__form` and `.wc-block-checkout__actions` are the Checkout Block's own classes). This beats both competing rules unconditionally, independent of stylesheet load order. No `!important` used.

### 2. Checkout step "card" lost background/border/padding (specificity)

`.wc-block-components-checkout-step` (specificity 0,1,0) lost to WooCommerce Blocks' `.wc-block-components-form .wc-block-components-checkout-step { background: none; border-style: none; padding: 0; }` (specificity 0,2,0), which loads after `dtb-checkout.css`. Added `body.woocommerce-checkout .wc-block-checkout__form .wc-block-components-checkout-step` (specificity 0,3,1) restating background/border/border-radius/box-shadow/margin/padding.

This also required two follow-up specificity corrections to avoid new regressions from the higher-specificity base rule:

- The pre-existing nested-step reset (`.wc-block-components-checkout-step .wc-block-components-checkout-step`, 0,2,0) gained a matching `body.woocommerce-checkout .wc-block-checkout__form .wc-block-components-checkout-step .wc-block-components-checkout-step` (0,4,1) restatement so a nested step (if one is ever rendered) still resets correctly instead of being outranked by the new base rule.
- The tablet-only padding override (`@media (768px–1023px)`) and the `forced-colors: active` border override both previously targeted the plain `.wc-block-components-checkout-step` (0,1,0) selector, which would now silently lose to the new 0,3,1 base rule. Both were given matching `body.woocommerce-checkout .wc-block-checkout__form .wc-block-components-checkout-step` selectors so they continue to apply.
- `checkout-desktop.css`'s own `.wc-block-components-checkout-step` background/border/padding rule (`@media (min-width: 1024px)`, 0,1,0) would likewise have been permanently outranked by the new unconditional 0,3,1 mobile rule at desktop widths. Given a matching `body.woocommerce-checkout .wc-block-checkout__form .wc-block-components-checkout-step` selector (tying specificity at 0,3,1); since `checkout-desktop.css` is enqueued with a dependency on `dtb-checkout` and therefore always loads after it, the desktop declaration still wins the tie at >=1024px without outranking the mobile rule below that width. This is the one specificity change made in `checkout-desktop.css` in this pass; the file's architecture (additive, `min-width: 1024px`-scoped only) is unchanged.

### 3. Email/text input styling lost (specificity)

`.wc-block-components-text-input input, .wc-block-components-textarea, .wc-blocks-components-select__select, .wc-blocks-components-select__container` (max specificity 0,1,1) lost to WooCommerce's type-qualified, form-scoped selector list, e.g. `.wc-block-components-form .wc-block-components-text-input input[type="email"], ...` (specificity 0,3,1 — 2 classes + 1 attribute selector + 1 element), covering `email`, `number`, `password`, `tel`, `text`, `url`, plus a similar `.wc-blocks-components-select .wc-blocks-components-select__container`/`__select` pair (0,2,0). All four controls and their `:focus` states got matching `body.woocommerce-checkout .wc-block-checkout__form ...` restatements (0,3,2 for the input line, 0,3,1 for textarea/select), beating every competing rule outright. Because the original DTB selector for inputs was bare `input` (no `[type=...]` qualifier), it already covered every type WooCommerce targets and more — no type-selector enumeration was needed to match coverage.

### 4. Native step heading not visually hidden (bug)

`ensurePanelMetadata()` in `checkout.js` looked up the heading with `panel.querySelector(':scope > .wc-block-components-checkout-step__heading')`, requiring it to be a *direct child* of the panel. On this install's actual rendered markup the heading is nested one level deeper, inside `.wc-block-components-checkout-step__heading-container`, so the query always returned `null` and the heading was never given `.dtb-checkout__native-heading--visually-hidden` — every accordion step showed both the native step heading ("Contact information") and the accordion header's own label ("Contact"). Fixed by dropping the `:scope >` restriction (`panel.querySelector('.wc-block-components-checkout-step__heading')`), matching the heading regardless of the intermediate wrapper.

### 5. Express Checkout never classified into the accordion (bug)

`GROUP_SELECTORS`' Payment bucket (added in Redesign v17/documented in v18) listed `.wp-block-woocommerce-checkout-express-payment-block` as the express-payment wrapper class. On this install's actual rendered markup (WooCommerce 11.0.0 Blocks) that class does not exist anywhere in the DOM; the real wrapper is `.wc-block-components-express-payment.wc-block-components-express-payment--checkout`, a classless `<div>`'s only styled child, sitting as the first child of the checkout form — above and outside every `.wc-block-components-checkout-step`. Because classification never matched, this element was never given `dtb-checkout__accordion-panel`/`inert`/collapse behavior: it rendered permanently open above Contact, visually disconnected from the accordion.

Added `.wc-block-components-express-payment` alongside the existing (kept, in case a future WooCommerce Blocks version emits it) `.wp-block-woocommerce-checkout-express-payment-block` selector in the Payment bucket. This is a selector-correctness fix only, not a re-architecture: v17/v18 already establish that Express Checkout groups with Payment, and this element has no `.wc-block-components-checkout-step` ancestor, so `classifyStepGroups()`'s existing `inner.closest(STEP_WRAPPER_SELECTOR) || inner` fallback treats the express-payment `<div>` itself as the accordion panel — the exact same pattern already used for the order-notes, terms, and actions blocks in this codebase, which also have no `.wc-block-components-checkout-step` wrapper. With the selector fixed, Express Checkout now collapses/expands with the rest of Payment instead of always rendering open.

### 6. `checkout-desktop.css` render-blocked mobile unnecessarily (perf)

`native-checkout.php` enqueued `dtb-checkout-desktop` with no 5th `$media` argument (defaulting to `all`), even though every rule inside is already gated behind `@media (min-width: 1024px)` or narrower. Added `'(min-width: 1024px)'` as the 5th `wp_enqueue_style()` argument so mobile browsers fetch it as non-render-blocking. No change to enqueue order, handle, dependency, or versioning.

### 7. Undersized tap targets on checkbox rows (a11y)

`.wc-block-components-checkbox` label rows ("Add order note", "Save payment information", "Use same address for billing") rendered as short as ~20px tall — checkout.css only styled the 20x20px checkbox mark itself, not the row. Added `min-height: var(--dtb-checkout-touch)` (the existing 48px touch token), flex alignment, and vertical padding to `.wc-block-components-checkbox` so every checkbox row meets the 48px touch target already used elsewhere in this file.

## Changed files

- `drywalltoolbox/wp/wp-content/themes/drywall-toolbox/assets/checkout/checkout.css`
- `drywalltoolbox/wp/wp-content/themes/drywall-toolbox/assets/checkout/checkout-desktop.css`
- `drywalltoolbox/wp/wp-content/themes/drywall-toolbox/assets/checkout/checkout.js`
- `drywalltoolbox/wp/wp-content/themes/drywall-toolbox/templates/checkout/native-checkout.php`

## Impact

Data/migration impact: none. Order identity/contract meta (`_dtb_checkout_gateway`, `_dtb_checkout_contract_version`, `_dtb_payment_provider`, captured-payment gating) untouched.

Security/payment boundary impact: none. No selector or script change reads, inspects, clones, or mutates any Stripe/provider iframe. No new JS event listeners, no order-creation path touched, no gateway-selection logic touched. `checkout.js` continues to only toggle presentation attributes (`inert`, height, ARIA) on native WooCommerce-rendered groups.

## Not verified in this pass — requires a real browser

Every fix above was cross-checked against the actual computed styles / DOM shape captured in the live runtime diagnostic (`docs/_working/dtb-checkout-runtime-390x844-2026-08-06T21-35-10-220Z.json`) and against the exact competing WooCommerce/WordPress-core CSS rules present in that capture's `stylesheets[].rules`, including confirming stylesheet document order to resolve specificity ties. Specificity was computed by hand for every new/changed selector, not guessed. No browser could be launched from this environment to confirm the rendered result after these changes — re-run the mobile visual/interaction QA in `checkout-ui-redesign-v18.md` and `checkout-ui-hardening-v19.md` (320/390/428/768/1024px, guest/authenticated, autofill, invalid-field discovery, Payment Element repaint, forced-colors, rotation across 768px/1024px) before production sign-off, with particular attention to: Place Order button now renders 56px/blue/bold; step cards show a visible background/border/shadow; text inputs show the subtle-gray/48px/8px-radius treatment on every affected type; each accordion step shows exactly one heading; Express Checkout now visually collapses/expands with the Payment step instead of always sitting open above Contact; checkbox rows are comfortably tappable.
