# Order Fulfillment Workbench

## Ownership

WooCommerce remains the system of record for orders, order items, prices, payment state, addresses, status, and commerce persistence.

The DTB order platform owns the operator projection used by the wp-admin Orders workbench. It may enrich WooCommerce order items with fulfillment-facing read data, but it must not create a second order, payment, refund, inventory, or fulfillment authority.

Veeqo remains authoritative for inventory allocation, fulfillment, shipping, and tracking. The workbench displays Veeqo/integration state and may invoke existing idempotent queue actions; it does not replace Veeqo state.

## Order item fulfillment contract

The canonical admin order detail endpoint is:

`GET /wp-json/dtb/v1/admin/orders/{id}/detail`

Each `record.line_items[]` projection includes the WooCommerce order-item identity plus fulfillment fields:

- `sku`: ordered SKU snapshot when available, otherwise the current WooCommerce product SKU.
- `product_kind`: DTB product kind resolved from order snapshot or canonical product metadata.
- `image`, `image_srcset`, `image_alt`: presentation media.
- `quantity`, `unit_price`, `subtotal`, `discount`, `total`: order-item commercial context.
- `variation_meta[]`: customer-selected non-protected variation/options metadata.
- `components[]`: toolset component rows with `name`, `sku`, per-set `quantity`, and order-adjusted `total_quantity`.
- `components_source`: `order_snapshot` for historically preserved kit contents or `catalog_current` for legacy-order fallback.
- `component_count` and `component_units`: operator summaries for picking.

## Toolset snapshot persistence

For new checkout-created orders, DTB stores protected WooCommerce order-item metadata:

- `_dtb_ordered_sku`
- `_dtb_ordered_product_kind`
- `_dtb_toolset_components_json` for toolsets

The component snapshot is derived from the canonical catalog `_includes_{n}_name` / `_includes_{n}_sku` metadata at order creation. It is historical transaction context attached to the WooCommerce line item, not a mutable catalog copy.

Classic checkout snapshots during `woocommerce_checkout_create_order_line_item`. Store API / Blocks checkout snapshots during `woocommerce_store_api_checkout_order_processed`.

Legacy orders without the protected snapshot may project the current catalog component definition so operators can still pick existing orders. The UI explicitly labels this as a current-catalog fallback because it is not a historical guarantee.

## Operator UI

The wp-admin Orders workbench renders an operator-first modal with:

- a compact order status strip for status, total, payment, fulfillment, and created time;
- SKU-visible order rows with product thumbnails, options, quantity, unit price, and total;
- inline expandable toolset component pick lists;
- a dedicated Fulfillment tab with shipping destination, Veeqo/tracking state, and SKU-first pick list;
- existing Customer, Linked, Timeline, and Actions panels;
- the native WooCommerce order editor retained only as a fallback link.

The workbench remains read-oriented except for existing authorized order actions and workflow transitions. It does not directly mutate inventory, shipping allocations, or payment state.

## Security and integrity

The existing admin REST authorization remains authoritative. Fulfillment projections do not expose protected order-item metadata directly; only allowlisted derived fields are returned.

Order actions continue to use capability checks and queue-backed/idempotent mutation paths. No new public endpoint, order-creation path, payment path, fulfillment writer, or external integration authority is introduced.


## Purchase provenance and legacy catalog recovery

Order-line provenance is stored on the WooCommerce line item because one order can contain products added from different storefront surfaces. It is not duplicated as an order-level source of truth.

For schematic-origin purchases, the React schematic viewer sends only stable identifiers through Store API extension metadata. The backend re-resolves the schematic, page, part, and WooCommerce product before persisting protected order-item metadata. Browser-supplied schematic titles, brands, categories, and product identity are never trusted as authoritative.

New schematic-origin order lines preserve:

- source surface;
- canonical schematic ID/title/brand/category;
- page ID/number/label;
- canonical part reference/SKU/title;
- validated shared schematic variant when applicable.

New orders also snapshot stable fulfillment-facing catalog identity including ordered SKU, MPN, brand, product kind, tool family, and product/variation IDs. These snapshots preserve operator context if catalog records are later changed or removed.

Legacy line items whose WooCommerce product ID is missing may be read-enriched only when the current catalog contains exactly one product with the exact historical order-item name. The order itself is never rewritten. The admin UI labels this as a **Legacy catalog match** so operators can distinguish recovered present-day catalog identity from historical order provenance.

Current catalog schematic groups shown for a legacy recovered item are compatibility context only. They must never be represented as proof that the customer purchased from a particular schematic.
# Operator-reviewed status changes

DTB Orders presents eligible lifecycle choices from the server-owned order
workflow registry. The shared `on-hold` status is labeled **On Hold**; it is
not a refund or payment decision. A status transition opens a review dialog
before mutation. The operator can elect to send the matching WooCommerce
customer status email and add a sanitized message of up to 2,000 characters.
The store's configured email enablement remains authoritative. A private
operator order note is not substituted for customer-visible email content.

The message is stored on the WooCommerce order before status hooks run and is
rendered only in the matching customer email. WooCommerce continues to own the
order transition and email lifecycle. Refunds must be created in WooCommerce's
native refund controls; DTB does not create a refund by setting `refunded`
status. Email layout and global copy continue to be authored in DTB Email
Studio, while the workbench provides per-order communication review.
