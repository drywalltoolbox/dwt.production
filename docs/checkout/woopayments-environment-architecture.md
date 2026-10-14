# WooPayments checkout environment architecture

Status: runtime refactor implemented on the draft migration PR; infrastructure provisioning and end-to-end acceptance remain outstanding. This document describes the environment contract, not a claim that checkout is ready.

## Observed state (2026-10-13)

- `/staging/` is a React frontend directory that shares the production WordPress installation, database, WooCommerce session, and WooPayments configuration. Its ordinary checkout redirects to the root `/checkout/`.
- WooPayments 11.2.0 is active. The retired Payment Plugins for Stripe extension is inactive. The WooPayments card gateway is enabled and its settings have `test_mode=yes`; enabled methods include card, Affirm, Afterpay, Klarna, and Amazon Pay.
- The custom gateway policy, public `/dtb/v1/checkout/capabilities` response, React readiness parser, checkout return path, and paid-order observer now identify WooPayments as the active provider. Historical provider metadata remains readable for existing orders.
- Test-mode WooPayments gateways are suppressed from storefront checkout unless the installation asserts the isolated staging contract (`WP_ENVIRONMENT_TYPE=staging`, `DTB_CHECKOUT_ENVIRONMENT=staging`, `DTB_WOOPAYMENTS_TEST_CHECKOUT_ENABLED=true`, `DTB_PRODUCTION_ORIGIN`, and `DTB_PRODUCTION_DB_NAME`) and runtime checks confirm both hostname and database name differ from production. The current same-host `/staging/` path cannot satisfy that contract.
- The DTB runtime no longer guesses provider readiness from an iframe, injects a card-timeout notice, or mutates WooCommerce checkout DOM. Telemetry is same-origin and rate limited; it does not use WordPress admin authorization or a cache-sensitive nonce.
- WooPayments' installed `dist/blocks-checkout.js` reads `wc.wcSettings`, while its generated `dist/blocks-checkout.asset.php` does not list `wc-settings`. WooCommerce warns about this dependency declaration. The warning is an upstream compatibility risk, not proof that it caused this guest gateway exclusion.

## Target topology and authority

```text
Production React -> production Store API/session -> production native /checkout/
                                             -> production WooPayments live account
                                             -> production WooCommerce orders/events

Staging React -> staging Store API/session -> staging native /checkout/
                                       -> staging WooPayments test account
                                       -> staging WooCommerce test orders/events
```

The two lanes require distinct WordPress installations, databases, `home`/`siteurl`, cookies, and WooPayments connections. Both can use the same reviewed code revision. Each frontend and its REST API and native checkout must share its own origin. The production backend must never accept a staging cart, checkout, order, or webhook. Provider fields, tokens, authentication, and capture remain owned by WooPayments; orders and refunds remain owned by WooCommerce.

Use a dedicated staging hostname such as `staging.drywalltoolbox.com`, with HTTPS and access control compatible with provider callbacks. Do not treat a `/staging/` directory on the production WordPress installation as payment staging. A SiteGround staging copy or separate WordPress installation must be verified to have a different database and URL before a payment test. If SiteGround's staging tool clones the store, its copied WooPayments connection must use **Create a fresh connection** to a test account, not **Transfer your connection**. The latter disconnects the original URL. See https://woocommerce.com/document/woopayments/testing-and-troubleshooting/safe-mode/ . SiteGround documents that its Staging tool copies both files and database: https://www.siteground.com/tutorials/staging/create-staging/ .

## Migration sequence

1. **Provision isolation.** Back up production. Create the staging WordPress/database/hostname. Verify its database name differs from production and configure `DTB_PRODUCTION_DB_NAME` to the production database name in staging-only configuration. Also verify database identity without publishing credentials, `home`/`siteurl`, HTTPS, cookie scope, REST origin, `/checkout/` routing, and checkout no-cache behavior. Deploy the staging React build to that origin. Keep the old `/staging/` route clearly identified as a frontend preview until it is retired or redirected to the new host.
2. **Contain external effects.** Remove or anonymize copied customer data and payment tokens as required for testing. Disable or sandbox production Veeqo, QuickBooks, marketplace, email, analytics, and fulfillment side effects in the staging environment; use environment-scoped configuration rather than runtime URL guesses. Never push staging order/account/provider tables or WooPayments options back to production. SiteGround's full deploy can replace live files and database, so use code-only deployment for this migration: https://www.siteground.com/tutorials/staging/deploy/ .
3. **Connect payments independently.** Establish a fresh WooPayments test account on staging and enable card there. Verify WooPayments account capabilities, test mode, supported Checkout Block, HTTPS, domain association, webhooks, and current compatible WooCommerce/WooPayments versions. Production remains a separate WooPayments connection and ultimately uses live mode for real shoppers.
4. **Refactor the DTB provider policy.** Implemented: WooPayments is the only active DTB checkout adapter. Test-mode WooPayments is suppressed unless the installation supplies the isolated staging contract above, including runtime-verified separate host and database names; no customer needs a WordPress admin login or nonce to reach checkout. Historical serialized order/provider values remain read-only compatibility data. No WooPayments vendor files are patched.
5. **Replace legacy readiness.** Implemented: the public capabilities endpoint reports WooPayments and non-secret gateway/environment status; React normalizes that contract. It remains advisory, while Checkout Block and WooPayments determine actual methods from cart, address, and provider eligibility.
6. **Reduce DTB checkout runtime to observation.** Implemented: removed iframe-based timeout UI and checkout DOM mutation. Telemetry is bounded, origin checked, rate limited, and independent of WordPress admin authorization.
7. **Validate and cut over.** On the isolated staging host, verify a guest and logged-in cart, card render, test payment, decline, 3DS, eligible express methods, webhook confirmation, order status, thank-you/return route, refund, retries, and duplicate-event containment. Check mobile Safari and desktop Chromium with their normal privacy settings. Confirm no staging order can reach live fulfillment/accounting. Only then deploy the reviewed code and live WooPayments configuration to production and retest a controlled live transaction under the launch plan.

## Release gates

- Guest card method appears and a WooPayments test card completes on the isolated staging host without an administrator cookie. A customer never needs WordPress admin REST authorization.
- Staging test orders and callbacks remain within staging. Production gateway mode, account connection, orders, and external integrations are unaffected by staging tests.
- The public capabilities response names WooPayments, and React does not mark it as a competing provider.
- The checkout has one native WooCommerce submission and one provider-owned payment UI. DTB does not manufacture a card-timeout notice from absent iframe markup.
- Payment method initialization and script dependencies are verified against a supported WooPayments/WooCommerce release pair. The `wc-settings` warning is either resolved upstream or documented with a reproducible, provider-supported resolution; a vendor-file edit is not an acceptance mechanism.
- No telemetry authorization failure, checkout cache hit, missing cart, duplicate order, or live downstream effect during staging acceptance.

## Current access limitation

The connected DTB tools administer the existing WordPress installation and its files; they do not provision a SiteGround site/database or a separate WooPayments connection. Repository changes can be prepared and reviewed before that infrastructure exists, but the full guest payment acceptance gate requires the isolated site and a provider test connection.
