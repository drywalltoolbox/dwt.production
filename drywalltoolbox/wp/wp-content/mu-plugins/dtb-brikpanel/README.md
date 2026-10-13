# DTB BrikPanel admin presentation

BrikPanel 3.3.34 owns the current WooCommerce admin theme. WooCommerce owns order, product, checkout, and financial records. This module owns **DTB-specific presentation extensions only**, loaded through the compatibility entrypoint `../dtb-readable-order-items.php`.

## Scope and boundaries
- `Orders/ReadableOrderItems.php`: WooCommerce order-item provenance rendering via public hooks; data stays unchanged.
- Future `Products/` extensions must use supported WordPress/BrikPanel hooks or bounded UI assets, never vendor file replacements.
- Do not duplicate BrikPanel page routing, save handlers, WooCommerce product mutation, or order/payment APIs.
- Scope selectors/hooks to the specific admin screen and preserve keyboard access/responsive layouts.
- Preserve existing third-party BrikPanel files during plugin updates.

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
