# Frontend Component Library

This folder now has a stricter split:

- `components/ui/`
  Presentation primitives and reusable design-system building blocks.
- `components/shell/`
  App chrome, top-level navigation, overlays tied to the shell.
- `components/catalog/`
  Product listing controls, grid loading states, and merchandising sections.
- `components/product/`
  Product detail modal flow, media, specifications, and reviews.
- `components/schematics/`
  Schematic browsing and selection flow.
- `components/account/`
  Account-layout wrappers used by authenticated account pages.
- `components/routing/`
  Route guards and transition wrappers.
- `components/shared/`
  Cross-feature helpers that do not belong to a single domain.
- `components/errors/`
  Error boundaries and crash containment components.

## Catalog routing and pagination contract

The catalog has two customer-facing listing scopes:

- `/products` is the general catalog and is intentionally not constrained by `is_parts`; brand-level **All Products** views include every product for the selected brand, including replacement parts.
- `/parts` is the dedicated parts catalog and is the only storefront scope that applies `is_parts=1`.

`ProductsCatalogPlatform.jsx` owns catalog route state and passes the owning surface to `buildCatalogUrl()`. Query mutations such as pagination, sorting, search, brand filters, and category filters must preserve that surface instead of defaulting to `/products`.

`catalog/Pagination.jsx` owns pagination presentation only. Product totals and route mutation remain owned by the catalog page. Static catalog snapshots are bootstrap-only presentation data; the live `/dtb/v1/catalog/products` response remains authoritative for items, totals, page counts, and filter truth.

## Current UI primitives

Use these first before adding a new standalone component:

- `ui/Dropdown.jsx`
- `ui/HeroSection.jsx`
- `ui/NavbarTabs.jsx`
- `ui/ProductShoppingCard.jsx`
- `ui/Toast.jsx`
- `ui/TrustedBrands.jsx`

## Domain components that should stay outside `ui/`

These are feature-specific or data-aware and should remain outside `ui/`:

- Shell: `shell/Header.jsx`, `shell/Footer.jsx`, `shell/CartSidebar.jsx`
- Product detail flow: `product/ProductDetail.jsx`, `product/ProductModal.jsx`, `product/ProductImageGallery.jsx`, `product/ProductCardImage.jsx`, `product/Reviews.jsx`, `product/TechnicalSpecifications.jsx`
- Catalog helpers: `catalog/FilterPanel.jsx`, `catalog/SearchBar.jsx`, `catalog/Pagination.jsx`, `catalog/ProductShoppingCardSkeleton.jsx`, `catalog/TrendingProducts.jsx`
- Schematics flow: `schematics/BrandSelector.jsx`, `schematics/ToolSelector.jsx`
- Routing helpers: `routing/PageTransition.jsx`, `routing/ProtectedRoute.jsx`
- Shared utilities: `shared/BackButton.jsx`, `shared/SEOHead.jsx`
- Error handling: `errors/ErrorBoundary.jsx`

## Removed legacy duplicates

These legacy components were removed because active pages now use the `ui/` replacements directly:

- `ProductCard.jsx` → replaced by `ui/ProductShoppingCard.jsx`
- `Toast.jsx` → replaced by `ui/Toast.jsx`
- `SortDropdown.jsx` → replaced by `ui/Dropdown.jsx`

These unused legacy components were also removed because they had no active importers:

- `ApiErrorBoundary.jsx`
- `SchematicDiagrams.jsx`
- `SchematicFilterBar.jsx`
- `VariantChips.jsx`
- `ui/Button.jsx`, `ui/FeatureSection.jsx`, `ui/PricingTable.jsx`
- `shell/MobileSearch.jsx`, `shell/NotificationsBell.jsx`, `shell/ShippingTicker.jsx`
- `account/AccountLayout.jsx`
- `product/ProductAvailabilityNotice.jsx`, `product/ProductDescriptionAccordion.jsx`, `product/ProductMediaGallery.jsx`, `product/ProductPrice.jsx`, `product/ProductQuantityStepper.jsx`, `product/ProductSkuBlock.jsx`, `product/ProductVariantSelector.jsx`
- `repairs/RepairCommentBox.jsx`, `repairs/RepairRequestForm.jsx`, `repairs/RepairTimeline.jsx`
- `storefront/StorefrontAnnouncementBar.jsx`, `storefront/StorefrontCTA.jsx`, `storefront/StorefrontCategoryTile.jsx`, `storefront/StorefrontHero.jsx`, `storefront/StorefrontShopMegaMenu.jsx`
- `shared/GlobalLoadingOverlay.jsx` (and its unused `context/GlobalLoadingContext.jsx`/`GlobalLoadingProvider`, which was never mounted). The sole active full-screen preloader is the early HTML shell in `frontend/index.html`; route transitions signal that same surface through `components/routing/globalPreloader.js`.
- `shared/LoadingSpinner.jsx` and the unreferenced `styles/global-loading.css` route-pending skeleton.

## Safe refactor rules

- If a component is mostly styling and accepts generic props, it belongs in `components/ui/`.
- If a component owns domain logic, API orchestration, route behavior, or feature-specific state, keep it in `components/`.
- Do not add new imports from deleted legacy files.
- Before deleting a component, confirm it has no imports anywhere in `frontend/src`.
