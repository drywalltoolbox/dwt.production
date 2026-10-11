# Frontend Toolset Builder

Last verified: 2026-10-11 against initial-launch scope.

## Status

The React Toolset Builder implementation is retained for **post-launch planned development**, but it is not part of the initial-launch customer route surface. `frontend/src/App.jsx` does not register `/toolset-builder`, and `routeModules.js` does not preload or resolve that path. A direct request therefore falls through the normal storefront 404 handling rather than presenting an inoperative purchase flow.

The final **Add set to cart** action remains disabled in the retained implementation until the backend phase supplies authoritative whole-set validation and cart finalization. Re-enabling the route requires an explicit product-release decision plus the missing backend finalization contract; it must not be exposed merely by restoring a navigation link.

This document describes the retained frontend contract only. It does not define product eligibility, compatibility, pricing, stock, discounts, shipping, cart validity, or checkout behavior.

## Ownership

`frontend/` owns:

- builder routing and lazy loading when the post-launch feature is explicitly enabled;
- workflow-selection presentation;
- guided step navigation;
- local in-progress selection state;
- brand/search presentation filters;
- canonical catalog API consumption;
- exact variation selection;
- loading, empty, error, retry, and review states;
- responsive and accessible presentation.

WooCommerce and DTB backend modules remain authoritative for commerce and domain rules.

The frontend must not:

- create or persist orders;
- calculate authoritative prices or discounts;
- claim compatibility from browser state;
- maintain a second cart;
- add a multi-line set by looping ordinary cart mutations;
- call the legacy brand-template `/dtb/v1/toolsets` API as the universal-builder contract.

## Active frontend files

- `frontend/src/pages/ToolsetBuilder.jsx` — route page and workflow landing.
- `frontend/src/features/toolset-builder/model.js` — temporary frontend presentation model for workflow order/copy/cardinality hints.
- `frontend/src/features/toolset-builder/ToolsetBuilderWorkspace.jsx` — guided workflow, live summary, review state.
- `frontend/src/features/toolset-builder/ToolsetBuilderProductCard.jsx` — product and on-demand variation selection.
- `frontend/src/features/toolset-builder/useToolsetBuilderCatalog.js` — request lifecycle and stale-response containment.
- `frontend/src/api/toolsetBuilderApi.js` — narrow builder adapter over canonical catalog endpoints.
- `frontend/src/styles/toolset-builder.css` — feature presentation authority.
- `frontend/tests/toolsetBuilderFrontendContract.test.mjs` — frontend architecture contract test.

## Data path

Retained frontend read flow when the post-launch route is explicitly enabled:

```text
/toolset-builder
  -> workflow presentation model
  -> functional tool family
  -> toolsetBuilderApi
  -> catalogPlatformCache
  -> apiClient
  -> GET /wp-json/dtb/v1/catalog/products?tool_family=...
  -> canonical catalog DTO
```

Variable products resolve on demand through:

```text
GET /wp-json/dtb/v1/catalog/products/:id/variations
```

That endpoint is a bounded configuration-selector read, not a PDP gallery endpoint. It returns authoritative variation identity, attributes/label, price, inventory/purchasability, and the already-persisted primary image. Catalog media-manifest/filesystem gallery enrichment is explicitly excluded from this selector path so opening a configuration control does not trigger expensive disk-index work.

The builder deliberately does not use the existing brand-specific Toolset Builder template endpoints.

## State model

Long-lived authoritative commerce state is not copied into React.

Local builder state currently contains only:

- selected workflow ID in the URL query string;
- current capability step;
- selected product/variation references for the in-progress configuration;
- temporary search, brand, page, and review UI state;
- request loading/error state.

Catalog responses remain server-owned data. Displayed subtotal is explicitly labeled estimated and is not used as a commerce authority.

## Workflow presentation model

`model.js` currently provides frontend-only workflow organization for:

- Complete Automatic Set;
- Finishing Set;
- Taping Set;
- Flat Box Set.

Each workflow is expressed as functional tool families with minimum/maximum presentation cardinality.

The handle model is universal: `handle` is the only canonical handle family exposed to the builder. The frontend does not create separate `flat_box_handle`, `angle_head_handle`, or `corner_roller_handle` authorities. Handle-to-tool compatibility is a separate server-owned concern and must be enforced through explicit compatibility metadata/validation rather than inferred from a React family name.

The Complete Automatic Set currently presents seven capability steps:

1. Automatic Taper
2. Finishing Boxes
3. Handles
4. Angle Heads
5. Corner Applicator
6. Corner Roller
7. Loading Pump

This model is intentionally temporary. During the backend phase, authoritative workflow/capability/cardinality policy should be supplied by a backend contract and this frontend model should become fallback copy/configuration only or be removed.

## Cart boundary

The review screen intentionally stops before cart mutation.

Required backend contract before enabling **Add set to cart**:

1. validate the complete configuration as one unit;
2. resolve exact product/variation identity server-side;
3. enforce product eligibility and tool-to-tool compatibility;
4. re-read current WooCommerce purchasability, price, and stock;
5. derive included/dependent items server-side;
6. attach stable toolset-instance metadata;
7. prevent duplicate finalization;
8. avoid partial multi-line cart state on failure;
9. mutate the existing authoritative WooCommerce cart/session only.

The frontend must call that one backend finalization contract rather than implement its own sequence of `addToCart()` calls.

## Responsive and accessibility behavior

The builder uses the canonical DTB token and responsive systems.

Implemented behavior includes:

- one semantic component tree across breakpoints;
- horizontal progress navigation where space is constrained;
- sticky progress/summary only where viewport geometry supports it;
- minimum 44px interaction targets;
- explicit labels for search, filtering, and variation controls;
- visible focus through the global focus authority;
- selected state communicated with text/icon structure in addition to color;
- reduced-motion behavior;
- loading, error, empty, retry, out-of-stock, and selection-limit states;
- responsive product cards and review composition.

## Verification

Source-level contract test:

```text
npm run test:toolset-builder
```

The test protects the following invariants:

- initial-launch `App.jsx` does not register `/toolset-builder`;
- initial-launch `routeModules.js` does not preload or resolve the builder route;
- storefront navigation does not point to the route;
- catalog reads use the canonical catalog layer and require `product_kind=tool` for selectable builder products;
- the legacy `/toolsets` API is not introduced as frontend authority;
- workflow presentation does not contain shipping/discount/pricing authority;
- the workspace does not import or call cart mutation APIs;
- final cart action remains disabled until server validation exists;
- exact variations are loaded through the canonical variation endpoint;
- feature CSS uses DTB class names, responsive behavior, and reduced motion.

A local build/test was not executed in the implementation session because the available runtime could not resolve GitHub to check out the branch. Do not treat source review as a passing build.
