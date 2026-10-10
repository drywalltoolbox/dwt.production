# Frontend Responsive Architecture

## Ownership

`frontend/` owns customer-facing routing, rendering, responsive layout, accessibility, interaction state, design primitives, motion, and API presentation. It does not own authoritative commerce state, pricing, inventory, orders, payments, refunds, tax, shipping, or fulfillment.

## Rendering hierarchy

The storefront uses this layout hierarchy:

```text
viewport
  -> #root application shell
    -> fixed storefront header
    -> main.main-content
      -> route page frame
        -> constrained container
          -> layout primitive
            -> domain component
    -> footer
    -> overlays and drawers
```

Each layer has one responsibility. The application shell owns document flow and the fixed-header offset. Page frames own route surfaces. Containers own readable widths and viewport gutters. Layout primitives own composition. Domain components own their internal presentation.

## Canonical files

- `frontend/src/styles/storefront-tokens.css`: brand, semantic, spacing, typography, content-width, motion, safe-area, and layer tokens.
- `frontend/src/motion/dtbMotion.js`: canonical Framer Motion durations, easing, physical spring response, semantic variants, and reduced-motion variants.
- `frontend/src/components/motion/GlobalMotionProvider.jsx`: application-wide Motion configuration and user reduced-motion policy.
- `frontend/src/styles/storefront-motion.css`: cross-component CSS timing/easing authority. It owns shared motion timing only, never component geometry or visual design.
- `docs/frontend/frontend-motion-system.md`: durable motion contract for route, content, surface, loading, and direct-manipulation transitions.
- `frontend/src/styles/responsive-foundation.css`: document normalization, intrinsic media behavior, accessibility, app sizing, and reusable layout primitives.
- `frontend/src/styles/storefront-shell.css`: header offset, main flow, surfaces, and shared shell presentation.
- `frontend/src/styles/unified-responsive.css`: the only global cross-route responsive geometry authority. It owns viewport geometry, responsive stacking, touch density, safe areas, overflow containment, and domain breakpoint behavior.
- `frontend/src/styles/storefront-visibility.css`: stable storefront visibility decisions that are not responsive layout rules.
- `frontend/src/components/layout/LayoutPrimitives.jsx`: React composition API for page frames, containers, sections, stacks, clusters, grids, split layouts, and sidebars.

## Cascade contract

`frontend/src/main.jsx` loads styles in this order:

1. utilities and document base;
2. design tokens and responsive foundation;
3. feature/component base styles;
4. typography and feature presentation authorities;
5. `storefront-motion.css` as the shared timing/easing authority;
6. `unified-responsive.css` as the final and exclusive responsive geometry layer.

Do not add another global mobile, tablet, desktop, fix, patch, mockup, polish, cleanup, or final-authority stylesheet. Stable component appearance belongs to the owning feature stylesheet. Shared motion timing belongs to `storefront-motion.css` and `dtbMotion.js`. Cross-route viewport behavior belongs to the correct section of `unified-responsive.css`.

## Layout contracts

### Containers

Use `Container` or `.dtb-container` instead of recreating page padding and `max-width` declarations. Supported sizes are `narrow`, `default`, `wide`, `full`, and `fluid`.

Mobile navigation uses a contained native vertical scroll region; only the open drawer temporarily locks document body scrolling. Closed taxonomy and brand branches must be `inert` so clipped links cannot enter the keyboard focus order. The navigation dialog must trap Tab focus and restore it on dismissal unless focus moved to another surface. Horizontal product rails prefetch via `IntersectionObserver` shortly before entering the viewport, but retain native swipe momentum and CSS snap semantics.

At mobile coarse-pointer widths, storefront rails use native inertia with proximity scroll snapping, preserving both horizontal gestures and normal page panning without JS gesture interception. Scrolled mobile header state changes only paint (subtle elevation and separation), not intrinsic header dimensions or the measured route offset.

Mobile grid and rail product cards preserve brand, sellable SKU, current price, and valid compare-at price. Their typography and price wrapping are constrained within the owning card, rather than ellipsizing commerce prices to fit narrow viewports. Existing equal-height metadata slots preserve aligned card footers across simple and variable items.

Horizontal card rails must remain inside the owning container gutter. Shared `StorefrontRail` instances must not use negative inline margins or persistent edge masks that visually cover the first or last card. Navigation controls belong inside the rail shell, while the scroll viewport keeps a small internal safety inset for borders, focus rings, and scroll snapping. Product image thumbnail rails follow the same edge-safety principle inside both the full PDP and quick-view modal.

### Category hero composition and loading

`frontend/src/components/catalog/CategoryHero.jsx` and `frontend/src/styles/category-hero.css` are the single implementation and visual authorities for the dedicated `/category/:slug` hero. Do not layer a second refinement/override stylesheet over this component. The category hero is a compact orientation masthead, not a homepage billboard: desktop uses a bounded 43/57 copy/media split, a narrow integrated seam, and a 15.5–17.5rem reserved height so product browsing remains above the fold.

On desktop, the breadcrumb is part of the dark content plane and shares its content gutter. On mobile, the same semantic breadcrumb follows the image at the bottom of one continuous full-bleed hero surface. Mobile copy appears first, followed by isolated existing product artwork and the breadcrumb; the anchor action leads to the existing product grid. The mobile floor-like setting is presentation-only CSS, not a new category artwork authority. The hero must remain full-bleed within the fluid catalog container without negative-margin page-layout hacks.

Category hero imagery remains transparent isolated product media. `frontend/src/constants/categoryHeroPresentation.js` may map category slugs to the bounded presentation modes `standard`, `wide`, `tall`, `compact`, or `oversized`. Those modes may adjust optical scale/position only; they must never encode taxonomy, commerce state, or product truth, and the feature stylesheet must not accumulate slug-specific selectors.

Category metadata loading must reserve the final hero footprint rather than rendering a small placeholder that later expands into the hero. The loading state uses the same responsive split geometry as the resolved state, with breadcrumb, copy, and media skeleton structure aligned to the final layout. The resolved hero stays covered by that structural shimmer until its selected hero image has loaded and completed its decode attempt. Successfully loaded hero image URLs are remembered for the lifetime of the frontend session so revisiting a category can reveal immediately without replaying an unnecessary shimmer. Failed media remains retryable and is never cached as successfully ready. Reduced-motion preference disables shimmer and transition travel while preserving layout reservation and functional rendering.

### Motion and device consistency

Desktop, tablet, and mobile share one semantic motion system. Do not create device-specific easing curves or arbitrary mobile durations. Device-specific motion differences are limited to geometry required by the interaction—for example, a mobile drawer can travel by a percentage while a desktop surface uses a small pixel offset.

Framer Motion components inherit the application-wide low-bounce spring from `GlobalMotionProvider`. Route/content/loading transitions that require deterministic timing use the semantic variants exported by `dtbMotion.js`. CSS transitions use the matching `--dtb-motion-*` tokens. `prefers-reduced-motion` and Motion's `reducedMotion="user"` must disable nonessential movement consistently at every breakpoint.

### Vertical composition

Use `Section` for route sections and `Stack` for vertical rhythm. Spacing should come from `--dtb-space-*` tokens, not unrelated page-specific values.

### Inline composition

Use `Cluster` for wrapping actions, metadata, filters, badges, and button groups. Controls must wrap without forcing viewport overflow.

### Repeating content

Use `AutoGrid` for product cards, package cards, feature groups, and other repeating content. The grid is intrinsic and uses `auto-fit` with a content minimum rather than a device-specific column matrix.

### Two-column composition

Use `SplitLayout` for product-detail and checkout-style primary/secondary layouts. Use `SidebarLayout` for filters and navigation. Both collapse to one intrinsic column when their content no longer fits.

### Component responsiveness

Reusable components should respond to their allocated width. Apply `dtb-component-region` to establish an inline-size container, then use container queries for internal changes. Viewport media queries remain appropriate for application-shell and route-level composition.

## Responsive invariants

1. Flex and grid children containing dynamic content use `min-width: 0`.
2. Media is intrinsically constrained and reserves an intentional aspect ratio where layout stability matters.
3. Horizontal scrolling exists only inside explicit rails, selectors, drawers, or data regions.
4. Root overflow containment is defensive only; components must still constrain their own width and dynamic content.
5. Page gutters and maximum widths come from shared tokens and containers.
6. Horizontal rails preserve the parent container's visible inline edge; their first and last interactive cards must not be shifted underneath negative-margin bleed, fades, or off-canvas controls.
7. Forms preserve a minimum 16px mobile text size without broad descendant `!important` rules.
8. Hover treatment is enabled only for devices that support hover and fine pointing.
9. Reduced-motion preferences remove nonessential transitions and animation.
10. Mobile and desktop use the same semantic component, domain state, and motion language unless the interaction model is materially different.
11. Feature styles may refine a component but must not redefine the global viewport, root, body, application shell, or global motion tokens.
12. Checkout rules are presentation-only and never alter WooCommerce, payment-provider, order, pricing, inventory, tax, shipping, or session ownership.
13. Schematic responsive rules must preserve image bounds and hotspot coordinate ownership.

## Domain sections in the unified authority

`unified-responsive.css` is organized by stable domain boundaries:

- document and shared route containers;
- header, drawers, overlays, and safe areas;
- catalog listing, product grid, and filters;
- product-detail shell and related products;
- cart and checkout presentation boundaries;
- account dashboard;
- repairs, returns, support, and tracking;
- schematics;
- tablet, mobile, narrow-mobile, coarse-pointer, and reduced-motion adaptations.

Selectors must be scoped to stable route or component classes. Avoid DOM-position selectors, wildcard class matching, and broad universal descendant overrides.

## CSS rules

Do not add:

- files named or described as fixes, patches, mockups, polish, cleanup, overrides, or final authority;
- wildcard class selectors such as `[class*="drawer"]`;
- universal descendant overrides such as `.component *` for sizing;
- `transition: all`;
- arbitrary local easing curves or motion durations when a `--dtb-motion-*` token applies;
- arbitrary `z-index` escalation;
- fixed content heights for dynamic text;
- negative margins for primary page layout;
- desktop/mobile duplicate React trees for presentation-only differences;
- page-specific rules in `index.css`;
- additional root overflow suppression;
- responsive behavior in runtime JavaScript when CSS can express the same layout.

## Change workflow

When changing responsive UI:

1. Identify the owning component and its feature stylesheet.
2. Keep base appearance in the feature stylesheet.
3. Use shared tokens, motion semantics, and layout primitives for new composition.
4. Place shared motion timing in `storefront-motion.css` / `dtbMotion.js`; place cross-route viewport behavior in `unified-responsive.css`.
5. Preserve accessibility, safe-area, reduced-motion, checkout ownership, schematic coordinates, and dynamic-content overflow behavior.
6. Remove superseded selectors instead of adding a later visual override.
7. Update this document and `frontend-motion-system.md` when the responsive or motion contract changes.


### Cross-route mobile interaction refinement (October 2026)

The unified responsive stylesheet remains the single owner for cross-route anchor alignment and keyboard focus visibility. Anchor targets and programmatically focused route elements honor the measured `--header-height` instead of obscuring content beneath persistent chrome. Interactive descendants of route content use a visible `:focus-visible` outline; individual components may refine but must not remove it.

The browser remains responsible for touch physics, vertical scroll and horizontal rails. On reduced-motion preferences, product rails and explicit rail-arrow navigation use non-smooth scrolling; do not animate the page-sized route wrapper or synthesize scroll offsets after lazy hydration. Do not move these concerns into a second animation framework or global scroll engine.

Performance changes are incremental: mobile touch pointerdown does not preload quick-view/PDP data for cards being swiped past. Validate keyboard, screen reader, browser history, actual touch scrolling, safe-area layouts, viewport resize, zoom, reduced motion and checkout on physical iOS/Android devices before merging.
