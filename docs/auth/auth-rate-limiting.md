# Authentication Rate Limiting

Last verified: 2026-10-11.

## Ownership

`dtb-platform/Security/RateLimiter.php` owns the shared DTB REST rate-limit primitive and the `dtb_rate_limit()` response adapter used by public authentication handlers. Authentication handlers must not implement independent transient counters.

## Public authentication boundary

The following public mutation/email-producing routes enter distinct request-fingerprint buckets before credential, user, reset-key, or email processing:

- `POST /wp-json/dtb/v1/auth/login`
- `POST /wp-json/dtb/v1/auth/register`
- `POST /wp-json/dtb/v1/auth/forgot-password`
- `POST /wp-json/dtb/v1/auth/reset-password`

The limiter is intentionally defensive rather than an authentication authority. WordPress remains authoritative for credentials and password-reset keys. The forgot-password handler must keep its enumeration-resistant generic response when it is not rate limited.

## Default buckets

| Bucket | Limit | Window |
|---|---:|---:|
| `auth_login` | 10 | 5 minutes |
| `auth_register` | 5 | 10 minutes |
| `auth_forgot_password` | 5 | 15 minutes |
| `auth_reset_password` | 10 | 15 minutes |

Defaults may be adjusted through the reviewed `dtb_rate_limit_settings` filter. A rejection returns HTTP 429, `Retry-After`, and private/no-store cache policy.

## Security invariants

- Rate-limit identity is derived by `DTB_RequestFingerprint`; callers do not choose the fingerprint.
- No password, reset key, JWT, email address, or other secret is persisted in the limiter key.
- Denials use the shared security log with bucket and route only.
- No provider/payment/cart behavior is coupled to these authentication buckets.
