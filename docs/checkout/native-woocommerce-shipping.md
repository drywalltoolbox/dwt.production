# WooCommerce-native shipping migration — Drywall Toolbox

## Decision and authority

WooCommerce owns shipping zones/classes/rates/cart totals, customer selection,
native Checkout Block and order shipping lines. DTB owns only narrow presentation
policy (hide redundant paid Standard when Free Standard qualifies) and the
existing payment-gated event ledger/Action Scheduler integration. Veeqo owns
inventory, allocation, fulfillment, carrier-rate shopping *after order import*,
labels, shipment state and tracking.

**Do not depend on checkout-time calls to Veeqo.** The custom Woo shipping
method `dtb_veeqo_rates` and its self-healing zone creation must be retired.
The Veeqo adapter and existing order/fulfillment workers remain unchanged.

## Verified existing production state (2026-10-23)

- WooCommerce United States zone **1** has only enabled custom
  `dtb_veeqo_rates` instance **1**.
- Rest-of-World zone **0** has only enabled custom
  `dtb_veeqo_rates` instance **2**.
- Woo setting `woocommerce_shipping_hide_rates_when_free = no`.
- Woo store `woocommerce_allowed_countries = all` and
  `woocommerce_ship_to_countries = all`.
- Current DTB merchant policy: Free Standard at domestic subtotal >= $50;
  nonqualifying domestic standard formerly used weight brackets
  $7.99 / $12.99 / $19.99 / $29.99. These are internal estimates,
  **not** verified carrier prices and are **not** automatically migrated.
- Veeqo order creation presently uses **one configured**
  `delivery_method_id` for all orders. It does not map each customer's
  chosen expedited checkout method to Veeqo's delivery/service selection.
  **Therefore do not enable paid expedited promises yet.**
- Product weight/dimensions are incomplete for some toolsets, and
  documented Veeqo carrier configurations reported `connected=false`.
  Native shipping classes do not cure carton or service eligibility.

## Source

- `drywalltoolbox/wp/wp-content/mu-plugins/dtb-commerce/Shipping/DTBShippingMethod.php`:
  removes custom rate/method/bootstrap; retains only a minimal WooCommerce
  package-rates display filter plus cache helpers for compatibility.
- `scripts/wordpress/migrate-native-shipping.php`: explicit WP-CLI migration,
  idempotent on DTB-managed native method instances, preflights zones and
  destination coverage, and refuses silent replacement of operator-managed
  shipping methods.
- `scripts/launch-readiness/tests/native_shipping_contract.php`: guards free
  Standard + paid upgraded methods coexistence and noneligible carts.
- `docs/checkout/native-woocommerce-shipping.md`: this rollout contract.

## Shipping classes

The explicit migration creates `small-parts`, `standard-tools`,
`long-bulky-tools`, `complete-toolsets`, and `oversized-freight` as
WooCommerce product shipping classes. **It does not auto-classify SKUs**
using product names, nor guess carton dimensions or rates. The operator must
audit class assignments and configure class-specific charges where approved.

Core Flat Rate and Free Shipping do not calculate dimensional or carrier
rates. Multi-box kits and freight need a *separate approved* fulfillment
and charging policy/extension, not synthetic postage rates.

## Guarded rollout — no production auto-migration

1. Before cutover, approve a real merchant Standard flat rate and a
   US/international service policy. Avoid copying legacy weight brackets
   blindly. Make sure WooCommerce still covers all enabled shipping
   destinations. In the observed production configuration, all countries are
   enabled yet ROW has **no independent method**, so APPLY MUST fail until
   ROW is addressed by a verified native shipping method or supported
   shipping destination policy is explicitly narrowed.
2. Run WP-CLI **dry run** on isolated staging:
   `wp --require=/path/to/scripts/wordpress/migrate-native-shipping.php dtb shipping migrate --standard-rate=<APPROVED_US_RATE>`
3. Validate rate/class eligibility and customer-facing checkout shipping lines
   in the native Checkout Block with *no payment/label purchase*. Ensure
   international, toolset and freight categories have an approved policy.
4. Run explicit cutover only after backups, lint and staging:
   `wp --require=/path/to/scripts/wordpress/migrate-native-shipping.php dtb shipping migrate --standard-rate=<APPROVED_US_RATE> --apply --allow-production`
   The script provisions native US Flat Rate and Free Shipping ($50 minimum)
   and disables both legacy custom zone instances only after native readback.
   Existing operator-managed methods are not overwritten.
5. Deploy matching GitHub PHP only after native instances are confirmed
   active. A **repository merge alone does not deploy** WordPress source.
   The replacement PHP intentionally does not load the old shipping method,
   therefore deploying it *before* native zone migration will break shipping.
6. Verify WooCommerce Store API and checkout at $49.99 / $50,
   multi-item carts, toolsets, missing dimensions, mixed products,
   supported country/region selection, taxes and shipping-line persistence.
   Verify old custom method no longer appears. Monitor WordPress PHP error
   logs after deployment.

## Rollback

Restore the original tracked `DTBShippingMethod.php` from the pre-migration
GitHub commit and restore original Woo zone-method statuses:
legacy instance 1 (US) and instance 2 (ROW) were enabled before migration.
Disable DTB native instances only when the legacy shipping method has been
restored and validated. Bump WooCommerce's shipping cache version.
Do **not** roll back databases, WooCommerce orders, customers or payments.
A backup of zone method instance settings and the shipping methods table is
advised before production cutover.

## Explicit non-goals and outstanding gates

- No real shipments, fulfillment mutations, labels, payments, refunds or orders.
- No fabricated expedited, overnight or freight service names/prices.
- No carrier service-level promises until selected Woo shipping method is
  propagated and enforced in the Veeqo order/fulfillment workflow.
- No claims that WooCommerce native Flat Rate computes live UPS/FedEx/USPS
  costs. Use a vetted carrier plugin only when real-time quotes are required.
- No automated international change without confirmed supported destination
  scope and merchant-approved price/eligibility policy.

## Platinum benchmark: adopt principles, not the plugin stack

Read-only inspection of Platinum's production WordPress via PLATMCP
(2026-10-23) confirmed one native Free Shipping instance (zone 1,
`requires = ''`, minimum 0), no Rest-of-World method, US-only selling
countries, and an active YITH Shippo shipping plugin alongside the official
Veeqo WooCommerce bridge.

YITH Shippo's inspected source can calculate **multiple outbound parcels**,
filter carrier services, combine quotes across parcels, apply shipping rules
and surface rates as a WooCommerce method. Its parcel editor supports boxes,
max-weight limits, padding, and dimensional adjustments. Nevertheless,
Platinum's published `PT-FIN` kit and 50-inch handle have missing
measurements/shipping classes, and populated-checkout behavior plus operational
label ownership were **not verified**. No third-party code was copied.

**Selected DTB adaptation:** WooCommerce zones/methods/classes remain the
only checkout shipping truth; Veeqo remains sole label/fulfillment owner.
Build a read-only completeness inventory and collect merchant-confirmed
packaging data before considering a single supported carrier-rating extension.
Do **not** add YITH Shippo, bypass native WooCommerce shipping, or build a
parallel packing engine in DTB MU plugins.

## Data readiness: operator packaging audit

Run the new non-mutating report via WP-CLI:

`wp --require=/path/to/scripts/wordpress/audit-shipping-packaging.php dtb shipping audit --format=csv`

The report identifies shipping-class gaps, zero/missing **catalog** weight,
length, width, height, and special-handling/variable-product review flags.
Woo product dimensions **are not treated as measured outbound carton
dimensions**. A special-handling review flag is tracked only through the
explicit `_dtb_shipping_carton_reviewed = yes` product metadata. This field
does not certify carrier-specific eligibility and is **never** used to create
rates. No script in this PR automatically fills or overwrites this field.

A shipping-class inventory must be manually validated at SKU/variation
level; classes `long-bulky-tools`, `complete-toolsets`,
`oversized-freight` flag special handling. The report itself never assigns
categories by product names.

### Release eligibility matrix

| Shipment | Native rate posture | Required evidence before additional services |
| --- | --- | --- |
| Small replacement part | Merchant-approved Standard / eligible Free | Accurate dimensions and actual package type |
| Ordinary hand tool | Merchant-approved Standard / eligible Free | Dimension and packed-carton verification |
| Long finishing handle / extended box | No claimed carrier-specific rate | Actual measured long carton and carrier acceptance |
| Complete toolset / kit | No fictitious one-box live quote | Merchant-confirmed multi-carton pack list and cost |
| Oversize / freight | Special-handling policy, no invented freight rate | Actual freight eligibility, pricing and handling |
| Mixed/quantity cart | Merchant Standard only until approved policy | Packing plan for all quantities and packages |

This matrix is a **configuration review gate**, not a promise that a single
unconditional native Flat Rate is economically appropriate for all categories.
The existing $50 merchant Free Shipping entitlement remains under explicit
business review for high-cost freight/toolsets; do not silently narrow the
promotion in the source code. Any change requires an approved policy and
checkout tests for the $50 boundary and mixed carts.

### Decision gate: optional live rates

Prefer native WooCommerce shipping for ordinary shipping. If carrier quotes
are needed, evaluate a **single** supported checkout-only shipping extension
with verified Checkout Block/Store API compatibility, multi-parcel packaging,
service eligibility, stable IDs, shipping-cost behavior, and ability to
disable external label purchase/tracking. Live Veeqo account status alone is
not evidence that checkout quote services work. No extension is installed by
this PR. Fulfillment service-level mapping to Veeqo is required before any
paid expedited delivery promise is enabled.

## Execution evidence: 2026-10-23

- Production MCP DTB confirmed site `https://drywalltoolbox.com` with US zone 1 (legacy instance 1 enabled) and ROW zone 0 (legacy instance 2 enabled), **no native replacement methods**. An in-place PHP deployment removing the legacy method would leave shipping without an enabled calculator.
- The connected Windows computer `DESKTOP-7CFR9JU` exposes an SSH alias `dtb-siteground`, but connection failed at DNS resolution for the configured SiteGround host. Thus no WP-CLI migration was run; no server file writes, zone changes, product writes or payments occurred.
- The 1,134-row official CSV was successfully audited on the Windows device in a separate, read-only script; every source row has an empty Shipping class and many records have incomplete dimensions. That source fact must not be interpreted as the current live Woo catalog state.
- `frontend/src/pages/ShippingPolicy.jsx` previously advertised overnight, Saturday, specific international services, same-day cutoff and calculated freight without verified eligible Woo rate instances. Source-only copy was adjusted to avoid promising unconfigured services. The actual native Checkout Block still owns rate selection and totals.
- Production migration is explicitly blocked until verified country coverage, carrier/fulfillment service choices, SKU packaging and class policy, approved merchant flat rate, isolated Store API tests, and usable WP-CLI/SiteGround access.

## Merge-first compatibility and separate production cutover

PR #134 **can merge before WooCommerce shipping migration**. The PHP module retains the legacy `dtb_veeqo_rates` class, rate generator, zone bootstrap and existing checkout-time behavior until `dtb_native_shipping_migration_version=1` is written by the explicitly invoked WP-CLI migration after native shipping methods are configured and read back. The legacy rate fallback and zone bootstrap stop once that marker is set. The custom method remains registered to support historical order references but its zone instances are disabled at cutover.

**Deployment order**: merge source → deploy the compatibility implementation (legacy active) → approve prices, classes and destination/special-handling rules → execute guarded native configuration from deployed WP-CLI → verify checkout rates and fulfillment → maintain rollback/backups. Merging is not migration; deploying is not proof of cutover. Never enable the migration flag manually ahead of native methods.

The shipping migration's `--special-handling-verified` and international coverage gates must be met before it provisions new methods. Those flags are attestation, not substitute calculations. Do not invent flat rates or assert freight coverage without merchant/carrier facts.


## Flexible Shipping Free replacement (2026-10-23)

**Target owner:** WooCommerce shipping zones and the installed Octolize Flexible Shipping Free extension (modern single method ID `flexible_shipping_single`). No DTB custom rate calculation, no additional carrier-rate extension and no separate checkout. The legacy method remains registered only for historical references after a guarded cutover. Veeqo remains the sole label, fulfillment and tracking authority.

**Observed live starting state:** plugin `flexible-shipping/flexible-shipping.php` v6.13.5 active, but no Flexible Shipping method attached to either WooCommerce zone. United States has enabled legacy DTB and a separate $50 Free Shipping method. Rest of World has only enabled legacy DTB. WooCommerce permits shipping to all countries. Zero defined product shipping classes in the live taxonomy. Therefore **do not run cutover** until usable replacement methods, destination restrictions and actual weight tiers exist.

**Initial rule source:** the currently deployed legacy method charges $7.99 (≤1), $12.99 (≤5), $19.99 (≤15), $29.99 (above 15) for domestic Standard when cart value is under $50, in WooCommerce's active weight unit; these are *existing historical site prices*, **not** validated USPS/UPS shipping costs or newly approved commercial rates. Do not silently transfer to Flexible Shipping without merchant review. Existing expedited and overnight prices are synthetic and must not be copied or promised. Price and unit policy for all unweighed products must be resolved; missing weight currently can be treated as zero by legacy casting.

**Free shipping and exclusions:** the existing WooCommerce native $50 Free Shipping method is enabled without a product-class exclusion. Flexible Shipping Free's built-in threshold does not independently solve oversized-product exceptions. Review free shipping eligibility and disable duplicate zero-price options before launch. Do not silently grant zero-cost service for freight, long tools or repair orders.

**Cutover:** configure an enabled modern Flexible Shipping single method in the US zone, resolve international policy (either restrict shipping to the verified US destinations or configure another supported method in Rest of World), verify representative weight tiers and exclusions, then execute the guarded `scripts/wordpress/migrate-native-shipping.php` command through an authorized WP-CLI environment. It will refuse cutover without enabled methods and explicit coverage/free-shipping attestations; successful cutover disables legacy instances and writes `dtb_native_shipping_migration_version=1` to stop legacy fallback and zone self-repair. Always retain rollback evidence and verify actual checkout rates. No label purchases or live test orders are required.

**Deployment limitation:** MCP DTB currently lists shipping methods and allows guarded plugin file writes, but does not expose zone-method creation or update, and its generic WordPress taxonomy interface rejects `product_shipping_class` because its WordPress REST exposure is disabled. The frontend build MCP reports no configured workspace/runtime. These are execution-access limitations, not reasons to fake shipping rates.
