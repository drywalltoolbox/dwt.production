# QuickBooks Accounting Control Center

## Authority and scope

The control center is an administrator workspace over the DTB accounting projection. WooCommerce remains the source of truth for orders, tax, payments, and refunds. WooPayments is the active checkout authority. QuickBooks receives accounting documents only. Settlement reporting is read-only and cannot capture, refund, or otherwise affect a payment.

All external accounting writes and reporting imports run through Action Scheduler group `dtb-orders`. Browser actions can preview, filter, export, approve rules, or enqueue work; they do not perform a QuickBooks or Stripe write.

## Workspace

- **Overview** — 30-day sales, refunds, tax, Stripe fees, reconciliation, exceptions, and recent documents.
- **Transactions** — indexed, full-range accounting ledger with Woo expected total, QBO total, variance, state, and trace.
- **Exceptions** — invariant, customer-match, mapping, API, and remote comparison failures.
- **Tax Center** — Woo tax jurisdiction/rate totals, collected tax, concrete refund reversals, net liability, QBO tax-preference detection, and approved tax-code policy.
- **Settlement** — paid Stripe payouts, balance-transaction fees, clearing/deposit evidence, currency, arrival date, and request trace.
- **Reports & Close** — queued read-only Profit and Loss, Balance Sheet, and Trial Balance snapshots; accountant CSV export; and a close gate that refuses unresolved or pending documents.
- **Rules** — accountant-approved QBO item, tax, deposit, clearing, fee, and bank mappings. Rules are environment scoped, policy-versioned, and approval stamped.
- **Automation** — `dtb-orders` health, daily report refresh, daily reconciliation setting, and settlement-import status.
- **Audit** — source identity, document identity, totals, state, policy version, payload hash, trace, external state, and timestamps.

The toolbar provides date/search filters and saved operational presets. Dry-run produces the exact pre-customer QBO payload and hash without a remote write. Reconcile, report refresh, settlement import, and backfill controls enqueue bounded work.

## Correctness gate

`DTB_QBO_AccountingService` snapshots authoritative WooCommerce totals excluding tax at line level, then applies Woo's exact transaction tax total once. It includes shipping and fees, preserves authoritative line `Amount`, and emits `Qty`/`UnitPrice` only when their multiplication rounds back to that amount. A document is rejected if:

- required QBO item or tax references are unverified;
- line plus tax total differs from the Woo document total;
- a customer lookup fails or matches more than one QBO customer;
- the date is in a closed period;
- the retrieved QBO document differs in document number, currency, total, or total tax.

Only an exact retrieved-QBO comparison marks the source order/refund synced. Run deterministic coverage with:

```text
php scripts/tests/quickbooks-accounting-fixtures.php
```

Fixtures cover coupons, fees, shipping tax, multiple tax rates, partial refunds, quantity rounding, guest arithmetic, multiple currencies, and an intentional total-invariant failure.

## Ledger and migration

The versioned `wp_dtb_accounting_documents` table is installed idempotently with `dbDelta`. Its natural key is active environment + hashed QBO realm + source type + source key. Indexes support order, state/time, transaction date, QBO entity, and external-state queries.

The migration changes no WooCommerce, Stripe-provider, or QBO schema. Rollback restores the previous reviewed MU-plugin release. Preserve the table during rollback for audit and recovery; remove it only after an independent backup and a separately approved decommission.

## Settlement configuration

Define a server-only Stripe restricted key as `DTB_STRIPE_ACCOUNTING_RESTRICTED_KEY` with only payout and balance-transaction read permissions. Never use or expose a browser key or checkout-provider secret. With no key, settlement automation reports `disabled` and makes no request.

Payout import records the bank deposit amount and associated Stripe fees. Accountant-approved clearing, fee-expense, deposit, and bank mappings are retained as policy. This does not manufacture checkout state or bypass provider-owned WooCommerce refunds.

## Deployment and acceptance

Promote the dependency-consistent MU-plugin, documentation, and fixture set through the official SiteGround Git workflow. Production promotion is operator initiated and is never triggered automatically by push or merge.

Before promotion, create independent file and database backups and verify rollback readiness. After promotion, clear required SiteGround caches and PHP OPcache. Confirm:

1. the ledger table installed with expected indexes;
2. all nine tabs load for an administrator;
3. a sandbox dry run passes for coupon, fee, shipping-tax, and multi-rate orders;
4. one paid order and one partial refund produce exact QBO comparisons;
5. an intentional mismatch is visible in Exceptions and is not marked synced;
6. report and settlement actions appear in `dtb-orders`;
7. the close gate refuses an open exception;
8. CSV export contains hashes and traces but no customer PII or secrets.

Rollback by promoting the prior immutable release through SiteGround Git, clearing caches, and verifying checkout plus `dtb-orders`. Do not delete Woo orders/refunds, QBO transactions, Action Scheduler history, OAuth state, accounting ledger rows, or Stripe records.
