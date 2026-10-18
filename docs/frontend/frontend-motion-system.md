# Frontend Motion and Render Continuity System

## Ownership

`frontend/` owns storefront presentation motion, route rendering continuity, client-side code preloading, local loading presentation, accessibility, and responsive behavior. These concerns must never become authorities for commerce state, payment state, inventory, orders, checkout persistence, routing data, or provider behavior.

The motion system has two canonical timing authorities:

- `frontend/src/motion/dtbMotion.js` — Framer Motion transitions, variants, restrained spring physics, distances, durations, and reduced-motion variants.
- `frontend/src/styles/storefront-tokens.css` — numerically matching CSS duration/easing/distance tokens.

`frontend/src/styles/storefront-motion.css` is the shared CSS timing layer. Feature styles continue to own geometry, appearance, layout, and component-specific states.

`frontend/src/components/motion/GlobalMotionProvider.jsx` applies the application-wide Framer Motion default and `reducedMotion="user"`. `App.jsx` mounts the single application `LazyMotion` boundary with `strict` enabled so feature code must continue using the lightweight `m` components and cannot silently bypass the async animation-engine contract.

## Render-continuity contract

Route loading is not page animation. Data loading is not route loading. Image loading is not page loading.

The customer-facing contract is:

1. Persistent content stays persistent.
2. Destination geometry appears immediately when a lazy route is unresolved.
3. Only unresolved regions display loading treatment after the route module is available.
4. Page roots never animate from `opacity: 0`.
5. Likely route code is requested from navigation intent before the click completes.
6. Motion confirms a state transition; it must not conceal application latency.
7. Loading indicators must preserve the geometry of the state they replace instead of introducing an unrelated full-page surface.

The persistent header, footer, cart shell, navigation surfaces, and other application chrome remain mounted during normal route navigation.

## Route-module loading

`frontend/src/routing/routeModules.js` is the route-module loading authority. It owns:

- the mapping from application route groups to dynamic page imports;
- promise de-duplication so preload and render consume the same module request;
- route-path resolution for preload intent;
- the existing one-time chunk-load recovery behavior.

Do not define a second independent dynamic import for a route that already exists in this registry. A preload that downloads different code than the corresponding `React.lazy` boundary defeats the continuity contract.

`frontend/src/components/routing/RouteIntentPreloader.jsx` observes same-origin internal links and preloads recognized route modules on pointer intent, keyboard focus, and touch intent. Imperative navigation surfaces that are not anchors should call `preloadRoute()` before navigation when practical. Product cards do this explicitly for PDP navigation.

Preloading is an optimization only. Routing correctness must never depend on a successful preload.

## Lazy-route pending surfaces

The application-level `Suspense` boundary uses `RouteLoadingSurface`, not a centered route spinner.

The pending surface is intentionally static and geometry-oriented:

- catalog routes reserve heading and product-grid geometry;
- product routes reserve gallery and purchase-panel geometry;
- account/content routes reserve stable content-panel geometry.

These surfaces do not attempt to reproduce business data and do not own loading state after the route module has rendered. Route components remain responsible for their own data skeletons and unresolved regions.

Protected routes use the same pending-surface language while authentication is validating. Authentication and redirect behavior remain unchanged.

## Initial document handoff

`frontend/index.html` owns a minimal static boot surface that can paint before the React entry bundle executes. It is presentation-only and contains no commerce, authentication, routing, or data authority.

`frontend/src/main.jsx` owns the handoff. `AppBootMarker` waits for React to commit and for two `requestAnimationFrame` boundaries before setting `data-dtb-app-mounted="true"`. The static shell then performs only a short opacity release and is removed from the document.

The boot surface must remain visually quiet: stable application background, DTB branding, no full-screen progress animation, no artificial minimum display duration. Under `prefers-reduced-motion: reduce`, its transition is effectively immediate.

Checkout retains its dedicated document-level handoff. `dtb-checkout-route-booting` suppresses the ordinary application boot surface so the two loaders never compete.

The boot watchdog remains the failure authority when the entry bundle does not mount. Before showing its recovery UI it removes the static boot shell, ensuring a failed boot cannot leave the recovery action obscured.

## Shared native scroll experience (October 2026)

`StorefrontScrollExperience.jsx` is the **single shared document-scroll enhancement owner**, mounted once inside React Router. It provides a blue reading-progress line, a contextual 44 px back-to-top control, and one IntersectionObserver for explicitly opted-in **homepage editorial headings**. It does **not** replace native wheel/touch momentum and never intercepts pointer or wheel input. Its helper policy lives in `utils/scrollExperiencePolicy.js`; styling is in `styles/storefront-scroll-experience.css`.

- **Browser owns scrolling:** iOS/Android momentum, overscroll, keyboard/focus scroll and nested rails remain native. Smooth motion is requested only on the deliberate back-to-top click, and is disabled under reduced motion. Do not apply `scroll-behavior: smooth` to `html`/`body`, which would conflict with route/history restoration.
- **React Router owns restoration:** `App.jsx` retains history-key positions, immediate PUSH resets, POP restoration and anchor navigation. The progress indicator reads current geometry and does not write route scroll positions.
- **Height changes:** passive `scroll`/`resize` listeners and a body `ResizeObserver` feed a single animation-frame measurement. The progress bar is updated by a transform ref; React state changes only when the back-to-top threshold is crossed.
- **Locked surfaces:** body/document inline overflow locks suppress the back-to-top control. The stylesheet also hides it for an active modal, quick-view lightbox or cart drawer. No motion controller runs against nested drawers, thumbnail rails, tool dialogs, or payment UI.
- **Exclusions:** full-document checkout and interactive schematic viewer do not receive this overlay. The non-interactive schematics landing page retains native reading progress.
- **Editorial-only reveals:** only the two homepage `StorefrontSection` headings explicitly set `revealHeading`. Content remains at least 90% opaque in a small 8 px pre-reveal offset; product grids, cards, filters, pricing, links and cart actions are never gated. MutationObserver attaches late-mounted opt-in headings, and IntersectionObserver marks them visible once. All observers/listeners disconnect on route change. Reduced motion stays fully visible.
- **Inertia policy:** no desktop virtual-scroll engine or Lenis is installed. Custom wheel inertia is deferred until measured browser/device validation; any future integration must be routed through `StorefrontScrollExperience` and suspend for scroll locks, route restoration, keyboard navigation, and nested gesture surfaces. Native mobile touch inertia remains the baseline.

**Verification:** `frontend/tests/scrollExperienceContract.test.mjs` checks exclusions, progress bounds, lock policy and the no-wheel-interception contract. Before release, run the frontend lint and source tests, locked build, and real iOS Safari/Android/desktop tests of fling inertia, focus/keyboard, drawer lock/unlock, POP restoration, dynamic catalog heights, reduced motion and active schematic gestures. GitHub source commits are not evidence of a deployed build or physical-device QA.

## History scroll restoration

`ScrollToTop` remains the sole route viewport authority. It sets native `history.scrollRestoration` to `manual` while the SPA is mounted and stores window scroll positions by React Router history `location.key`.

Navigation semantics are:

- `PUSH` / ordinary route change: reset to the new route start before paint.
- `POP` / browser Back or Forward: restore the previously recorded position for that history entry when available.
- query-only changes on the same pathname/hash: preserve the current viewport because those query parameters represent in-page state such as filters and tabs.
- hash navigation without a saved POP position: resolve the hash target before falling back to the route start.

Saved positions are session-memory UI state only and are bounded to 100 entries. They are not persisted, sent to the backend, or treated as application data.

## Application-shell boundary

`GlobalMotionProvider` is mounted in `frontend/src/main.jsx` above `App`, routing, header, footer, cart/sidebar surfaces, route content, dialogs, drawers, and other application UI.

`PageTransition` animates only the changing route surface. Its route variant must remain fully opaque from the first frame. Mobile/tablet retain the restrained 4 px / 180 ms settle. Desktop route commits are intentionally static and consume the reduced/static route variant because translating a full desktop page promotes a very large raster surface and can destabilize sticky/fixed descendants. Do not restore whole-page desktop transforms or entire-page fades.

Checkout is excluded from the normal route wrapper because payment-provider rendering and checkout integrity take precedence over decorative page motion. Provider-owned payment controls must never be wrapped in transforms or transitions that could interfere with focus, authentication, or payment behavior.

## Motion language

DTB uses one restrained motion language across desktop, tablet, and mobile:

1. **Route settle** — opaque 4 px micro-lift, 180 ms.
2. **Async replacement** — structural skeleton/content crossfade, 220 ms.
3. **Standard content reveal** — 320 ms, only for genuinely new in-page content where a reveal communicates state.
4. **Elevated reveal** — 360–400 ms for deliberate overlays or major presentation changes.
5. **Direct manipulation** — low-bounce spring for drawers, indicators, toggles, and controls whose geometry follows user interaction.
6. **Exit/dismissal** — 180 ms.

A component must not replay a second root entrance animation immediately after `PageTransition`. Local animations are appropriate for dialogs, accordions, transient messages, cart-line insertion/removal, image-gallery changes, and similar stateful regions.

## Canonical motion values

### Timed easing

- Standard: `cubic-bezier(0.22, 1, 0.36, 1)`
- Emphasized: `cubic-bezier(0.16, 1, 0.3, 1)`
- Soft: `cubic-bezier(0.2, 0.8, 0.2, 1)`
- Exit: `cubic-bezier(0.4, 0, 0.2, 1)`

### Durations

- Instant: 100 ms
- Fast/route settle: 180 ms
- Async replacement: 220 ms
- Normal: 320 ms
- Elevated: 360 ms
- Overlay: 400 ms
- Slow: 440 ms

The 440 ms token remains available for rare intentionally slow presentation work. It is not the loading-replacement duration.

### Physical response

Default interactive spring:

- stiffness: 360
- damping: 34
- mass: 0.82

Gentler sheet/drawer spring:

- stiffness: 300
- damping: 32
- mass: 0.9

Springs do not receive CSS easing functions.

## Loading hierarchy

Use the narrowest valid loading boundary:

- **Route module unresolved:** `RouteLoadingSurface`.
- **Authentication unresolved:** the protected-route pending surface.
- **Page data unresolved:** a skeleton local to that page region.
- **Image unresolved:** image placeholder/skeleton inside its existing media geometry.
- **Mutation pending:** preserve existing content and disable/annotate the affected control; do not replace the complete page.
- **Document navigation/checkout handoff:** keep the current document visible until the browser commits the destination unless the dedicated checkout flow owns a different safe handoff.

Avoid sequential replacement chains such as route spinner → page spinner → skeleton → content.

## Desktop stability contract

Desktop has a larger composited paint surface than mobile and therefore uses stricter large-surface rules:

- Do not apply a transform or persistent `will-change` to the complete desktop route tree.
- Desktop mega-menu first-mount opening must paint a hidden opacity frame before the shared sheet eases into view; a freshly mounted fully opaque panel cannot animate its initial entrance. The sheet uses canonical emphasized opacity timing for entry and fast exit timing. Opening motion never translates or scales the large fixed sheet.
- Desktop mega-menu taxonomy switching is atomic. The open sheet remains painted while its rendered panel changes; there is no fade-to-zero or multi-frame blank interval between menu tabs.
- The desktop mega-menu keeps a constant centering transform and may animate opacity only. Do not scale or vertically translate the full 1000–1240 px fixed sheet during routine open/close.
- Desktop navigation must use explicit transitioned properties. `transition: all` is prohibited on primary navigation controls.
- Product-card `content-visibility: auto` is restricted to viewports at or below 1024 px. Desktop product grids remain continuously painted to avoid visibility-heuristic pop-in during hover and scrolling.
- Local transforms remain appropriate for small controls, chevrons, buttons, and bounded cards when they do not force a large subtree into its own compositor layer.

These constraints prioritize frame continuity over decorative movement. A stable desktop frame is the primary requirement.

## Product Quick View continuity

Desktop Quick View is a large overlay surface and follows stricter lifecycle rules than small dialogs:

- The desktop Quick View scroll shell is structural and remains fully opaque. Do not fade, scale, or translate the viewport-sized shell. The bounded white card owns the visible entrance/exit presentation.
- The modal shell must not use persistent `will-change`, `translateZ(0)`, or `contain: layout paint` as blanket compositor hints.
- Full-screen backdrop blur is prohibited for routine Quick View open/close; use a stable translucent backdrop instead.
- Quick View must animate on its first mounted open. Do not suppress the initial presence animation when `ProductModal` mounts already open.
- Desktop close must never fade the white product card to transparency over the darkened storefront. The card remains opaque during dismissal and may use only a restrained micro-translation while the backdrop releases.
- Body scroll locking must compensate for the removed scrollbar and preserve that lock until the exit animation has completed. Restoring overflow before exit completion causes the storefront to shift underneath a still-visible modal.
- Quick View shell geometry CSS must live in a persistent imported stylesheet, not a conditional `<style>` node inside the presence subtree.
- Product data must remain mounted through the exit interval. Owners set visibility false first and clear the selected product only after the 180 ms exit has completed.
- `ProductModal` is the sole document scroll-lock owner for Quick View. `ProductDetail` and nested PDP children must never write `body.style.overflow`, `body.style.paddingRight`, or equivalent document geometry.
- Desktop product-card intent prewarms both the Quick View component modules and the canonical `/catalog/products/:slug/detail` request through the same in-flight/cache authority consumed by the modal.
- `product-quick-view-desktop.css` is the sole Quick View geometry authority. General PDP polish styles must not redeclare `.product-modal-card-shell` geometry.
- Nested Quick View owners such as search and cart must restore the prior scroll-lock state rather than assuming the body was originally unlocked.
- Product imagery may animate locally inside its bounded gallery; those image transitions must not promote or transform the complete modal shell.
- Once Quick View has painted a real gallery for a product/variation identity, late detail hydration must not replace that primary image or change gallery control geometry during the same open interaction. A placeholder may yield to the first real image, but a valid painted media set remains stable until the product/variation identity changes or the modal is reopened.

These rules apply to catalog, homepage/product rails, search, and cart Quick View entry points.

## Product card image decode continuity

`ProductCardImage` keeps a static image bed in the existing fixed media geometry until the current image has completed decoding. Both network load and already-cached load converge on the same decode path; source changes cancel stale pending decode completions, and valid images whose `decode()` rejects may still display when natural dimensions confirm they loaded. The image itself uses only the canonical 220 ms opacity handoff; no individual card translation or stagger is applied on image resolution. This avoids many independent card images sliding into view while scrolling.

These rules apply to storefront product cards across grids and horizontal rails, without changing catalog data or fetch ownership.

## Product gallery media handoff

The active product gallery image is an eager-loaded visual target, including when advancing to an image other than index zero. A deferred `loading="lazy"` hint must not stall the currently selected image. The gallery maintains its reserved media bed/skeleton until that image's source-specific load state resolves; its entrance must not begin from transparent while it is undecoded. The skeleton identity is tied to the image URL, not the ordinal index, because different product/variation media may occupy the same index. Gallery timing consumes canonical JavaScript motion values instead of declaring an independent easing curve. This is a bounded image transition and must not introduce a full-page fade.

## Hero media continuity

Homepage hero image stages remain painted from the first render rather than fading an entire media column in before its eager-loaded image has decoded. Category hero media use image-specific readiness and a reserved skeleton stage; pending decode callbacks and animation frames must be invalidated when the source changes or the component unmounts so stale responses cannot reveal a newly selected image. Category layout, artwork composition and shop CTAs remain owned by their existing feature styles and components. Do not replace either hero with an unconditional page-level opacity animation.

## Mobile Quick View touch and scroll ownership

The product Quick View retains its owning modal body's overflow lock and scrollbar compensation while open, but must not apply `touch-action: none` to `<body>`. Touch-action is constrained through ancestors and can prevent vertical momentum scrolling within the modal itself. The modal scroll shell handles vertical native scrolling; the gallery thumbnail strip owns horizontal `pan-x` gestures. Never suppress native document or modal panning with broad touch handlers merely to prevent backdrop scroll. Confirm this contract on iOS Safari and Android Chrome, including nested horizontal gestures and modal close/reopen.

## Mobile native scrolling and route ownership

Native browser touch/momentum scrolling is the default for the document, catalog rails and independently scrollable overlays. Do not intercept `touchmove`/wheel events or install JavaScript-based smoothing on the root scroll shell. Route changes reset only explicitly marked `[data-route-scroll-container]` elements; generic overflow utility classes are not a route-scroll ownership signal, because they also appear in drawers, product widgets and other independently scrollable components. History POP restoration and hash navigation remain synchronous and use `behavior: 'auto'`, without delayed animated correction. Any scroll-locked modal must preserve the underlying document position and release locks at the owning modal lifecycle boundary.

The mobile navigation panel is a native vertical scroller with contained overscroll. Its full-size surface uses only a small translation and opacity during entrance/exit, never a scale animation. While navigation is open, keyboard Tab navigation remains within the dialog; collapsed brand/taxonomy branches are inert rather than focusable offscreen. On dismissal, focus returns to the opening control if focus has not already moved into a newly opened surface. Product rails use the existing native horizontal CSS scroll snapping and initiate their catalog read when approaching within 280px of the viewport via `IntersectionObserver`; they do not replace native touch momentum.

## Responsive contract

Motion semantics are shared across breakpoints. Mobile differences are limited to interaction geometry where the interaction itself differs.

Mobile navigation and drawers use the shared low-bounce spring. The outer mobile-drawer shell is structural only; the Motion backdrop and drawer own entrance/exit animation so CSS does not double-animate the same surface.

Pending route geometry collapses responsively while retaining the destination's general visual structure.

## Accessibility

`GlobalMotionProvider` uses `reducedMotion="user"`, and the CSS motion authority includes a `prefers-reduced-motion: reduce` safety net.

Reduced motion removes nonessential transforms, smooth scrolling, shimmer animation, and long CSS transitions while preserving state changes, visibility, focus, layout, and loading semantics. Static route pending geometry remains visible because it communicates application state without requiring animation.

## Performance rules

- Prefer opacity and transform for motion.
- Page roots must remain opaque.
- Do not animate page width, expensive layout properties, or large blur values for routine navigation.
- Do not apply universal transitions or `transition: all`.
- Do not stack CSS and Framer Motion transforms on the same interaction surface.
- Route transitions must not delay data fetching.
- Loading skeletons reserve final component geometry.
- Do not disable route code splitting to hide lazy-loading defects; preload likely chunks instead.
- Internal route preloading must remain same-origin and de-duplicated.
- Geometry measurement must batch reads before writes where possible to avoid forced synchronous layout.
- Persistent shell components stay mounted during route transitions.
- The static document boot shell must yield only after a committed React frame; it must never become a route-loading surface.
- Browser-history restoration must remain synchronous in the layout phase and must not add delayed corrective scroll writes.
- Keep `LazyMotion` strict; new animated components use `m` under the shared async feature provider rather than importing the full `motion` component.

## Regression contract

`frontend/tests/renderContinuityContract.test.mjs` statically protects the critical continuity rules: centralized route loading, no legacy route spinner, opaque route motion, the 220 ms async replacement token, absence of root fades on the specifically remediated cart/repair/order-tracking surfaces, history-aware POP restoration, strict LazyMotion usage, the initial HTML-to-React boot handoff, static desktop route composition, atomic desktop mega-menu switching, bounded navigation transitions, desktop product-card paint continuity, and geometry-stable Quick View open/close lifecycle.

This test supplements browser profiling; it does not prove runtime frame pacing. Lighthouse/Chrome Performance traces remain required when changing startup providers, global CSS, large navigation surfaces, or animation-heavy components.

## Implementation rules

New Framer Motion code should consume semantic transitions/variants from `dtbMotion.js`. New CSS should use `--dtb-motion-*` tokens.

When modifying bespoke motion, migrate it to the nearest semantic transition instead of adding one-off timings. Feature-specific motion is acceptable only where the interaction cannot be represented by route, async, content, surface, overlay, direct-manipulation, or exit semantics.

## Homepage campaign hero

The homepage hero is a stable full-bleed stage with manual campaign navigation. `frontend/src/components/home/HomeHero.jsx` owns interaction state; `homeHeroCampaigns.js` contains presentation copy and route destinations only. It must not become an authority for catalog, pricing, inventory, repair, order, or payment state.

The hero intentionally does not auto-advance. Campaign changes occur only after explicit tab, keyboard, or horizontal touch/pen swipe interaction. This preserves reading time and avoids adding pause/stop chrome solely to satisfy moving-content accessibility requirements.

Motion is bounded to the campaign content and active navigation indicator. The stage, responsive `<picture>`, scrim, trust strip, quick links, and surrounding document geometry remain stable. `dtbMotion.js` owns `homeHeroContentVariants`, `homeHeroItemVariants`, reduced-motion equivalents, and the navigation spring.

The current responsive artwork remains the LCP authority until campaign-specific media is produced. Future hero assets must preserve browser-native `<picture>` art direction, a stable text-safe composition, compressed responsive sources, and the existing media/content layer separation rather than introducing JavaScript viewport branching.

`frontend/tests/homeHeroResponsiveMediaContract.test.mjs` protects this contract, including responsive art direction, LCP priority, explicit manual navigation semantics, reduced-motion support, layer ordering, and compressed asset bounds.


### Mobile scroll and input responsiveness (October 2026)

The browser owns the vertical page scroll, horizontal rail inertia, axis arbitration, and overscroll behavior. Keep scroll snapping optional (proximity) on narrow coarse-pointer product rails rather than routing each pointer movement through React state or replacing native momentum with a JavaScript smooth-scroll library. Avoid global `touchmove` interception, synthetic rubber-band physics, per-frame layout writes, and page-wide `will-change`.

Product rail cards do **not** initiate speculative PDP and quick-view fetches on touch `pointerdown`: initial contact can be the start of an inertial swipe. Preserve the existing intent preloading on focus and mouse/pen pointer entry; actual taps still open the existing product interaction. This keeps touch input handling lightweight without changing catalog, cart, modal, or checkout authorities.

Performance acceptance must use production-build mobile traces for interaction delay, main-thread long tasks, stable layout and actual touch frame pacing. The static source contracts are guardrails, not replacements for Safari/Chrome device measurements. Reference material: MDN CSS Scroll Snap, MDN touch-action and overscroll-behavior, and web.dev Interaction to Next Paint optimization guidance.


### Cart motion consistency refinement (October 2026)

The cart page uses the shared `dtbTransition.standard` and `dtbTransition.exit` presets instead of per-row custom durations, easings and index-based stagger. Cart rows retain layout reflow and exit feedback under normal motion settings. Under `prefers-reduced-motion`, the cart suppresses layout projection and positional exits, leaving authoritative cart updates and assistive semantics intact. Animation never implies a successful mutation before the cart context updates.

### Motion-surface audit and follow-up priorities

Source-verified: the app mounts strict LazyMotion with asynchronous domMax features; `StorefrontMobileDrawer` uses `MotionDrawer`, `MotionBackdrop` and reduced-motion support; `ProductModal` uses shared variants and responsive drawer/dialog wrappers; `Cart` uses animated list presence and layout; `RepairPackages` uses motion hover and content; `PageTransition` intentionally does not animate a document-sized route wrapper.

Follow-up inspection and physical-device validation are required for product-card to quick-view shared-element transitions, gallery image transitions, search/filter sheets, toolset selection, repair workflow, schematic hotspots, account tabs and checkout validation feedback. Do not introduce layoutId across route/portal boundaries until the origin/destination image lifecycle, focus, modal ownership, reduced motion and geometry stability are proven. Retain browser-native rails and scrolling.
