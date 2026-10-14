# WooPayments checkout environment architecture

Status: implementation decision for the WooPayments migration. The runtime migration and acceptance tests are outstanding. This document describes the target, not a claim that checkout is ready.

## Observed state (2026-10-13)

- `/staging/` is a React frontend directory that shares the production WordPress installation, database, WooCommerce session, and WooPayments configuration. Its ordinary checkout redirects to the root `/checkout/`.
- WooPayments 11.2.0 is active. The retired Payment Plugins for Stripe extension is inactive. The WooPayments card gateway is enabled and its settings have `test_mode=yes`; enabled methods include card, Affirm, Afterpay, Klarna, and Amazon Pay.
- `DTB_PaymentPluginsStripeNativeCheckout::enforce_single_stripe_authority()` removes WooPayments gateways for ordinary visitors whenever WooPayments is in test mode. It permits only the card gateway for a `manage_woocommerce` operator. This is a deliberate protection on the shared live backend. Consequently, the current `/staging/` cannot run a representative guest test checkout.
- The same legacy class still owns the public `/dtb/v1/checkout/capabilities` response and reports Payment Plugins for Stripe as the provider. The React product readiness parser requires that retired provider and treats WooPayments as a competing gateway. These signals cannot describe the active WooPayments checkout.
- The DTB runtime script shows a card timeout after 15 seconds when it does not find a Stripe iframe under a payment block. No eligible card gateway also meets that condition, so the notice does not establish a network or provider timeout. The telemetry endpoint has a separate logged-in REST nonce defect tracked by PR #86.
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

1. **Provision isolation.** Back up production. Create the staging WordPress/database/hostname. Verify database identity without publishing credentials, `home`/`siteurl`, HTTPS, cookie scope, REST origin, `/checkout/` routing, and checkout no-cache behavior. Deploy the staging React build to that origin. Keep the old `/staging/` route clearly identified as a frontend preview until it is retired or redirected to the new host.
2. **Contain external effects.** Remove or anonymize copied customer data and payment tokens as required for testing. Disable or sandbox production Veeqo, QuickBooks, marketplace, email, analytics, and fulfillment side effects in the staging environment; use environment-scoped configuration rather than runtime URL guesses. Never push staging order/account/provider tables or WooPayments options back to production. SiteGround's full deploy can replace live files and database, so use code-only deployment for this migration: https://www.siteground.com/tutorials/staging/deploy/ .
3. **Connect payments independently.** Establish a fresh WooPayments test account on staging and enable card there. Verify WooPayments account capabilities, test mode, supported Checkout Block, HTTPS, domain association, webhooks, and current compatible WooCommerce/WooPayments versions. Production remains a separate WooPayments connection and ultimately uses live mode for real shoppers.
4. **Refactor the DTB provider policy.** Make WooPayments the sole new-checkout provider in the active environment. An explicit deployment environment, verified at bootstrap, governs whether test mode can appear to guests. Production test mode remains closed to guests; isolated staging test mode permits guest card and supported express methods. Preserve historical Stripe order IDs, callbacks, refunds, and paid-state evidence without offering its inactive extension for new payments. Avoid patching WooPayments plugin files.
5. **Replace legacy readiness.** The public capabilities endpoint must report the active provider and non-secret environment/gateway status from WooPayments, including whether guest card checkout is actually available. Update React normalization and any product method labels to match. Do not use this advisory endpoint as an alternate payment authority; the Checkout Block still decides actual methods from cart, address, and provider eligibility.
6. **Reduce DTB checkout runtime to observation.** Remove the custom iframe-presence fallback and legacy provider names; WooCommerce/provider UI must explain unavailable payment methods. Record bounded diagnostics based on supported public state and resource failures. Fix the telemetry cookie-auth nonce without weakening DTB's nonce, origin, or rate limits.
7. **Validate and cut over.** On the isolated staging host, verify a guest and logged-in cart, card render, test payment, decline, 3DS, eligible express methods, webhook confirmation, order status, thank-you/return route, refund, retries, and duplicate-event containment. Check mobile Safari and desktop Chromium with their normal privacy settings. Confirm no staging order can reach live fulfillment/accounting. Only then deploy the reviewed code and live WooPayments configuration to production and retest a controlled live transaction under the launch plan.

## Release gates

- Guest card method appears and a WooPayments test card completes on the isolated staging host without an administrator cookie. A customer never needs WordPress admin REST authorization.
- Staging test orders and callbacks remain within staging. Production gateway mode, account connection, orders, and external integrations are unaffected by staging tests.
- The public capabilities response names WooPayments, and React does not mark it as a competing provider.
- The checkout has one native WooCommerce submission and one provider-owned payment UI. DTB does not manufacture a card-timeout notice from absent iframe markup.
- Payment method initialization and script dependencies are verified against a supported WooPayments/WooCommerce release pair. The `wc-settings` warning is either resolved upstream or documented with a reproducible, provider-supported resolution; a vendor-file edit is not an acceptance mechanism.
- No authenticated telemetry 403, guest telemetry failure, checkout cache hit, missing cart, duplicate order, or live downstream effect during staging acceptance.

## Current access limitation

The connected DTB tools administer the existing WordPress installation and its files; they do not provision a SiteGround site/database or a separate WooPayments connection. Repository changes can be prepared and reviewed before that infrastructure exists, but the full guest payment acceptance gate requires the isolated site and a provider test connection.
