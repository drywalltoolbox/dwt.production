# DTB Visual Designer Email Studio

Last reconciled with tracked source: 2026-08-04.

The Email Studio is an operator-only workspace inside **wp-admin → Visual Designer → Email Studio**. It replaces the removed standalone `drywalltoolbox/wp/email-previewer/` utility.

## Ownership

- `dtb-commerce/Email/Preview/EmailPreviewService.php` owns read-only rendering through registered WooCommerce `WC_Email` classes, DTB template overrides, WooCommerce hooks, and WooCommerce CSS inlining.
- `dtb-visual-designer` owns the operator interface and permission-protected REST adapter.
- WooCommerce owns orders, email classes, lifecycle data, totals, customer data, and settings.
- The studio is not an email sender, template authority, or alternate lifecycle implementation.

## Request flow

```text
Authorized Visual Designer operator
  -> GET /dtb/v1/design/email-studio
  -> bounded recent-order manifest + registered supported email IDs
  -> POST /dtb/v1/design/email-studio/render
  -> canonical EmailPreviewService
  -> cloned WC_Email + read-only WC_Order
  -> DTB deterministic template resolver
  -> WooCommerce hooks and CSS inliner
  -> sandboxed iframe srcdoc
```

The renderer never calls `trigger()` or `send()`, never changes an order, and
never enqueues integration work. `WC_Email::get_content()` and
`WC_Email::style_inline()` are the canonical HTML and CSS-inlining path used
by the preview.

## Supported scope

The initial production scope is limited to email classes that can be prepared truthfully from a `WC_Order` alone:

- processing, completed, on-hold, failed, cancelled;
- invoice/order details;
- admin new, cancelled, and failed order.

Refund, native Fulfillment, account, password-reset, and other lifecycle-specific emails are omitted until the required authoritative object can be supplied. The studio must not fabricate these objects.

## Security and data handling

- All endpoints use the Visual Designer operator permission callback.
- Requests require authenticated WordPress REST nonces.
- Email IDs are allowlisted and order IDs are validated.
- Order lookup is read-only and bounded to a 30-order selector.
- Rendered HTML is capped at 750,000 bytes.
- The iframe is sandboxed and receives only the rendered HTML document.
- The preview frame permits its own origin so the self-hosted Geist font loads;
  scripts, forms, and top-level navigation remain blocked by the sandbox.
- No credentials, payment data, raw exceptions, or executable editor input are exposed.

## Operator workflow

1. Open Visual Designer and select Email Studio.
2. Select a registered email lifecycle.
3. Select an existing WooCommerce order as preview data.
4. Switch between desktop and mobile canvases.
5. Use Refresh preview after changing canonical DTB email templates or styles.
6. Perform final inbox-client testing separately; the browser preview is the real server-rendered HTML, but Gmail, Outlook, and Apple Mail use different rendering engines.

## Persistence and deployment impact

The studio introduces no database table or migration. It reads existing WooCommerce orders and registered email configuration. The standalone Node/BrowserSync preview environment and its production-transfer ambiguity are removed.
