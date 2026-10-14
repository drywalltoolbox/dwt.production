# Product checkout handoff

Last verified against active source: 2026-10-14.

## Payment and order authority

WooCommerce owns cart/session state, addresses, shipping, tax, totals, checkout submission, order state, and refunds. WooPayments owns payment-method eligibility and UI, wallet rendering, tokenization, authentication, capture, and provider webhooks. DTB owns only the native checkout shell, advisory readiness, checkout environment policy, and verified order-lifecycle observation. React never creates or confirms a payment.

```text
React product/cart
  -> same-origin WooCommerce Store API cart/session
  -> native WooCommerce Checkout Block
  -> WooPayments provider-owned payment UI and lifecycle
  -> WooCommerce order/payment state
  -> DTB verified payment events and downstream queues
```

The product CTA performs one cart mutation and a full-document checkout handoff. It does not select a wallet or payment method. Actual method availability is determined by WooCommerce and WooPayments after checkout loads, based on the account, cart, address, browser, and provider settings.

## Environment policy

Test-mode WooPayments methods are hidden unless the backend explicitly identifies itself as isolated staging. Guest test checkout requires all of the following deployment values:

- `WP_ENVIRONMENT_TYPE` is `staging`;
- `DTB_CHECKOUT_ENVIRONMENT` is `staging`;
- `DTB_WOOPAYMENTS_TEST_CHECKOUT_ENABLED` is boolean `true`;
- `DTB_PRODUCTION_ORIGIN` is configured, and staging's `home_url()` host differs from its host.

The deployment operator may set these only after confirming separate WordPress files/configuration, database, hostname, cookies, WooPayments connection, and downstream integrations. A `/staging/` folder or test-mode toggle on the production installation is not isolation. No WordPress admin authentication is required for ordinary customer checkout. The public capabilities endpoint is advisory and contains no secrets.

## Client readiness

`GET /wp-json/dtb/v1/checkout/capabilities` reports the active provider, Checkout Block presence, WooPayments enabled state, test mode, and whether the explicit isolated test environment contract is active. Product readiness never replaces the Checkout Block's gateway decision. If readiness is missing, the checkout handoff remains available and the provider remains the final authority.

## Runtime and security

- The provider owns its external scripts and secure payment surfaces. DTB does not inspect cross-origin frames, infer readiness from iframe markup, inject provider errors, or modify checkout DOM.
- The telemetry endpoint accepts only same-origin requests, applies rate limits, sanitizes and redacts bounded diagnostics, and does not require WordPress admin authorization.
- Checkout documents and Store API sessions must remain private and uncached. The same-origin WooCommerce session is the cart authority for both guests and signed-in customers.
- SiteGround optimization must not combine, defer, or cache the registered Checkout Block and payment runtime graph.
- Current WooPayments/WooCommerce release compatibility, provider script dependencies, callbacks, and webhook delivery are acceptance gates. Vendor plugin files are not patched to silence dependency warnings.

## Acceptance gate

Run the full matrix only on isolated staging with a separate WooPayments test connection: guest and authenticated carts; card success, decline, retry, and 3DS; supported express methods on eligible devices; shipping/tax/coupon recalculation; order confirmation and return URL; webhook and duplicate-event behavior; refunds; and containment of fulfillment, accounting, notification, and analytics effects. Repeat browser coverage on mobile Safari and desktop Chromium.

Existing orders may retain historical provider metadata, IDs, transaction references, and callbacks. These values remain readable for reconciliation and refunds; they are not an active checkout integration. Do not rewrite historical orders during cutover.
