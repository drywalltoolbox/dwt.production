# WooCommerce shipping and manual supplier-direct fulfillment

## Ownership

- WooCommerce owns checkout, customer shipping selection, payment capture, orders, totals, and the shipping lines saved to each order.
- DTB owns the payment-gated order ledger, Action Scheduler orchestration, order management, tracking records, and customer support.
- Launch fulfillment is manual supplier-direct: after payment is captured, staff place the supplier order and the supplier ships to the customer.
- Veeqo and QuickBooks remain installed for later integration work, but launch mode blocks external provider activity. Supplier-direct orders are not automatically sent to Veeqo.

## Production readback — 2026-10-23

MCP DTB reports production at `https://drywalltoolbox.com` (WordPress 7.1.3, PHP 8.2.35).

- Flexible Shipping is inactive.
- US zone 1 has enabled legacy DTB shipping instance 1 and WooCommerce Free Shipping instance 3, with a $50 minimum. Rest-of-World zone 0 has enabled legacy DTB instance 2. No native core Flat Rate replacement is configured.
- The live DTB method source was read back at 24,568 bytes, SHA-256 `f67e9fa84029d5495a4ff8ef84caf0843dd49bdcf04da8d9b755be419e55b747`. It offers Standard shipping, applies domestic Free Standard at a $50 cart subtotal without an oversized-item exception, and contains no Express, Overnight, Veeqo quote call, or delivery-window claim.
- Below $50, the current legacy Standard method uses historical weight tiers ($7.99 / $12.99 / $19.99 / $29.99). Those are existing merchant checkout charges, not verified carrier quotes or documented fulfillment costs. They are not copied into native WooCommerce settings by this PR.
- The live method also has historical International Standard tiers. Supported countries and the full destination policy have not been verified.
- Production launch mode sets `DTB_LAUNCH_EXTERNAL_INTEGRATIONS_DISABLED=true`. Veeqo API requests, recurring inventory scheduling, and supplier-direct order sync are paused; QuickBooks API requests and token exchange are blocked. Stored credentials remain for future integration work.
- The repaired PHP file is now included in this PR so GitHub source matches the MCP DTB production readback.

## Shipping policy

The $50 free-shipping threshold applies to all qualifying carts, including oversized items. There is no oversized-item exception to free shipping and no class-based free-shipping exclusion.

Keep WooCommerce as the checkout and order authority. The current legacy DTB method remains the paid Standard path until an approved native WooCommerce paid rate is configured and verified. Do not represent historical weight tiers as carrier quotes or invent charges, dimensions, package weights, or delivery guarantees. Do not offer expedited or delivery-window options.

A future native-method cutover must preserve the all-items $50 free-shipping rule, supported destination coverage, and an enabled paid rate below $50. MCP DTB currently exposes shipping-zone/method reads but no shipping-method create/update or WP-CLI execution action.

## Migration script boundary

`scripts/wordpress/migrate-native-shipping.php` is a guarded WP-CLI cutover for preconfigured WooCommerce core Flat Rate and Free Shipping methods. It does not set prices, create methods, assign shipping classes, infer package dimensions, purchase labels, or change orders.

Before applying it, an operator must verify the real paid rate, supported destination coverage, the $50 threshold for oversized and standard merchandise alike, and the methods in checkout. The script requires coverage and free-shipping review attestations; it does not require an oversized-exception attestation. Apply disables the legacy method only after it finds one enabled US Flat Rate and one enabled Free Shipping method configured at $50, plus a Rest-of-World Flat Rate if non-US destinations remain enabled.

Do not run the apply command against production until the native rates are merchant-approved, configured and read back, and checkout is verified in a genuinely isolated staging environment. No live rate cutover was performed.

## Staging checkout verification

The staging storefront at `https://drywalltoolbox.com/staging/` displayed a cart checkout link under `/staging/checkout`, but that route redirected to the production `https://drywalltoolbox.com/checkout/`. MCP DTB also reports the connected WordPress environment as production. Therefore this session did not verify a staging checkout, and no order, payment, or production checkout submission was made. Resolve the staging-to-production checkout routing before treating staging checkout validation as complete.

## Launch gates

1. Approve paid Standard charges and supported destinations without inventing rates.
2. Configure native WooCommerce paid and free methods, preserving free shipping at $50 for oversized and standard carts.
3. Verify checkout in an isolated staging environment at the $50 boundary and confirm an enabled paid option below it.
4. Run the repository's existing PHP syntax check and review the staging checkout result.
5. Only then squash-merge and later deploy through the established WordPress release path. A GitHub merge alone does not deploy WordPress source.

## Rollback

Keep the currently working production shipping source and zone methods intact until native replacements are configured and verified. Before a future cutover, back up source and zone-method settings. Roll back shipping source and zone-method states together if checkout rates fail; do not roll back WooCommerce orders, customers, payments, or unrelated Action Scheduler work.
