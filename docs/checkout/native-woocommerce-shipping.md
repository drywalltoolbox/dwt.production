# WooCommerce shipping and supplier-direct fulfillment

## Authority and launch model

WooCommerce owns shipping zones, methods, rate calculation, cart totals, customer selection, native Checkout Block, payment/order state, and order shipping lines. DTB owns the payment-gated order event ledger, Action Scheduler orchestration, order management, tracking records, and customer support. For launch, staff manually place supplier-direct orders after WooCommerce records captured payment; suppliers ship to customers. Veeqo remains available for inventory and future stocked-item fulfillment, but storefront orders must not be created in Veeqo automatically during manual supplier-direct launch.

Do not call Veeqo for checkout rates. Flexible Shipping is the only intended checkout rate authority. Do not add a parallel checkout, shipping engine, carrier rate adapter, or supplier order automation.

## Production evidence — 2026-10-23

MCP DTB confirmed the production site is `https://drywalltoolbox.com` (WordPress 7.1.3, WooCommerce 11.2.1, PHP 8.2.35). Flexible Shipping 6.13.5 and WooCommerce Shipping 2.4.3 are active.

- United States zone 1 has three enabled methods: DTB legacy instance 1, native WooCommerce Free Shipping instance 3 (minimum amount $50), and Flexible Shipping single-method instance 4 (title “Standard Shipping”).
- Flexible Shipping instance 4 reports a $50 free-shipping threshold. The returned configuration included a weight rule from 0 to 1 at $7.99; the API response was truncated before all rules could be independently read back. Treat the other historical tier amounts as unverified until a complete settings readback.
- Rest-of-World zone 0 has only enabled DTB legacy instance 2. It has no Flexible Shipping fallback.
- Veeqo integration health reports enabled and inventory-ready. Webhook registration is disabled. The active order workflow enqueues `dtb_order_sync_veeqo` through Action Scheduler after the payment-gated order lifecycle.
- The production catalog shipping-class assignments, complete Flexible Shipping rules, and current WooCommerce destination options were not fully verified in this session.

No live zone, rate, product, order, payment, label, or provider settings were changed during this inspection.

## Rate and eligibility policy

Keep existing customer-facing charges only when they are verified as configured merchant checkout prices. The historical DTB source contains domestic weight tiers of $7.99, $12.99, $19.99, and $29.99, but these are not evidence of carrier cost, packaging cost, or measured parcel eligibility. Do not describe them as carrier quotes or copy unverified tiers into Flexible Shipping. Do not invent package weights, dimensions, freight prices, or delivery dates.

The existing $50 free-shipping policy must be represented once in the active checkout method. The US zone currently has two possible free options: the native Free Shipping method and Flexible Shipping's built-in threshold. Before retiring legacy shipping, verify the Flexible Shipping threshold and disable the duplicate native free method only after the intended free-shipping rules are confirmed.

Long tools, complete toolsets, mixed carts, freight, and repair/service lines need explicit exceptions. Shipping classes alone do not define parcel dimensions or shipping charges. Do not grant free shipping to a special-handling item unless the business policy explicitly makes it eligible. Do not assign classes from product names or guessed dimensions. Build SKU/variation assignments from verified packaging and supplier data, then configure and read back the matching Flexible Shipping and free-shipping exclusions.

Do not offer expedited, overnight, Saturday, same-day, freight, or delivery-window services unless an actual configured checkout method supports the service and the fulfillment workflow honors the selected service. No delivery date is guaranteed by a transit estimate.

## Supplier-direct fulfillment safety

The paid WooCommerce order and captured payment remain authoritative. Staff place the supplier order manually and enter supplier/carrier tracking into DTB's existing order workflow for customer support.

PR #136 adds a fail-closed guard in the `dtb_order_sync_veeqo` Action Scheduler handler. Unless `DTB_ORDER_VEEQO_SYNC_ENABLED` is explicitly enabled in site configuration, the worker records a skipped Veeqo integration state and event without creating a Veeqo order. This leaves the payment ledger, Action Scheduler, inventory integration, and Veeqo's future stocked-inventory capability available. The guard also applies to already queued Veeqo order-sync jobs. This repository change has not yet been deployed to production.

## Migration command

`scripts/wordpress/migrate-native-shipping.php` is an explicit WP-CLI cutover command. It does not create shipping prices, methods, classes, product assignments, or country policy. It requires one enabled Flexible Shipping method in the US zone and, while non-US destinations remain supported, an enabled Flexible Shipping method in Rest of World. Production execution requires explicit production, destination-coverage, and free-shipping review flags. On successful cutover it disables the legacy DTB zone instances and writes `dtb_native_shipping_migration_version=1` to stop legacy rate fallback and zone self-repair.

Preview and cutover are separate operations. Never set the migration marker by hand. Keep backups and read back both zone method states and checkout rates after a cutover.

## Current cutover blockers

The current production configuration is not ready for a safe cutover:

1. Zone 0 has no Flexible Shipping method, while the last documented WooCommerce country policy allows all countries. Reconfirm the live country policy and either configure verified destination rates or intentionally narrow the supported destinations under an approved business policy.
2. The production Flexible Shipping method's complete rate table was not returned by MCP DTB. Verify every weight tier and its WooCommerce weight unit before relying on it.
3. The native and Flexible Shipping $50 free-shipping paths are both enabled. Confirm and retain only one customer-facing free option.
4. Oversized, long-tool, complete-kit, and freight exclusions are not verified. Obtain supplier/package measurements and SKU-level class assignments before enabling the $50 offer for those carts.
5. PR #136 must pass PHP lint and the existing repository checks, then be merged and deployed. Deployment does not execute the WP-CLI migration; the migration must be run through an authorized server-side WP-CLI path after all configuration readbacks.

MCP DTB exposed shipping-zone and method reads, guarded managed-file writes, and non-sensitive option reads, but did not expose zone-method create/update or a WP-CLI execution action during this session. Do not emulate those operations with direct database writes or a page-load migration.

## Rollback

If a cutover fails, restore the backed-up zone method statuses and the pre-cutover DTB shipping source. Restore DTB legacy shipping before re-enabling its zone instances. Clear the WooCommerce shipping cache version only after the intended method set has been read back. Do not roll back WooCommerce orders, customers, payments, or refunds.

## Historical context

The catalog audit in `docs/checkout/shipping-catalog-readiness-20261023.md` describes the official source CSV, not a complete live WooCommerce catalog read. It must not be used as proof that every live product has the same weight, dimensions, or shipping-class state.
