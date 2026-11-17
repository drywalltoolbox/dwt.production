# Drywall Toolbox Design System

## 1. Product and design intent

Drywall Toolbox is a contractor-focused ecommerce and service platform for professional drywall tools, replacement parts, schematics, repairs, returns, and account workflows. The interface must feel precise, durable, fast, and trustworthy: closer to a well-machined professional tool than a lifestyle marketplace or generic SaaS dashboard.

Design for working contractors who may be on a phone, in a truck, wearing gloves, comparing technical identifiers, or trying to complete a purchase quickly. Product identity, compatibility, price, availability, shipping context, and the primary action must be immediately legible.

Core qualities:

- professional, direct, and technically credible;
- clean white commerce surfaces framed by a deep navy shell;
- confident brand blue used for selection, focus, links, and Add to Cart;
- restrained geometry, compact information density, and generous interaction targets;
- product photography and technical information lead; decoration recedes;
- clear state changes with calm, purposeful motion;
- one coherent responsive system from 320px phones through wide desktop screens.

Avoid generic dashboard styling, oversized decorative cards, excessive gradients, fake trust claims, ornamental icons, glass effects without a functional reason, or layouts that hide price and purchasing information.

## 2. Brand identity

Use the supplied Drywall Toolbox logo assets without redrawing, recoloring, stretching, cropping, or reconstructing them.

- `frontend/src/assets/brand/dtb-logo-white.webp`: canonical desktop brand mark for the deep navy shell or dark imagery.
- `frontend/src/assets/brand/dtb-logo-white-mobile.webp`: matching mobile header variant.
- `frontend/public/logos/email-logo-white.png`: transparent PNG export of the same desktop mark for email clients.
- Preserve the source aspect ratio and clear space equal to at least the height of the outer logo frame.
- Do not use brand or payment-provider marks as decorative patterns.

Brand voice is practical, specific, confident, and helpful. Prefer “Find the exact part” over aspirational marketing language. Labels and errors should tell the user what happened and what to do next.

## 3. Color system

The default storefront color scheme is light. Dark color is reserved for the application shell, high-emphasis commerce actions, overlays, and technical contrast—not for a site-wide dark theme.

### Brand and neutral tokens

| Role | Token | Value | Use |
|---|---|---:|---|
| Page background | `dtb.page.bg` | `#FFFFFF` | Primary page canvas |
| Surface | `dtb.surface` | `#FFFFFF` | Cards, forms, sheets |
| Subtle surface | `dtb.surface.subtle` | `#F8FAFC` | Section contrast, quiet panels |
| Muted surface | `dtb.surface.muted` | `#EEF2F7` | Disabled/secondary regions |
| Shell | `dtb.shell` | `#0A1020` | Header, footer, strongest dark surfaces |
| Shell secondary | `dtb.shell.secondary` | `#121A2F` | Dark-surface layering |
| Drawer | `dtb.drawer` | `#020617` | Mobile navigation/drawer |
| Primary | `dtb.primary` | `#2255EE` | Add to Cart, links, active states, focus |
| Primary strong | `dtb.primary.strong` | `#1945D8` | Hover/pressed primary action |
| Primary soft | `dtb.primary.soft` | `#EEF3FF` | Selected rows, soft callouts |
| Text | `dtb.text` | `#0F172A` | Headings and primary copy |
| Text soft | `dtb.text.soft` | `#334155` | Secondary copy |
| Muted text | `dtb.text.muted` | `#64748B` | Metadata, placeholders, help |
| Border | `dtb.border` | `#DBE3F1` | Standard divisions and controls |
| Border strong | `dtb.border.strong` | `#C3CFDF` | Emphasized structure |
| Success | `dtb.success` | `#16794B` | Confirmed state |
| Warning | `dtb.warning` | `#9A6700` | Attention, non-blocking risk |
| Danger | `dtb.danger` | `#B42318` | Error/destructive action |
| Focus | `dtb.focus` | `#2255EE` | Keyboard focus ring |

Primary and accent are the same blue family. Do not introduce a second accent hue. Blue is functional, not decorative.

### Commerce action hierarchy

- Add to Cart: brand blue `#2255EE`, white label, strong/pressed blue `#1945D8`.
- Checkout Now / proceed to checkout: near-black or shell-colored high-emphasis action. This is intentionally distinct from Add to Cart.
- Secondary: white surface, `#0F172A` text, `#C3CFDF` border.
- Tertiary/ghost: transparent, text or brand-blue label, visible hover surface.
- Destructive: use red only for a genuine destructive or blocking action.
- Never display payment-method logos unless real backend capability confirms those methods are available.

All text and interactive states must meet WCAG AA contrast. Do not communicate status by color alone; pair it with a label, icon, or structural change.

## 4. Typography

The active customer-facing authority is a single self-hosted variable family: **Geist Variable**. Use weight, size, spacing, and layout—not extra font families—to create hierarchy.

Font stack:

`"Geist Variable", "Geist", -apple-system, BlinkMacSystemFont, "Segoe UI", ui-sans-serif, system-ui, sans-serif`

Typography behavior:

- Body weight: 500; headings and action labels: 700–850 as appropriate.
- Base body size: fluid `16–17px`; line-height `1.55–1.65`.
- Heading tracking: slightly tight, approximately `-0.02em` to `-0.03em`.
- Body tracking: subtle `-0.005em`; never add positive tracking to lowercase body copy.
- Uppercase eyebrows and small labels: `0.05em–0.12em` positive tracking.
- Use tabular figures for prices, quantities, SKUs, MPNs, totals, dates, and comparison data.
- Balance heading wraps and use pretty wrapping for paragraphs.
- Keep long-form copy to approximately `68ch` maximum.
- Use optical sizing and normal kerning.

### Fluid type scale

| Style | Size | Typical use |
|---|---|---|
| Display / H1 | `clamp(2rem, 1.42rem + 2vw, 4rem)` | Page hero and major route title |
| H2 | `clamp(1.625rem, 1.26rem + 1.2vw, 2.75rem)` | Major section title |
| H3 | `clamp(1.25rem, 1.08rem + 0.58vw, 1.75rem)` | Card groups and subsections |
| Lead | `clamp(1.0625rem, 0.98rem + 0.28vw, 1.25rem)` | Introductory copy |
| Body | `clamp(1rem, 0.96rem + 0.12vw, 1.0625rem)` | Standard content |
| Small | `clamp(0.875rem, 0.84rem + 0.1vw, 0.9375rem)` | Metadata, secondary labels |
| Extra small | `clamp(0.75rem, 0.72rem + 0.08vw, 0.8125rem)` | Dense technical captions |

Use no more than three visible heading levels on one screen. Headings have at least twice as much space above as below so they associate with the content that follows.

## 5. Spacing and layout

Use the fluid spacing scale consistently:

| Token | Fluid value |
|---|---|
| Space 1 | `clamp(0.25rem, 0.22rem + 0.12vw, 0.375rem)` |
| Space 2 | `clamp(0.5rem, 0.42rem + 0.24vw, 0.75rem)` |
| Space 3 | `clamp(0.75rem, 0.63rem + 0.38vw, 1rem)` |
| Space 4 | `clamp(1rem, 0.78rem + 0.72vw, 1.5rem)` |
| Space 5 | `clamp(1.5rem, 1.12rem + 1.2vw, 2.5rem)` |
| Space 6 | `clamp(2rem, 1.42rem + 1.9vw, 4rem)` |
| Space 7 | `clamp(3rem, 2rem + 3vw, 6rem)` |

Viewport gutter: `clamp(1rem, 0.56rem + 1.42vw, 2rem)`. On narrow mobile, it may compress to `clamp(0.875rem, 3.8vw, 1.25rem)`.

Content widths:

- Narrow/readable: `48rem` or `68ch` for prose and focused forms.
- Default: `75rem` for most routes.
- Wide: `90rem` for catalogs and rich product layouts.
- Full: `120rem` maximum for large product grids.
- Fluid catalog grids may use the full viewport minus the fluid gutters.

Use intrinsic layouts: stack, wrapping cluster, auto-fit grid, sidebar layout, and split layout. Never allow horizontal page overflow. Grid children use `minmax(0, 1fr)` and media remain intrinsically responsive.

## 6. Shape, elevation, and layering

Radii:

- Small control/detail: `8px`.
- Standard control/card: `12px`.
- Prominent card/sheet: `16px–20px`.
- Large hero/modal: `28px` only when the scale justifies it.
- Pills/badges: `999px`.

Use radii consistently within a component family. Avoid making every container a large rounded card.

Elevation:

- Card: `0 8px 22px rgba(15, 23, 42, 0.08)`.
- Elevated popover: `0 14px 36px rgba(15, 23, 42, 0.14)`.
- Sheet/dialog: `0 24px 60px rgba(15, 23, 42, 0.20)`.
- Focus halo: `0 0 0 4px rgba(34, 85, 238, 0.20)`.

Prefer borders and surface contrast over shadow. Use the strongest shadow only for an element that genuinely floats above the document.

Layer order: base, dropdown, sticky, overlay, drawer, dialog, toast. Drawers and dialogs must not compete with checkout/provider overlays.

## 7. Iconography and imagery

- Use Lucide-style outline icons: simple geometry, consistent stroke, typically 16–24px.
- Icons support comprehension; they never replace a critical text label unless the meaning is universal and an accessible name is supplied.
- Product photography uses neutral backgrounds, preserves the whole tool or part, and avoids decorative crops that obscure form or compatibility.
- Category hero artwork uses transparent WebP on a consistent virtual canvas. Authoring should tightly bound the visible product group, preserve 5–8% safe area, align products to a consistent optical baseline, avoid baked scene backgrounds, and keep shadows contained within the canvas. The frontend may use only the bounded presentation modes defined by the category hero contract to compensate for broad shape classes; source-media normalization remains preferred over per-category CSS.
- Schematics remain technical, high-contrast, zoomable documents; hotspots are precise targets, not decorative markers.
- Preserve authentic manufacturer brand marks and product-media provenance.

## 8. Core components

### Header and navigation

Desktop uses a deep navy shell with the white logo, utility context, search, category/shop navigation, account, and cart. Mobile uses the same information architecture through a hamburger/drawer and a dedicated search treatment—not a new bottom navigation paradigm. Header height is approximately `72px` desktop and `70px` mobile.

Navigation must show hover, active, and keyboard-focus states. Mega menus use clear taxonomy groups and restrained product imagery. The drawer traps focus, closes with Escape, restores focus to its trigger, respects safe areas, and uses a minimum 44px target.

### Buttons

Default control height and minimum touch target: `44px`.

Required states: default, hover, active/pressed, focus-visible, disabled, loading, and success where the action confirms locally. Loading preserves button width and prevents duplicate submission. Use a spinner plus an accessible busy label. Add-to-cart success is communicated on the initiating control and persistent cart count, not a redundant success toast.

### Form controls

Use persistent visible labels. Inputs are white with a clear neutral border, 12px radius, readable help text, and a blue focus treatment. Place validation near the field and provide an error summary when a multi-field submission fails. Never use placeholder-only labels. Group related options with `fieldset` and `legend`.

### Product card

A product card prioritizes, in order: image, brand/product name, identifiers or variation cue, price, availability, and action. Keep cards compact and comparison-friendly. Image ratios remain stable to prevent layout shift. Entire-card click targets must not swallow nested controls. Quick view is supplemental, not the only path to product details.

### Product detail

Use a responsive image gallery paired with a purchase panel. Expose product name, brand, price, availability, variations, quantity, Add to Cart, Checkout Now, fulfillment context, technical identifiers, and compatibility without hiding purchase essentials behind tabs. Mobile order places name/price/variation/action before long description content.

### Catalog and filters

Desktop may use a sidebar or anchored filter panel; mobile uses a sheet/drawer. Always show result count, sort, applied-filter chips, clear-all, loading, empty, and error states. Filter changes must remain understandable and reversible.

### Breadcrumbs

Use semantic breadcrumb navigation with linked ancestors and a plain-text current page. Allow wrapping on mobile; do not horizontally squeeze or truncate the only available route context.

### Cards and informational surfaces

Cards use a white surface, subtle border, compact radius, and restrained shadow. Reserve blue soft surfaces for selected or actionable emphasis. Do not wrap every section in a card.

### Drawers, sheets, dialogs, and overlays

Desktop dialogs are centered and width-bounded. Mobile dialogs become bottom sheets where appropriate, use `100dvh` constraints, safe-area padding, a clear close control, and no page overflow. Every overlay requires focus containment, Escape handling, focus restoration, and a labelled title.

### Feedback states

- Loading: use stable-size skeletons for content grids; use inline progress for actions.
- Empty: explain why the surface is empty and offer one relevant next action.
- Error: state the failure in plain language, preserve user input, and offer retry/recovery.
- Success: confirm the completed action close to its origin.
- Toasts: reserve for error, warning, or information that cannot live at the initiating control. Use polite live regions and a visible close action.

## 9. Commerce-specific patterns

### Cart

The cart exists as a responsive side sheet and a dedicated page. It shows stable product imagery, variation/identifier context, quantity controls, removal, subtotal, and a strong path to checkout. Quantity changes show pending and error states without losing the prior valid value.

### Checkout boundary

The React `/checkout` route is only a handoff/presentation surface. Native WooCommerce Checkout Block owns checkout fields and submission; the active provider owns payment fields, wallets, authentication, and confirmation.

When generating checkout concepts:

- show one field set, one payment state, and one native order submission action;
- do not invent card fields, wallet controls, payment iframes, fake gateway forms, or a second order summary authority;
- do not clone, inspect, reparent, or visually replace provider-owned iframe content;
- use only provider-supported appearance/theming surfaces;
- keep totals, shipping, tax, discounts, payment status, and order state visually represented as server/provider-owned values;
- render payment marks only when backend capability confirms availability;
- prioritize completion, trust, field clarity, and mobile keyboard behavior over decorative chrome.

### Repairs, returns, and tracking

These are genuine multi-step/stateful workflows. Use a scoped stepper only where stages are real. Show current state, completed state, next action, identifiers, timestamps, shipment/quote context, and explainable errors. Never place workflow stepper chrome in the global storefront header.

## 10. Responsive behavior

Design mobile-first and fluidly. Use `clamp()`, intrinsic grids, wrapping, and content-based adaptation. Existing major thresholds are:

- compact/narrow phone: up to `360px` (`22.5rem`);
- mobile: below `768px` (`47.99rem`);
- tablet/compact desktop: up to `1024px` (`64rem`);
- feature-specific collapse may occur around `896px` (`56rem`).

At mobile sizes:

- preserve a minimum 44px touch target;
- stack split and sidebar layouts;
- make sheets fit the dynamic viewport and safe areas;
- keep primary actions visible without obscuring content;
- allow breadcrumbs, labels, and technical identifiers to wrap;
- never duplicate business logic into separate mobile and desktop experiences.

Validate concepts at 320, 390, 768, 1024, 1440, and wide desktop widths. Hover enhancements only apply to fine pointers; essential actions remain visible and usable without hover.

## 11. Motion

Motion communicates hierarchy, continuity, and state. It is never ornamental.

- Fast feedback: approximately `160–180ms`.
- Standard transition: approximately `280–320ms`.
- Slow/emphasized: approximately `380–420ms`.
- Standard easing: `cubic-bezier(0.22, 1, 0.36, 1)`.
- Emphasized entrance: `cubic-bezier(0.16, 1, 0.3, 1)`.
- Exit: `cubic-bezier(0.4, 0, 0.2, 1)`.
- Route transitions use a subtle 8px vertical shift and near-imperceptible scale, not dramatic movement.
- Loading spinners may rotate linearly; avoid pulsing large areas.
- Under `prefers-reduced-motion: reduce`, remove transform travel and nonessential animation, using near-instant opacity feedback where state still needs acknowledgement.

## 12. Accessibility requirements

WCAG 2.1 AA is the minimum baseline.

- Semantic HTML first; ARIA only fills semantic gaps.
- Visible keyboard focus: 3px blue/light mixed outline with 3px offset, or an equally visible component-specific equivalent.
- Minimum interactive target: 44 by 44px.
- Provide a skip link and correct heading order.
- Dialogs, menus, tabs, accordions, and drawers implement the corresponding keyboard interaction pattern.
- Do not rely on hover, color, animation, or iconography alone.
- Announce asynchronous status without moving focus unnecessarily.
- Respect reduced motion, forced colors, zoom, reflow, screen readers, coarse pointers, and mobile safe areas.
- Use `100dvh` rather than bare `100vh` for mobile full-height interfaces.
- Images require meaningful alt text or empty alt text when decorative.

## 13. Screen families Stitch should generate consistently

Use this system for:

1. Home storefront: category-led discovery, search, hero product context, trusted brands, and concise service paths.
2. Product listing: category/brand context, filter/sort/result count, dense responsive product grid.
3. Product detail: gallery, commerce information, variations, purchase actions, technical data, compatibility.
4. Parts and schematics: exact identifier search, technical hierarchy, diagram viewer, hotspot-linked parts.
5. Repairs: intake, package selection, quote/approval, shipping, and status tracking.
6. Cart and native-checkout presentation: completion-focused and boundary-safe.
7. Authentication and account: brand-blue focus, compact forms, order/repair/return visibility.
8. Policies, FAQ, contact, errors, loading, empty, and confirmation states.

## 14. Generation guardrails

Stitch-generated concepts must preserve these invariants:

- Keep the global shell and route-level workflows distinct.
- Do not create a parallel palette, font system, spacing scale, breakpoint system, component library, or mobile business flow.
- Use real product content structure: brand, product name, SKU/MPN, variation, compatibility, price, availability, and shipping context.
- Never alter stable business identifiers for visual neatness.
- Never depict React as owning order, payment, tax, shipping, refund, inventory, or fulfillment truth.
- Avoid unsupported claims such as “in stock,” “free shipping,” or payment availability unless supplied as explicit screen data.
- Generated output is a design reference. Production behavior remains governed by the React frontend, WooCommerce, DTB modules, and payment/integration providers.

