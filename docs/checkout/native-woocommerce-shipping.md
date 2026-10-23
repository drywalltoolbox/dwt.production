# WooCommerce shipping and supplier-direct fulfillment

## System ownership

- WooCommerce owns checkout, shipping zones and methods, displayed rates, order totals, payment state, and the shipping lines saved on the order.
- DTB owns the payment-gated order ledger, Action Scheduler orchestration, order management, tracking records, and customer support.
- Launch fulfillment is manual supplier-direct: after WooCommerce records captured payment, staff place the supplier order; the supplier ships to the customer.
- Veeqo code and credentials remain available for future stocked-item integration; production provider calls are paused during launch. Supplier-direct orders must not be created in Veeqo automatically.
- Do not calculate checkout rates through Veeqo, add a competing checkout/rate engine, or promise services the fulfillment workflow does not support.

## Production readback — 2026-10-23

MCP DTB confirmed production at `https://drywalltoolbox.com` (WordPress 7.1.3, WooCommerce 11.2.1, PHP 8.2.35).

Flexible Shipping 6.13.5 was active during inspection, but its plugin has since been deactivated through MCP DTB. No order, payment, product, shipping-method setting, or provider configuration was changed.

- US zone 1 currently exposes enabled legacy DTB shipping instance 1 and WooCommerce Free Shipping instance 3, configured with a $50 minimum. The Free Shipping method is active alongside the legacy service.
- Rest-of-World zone 0 currently exposes only enabled legacy DTB shipping instance 2.
- No native core Flat Rate replacement has been verified in either zone. The legacy method is still the paid checkout path. Its source includes estimated weight tiers and synthetic Express and Overnight options; these are not verified supplier or carrier charges and do not establish delivery guarantees.
- The complete destination-country policy, product shipping-class assignments, and package measurements were not verified.
- Before the launch gate was applied, Veeqo reported enabled and inventory-ready, and paid orders queued Veeqo order sync through Action Scheduler. Production now defines `DTB_LAUNCH_EXTERNAL_INTEGRATIONS_DISABLED=true`; Veeqo API requests return before network access, the recurring inventory schedule/runner exits, and the order-sync handler records skipped state/events. The integration remains installed for future reactivation.

## Required shipping policy

Use preconfigured WooCommerce methods as the checkout authority. Standard charges must be merchant-approved customer prices grounded in current supplier/packaging and fulfillment data. Do not treat historical DTB estimates as carrier quotes or copy them into native methods without approval. Do not invent package dimensions, weights, oversized charges, or delivery dates.

The existing $50 free-shipping threshold is the current US merchant policy. Oversized, long-tool, complete-kit, freight, and mixed-cart exceptions need explicit SKU/variation eligibility based on verified product and supplier data. WooCommerce shipping classes can support class-specific Flat Rate charges. Core WooCommerce Free Shipping does not exclude shipping classes, so a core-only configuration cannot enforce “free at $50 except oversized classes.” Do not disable the legacy method until an approved, supported way to enforce that exception is configured and verified.

No expedited, overnight, same-day, Saturday, freight, or delivery-window choice should be shown unless an active WooCommerce rate and the fulfillment process both support it. A transit estimate is not a delivery guarantee.

## Manual fulfillment and Veeqo boundary

WooCommerce remains authoritative for the customer order and captured payment. DTB records payment-gated lifecycle events and schedules its existing jobs. During manual supplier-direct launch, staff place supplier orders and enter tracking for customer support.

Production launch mode is active through `DTB_LAUNCH_EXTERNAL_INTEGRATIONS_DISABLED=true` in the integrations bootstrap. Veeqo order sync is skipped, outbound Veeqo API calls are blocked, and the recurring inventory scheduling/runner is paused. QuickBooks order/refund writes were already paused; launch mode now blocks all authenticated QuickBooks API requests and OAuth token exchange. Credentials and tokens are retained for later integration work. WooCommerce payment/order authority, DTB’s payment ledger, and unrelated Action Scheduler jobs remain active. The corresponding source changes are in draft PR #136.

## Migration source and operational boundary

`scripts/wordpress/migrate-native-shipping.php` is an explicit WP-CLI preview/apply command for preconfigured WooCommerce core Flat Rate and Free Shipping methods. It does not set prices, create methods, assign product classes, change destination policy, or alter orders. It requires one enabled US Flat Rate and one enabled US Free Shipping method at $50, plus a non-US Flat Rate if non-US shipping remains enabled. Apply also requires explicit coverage, free-shipping, and oversized-exception review flags.

The flags are operator attestations, not automated proof that product assignments or core Free Shipping eligibility enforce oversized exclusions. Do not run apply until that policy gap is resolved and rates are confirmed in checkout. Never run this command from a request/page load or emulate shipping-method changes with direct database writes.

MCP DTB in this session supports shipping-zone/method reads and guarded managed-file writes, but exposes no shipping-method create/update action or WP-CLI execution action. Plugin deactivation is reversible. No live rate cutover was performed.

## Remaining launch gates

1. Approve real standard customer charges and identify the supported destinations.
2. Choose an approved way to enforce the $50 free-shipping threshold with oversized exclusions; core Free Shipping does not provide that class exclusion.
3. Verify product/variation class eligibility against supplier measurements and packaging. Product catalog dimensions alone are not outbound carton measurements.
4. Configure and read back native WooCommerce methods in each supported zone, while preserving an available paid method at checkout.
5. Remove the legacy method and its synthetic expedited options only after the replacement rates and eligibility rules are live and verified.
6. Run the repository’s existing PHP checks and a staging checkout review before merging the launch-mode source changes. PHP CLI and PR-triggered workflow checks were unavailable in this session.

## Rollback

Flexible Shipping can be reactivated from plugin management if required, though it was not the only currently active checkout method. Keep the legacy method enabled until a supported replacement is ready. Do not roll back WooCommerce orders, customers, or payments. Before a future rate cutover, capture method settings and zone states, then verify checkout rates and saved order shipping lines after the change.
