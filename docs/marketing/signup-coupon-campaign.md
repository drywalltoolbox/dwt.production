# New account first-order coupon campaign

## Purpose and owners

The `welcome-10-first-order` campaign grants a one-time 10% coupon to a newly
created WooCommerce customer account while the campaign is active. Each code
is personalized to one WordPress/WooCommerce customer ID and the email on that
account. The coupon is applied and calculated by WooCommerce.

- `dtb-marketing/SignupOffer/SignupCouponCampaign.php` owns campaign state,
  signup reward issuance, the operator settings screen, and campaign display
  configuration.
- `dtb-platform/Auth/AuthRoutes.php` emits the server-side account-created
  event after it creates the customer record.
- `dtb-commerce/Validation/SignupCouponPolicy.php` enforces signed-in account
  ownership and first-order eligibility during native WooCommerce coupon
  validation.
- The existing WooCommerce customer-new-account email remains the only owner of
  the registration notification and includes the personal code when issuance
  succeeds.
- `frontend/src/api/signupOffer.js` reads public campaign state without browser
  caching. The React popup never grants eligibility or calculates discounts.

## Issuance and redemption contract

1. The public campaign endpoint reports active state only. It returns no
   customer identity or coupon code and sends `private, no-store` headers.
2. The signup CTA loads only on the homepage for an anonymous browser that has
   not dismissed it, and only renders after the endpoint confirms the campaign
   is active.
3. After server-side customer creation, the campaign issues a WooCommerce
   percentage coupon through `WC_Coupon` CRUD. The coupon has a keyed,
   deterministic, user-specific code so retries do not create duplicates.
4. The coupon is restricted to the account email, account ID, campaign ID,
   first qualifying account order, one use total, and one use per customer.
   WooCommerce's configured sale-item, individual-use, minimum-spend, and expiry
   settings are copied onto the coupon at issuance time.
5. Coupon creation runs before the existing new-account notification. If
   issuance fails, the welcome email is deferred and Action Scheduler retries
   the bounded issue-and-email operation up to three times. Terminal failures
   are logged with campaign and user IDs but no email address or coupon code.
6. Existing coupons remain redeemable through their individual expiry even if
   the campaign is paused or ended. No new accounts receive coupons while the
   campaign is inactive.

The campaign endpoint is `GET /wp-json/dtb/v1/marketing/signup-offer`. It is
public, read-only, and uncached. Registration, account reward data, and coupon
codes must never be placed in cacheable page markup or analytics payloads.

## Operator configuration

WordPress admins with `manage_woocommerce` can configure the campaign at
**Settings → Signup Coupon Campaign**. It starts inactive. Turning it on starts
signup eligibility immediately. The screen supports a future campaign end,
coupon lifetime after signup, minimum spend, sale-item exclusion, and whether
the coupon can combine with other coupons.

Defaults are: 30 days per coupon, no minimum spend, sale items excluded, and no
coupon stacking. The campaign is fixed at 10% and applies to all products
otherwise eligible under the current terms.

## Verification and launch limits

The SiteGround staging deployment currently hosts only the React storefront.
Its `.env.staging` and API client route WordPress, WooCommerce, registration,
coupon issuance, and email delivery to the root production domain. Staging is
therefore not an isolated transaction environment: creating an account there
can create a real production customer and personal coupon and send the production
new-account email.

Until a separate staging WordPress/WooCommerce database is available, limit
staging checks to the static build/routing, the public read-only campaign
endpoint, and UI behavior that does not submit registration or checkout. Use
the synthetic Email Studio preview for copy/layout checks. Do not test account
creation, coupon redemption, or retry delivery from the current staging site.

Full campaign verification requires an isolated WordPress/WooCommerce staging
environment with a separate database, non-production mail sink, and payment
sandbox. On that environment verify account-created coupon ownership, rejected
different-account/email attempts, first-order and later-order behavior,
sale-item and stacking settings, expiry, repeated issuance, Action Scheduler
retry recovery, and inactive/ended campaign states. Do not send test emails to
live customers.

The current registration flow does not verify email ownership. Coupon
restrictions bind redemption to the account and billing email, but this
campaign does not prove that the person creating the account controls the
entered email address. Email verification is a separate authentication change
and must be resolved before positioning the offer as identity-verified.
