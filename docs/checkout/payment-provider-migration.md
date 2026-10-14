# WooPayments checkout migration

Status: WooPayments is the active DTB checkout provider in the codebase. Production and staging configuration, account connection, webhooks, and full acceptance are separate deployment gates.

## Ownership contract

- WooCommerce owns the cart/session, checkout submission, totals, customer/order records, payment status, and refunds.
- WooPayments owns payment method availability, payment UI, tokenization, authentication, capture, wallet/provider callbacks, and webhooks.
- DTB owns only same-origin checkout handoff, an environment safety policy, non-secret readiness reporting, and verified order lifecycle observation.
- React never creates provider payment objects or presents static provider-specific wallet controls.

## Environment separation

Do not run guest test checkout on a production WordPress/database/runtime just because the frontend URL contains `/staging/`. A representative test lane needs its own hostname, WordPress configuration, database, WooCommerce session/cookies, WooPayments test connection, and sandboxed downstream effects.

The backend makes test-mode methods available only when the deployment asserts all of these conditions:

```text
WP_ENVIRONMENT_TYPE = staging
DTB_CHECKOUT_ENVIRONMENT = staging
DTB_WOOPAYMENTS_TEST_CHECKOUT_ENABLED = true (boolean)
DTB_PRODUCTION_ORIGIN = https://<production-host>
staging home_url host != production host
DTB_PRODUCTION_DB_NAME = <production-database-name>
staging DB_NAME != DTB_PRODUCTION_DB_NAME
```

Set these staging-only values only after verifying that the staging host/database and provider connection are isolated. The runtime checks that the staging host and database name differ from their production counterparts. Customers do not need a WordPress admin session or REST authorization to check out.

## Cutover gates

1. Back up production and provision isolated staging; verify host, database, cookies, HTTPS, REST/Store API, native Checkout Block route, and private/no-store caching.
2. Remove or anonymize copied customer and token data as required. Disable or sandbox fulfillment, accounting, notifications, marketplaces, analytics, and other external effects.
3. Establish a separate WooPayments test connection. Verify account health, enabled methods, eligible test devices, callbacks, webhooks, and compatible WooCommerce/WooPayments versions.
4. Deploy the reviewed source to staging. Verify the public capability response names WooPayments, guest test methods are available, and no admin cookie is present.
5. Run card success/decline/retry/3DS; supported express methods; address, shipping, tax, coupon, and total updates; order return; webhooks; duplicate events; refund; and downstream containment on mobile Safari and desktop Chromium.
6. Validate existing saved methods, customer records, subscriptions/renewals, and refund behavior before deciding whether any provider-managed data migration is needed. DTB does not rewrite historical orders.
7. Only after those gates, prepare production WooPayments live-mode configuration and run the separately approved production acceptance procedure.

## Compatibility and rollback

New checkout orders use `_dtb_checkout_gateway=woo_native_woopayments` and `_dtb_checkout_contract_version=woopayments-v1`. Historical order identifiers and provider evidence remain readable for reconciliation/refund/accounting; they are not offered for new checkout. See [captured-payment reconciliation](checkout-payment-reconciliation.md).

Do not patch WooPayments vendor assets to address script warnings. Resolve compatibility at a supported plugin release or retain a reproducible upstream issue as a release gate. Rollback restores a matching, previously reviewed DTB/theme/provider configuration set. Do not delete or rewrite orders created during a failed cutover; reconcile them explicitly.

## Current infrastructure limitation

The available repository and DTB WordPress integration do not provision a separate SiteGround installation/database or a fresh WooPayments connection. Therefore source changes can be reviewed, but the isolated guest-payment acceptance gate cannot be marked passed until that environment exists.
