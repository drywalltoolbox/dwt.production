# DTB Returns

`dtb-returns` owns Drywall Toolbox return-request workflow, return persistence, public return tracking, operator transitions, and customer-facing returns REST contracts. It does not own WooCommerce orders, products, payment refunds, inventory, fulfillment, or accounting.

## System ownership

- **WooCommerce** remains authoritative for order identity, checkout customer data, order-line identity, quantities, refunded quantities, product identity, and commerce persistence.
- **DTB Returns** owns return requests, request classification, selected return-line references, customer notes, return status, resolution workflow, public tracking access, idempotency, and operator actions.
- **Frontend** renders the customer experience and submits explicit return intent. It never determines authoritative eligibility or creates alternate order/product state.
- **Veeqo / fulfillment integrations** remain authoritative for downstream inventory, allocation, shipping, and tracking where integrated.
- **Payment providers / WooCommerce refund handling** remain authoritative for actual payment refund execution. A DTB return workflow status does not itself move money.

## Verified customer workflow

The storefront uses the verified public contract:

1. `POST /wp-json/dtb/v1/returns/lookup`
2. Customer supplies **order number + checkout email**.
3. DTB Returns verifies both against WooCommerce and issues a short-lived lookup token.
4. The response projects customer-safe WooCommerce order lines and server-owned eligibility fields.
5. Customer selects explicit line-item IDs and quantities and classifies the request.
6. `POST /wp-json/dtb/v1/returns/request/verified`
7. DTB Returns re-validates the token, request type, reason, line identity, quantity, and eligibility against WooCommerce before persistence.
8. A `dtb_return` record is created in `pending_review` and receives a secure public tracking token.
9. Customer must wait for approval and return instructions before shipping.

The older public `POST /wp-json/dtb/v1/returns/request` endpoint remains registered for compatibility. The React storefront does not use it. Compatibility callers must still provide a WooCommerce order number and matching checkout email; the handler verifies both against WooCommerce and re-derives order/customer identity server-side before persistence or notification. Do not remove or repurpose the legacy endpoint until all external callers and deployment dependencies are audited.

## Public lookup security

The lookup endpoint is intentionally public because guest purchasers need to initiate returns. Public access does not imply unverified access.

Controls:

- order number and checkout email are both required;
- lookup failures use a generic response and do not reveal which identifier was incorrect;
- lookup is rate-limited per source IP;
- successful lookup returns a short-lived HMAC-backed token;
- the token is tied to the WooCommerce order ID, checkout email, expiry, and `AUTH_KEY`;
- token-to-order mapping is stored only as an expiring transient;
- the verified submit route never trusts customer identity fields from the browser;
- order/customer values are re-derived from WooCommerce at submission time;
- request types and reasons are server allowlisted;
- submitted line-item IDs and quantities are re-read and revalidated against WooCommerce;
- public customer photo upload is intentionally not implemented against the general WordPress media library because shipping labels and damage evidence may contain private customer information.

## Eligibility semantics

The public order-line projection intentionally separates two concepts:

- `eligible_for_request`: the line has remaining quantity that may be submitted for DTB problem review.
- `standard_return_eligible`: the line also satisfies the current ordinary standard-return window and is eligible for a buyer-remorse `standard_return` request.

This distinction prevents the standard return window from silently blocking a damaged, incorrect, or defective-item issue that still needs operator review. The verified submit endpoint enforces the selected request type again server-side.

The standard window currently defaults to **45 days** and can be changed through `dtb_returns_policy_window_days`.

Product or policy integrations may further narrow the customer-safe line projection through:

`dtb_returns_public_item_projection`

The filter must never broaden an order quantity beyond WooCommerce truth or substitute another order/product authority.

## Request classification

Allowed request types:

- `standard_return`
- `order_problem`
- `product_problem`

Allowed reason combinations are enforced server-side. The browser cannot pair arbitrary reasons with request types.

Examples:

- `standard_return`: changed mind, ordered by mistake, better price found, other.
- `order_problem`: arrived damaged, wrong item received, item not as described, other.
- `product_problem`: defective/not working, item not as described, other.

## Item identity and persistence

Returns continue to use the private `dtb_return` CPT. New return metadata is additive and requires no schema migration:

- `_dtb_return_request_type`
- `_dtb_return_items` — JSON array containing WooCommerce item ID, product ID, variation ID, display name, SKU, and requested quantity.
- `_dtb_return_idempotency_key`

WooCommerce line-item IDs are stored as references. DTB Returns does not copy or become authoritative for the source order lines.

Legacy return records remain readable; records created before these fields exist expose empty request-type/item values.

## Idempotency and concurrency

The verified submit route requires a client-generated idempotency key that remains stable across retries of one logical submission.

Duplicate protection has two layers:

1. lookup for an existing return with the same WooCommerce order ID + idempotency key;
2. a short-lived cross-request mutex using WordPress's unique option-name constraint around the final recheck and create operation.

The lock is released in `finally` and stale locks older than 60 seconds are recoverable. This prevents fast duplicate clicks or retry races from creating multiple return records and duplicate notification side effects.

## Customer evidence

The portal tells customers with damaged, wrong, or defective products to retain supporting photos of the item, carton, shipping label, and affected area.

It intentionally does **not** upload those files into the ordinary WordPress media library. A future evidence implementation must use a private attachment store or equivalent access-controlled object storage with:

- explicit return ownership;
- private-by-default access;
- short-lived signed retrieval;
- MIME/type/size validation;
- malware/content scanning where available;
- retention/deletion policy;
- auditability;
- no guessable public media URLs.

Do not add a public `<input type="file">` until that backend contract exists.

## Customer policy and shipping guidance

The storefront communicates operational responsibilities but does not expose a warehouse destination before approval.

Key rules surfaced to customers:

- verify the order before requesting a return;
- select the exact approved line items and quantities;
- classify standard returns separately from damaged/wrong-order and product-problem cases;
- wait for a Return ID and instructions before shipping;
- keep required packaging, accessories, documentation, and included components together;
- protect merchandise against movement during return transit;
- return only approved items and quantities;
- inspection occurs before the final approved resolution is completed.

The detailed customer policy remains at `/return-policy`; `/returns` is the transactional workflow.

## API compatibility

New verified endpoints are additive. No queue contract, Veeqo contract, QuickBooks contract, checkout contract, or order-creation workflow is changed by this module update.

Any future integration that creates refunds, receives inventory, or triggers replacement fulfillment must preserve the existing system authorities and use stable return/order correlation identities.
