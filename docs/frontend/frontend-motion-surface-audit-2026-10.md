# Drywall Toolbox — storefront motion surface audit (October 2026)

## Scope and architectural contract

This audit follows the active React frontend on `drywalltoolbox/dwt.production` at the base of the `feat/motion-cart-reduced-motion-20261010` branch and subsequent changes on that branch. It is a source-level implementation review, **not** proof of physical-device performance, deployed behavior, or a completed visual design review.

Ownership remains with the existing frontend component and feature modules. React Router retains navigation and history scroll restoration. The browser retains vertical scrolling, momentum, overscroll, and horizontal rail gestures. `frontend/src/motion/dtbMotion.js` owns semantic Framer Motion presets; `frontend/src/styles/storefront-tokens.css` owns CSS timing tokens. The application uses a single strict `LazyMotion` provider, loading `domMax` asynchronously. No page-level scroll animation system or commerce state authority is introduced.

## Audited surface inventory

| Surface | Owning source | Source-verified behavior and refinement | Disposition |
| --- | --- | --- | --- |
| Mobile header | `components/storefront/StorefrontHeader.jsx`, `styles/unified-responsive.css` | Removed scroll-threshold React state, passive listener, and scroll-driven visual style; persistent shell no longer changes with the document's scroll position. | Refined |
| Mobile homepage hero | `components/home/HomeHero.jsx`, `styles/home-hero.css` | Existing campaign motion is Framer-driven and presentation-only. Mobile/tablet stage now uses `100svh` with `vh` fallback, avoiding `100dvh` resizing when browser chrome expands/collapses anywhere on the page. | Refined |
| Homepage campaign navigation | `components/home/HomeHero.jsx` | Existing `AnimatePresence` variants, reduced motion, directional campaign state, and touch detection. Do not tie animation to vertical scroll. | Retained |
| Product rails | `components/storefront/StorefrontRail.jsx`, `StorefrontProductRail.jsx` | Native overflow, scroll snapping, arrow/keyboard navigation, reduced motion. No JavaScript drag hijacking. | Retained |
| Product cards | `components/storefront/StorefrontProductTile.jsx`, `styles/storefront-product-card.css` | Touch swipe must not eagerly warm quick-view/PDP on pointerdown; keyboard/mouse intent still works. Existing CSS paint interactions avoid per-card Framer projection. Shared-element transitions remain deferred pending gallery/modal lifecycle validation. | Retained |
| Product quick view | `components/product/ProductModal.jsx`, `components/motion/*` | Existing mobile Motion drawer, desktop bounded Motion dialog, backdrop, and reduced-motion variants. Added keyboard focus containment and protected nested image-lightbox focus/Escape ownership. | Refined |
| Product gallery and lightbox | `components/product/ProductImageGallery.jsx` | Existing directional Framer transitions and full-screen portal; source currently freezes product image identity to avoid placeholder/variation flashes. Do not rework projection/identities without regression evidence. | Retained; lint debt |
| Product variations | `components/product/ProductVariationSelector.jsx` | Already uses `m`, `AnimatePresence`, restrained selection feedback and reduced-motion preference. Product identity, pricing, and stock remain authority-owned. | Retained |
| Cart page | `pages/Cart.jsx` | Canonical motion timing, item presence/reflow, and reduced-motion-disabled geometry projection. Only committed cart state animates. | Refined |
| Cart sheet | `components/storefront/StorefrontCartSheet.jsx` | Existing sheet styling, accessibility and cart mutations; avoid duplicate state and false-success motion. | Retained; device QA |
| Search suggestions | `components/storefront/StorefrontSearchOverlay.jsx`, `styles/storefront-drawer.css` | Header input remains accessible; suggestions are a non-modal region, inert when closed. Restored empty-query browse panel visibility and protected nested quick-view/lightbox Escape ownership. No layout animation on search results. | Refined |
| Mobile catalog filters | `components/catalog/FilterPanel.jsx`, `styles/filter-panel.css` | Converted competing CSS entrance keyframes to owned Framer fade/spring sheet with dialog role, Escape/Tab containment, focus restoration and reduced-motion fallback. | Refined |
| Desktop mega-menu | `components/storefront/StorefrontDesktopNavigation.jsx` | Keeps existing atomic menu switching and CSS visual states. Avoid huge re-raster and flicker. | Retained; lint debt |
| Mobile navigation sections | `components/storefront/StorefrontHeader.jsx`, `styles/storefront-navigation-taxonomy.css`, `styles/storefront-drawer.css` | Parent expansion uses measured intrinsic Framer height; removed arbitrary 480/760/2400px caps; children remain inert while collapsed. Existing nested taxonomy retains local CSS transitions. | Refined |
| Toolset landing | `pages/ToolsetBuilder.jsx` | Workflow cards use tactile restrained springs on hover/tap, disabled under reduced motion; route/query selection unchanged. | Refined |
| Toolset product selection | `features/toolset-builder/ToolsetBuilderProductCard.jsx` | Animate selected-badge presence only after actual selection state changes; selected buttons express `aria-pressed`. Preserves variation, inventory and maximum selection rules. | Refined |
| Repair packages | `pages/RepairPackages.jsx` | Existing motion card/tab presentation standardized to suppress spatial movement and projection for reduced motion. Repair package identity, prices, and workflow URL unchanged. | Refined |
| Repair workflow and status | `components/repairs/*`, `pages/RepairStart.jsx`, `RepairTrack.jsx` | Domain progress, state and operator feedback must remain stable; do not imply successful repairs from animation. Further UI choreography needs interaction-level QA. | Deferred |
| Schematic diagram and hotspots | `components/schematics-v2/HotspotLayer.jsx`, `DiagramViewer.jsx` | Diagram intrinsic geometry and hotspot coordinates are authoritative. Never apply Framer transform to positioned hotspot centers/zoom layer. | Retained |
| Schematic part dialog | `components/schematics-v2/SchematicPartDialog.jsx` | Added mobile focus containment and focus restoration while retaining exact diagram-relative desktop geometry and propagation barriers. | Refined |
| Account dashboard | `components/dashboard/AccountHub.jsx`, `components/ui/NavbarTabs.jsx` | Existing deep-linked tabs and active indicator. Dashboard now observes reduced motion on hero/tab transitions and loading dots; canonical timing. | Refined |
| Account sheet | `components/account/AccountHubSheet.jsx`, `styles/account-hub-motion.css` | Existing CSS-owned responsive bottom/right sheet and account loading. Do not introduce Framer competing with CSS transitions without lifecycle replacement. | Retained |
| Order tracking | `pages/OrderTracking.jsx` | Preserve status/fulfillment presentation fidelity; no animation may suggest shipping or payment state absent from API. | Retained |
| Checkout | `pages/WooNativeCheckout.jsx`, `components/checkout/*` | Payment-provider UI and WooCommerce checkout continue to own their own state. Restrict motion to client-side validation feedback outside provider-controlled elements. | Deferred; separate checkout QA |
| Route navigation | `App.jsx`, `components/routing/PageTransition.jsx` | History-key scroll preservation and immediately opaque route wrapper. Never animate entire page tree, mask loading, or add a global scroll controller. | Retained |

## Quality and release gates

1. Verify PR source and the exact commit SHA validated by CI before merge.
2. Run frontend locked dependency installation, ESLint, all source-level contract suites, and `npm run build:staging`. Existing unrelated lint and contract failures are release blockers, not evidence that this change passed.
3. Verify the mobile hero on Safari iOS and Chrome Android while scrolling far below the hero, including browser address-bar collapse/expansion; its height must remain stable while viewport UI changes.
4. Validate 320px, 375px, 390px, 768px, 1024px, and desktop, landscape, safe areas, zoom, reduced motion, and orientation changes.
5. Test tab/Escape focus containment and restoration for navigation, search, filters, quick view, gallery lightbox, account and schematic part dialogs.
6. Exercise real cart changes, variable-product selection, checkout validation and transaction handoff without visual false success.
7. Capture INP (target <=200ms p75), CLS (target <=0.1), LCP (target <=2.5s), jank/long-task traces and memory under CPU throttling. These are targets, not measurements.
8. Inspect actual rendered visuals and mobile screen recordings for composition and jitter; never certify a visual enhancement from code inspection alone.

## Explicit non-goals

No additional scroll engine, full-page route animation, speculative shared-element image identity, schematic hotspot coordinate transform, checkout/provider animation takeover, backend commerce mutations or API/queue changes.

## Residual risks

Nested modal and focus ordering, body-scroll lock ownership, fixed header geometry, CSS vs Motion competition, new tests against legacy source contracts, and existing lint debt (notably product gallery render-time ref mutation) require CI and physical-device investigation. A successful staging build alone cannot certify storefront performance or production readiness.
