# WooCommerce classic HTML email architecture

Last reconciled with tracked source: 2026-10-31.

Scope: WooCommerce's classic HTML/PHP transactional email pipeline only.
Plain-text templates, the block email editor, and POS templates are
explicitly out of scope and are never touched by any file described here.

## Ownership model

- **`dtb-platform`** owns the shared branded email presentation system,
  reusable components, platform-wide sender identity, and the transactional
  Reply-To alignment safeguard (`Support/Email.php`,
  `Support/Email/Deliverability.php`, and
  `Support/Email/OrderItemPresentation.php`). SMTP transport and DNS-based
  authentication (SPF/DKIM/DMARC/PTR) remain infrastructure concerns and are
  not encoded in application code.
- **`dtb-commerce`** owns WooCommerce email registration, template
  resolution, settings integration, and commerce-specific content
  (`Email/TemplateOverride.php` and `Email/templates/emails/*.php`).
- **`dtb-order-platform`** owns order-event identity and duplicate prevention
  for customer-facing order emails.
- **`dtb-integrations`** remains authoritative for Veeqo fulfillment and
  tracking projections.

## Template-routing strategy

`dtb-commerce/Email/TemplateOverride.php` hooks `woocommerce_locate_template`
at a late priority with an explicit allowlist. It only rewrites WooCommerce's
resolved template path when WooCommerce resolved its own bundled default, the
template is allowlisted, and the request is not for `emails/plain/*`.

## Gmail/client rendering contract

The classic HTML shell uses a conservative table-first structure capped at
680px. The outer shell is centered by table alignment, not by a spacer-column
layout or CSS-only sizing. The shell table itself is `width="100%"` with a
680px maximum so a narrow client remains intrinsically fluid even if its
media-query processing is incomplete. Critical dimensions, backgrounds,
padding, and logo sizing are duplicated inline so Gmail variants still render
the intended structure after sanitization/inlining.

Shared content rails (introduction, lifecycle progress, section cards) use a
percentage width in the base rules. Critical layout must not depend on
`calc()`, flexbox, grid, viewport units, JavaScript, or client-side DOM
behavior. The mobile media query is therefore a progressive enhancement for
spacing, typography, address stacking, and compact order-item sizing—not the
mechanism that prevents horizontal overflow.

Remote web fonts are not required for layout correctness. The email shell
must remain readable with native sans-serif fallbacks when clients strip
`<link>` tags or external font requests.

The shared classic HTML header and footer use distinct, full-resolution artwork
with solid navy fallbacks: `/assets/email/dtb-email-header-background.jpg` (2172×724,
about 202KB) and `/assets/email/dtb-email-footer-background.jpg` (2032×774, about
245KB). Their uncompressed source masters are
`docs/reference/ui/dtb-email-header-enhanced-20261031.png` and
`docs/reference/ui/dtb-email-footer-enhanced-20261031.png`. Hosted logo,
social links, account, shop, contact, and copyright text remain live HTML. Keep
both assets available at the site root when releasing the storefront and email
templates together. The platform's shared `dtb_render_branded_email()` layout
uses independent header and footer artwork tokens in
`dtb-platform/Support/Templates/branded-email.html` so non-WooCommerce DTB
transactional mail follows the same split treatment.
Both the branded shell and WooCommerce's classic email footer render their
shared logo, social icons, account/shop/contact navigation, and legal line
through `dtb_email_global_footer_content_html()` in the platform email layer.
Campaign-specific footer notes, including launch unsubscribe instructions,
are inserted into that same global footer composition.
`dtb_email_button()` is the shared CTA renderer; campaign layouts may select a
primary or secondary visual variant while preserving the same dimensions,
corner radius, and focus behavior. The email icon helper serves raster PNGs so
mail clients do not depend on inline SVG. The launch icon exports are based on
the supplied `products/launch/media/email/micons.ai` source sheet.
The shared renderer also accepts opt-in `theme`, `hide_heading`, `flush_body`,
and `hide_signoff` arguments for campaign layouts that need to use this same
header/footer shell around a custom, edge-to-edge body. Defaults preserve the
existing transactional-email layout; the coming-soon launch email uses the
light palette and flush body mode.
Email typography uses the self-hosted Geist Variable font from the storefront's
Fontsource package, with Apple system, Segoe UI, Roboto, Helvetica, and Arial
fallbacks. The same-origin WOFF2 asset is
`/assets/email/geist-latin-wght.woff2` (29.4KB); its SIL Open Font License is
preserved at `docs/reference/ui/geist-font-license.txt`. The shared scale uses
15px/24px body text, 32px/40px primary headings, 23px/30px section headings,
and 18px/26px subheadings, with tighter display tracking and letter-spaced
utility labels. Account, order-summary, and support surfaces use restrained
cool-neutral borders, rounded corners, generous spacing, and the primary
`#2255EE` token for actions and brand accents. Mobile hero copy remains legible
at 13px or larger with fluid table layouts.
The administrator launch preview keeps scripts disabled but grants its
`srcdoc` iframe the parent origin so it can load the self-hosted WOFF2. Real
mail clients may still block web fonts and must remain legible with the
system-font fallbacks.

The customer new-account template uses
`/assets/email/account-welcome-hero.jpeg` as a full-bleed hero background with
a left-to-right navy scrim, live text shadows, and a prominent account CTA.
The solid navy table color remains the fallback when a client blocks images or
gradients. The hero image stays visible behind the text on desktop and mobile.
The display headline follows the storefront hero's bold italic treatment while
retaining the email-safe Geist/system stack. The CTA continues to use
WooCommerce's password-setting URL when one is supplied, and otherwise the
WooCommerce My Account URL. The offer block appears only
for a user-owned welcome campaign coupon verified as a 10% percentage
discount; preview mode uses a clearly marked `PREVIEW10` code and does not
create a coupon. The header, footer, and account hero artwork assets are
source-controlled under `frontend/public/assets/email/`; the shared email logo
is `frontend/public/logos/email-logo-white.png`. These assets must be released with the MU-plugin
template changes. The classic header uses the compact, logo-height band with
10px vertical padding; the footer uses its separate, taller artwork and
remains tall enough for navigation and legal links.

### Decorative hero artwork

The desktop lifecycle hero may use the approved background artwork as a
progressive decorative treatment. The artwork is never semantic content. On
clients at 600px and below, DTB explicitly disables the hero background image
and renders a deterministic solid `#030712` hero. This avoids Gmail mobile
scaling the legacy HTML `background` attribute independently from the CSS
`background-size` declaration, which previously allowed the decorative image
to dominate the message viewport. The order/lifecycle eyebrow and `h1` remain
ordinary live text.

### Mobile order and address behavior

The order summary remains one semantic table. On narrow clients the header row
is hidden, while product identity remains the flexible column and quantity and
price remain bounded columns. Product images are reduced to 50px. This avoids
maintaining duplicate desktop/mobile order markup.

Billing and shipping addresses use a fixed two-column table at desktop width
and stack through the single mobile media query. Address, email, shipping, SKU,
and metadata text are allowed to wrap so long values cannot force horizontal
overflow.

## Sender identity and Reply-To contract

`dtb-platform/Support/Email.php` defines the canonical outbound From identity.
`dtb-platform/Support/Email/Deliverability.php` normalizes Reply-To headers at
the `wp_mail` boundary. Same-domain Reply-To values are preserved; missing or
cross-domain Reply-To values are replaced with the canonical DTB mailbox.
This prevents a legitimate DTB message from intentionally presenting a
Drywall Toolbox From address while routing replies to an unrelated domain.

The application does **not** configure SMTP credentials, DKIM keys, SPF,
DMARC, return-path/envelope sender, PTR/rDNS, or TLS. Those remain provider and
DNS configuration. Production deployment therefore requires the configured
SMTP provider to authenticate the same organizational domain used by the
visible From header.

## Settings precedence

WooCommerce Settings → Emails remains authoritative for enablement,
recipients, CC/BCC, email type, and any subject/heading/additional-content an
administrator has explicitly configured. DTB supplies default copy and visual
presentation without bypassing WooCommerce's email settings system.

## Customer cancellation email

The order workbench's **Move to Cancelled** action accepts an optional
operator-entered note, limited to 2,000 characters. The note is stored on the
WooCommerce order before the status transition fires the native customer
cancellation email, then rendered as escaped text in that email. Orders
cancelled through other paths have no note unless one was explicitly saved by
the workbench. The customer cancellation template intentionally omits
`woocommerce_email_customer_details`, so it does not render billing or shipping
address details. Email Studio's synthetic cancellation fixture includes a
sample note for layout review; it does not represent a real order.
The shared order-details projection serves customer and operator order emails.
It preserves WooCommerce's shipping and tax breakdowns and adds an explicit
shipping and tax total if WooCommerce omits either row, including zero-value
rows. Tax-inclusive orders label the tax as included. The synthetic preview
includes sample shipping and tax amounts so both rows are visible during review.
The cancellation note uses an email-safe, inset brand-accented panel with an
“A message about your order” heading and the same Geist/system font stack as
the rest of the message.
The customer cancellation hero uses the heading “We’ve cancelled your order”
and explains that support can help with questions or next steps, without
assuming or disclosing a cancellation reason.
Its support callout reads “Need a hand?” and gives customers a direct “Contact
us” link with a short, reassuring message.

## Fulfillment authority

Veeqo remains authoritative for shipment/tracking facts. Native WooCommerce
Fulfillment objects and their notification hooks continue to own fulfillment
email triggering. The email rendering and deliverability changes do not alter
order creation, payment, inventory, fulfillment, queues, or provider state.

## Validation and production checks

Before shipping email changes to production:

- render at least admin new-order and customer processing-order emails through
  WooCommerce, not a standalone HTML fixture;
- verify desktop Gmail web and Gmail mobile/app rendering at narrow and wide
  widths;
- confirm the mobile hero renders as a solid dark surface with live text and
  no oversized decorative background artwork;
- verify the order table, totals, billing/shipping addresses, progress steps,
  CTAs, and long SKU/shipping text without horizontal overflow at narrow
  widths;
- inspect the received message's original headers and confirm SPF, DKIM, and
  DMARC pass with an aligned `From: ...@drywalltoolbox.com` identity;
- confirm Reply-To is a `@drywalltoolbox.com` mailbox;
- confirm public HTTPS availability of `/logos/email-logo-white.png`,
  `/assets/email/dtb-email-header-background.jpg`, `/assets/email/dtb-email-footer-background.jpg`,
  `/assets/email/geist-latin-wght.woff2`,
  `/assets/email/account-welcome-hero.jpeg`, and any email icon assets used by
  the rendered template;
- verify SMTP transport/TLS and provider-level envelope sender settings in the
  production mailer configuration.
