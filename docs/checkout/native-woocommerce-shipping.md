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
