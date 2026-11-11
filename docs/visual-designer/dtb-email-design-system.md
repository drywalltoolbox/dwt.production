# DTB Email Design System

Last reconciled with tracked source: 2026-08-04.

This is the design specification for the WooCommerce classic HTML email
redesign (see `docs/operations/woocommerce-html-email-architecture.md` for
the engineering/routing architecture that renders it). This document
describes what the emails look like and say; the architecture doc describes
how that content gets on the wire.

## Visual language

- **Brand identity.** A black header carrying the DTB logo, a clean white
  content surface (`#ffffff`), brand-primary blue accents (`#2255ee`) for
  links/buttons/badges, and restrained borders (`#e4eaf2`) instead of heavy
  dividers or drop shadows. This
  matches the palette already established for DTB's other transactional
  email surfaces (`dtb_email_palette('light')`, `dtb-platform/Support/Email.php`)
  so the WooCommerce classic pipeline and DTB's support/returns/repair/
  marketing emails read as one brand, not two.
- **Typography.** Nunito is the only intended display and body typeface.
  HTML emails request weights 400-800 from Google Fonts and use
  `Nunito, Arial, sans-serif`; clients that block remote fonts receive Arial
  without changing hierarchy or layout.
- **Tone.** Professional, direct, and specific to drywall tools/parts/repairs
  — no generic ecommerce filler ("Thank you for your purchase!"). Every
  email states what happened, what it means for the customer, and what (if
  anything) happens next.

## Layout architecture

Fluid table layout capped at 680px on desktop and filled to the viewport on
mobile: a dark logo-only header band, a white content body, and a dark footer
band bookending it. Hero, header, and footer are edge-to-edge; body components
share one 22px desktop content rail and a 14px mobile rail. See
`Email/templates/emails/email-header.php` / `email-footer.php` /
`email-styles.php` in `dtb-commerce` for the concrete markup. Content within
the body follows a consistent vertical rhythm: hero (order-number eyebrow +
heading only) → progress tracker (only where the caller has authoritative
state to show) → order summary card → addresses card → support card →
footer.

## Component library

All components are reusable PHP functions in `dtb-platform/Support/Email.php`
(plus the order-item renderer in `Support/Email/OrderItemPresentation.php`),
consumed directly from the WooCommerce template overrides in
`dtb-commerce/Email/templates/emails/`. One implementation, not one per
email:

| Component | Function | Used for |
|---|---|---|
| Hero | `dtb_email_hero( $heading, '', $eyebrow )` | the concise patterned black/blue lifecycle heading block every email leads with; supporting context is deliberately omitted and the heading remains the caller's admin-configurable `$email_heading` |
| Progress tracker | `dtb_email_progress_steps( $steps )` | standalone lifecycle icons + connecting line + labels; caller-driven per-step tone (`done`/`active`/`warning`/`danger`/`upcoming`) — only used where the template has authoritative state for every stage it shows |
| Card | `dtb_email_card_open( $title, $meta, $icon )` / `dtb_email_card_close()` | white rounded bordered section (order summary, addresses, shipment summary); native `do_action()` output can be echoed directly between open/close; icons are reserved for the address panel |
| Next-steps grid | `dtb_email_next_steps_grid( $items )` | icon-free compact text grid retained for exceptional content; omitted from processing emails because the lifecycle tracker already communicates the next state |
| Support card | `dtb_email_support_card( $text, $cta_url, $cta_label )` | "need help?" card with an outlined CTA button and the default `repairs` icon from the active four-file email icon library |
| Status badge | `dtb_email_status_badge( $label, $tone )` | exceptional state detail where the heading alone is insufficient, currently full/partial refund distinction and concise admin state |
| CTA button | `dtb_email_button( $url, $label )` | pay, retry payment, reset password, view account — MSO-safe with a VML fallback |
| Detail/summary table | `dtb_email_details_table_light( $rows )` | compact carrier/tracking and other lifecycle-specific label/value data; never duplicates order summary data |
| Note box (light) | `dtb_email_note_box_light( $content )` | order notes, shipment merchant notes, styled for this light theme (see Redesign v2 — the original `dtb_email_note_box()` is hardcoded dark, for the separate `dtb_render_branded_email()` shell) |
| Order item row | `dtb_email_render_item_thumbnail()` / `dtb_email_render_item_name()` | product thumbnail + name (+ SKU, now shown to customers too, not just operators) in order/fulfillment item tables, via the standard `woocommerce_order_item_thumbnail`/`_name` filters |
| Footer social icons | `dtb_email_social_icons()` / `dtb_email_social_links()` | confirmed-real social profile links only (sourced from `frontend/src/utils/schema.js`'s structured-data `sameAs`) — never a fabricated profile URL |
| Footer navigation | `dtb_email_footer_link_html()` / `dtb_email_footer_links()` | Terms from the configured WooCommerce page, Privacy from the configured WordPress privacy page, and the DTB support URL; unconfigured legal pages are omitted rather than fabricated |
| Shipment/tracking panel | `email-fulfillment-details.php` | carrier, tracking number, tracking-URL CTA, now inside a card |
| Address panel | `email-addresses.php` (`.address-title` + `.address`) | billing/shipping, side-by-side on desktop, stacked on mobile, now inside a card |

## Responsive design

Single `@media screen and (max-width: 600px)` block in `email-styles.php`
tightens header/body padding and heading size, stacks address and support
cells, converts each item into a full-width product row followed by explicit
quantity/price labels, and makes CTAs full width. No second mobile template
or client-side behavior exists.

## Accessibility

- Semantic heading hierarchy (`h1` for the email heading, `h2` for section
  titles — order summary, shipment summary, customer details).
  `role="presentation"` on every layout table so screen readers skip
  decorative structure.
  `lang`/`dir` attributes from `language_attributes()`/`is_rtl()` on the root
  `<html>` element (unchanged from WooCommerce core — see the architecture
  doc's RTL note).
- Text/background contrast: navy-on-white and the palette's badge
  tones were chosen to clear WCAG AA at the sizes used (12–17px).
- The logo and product thumbnails carry descriptive `alt` text. Lifecycle,
  support, address, and social icons are decorative (`alt=""`) because their
  adjacent live text carries the meaning; no state is communicated by color
  or imagery alone.
- Buttons are real `<a>` elements with visible text labels, not
  image-only or icon-only controls.

## Email client compatibility

Table-based layout (no CSS Grid/Flexbox), inline styles applied by
WooCommerce's own Emogrifier CSS-inliner pipeline from `email-styles.php`
(WooCommerce core mechanism, unchanged), an MSO conditional VML fallback for
the button component (`dtb_email_button()`), and a VML-backed hero background
using `/logos/email-background-pattern.png`. Nunito is requested from Google
Fonts and repeated in inline font declarations; Arial is the deterministic
fallback for clients such as desktop Outlook that block web fonts. Dark-mode
email clients are not specifically
inverted (a deliberate reliability choice — the fixed navy header and white
body read correctly in both light- and dark-mode inboxes without relying on
`prefers-color-scheme` support, which several major clients still handle
inconsistently for inlined table email).

## Copywriting voice per email category

- **Admin (operator) emails** — action-oriented: state what changed, what
  action (if any) is required, and skip pleasantries. Example:
  "Order #1042 from Casey Alvarez was cancelled. Release any reserved
  inventory and stop fulfillment if it has not already shipped."
- **Customer status emails** — lead with what happened in plain terms, then
  what it means, then what's next. Every processing/on-hold email answers:
  payment status, current stage, and next expected step.
- **Customer account/security emails** — brief, reassuring, single clear CTA.

## Per-email specification (all 16 supported lifecycle emails)

CTA text and destination are resolved centrally by
`dtb_order_tracking_cta_for_email()` in `dtb-order-tracking-links.php`,
keyed off `$email->id`, and rendered from the shared
`woocommerce_email_after_order_table` hook — not hardcoded per template.
Progress-tracker steps are hardcoded per template (they're
lifecycle-specific copy, not derived data) but always describe only states
DTB has authoritative data for; no step ever claims "delivered."

| Email | Progress tracker / status badge | Core message | Primary CTA |
|---|---|---|---|
| `new_order` (admin) | Status badge: New order (info) | New order + customer name, review/fulfill | Edit order (admin) |
| `cancelled_order` (admin) | Status badge: Order cancelled (danger) | Cancelled, release inventory | Edit order (admin) |
| `failed_order` (admin) | Status badge: Payment failed (danger) | Payment failed, no action needed unless retried | Edit order (admin) |
| `customer_processing_order` | Progress: Payment received (done) → Being prepared (active) → On the way soon (upcoming) | Payment confirmed, preparing shipment, tracking to follow | Track your order |
| `customer_completed_order` | Progress: Payment received (done) → Prepared (done) → Order complete (done) | Order fully closed out (deliberately not equated with "shipped" or "delivered") | View order details |
| `customer_on_hold_order` | Progress: Payment pending (warning) → Being prepared (upcoming) → On the way soon (upcoming) | On hold pending payment confirmation, no action needed unless payment is due | Pay securely (if `needs_payment()`) / View order details |
| `customer_failed_order` | — (hero only) | Secure retry action; order summary remains authoritative | Retry payment |
| `customer_cancelled_order` | — (hero only) | Order summary without speculative capture/refund claims | View order details |
| `customer_refunded_order` | Refund / partial refund issued (info) | Full vs. partial distinguished via core's `$partial_refund` | View order details |
| `customer_invoice` | — (hero only) | Payment action only when `needs_payment()`; otherwise order reference | Pay for this order |
| `customer_note` | — (hero only, no stepper) | Store note quoted, order details for reference | View order details |
| `customer_new_account` | — (hero only, no stepper/support card) | Account ready, set/confirm password | Set your password / Go to my account |
| `customer_reset_password` | — (hero only, no stepper/support card) | Reset request, single reset link | Reset your password |
| `customer_fulfillment_created` | Progress: Payment received (done) → Prepared (done) → On the way (active) | Part/all of order shipped, tracking details — the one progress tracker whose "on the way" step is backed by real Veeqo fulfillment data, not inference | Track shipment |
| `customer_fulfillment_updated` | Progress: Payment received (done) → Prepared (done) → Shipment updated (active) | Optional merchant note and authoritative current tracking | Track shipment |

Each row's implementation lives in the correspondingly-named file under
`dtb-commerce/Email/templates/emails/`. Every customer-facing lifecycle
email (all rows below the three admin rows) also renders a single
`dtb_email_support_card()` near the end of the body, except the two
account/security emails (`customer_new_account`, `customer_reset_password`),
which stay intentionally minimal per the copywriting-voice guidance above.

## Redesign v2 (2026-08-01)

**Why.** An approved visual mockup ("Thank you for your order!") specified a
denser, card-based, lifecycle-aware presentation — dark logo-only header,
order-number eyebrow, hero title/subhead, a three-stage progress tracker,
white card-based order summary, and a dark footer bookend — as the new
target for the customer-facing email family. Redesign v1 (2026-07-31, see
above) had already moved the system from WooCommerce's stock look to a
light/modern theme with a dark header band, but had a dark
header/light-everything-else asymmetry, no progress tracker, no card
chrome around order/address content, a status-badge-only lifecycle signal,
and a single non-lifecycle-aware CTA. This pass extends v1's existing token
system and template-override path — it does not replace it or introduce a
second rendering authority.

**What changed.**
- `dtb-platform/Support/Email.php`: new reusable components — hero
  (`dtb_email_hero`), progress tracker (`dtb_email_progress_steps`), card
  chrome (`dtb_email_card_open`/`_close`), next-steps grid
  (`dtb_email_next_steps_grid`), support card (`dtb_email_support_card`),
  light-themed note box (`dtb_email_note_box_light`), and footer social
  icons (`dtb_email_social_icons`/`dtb_email_social_links`). Footer palette
  tokens (`footer_bg`/`footer_text`/`footer_link`/`footer_sep`) reworked to
  a dark bookend matching the header.
- `email-header.php` / `email-styles.php` / `email-footer.php`: header band
  is now logo-only; the `<h1>` heading moved into the full-width patterned
  hero with white, left-aligned text. The footer band is dark to match the
  header, with social icons and "Contact Us" wording; the global `h1` rule
  now describes that hero context instead of the old header-band context.
- `email-order-details.php` / `email-addresses.php` /
  `email-fulfillment-details.php`: order summary, addresses, and shipment
  summary are now wrapped in the shared card component; SKU is now shown to
  customers, not just admins; the "view your order" link in the fulfillment
  template now points at this store's canonical order-tracking page
  (`dtb_order_tracking_url()`) instead of WooCommerce's native My Account
  orders page, matching the destination used everywhere else.
- `dtb-order-tracking-links.php`: CTA button label/destination is now
  resolved per email ID (`dtb_order_tracking_cta_for_email()`) instead of a
  single fixed "View order details" link — see the per-email table above.
- All 12 customer lifecycle templates (`customer-processing-order.php`
  through `customer-note.php`) rewritten to lead with the hero, use a
  truthful progress tracker, refund badge, or no secondary state block, and
  end with a single support card. `customer-new-account.php` /
  `customer-reset-password.php` get the hero only, per the existing
  copywriting-voice guidance for account/security email. The three admin
  templates (`admin-new-order.php`, `admin-cancelled-order.php`,
  `admin-failed-order.php`) use the same global hero, status, order-summary,
  address, and footer system while keeping concise operator-specific copy and
  omitting customer support content.

**Deliberate judgment calls.**
- Footer Terms and Privacy links are settings-backed. Terms resolves from
  WooCommerce's configured terms page and Privacy from WordPress's configured
  privacy-policy URL; either is omitted when its page is not configured.
  Contact always uses `dtb_email_support_url()`. No route is fabricated.
- Footer social icons are Facebook and Instagram only — the only two
  profiles confirmed real in `frontend/src/utils/schema.js`'s structured
  data (`sameAs`). No YouTube/LinkedIn/X icon was added, since none of
  those profiles exist in the codebase and inventing a URL would be a
  broken link in production.
- Progress, address, support, and social icons render directly through
  `dtb_email_icon()` from transparent PNG assets under `/logos/email-icons/`.
  Template markup never adds a circular background, border, or outline around
  an icon. Order, shipment, and generic section headings remain text-only.
- `customer_fulfillment_created` is the only progress tracker with an
  "on the way" step marked `active` rather than `upcoming`; it's backed by
  an actual Veeqo-projected `Fulfillment` object, not inference. No other
  template's tracker ever implies shipment or delivery it can't
  substantiate.

## Validation contract

`php scripts/tests/woocommerce-email-template-contract-fixtures.php` checks
the mapped classic-HTML registry, mapped files, upstream extension-point
presence/order, icon allowlist-to-file integrity, 680px/Nunito/brand-token
contracts, refunded quantity handling, and the canonical read-only preview
path. This static fixture complements, but does not replace, rendering through
WooCommerce and real inbox-client tests. Before shipping to real inboxes:
- [ ] Trigger each of the 16 supported lifecycle emails against a real WooCommerce
      install (or a preview tool) and visually confirm the hero, progress
      tracker/status badge, card chrome, and footer render correctly.
- [ ] Check Gmail (web + app), Apple Mail, and Outlook desktop specifically
      — Outlook's Word rendering engine is the most likely to reveal a
      table/CSS compatibility issue the others wouldn't.
- [ ] Confirm the MSO button fallback renders a clickable button (not just
      a fallback link) in Outlook desktop.
- [ ] Confirm long product names, SKUs, and addresses wrap instead of
      overflowing the 600px content width on both desktop and mobile
      clients.
- [ ] Confirm dark-mode inboxes (iOS Mail, Gmail dark mode) still show
      correct contrast against the fixed black header/footer and white body.
