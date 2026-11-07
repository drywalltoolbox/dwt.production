# Veeqo Inventory Workspace

## Ownership

Veeqo remains the inventory, allocation, fulfillment, and warehouse authority. WooCommerce stores the checkout-facing projection only.

The wp-admin inventory workspace is an operator read model over WooCommerce and DTB mapping metadata. It does not provide generic writes to:

- SKU or other immutable identifiers
- Veeqo sellable IDs
- warehouse IDs
- on-hand inventory
- committed inventory
- available inventory
- incoming inventory

Those values remain read-only in this workspace. Existing reconciliation, order projection, webhook, queue, and Veeqo client wiring are unchanged.

## Operator capabilities

The Inventory tab provides:

- server-side sorting and pagination
- product/SKU search
- stock, mapping, and product-type filters
- current-page row selection
- a product inspector drawer
- CSV export for selected visible rows
- preview-before-apply bulk editing of WooCommerce-owned merchandising fields

Supported bulk fields are:

- product status
- catalog visibility
- backorder policy
- low-stock threshold

Bulk requests are limited to 100 product IDs. A preview creates an operator-bound, short-lived manifest. Apply requests must provide the same IDs, field changes, and preview hash. Replays after successful application fail because the manifest is deleted.

## REST routes

All routes require `manage_woocommerce`:

- `GET /dtb/v1/veeqo/admin/control-center/inventory`
- `GET /dtb/v1/veeqo/admin/control-center/inventory/{product_id}`
- `POST /dtb/v1/veeqo/admin/control-center/inventory/bulk-preview`
- `POST /dtb/v1/veeqo/admin/control-center/inventory/bulk-apply`

The existing reconciliation and integration routes retain their prior ownership and behavior.

## Data sources

The table is based on WooCommerce product lookup data plus DTB projection metadata:

- `_veeqo_sellable_id`
- `_veeqo_mapped_sku`
- `_veeqo_stock_raw_available`
- `_veeqo_stock_on_hand`
- `_veeqo_stock_committed`
- `_veeqo_stock_incoming`
- `_veeqo_stock_synced_at`

When detailed projection metadata is absent, the workspace falls back to WooCommerce stock quantity for on-hand and available display. This fallback is presentation-only and does not change inventory authority.

## Deployment

Deploy the complete dependency-consistent file set together. Do not deploy only the controller or only the assets.

After transfer:

1. Clear SiteGround dynamic cache and CDN cache.
2. Purge WordPress/SiteGround cache.
3. Hard-refresh the Veeqo Control Center.
4. Confirm all Veeqo control-center routes return JSON.
5. Validate sorting, filtering, inspection, preview, apply, permissions, and replay rejection.
6. Run Veeqo inventory reconciliation as a dry run before any applied reconciliation.

## Rollback

Restore the prior controller and admin-page files, then remove the inventory workspace service and supplemental assets. Clear caches after rollback. Bulk edit previews are transient and expire automatically; completed WooCommerce field changes require an explicit product-level reversal and are not automatically reverted by file rollback.
