# DTB BrikPanel admin presentation

BrikPanel 3.3.34 owns the current WooCommerce admin theme. WooCommerce owns order, product, checkout, and financial records. This module owns **DTB-specific presentation extensions only**, loaded through the compatibility entrypoint `../dtb-readable-order-items.php`.

## Scope and boundaries
- `Orders/ReadableOrderItems.php`: WooCommerce order-item provenance rendering via public hooks; data stays unchanged.
- `Orders/QuickViewExperience.php` and `Orders/QuickViewRenderer.php`: optional, read-only composition of WooCommerce's native order-preview markup. The Backbone template, AJAX data source, footer actions, modal lifecycle, and order authority remain WooCommerce-owned.
- `Orders/assets/order-quick-view.css`: scoped styling for the native preview modal. It is enqueued on the order list only when `DTB_ORDER_QUICK_VIEW_V2` is enabled.
- Future `Products/` extensions must use supported WordPress/BrikPanel hooks or bounded UI assets, never vendor file replacements.
- Do not duplicate BrikPanel page routing, save handlers, WooCommerce product mutation, or order/payment APIs.
- Scope selectors/hooks to the specific admin screen and preserve keyboard access/responsive layouts.
- Preserve existing third-party BrikPanel files during plugin updates.

## Order Quick View V2

V2 is enabled by default through `DTB_ORDER_QUICK_VIEW_V2` and can be disabled
through the central feature flag. When
enabled, the existing `woocommerce_admin_order_preview_get_order_details`
filter wraps WooCommerce's generated item HTML with order context and a
WooCommerce-backed summary/customer sidebar. A scoped observer moves the
already-rendered native address, items, summary, and customer nodes into the
responsive layout; it does not replace the Backbone template, modal lifecycle,
AJAX endpoint, or order data. The native Edit action remains the only
order-management action in the preview.

Turn V2 off by defining `DTB_ORDER_QUICK_VIEW_V2` as `false` (or returning
`false` from `dtb_feature_enabled_DTB_ORDER_QUICK_VIEW_V2`). This restores the
existing preview presentation immediately. Risk details are not shown unless
a verified read-only provider source is added.

## Full order-edit workspace V2

`Orders/OrderEditExperience.php` and its co-located CSS/JS are enabled by
default through `DTB_ORDER_EDIT_WORKSPACE_V2` and can be disabled through the
central feature flag. The adapter moves WooCommerce's existing order-data
postbox above BrikPanel's existing tabs and the native notes postbox into the
right rail after order actions. Item, customer, More-tab and summary postboxes
retain their native nodes, callbacks, and tab behavior. It does not render
replacement fields, intercept saves, or create order mutations. Totals remain
within WooCommerce's native items postbox.

## Product editor migration gate
Live active BrikPanel plugin at `wp-content/plugins/brikpanel-admin-panel-dashboard-for-woocommerce` currently contains substantial product editor source: `front-end/products/brikpanel-product-editor.php`, `.js`, `.css`, as well as product list screens. Before extracting DTB edits:
1. Identify the exact vendor 3.3.34 baseline and compare hashes/diffs against live files.
2. Attribute each change to upstream/vendor or DTB; trace PHP enqueue/hooks, JS event handlers and CSS selectors.
3. Extract **only** DTB-owned behavior into this module with proper scoping and no duplicate handlers.
4. Test product editing, variations, imagery, SKU, inventory, search/filter, responsiveness and accessibility.
5. Restore modified vendor files only when equivalence is proven, backups exist, and rollback is executable.

**Not migrated yet:** the large live BrikPanel product editor files; their custom/vendor boundaries are not yet verified. This is intentional to avoid regressions.

## Deployment and rollback
Deploy the module files before updating the compatibility entrypoint. Verify live readback against GitHub. Reverting the entrypoint returns the previous layout without touching WooCommerce data or BrikPanel vendor files.

## Product Media Studio extracted (prelaunch enabled; rollback flag)
`Products/ProductMediaStudio.php` and its co-located JS/CSS were extracted from
`elliotttmiller/launch-dtb/docs/admin/brikpanel/products`. They are
enabled for prelaunch UI verification after static compatibility review. Disable immediately with `DTB_BRIKPANEL_PRODUCT_MEDIA_STUDIO=false` if the editor misbehaves.
The adapter hydrates existing variation galleries and enqueues a progressive UI,
but does not add a save endpoint or replace the WooCommerce storefront gallery.

Current live BrikPanel product editor and its shipped vendor variation-gallery
file do not match the reference implementation. The legacy media bridge relies
on BrikPanel's private `state.variations` and a temporary `wp.media` facade,
so this specific UI behavior requires editor interaction testing before opt-in.

Required before activation: verify product-editor screen ID and enqueue order,
variation gallery edit/reorder/cancel/save/reload, image IDs and media permissions,
variable-product defaults and stock/prices untouched, keyboard/focus behavior,
mobile viewport, and vendor JavaScript compatibility. Keep QuickBooks paused and
test-order side-effect boundaries unchanged.
