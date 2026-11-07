# WooCommerce 11.2.1 HTML email override compatibility review — 2026-10-13

Scope: 24 allowlisted DTB-owned classic HTML templates under `dtb-commerce/Email/templates/emails/`, compared read-only against the **installed** WooCommerce 11.2.1 `plugins/woocommerce/templates/emails/` files. One separate third-party `email-templates` override is outside DTB ownership.

## Verification results

All 24 corresponding core files exist. All 24 DTB overrides differ from installed core and lack machine-readable `@version` headers. Missing headers explain WooCommerce Status' 24 warnings; they do **not** individually prove incompatibility. Do not copy core templates over intentional DTB customization or add fabricated `@version` values.

| DTB template | Installed core template @version |
|---|---|
| admin-cancelled-order.php | 9.8.0 |
| admin-failed-order.php | 10.4.0 |
| admin-new-order.php | 10.4.0 |
| customer-cancelled-order.php | 10.4.0 |
| customer-completed-order.php | 10.4.0 |
| customer-failed-order.php | 10.4.0 |
| customer-fulfillment-created.php | 10.4.0 |
| customer-fulfillment-updated.php | 10.8.0 |
| customer-invoice.php | 11.1.0 |
| customer-new-account.php | 10.9.0 |
| customer-note.php | 10.4.0 |
| customer-on-hold-order.php | 10.4.0 |
| customer-processing-order.php | 10.4.0 |
| customer-refunded-order.php | 10.4.0 |
| customer-reset-password.php | 10.9.0 |
| email-addresses.php | 10.6.0 |
| email-customer-details.php | 9.7.0 |
| email-footer.php | 10.4.0 |
| email-fulfillment-details.php | 10.7.0 |
| email-fulfillment-items.php | 11.0.0 |
| email-header.php | 10.7.0 |
| email-order-details.php | 11.2.0 |
| email-order-items.php | 11.2.0 |
| email-styles.php | 11.0.0 |

## Contract and ownership

`Email/TemplateOverride.php` is the allowlisted resolver. WooCommerce order data/financial values remain authoritative; DTB owns HTML presentation. Its `source_version` map records original upstream versions **traced during authoring** and is not a claim that each override currently matches installed WooCommerce 11.2.1. Especially check `email-order-details.php`, `email-order-items.php`, `email-styles.php`, and `customer-invoice.php` for upstream-version drift before updating those provenance values.

This was a **static existence/header/difference baseline**, not full behavioral certification. Remaining launch gates: diff PHP arguments and template hooks against core; render every supported notification with seeded (noncustomer) WooCommerce objects; check HTML escaping, item prices/taxes/refunds, fulfillment details, links, accessible markup, transactional-email delivery; verify classic vs block email compatibility; test editor and plain email paths. Do not emit test emails to real customers. Preserve intentional disabled customer-fulfillment-deleted behavior.

## Third-party override

The `email-templates` plugin's `email-downloads.php` is outside this DTB map; its missing @version warning requires a separate vendor-compatible decision, not a DTB mutation.

## Outcome

No email template source changes authorized by these static results. 24 warnings remain correctly visible pending template-by-template behavioral certification. This report is factual and intentionally does not claim complete runtime compatibility.
