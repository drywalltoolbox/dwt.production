# QuickBooks Online Integration

## Current ownership contract

WooCommerce remains the authority for products, customers, orders, payments, refunds, totals, shipping, and tax calculation. Veeqo remains the inventory and fulfillment authority. The Intuit WooCommerce Connector is the intended sole writer for WooCommerce sales and payment synchronization into QuickBooks Online. QuickBooks remains the accounting system of record.

DTB QuickBooks accounting-record writes and order-export paths are disabled in code. `dtb_qbo_exports_paused()` is fail-closed during this migration; read-only diagnostics may continue. DTB must not run alongside the Intuit connector as a second sales, refund, customer, or settlement writer. The queue endpoint rejects export requests, pending DTB jobs exit as disabled, the custom Stripe settlement import/posting job exits as disabled, and the API client rejects non-read-only requests.

Do not restore DTB exports by changing the pause to `false`. Any future ownership change requires a reviewed architecture decision, verified connector boundaries, idempotency and reconciliation evidence, and a separate reviewed deployment.

## Data flow and boundaries

The intended sales path is:

```text
WooCommerce products, customers, orders, payments, and refunds
  → Intuit WooCommerce Connector
  → QuickBooks Online accounting records
```

DTB continues to own its operational order event ledger and Action Scheduler work for DTB-owned workflows such as Veeqo and customer notifications. QuickBooks export jobs are not eligible for enqueue or retry while exports are paused. The existing DTB QuickBooks webhook endpoint is not a connector correlation adapter: it only recognizes SalesReceipt and RefundReceipt CloudEvents, and it can associate a SalesReceipt only by the legacy `_dtb_quickbooks_entity_id` order metadata. It does not infer order identity from names, amounts, or document numbers.

An Intuit event with no unique legacy DTB order match is audited and acknowledged as intentionally ignored. It is not retried because retrying cannot create the missing identity link. WooCommerce-unavailable events remain retryable. No webhook may create or modify a WooCommerce order, payment, or refund.

## Connector setup decisions required before production sync

Keep automatic sync disabled until these items have documented answers:

1. **Import boundary:** decide whether the connector imports only new orders or any historical orders, the cutoff date, and whether existing QuickBooks records are matched or skipped. Confirm that records created by prior DTB tests are excluded or explicitly classified before import.
2. **Products and item matching:** inspect the WooCommerce catalog, SKU coverage, variants, bundles, shipping, fees, and discounts. Choose whether the connector matches existing QuickBooks items by SKU or creates items, and define the duplicate and non-SKU behavior. Do not use DTB's former generic `DTB Product Sales` mapping as an assumed connector mapping.
3. **Tax:** choose connector tax behavior to reflect the actual business policy and QuickBooks company configuration. Do not disable tax merely to clear a software readiness check. Confirm treatment for shipping, discounts, exempt orders, and jurisdictions with the appropriate tax authority or qualified adviser.
4. **Payments and payouts:** WooPayments is the active WooCommerce gateway. Define how its payment method, captures, refunds, payout batches, and fees should appear in QuickBooks; verify what the Intuit connector actually supports for WooPayments before choosing the mapping. Avoid a second DTB settlement writer.
5. **Order eligibility:** select WooCommerce statuses and payment conditions that exclude failed, pending, cancelled, refunded test, and WooPayments test-mode orders. Confirm whether partially refunded or edited orders update the corresponding QuickBooks records and how unsupported changes are handled.
6. **Inventory:** leave connector stock updates off while Veeqo owns inventory. Confirm the connector cannot overwrite WooCommerce/Veeqo stock projections.
7. **Correlation and exceptions:** identify the connector's stable external order identifier and event behavior for order updates, cancellations, refunds, and duplicates. DTB's legacy webhook matching is not sufficient evidence of connector correlation.

Record each decision and a small, approved sandbox validation plan before enabling production synchronization. Do not create production test orders, payments, refunds, or journal entries as part of this code change.

## Legacy DTB connection

The former DTB OAuth connection is separate from the Intuit WooCommerce Connector. Keep it unchanged until read-only dependent features (dashboards, webhooks, or other accounting views) have been inventoried. Do not refresh or disconnect it as a side effect of adopting the connector. Read-only QuickBooks API calls remain available through DTB where their feature and credentials are still valid.

Historical DTB ledger, queue, audit, and QuickBooks records are retained. This integration change does not delete or rewrite test history. Existing exceptions require explicit disposition through the normal accounting review process; they are not silently cleared or re-exported.

## Webhook handling

The legacy DTB webhook route is `/wp-json/dtb/v1/webhooks/qbo`. It validates Intuit's signature, accepts only the documented CloudEvents array for SalesReceipt and RefundReceipt, and queues accepted events in the `dtb-orders` Action Scheduler group. The handler checks environment and realm before processing.

For SalesReceipt reconciliation, exactly one order must match `_dtb_quickbooks_entity_id`. Zero matches are recorded as `qbo_sales_receipt_webhook_unmapped_ignored`; multiple matches are recorded as `qbo_sales_receipt_webhook_ambiguous_ignored`. Both outcomes receive a completion marker and do not retry. Temporary WooCommerce unavailability still follows bounded retry handling. RefundReceipt events are observed and audited; they do not mutate WooCommerce.

Do not subscribe this legacy endpoint to unrelated QuickBooks entities. Before relying on it for connector-created transactions, implement and review a stable correlation contract based on connector-documented identifiers and verify actual event payloads. Do not match using customer names, amounts, or mutable document numbers.

## Operator status and recovery

The DTB QuickBooks sync status is not a connector readiness indicator. `exports_paused` and `execution: disabled` mean DTB order exports are intentionally unavailable. The DTB queue endpoints return a conflict/unavailable response while paused; they must not report an export batch as queued. Existing queue history is preserved for audit.

Do not use DTB's former Discover and map flow, manual retry, daily backfill, OAuth-connect backfill, or item readiness screen to activate sales exports. Those controls belong to the retired DTB sales-write path and must remain blocked by the central write pause.

## Verification boundaries

A source review can establish that DTB rejects writes and refuses export queueing. It cannot establish that the Intuit Connector is connected, correctly configured, complete, or reconciling the live QuickBooks company. Confirm those states in the connector and QuickBooks interfaces after the decisions above are approved. Compare eligible WooCommerce orders with connector sync history and QuickBooks records using the connector's documented stable identifiers; investigate duplicates, omissions, and exceptions before enabling automatic production sync.
