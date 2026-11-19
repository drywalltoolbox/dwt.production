# PageSpeed Insights audit — homepage, 2026-11-19

## Scope and evidence

Audited the supplied PSI report for `https://drywalltoolbox.com/` in both form factors, downloaded the report's embedded Lighthouse JSON read-only, and traced its highest-impact findings into the current frontend and DTB WordPress integration. Lighthouse version: 13.6.0. Mobile: Moto G Power 2022 emulation, 412×823, DPR 1.75. Desktop: 1350×940, DPR 1. Both report runs carry this warning: **“The page loaded too slowly to finish within the time limit. Results may be incomplete.”** The reported mobile LCP is 25.1 s while its cited breakdown totals about 1.75 s; desktop reports LCP 4.5 s while its breakdown totals about 2.27 s. Therefore the precise LCP values and category scores need complete repeat runs; image payload, code paths, accessibility nodes, and network errors remain directly evidenced by this report.

The run has no `loadingExperience` or `originLoadingExperience` data. This is Lighthouse lab evidence only; it does not provide CrUX/field LCP, INP, or CLS for customers. The test is for the homepage and does not represent PDP, category, parts, cart, or checkout routes. No frontend changes or tests were made in this audit.

## Report summary

| Lighthouse result | Mobile | Desktop |
|---|---:|---:|
| Performance | 56 | 41 |
| Accessibility | 97 | 97 |
| Best Practices | 92 | 92 |
| SEO | 100 | 100 |
| Agentic browsing | 100 | 100 |
| First Contentful Paint | 1.8 s | 0.4 s |
| Largest Contentful Paint | 25.1 s* | 4.5 s |
| Speed Index | 7.5 s | 5.7 s |
| Total Blocking Time | 420 ms | 750 ms |
| CLS | 0 | 0.007 |
| Transfer | 6,355 KiB | 8,346 KiB |
| Image transfer | 5,481,504 B / 19 requests | 7,519,634 B / 24 requests |
| CSS transfer | 131,181 B / 9 stylesheets | 131,112 B / 9 stylesheets |
| Script transfer | about 760 KB / 9 scripts | about 760 KB / 9 scripts |

`*` The mobile report is incomplete and its LCP breakdown does not agree with the reported LCP. Confirm with a completed run and a browser trace before attributing the 25.1-second value to a specific cause. CLS is already good in these lab runs. TBT is a Lighthouse lab metric, not field INP. The current good Core Web Vitals targets at the 75th percentile are LCP ≤2.5 s, INP ≤200 ms, and CLS ≤0.1.

## Priority findings

### P0 — Fix image source dimensions and delivery

The clearest, reproducible issue is oversized image delivery. Lighthouse estimates about **2,065 KiB mobile** and **3,996 KiB desktop** savings. The `/Shop by Tool Type/` category images are downloaded at source dimensions despite displaying at roughly 96–264 px:

| Asset | Transfer bytes | Source dimensions | Example displayed size | Finding |
|---|---:|---:|---:|---|
| `finishing-box.webp` | 1,237,484 | 1672×941 | 264×148 mobile; 160×90 desktop | ~1,231 KiB savings mobile; ~1,235 KiB desktop |
| `corner-applicators.webp` | 844,972 | 1254×1254 | 96×96 desktop | ~843 KiB savings |
| `powered-compound-applicators.webp` | 436,578 | 1254×1254 | 96×96 desktop | ~435 KiB savings |
| `automatic-tapers.webp` | 334,122 | 1536×1024 | 222×148 mobile; 144×96 desktop | ~328 KiB mobile; ~332 KiB desktop |
| `automatic-taping-tool-sets.webp` | 277,340 | 1200×1200 | 148×148 mobile; 96×96 desktop | ~274 KiB mobile; ~276 KiB desktop |
| `corner-finshers.webp` | 171,268 | 1536×1024 | 222×148 mobile; 144×96 desktop | ~168–170 KiB |

The exact `finishing-box.webp` bytes and dimensions match the canonical local asset under `products/launch/media/categories/thumbnails/`, so this is not just a CDN transformation issue. The source media itself is too large for the component. The current catalog thumbnail generator contract describes 512×512 transparent assets, but this checked-in image is 1672×941; reconcile the generator/source contract before replacing it so the artwork, crop, aspect ratio, transparency, and visual fit remain intentional.

Also review the homepage brand section assets: the report transfers the TapeTech SVG wrapper at about **1.71 MB** and the USG logo wrapper at about **723 KB** (Columbia is about 264 KB). The local TapeTech SVG is 2.48 MB and contains an embedded base64 raster; USG's SVG contains two embedded raster images. Compact, display-specific homepage brand variants already exist under `frontend/public/brands/home-strip/` (TapeTech is 6.3 KB). Use those only where their visual treatment matches; otherwise create quality-reviewed compact equivalents while retaining the source masters for larger brand presentations. Don't assume that an SVG extension makes a raster-heavy logo lightweight.

The active frontend resolver returns a single fixed WordPress URL, and `ToolTypeTile` renders it as a plain `<img>` without `srcSet`/`sizes`. Generate a small, quality-reviewed responsive family (for example sizes matched to actual card width and device-pixel ratio), serve AVIF/WebP with fallback as required, and use `srcset`/`sizes`. Preserve explicit layout reservation and the current contain/crop behavior. Defer images beyond the visible initial rail area; only the truly visible first item(s) need eager loading. Current home variant sets the first four horizontal rail images to `loading="eager"`.

### P1 — Avoid loading the global chat bundle during initial storefront startup

PSI sees `woocommerce-chat-widget.js` from Google at about **484 KB**, with about **237 KB unused** in this homepage run. It has only a 50-minute cache lifetime; PSI estimates 388 KiB repeat-visit savings. The Google widget and its WordPress storefront adapter also contribute long tasks and forced reflows. The frontend calls `initializeGeminiStorefront()` immediately from the entry point. The DTB bootstrap exposes Google's `gecx_defer_widget_until_interaction` option and defaults the `shouldLoadWidget` configuration to eager unless the option/filter is enabled.

Review the live plugin setting and use the provider's supported defer-until-interaction path where compatible with the customer support requirement. Keep a visible, accessible launch control and let Google's adapter load its widget when activated. Do not proxy, clone, or self-host Google's auth/cart/widget code. PSI also records `agenticapplications.googleapis.com` calls failing with HTTP 400 (`express:retrieveConfig`) and 404 (`express:executeChat`); verify the Google plugin/agent configuration and resolve those errors if chat remains enabled. If the feature is not active for this store, disable its bootstrap. The report establishes failed requests, not their exact configuration cause.

### P1 — Reduce global render-blocking CSS and external font dependencies

- `main.5e950fb0.css` transfers about 112.4 KB; Lighthouse marks 79.0 KB mobile (70.3%) and 82.8 KB desktop (73.7%) unused on the homepage. The CSS is render-blocking, with about 1,126 ms mobile and 223 ms desktop opportunity measured for it.
- The Google Fonts Barlow stylesheet adds about 751 ms mobile / 201 ms desktop to the render-blocking insight. The longest dependency chain also includes Google Symbols at about 2.5 seconds, associated with the widget path.
- The entry stylesheet imports shared drawer, cart sheet, search-card, catalog/product, motion, and other rules globally. Audit actual route and interaction coverage; retain anything needed for menus, cart, dialogs, focus, loading/error, and responsive states, but move truly route-/feature-only CSS behind the existing lazy route/feature boundary. The objective is a smaller initial critical stylesheet, not blind deletion from Lighthouse's single initial-state coverage.
- Self-host the one required Barlow WOFF2 (or select the already local Geist face if design review accepts it), with `font-display: swap` and only a justified preload. This removes the render-blocking remote CSS fetch and its origin dependency. The widget's Google Symbols chain should disappear from initial rendering when the widget is deferred.

### P1 — Reduce render/layout work and JS long tasks

Lighthouse reports 20 mobile / 19 desktop long tasks; TBT is 420 / 750 ms. The main-thread breakdown is about 27.6 / 29.3 seconds, dominated by rendering, style/layout, and other work rather than JS evaluation alone. The homepage renders featured/new arrivals and seven brand product sections with up to ten products per brand. Measure the above-fold DOM and rendering costs; use layout-stable containment or `content-visibility: auto` for distant sections where verified safe, and avoid mounting costly below-fold-only UI until needed without hiding indexable navigation/product content from the rendered page.

Lighthouse points to the React/vendor bundles, `main.d49d06a0.js`, a small chunk that is 93% unused in this route, and the Gemini scripts. Source offsets report forced reflows in `vendor.6af052dd.js`, `main.d49d06a0.js`, and the Gemini runtime. Use production source maps/webpack stats and a Chrome Performance trace to map offsets to source before rewriting code. Batch layout reads before writes and split costly noncritical work across idle/interaction boundaries. Prioritize third-party deferral and the largest measured tasks over generic React memoization.

### P2 — Resolve accessibility, best-practice, and security findings

- **Contrast:** the homepage subtitles “Discover featured tools…” and “Explore the latest additions…” are measured at #71819a on white, ratio 3.95:1, below 4.5:1 for the shown text sizes. Current local token source says `--dtb-muted: #64748b`, which should differ from that measured color. Reconcile the deployed computed style and source/cache before changing the token; then ensure at least 4.5:1 while preserving the restrained visual hierarchy.
- **Image aspect ratio:** Lighthouse flags the hidden boot preloader logo: asset 460×132, HTML attributes 458×128. Align the declared dimensions with the source asset in `frontend/index.html`. This is a small best-practice cleanup and is not a major CWV driver.
- **Console errors:** same Gemini 400/404 requests described above.
- **CSP:** PSI's inspector issue says there is no Content Security Policy in enforcement mode. Local DTB code explicitly leaves CSP off by default. This is a separate security-hardening task, not a performance fix; review active script/style/image origins, begin with report-only monitoring, and enforce through the owning WordPress/security path only after the policy is validated.
- SEO scores 100 in this lab run, but this is not a ranking/indexing guarantee. Accessibility's actual scored failure is the two contrast nodes; other audits that show no score are not applicable/not scored, not a blanket proof of accessibility.

## Positive evidence to preserve

- The LCP request-discovery insight passes: the reported hero image is in the initial document, eager, and `fetchpriority=high`. Source `RepairHero` already uses eager loading for the active home hero and responsive media. Do not add duplicate preloads or lazy-load it.
- CLS is 0 mobile and 0.007 desktop. Preserve reserved hero/card space and do not destabilize image/hero geometry while tuning.
- Root-document observed response is about 1 ms in these test datacenters; no server-response-time issue is shown. This does not replace field TTFB measurement.
- Core web vitals improvements should not degrade keyboard navigation, reduced motion, cart access, AI support availability, image identity, or brand artwork.

## Recommended implementation sequence

1. Reconcile the mobile LCP inconsistency and incomplete-run warning with a complete controlled PSI/Lighthouse capture. Retain trace and network waterfall.
2. Resize/re-encode the six largest category sources and their related thumbnails; publish verified responsive derivatives and wire `srcSet`/`sizes`. Visually compare every changed image at mobile, desktop, and high-DPR.
3. Confirm and configure the supported Gemini defer behavior, then verify the launcher, open/close, cart/auth handoff, and provider errors on production-like routes.
4. Split initial CSS and localize the hero font; measure style coverage across route and interaction states to avoid regressions.
5. Trace long tasks/reflows with source maps; reduce below-fold rendering and expensive layout work based on attributed call stacks.
6. Fix contrast after resolving the production/local color mismatch; correct preloader intrinsic dimensions; evaluate CSP separately with security review.
7. Repeat mobile/desktop PSI and compare exact assets, completed load, LCP breakdown, TBT, transfer, and score. Also measure field CWV by device and expand tests to representative PDP, category/parts, and commerce routes. Use the 75th percentile field targets: LCP ≤2.5 s, INP ≤200 ms, CLS ≤0.1.

## Source references inspected

- `frontend/src/main.jsx` — global styles and immediate Gemini bootstrap.
- `frontend/src/components/catalog/ShopByToolType.jsx`, `frontend/src/utils/categoryThumbnailImages.js` — category image loading and fixed URL selection.
- `frontend/src/pages/Home.jsx`, `frontend/src/components/home/HomeHero.jsx`, `frontend/src/components/RepairHero.jsx` — homepage structure and existing high-priority responsive hero.
- `frontend/src/styles/storefront-sections.css`, `frontend/src/styles/storefront-tokens.css` — subtitle color source.
- `frontend/public/brands/home-strip/` and full brand SVGs — compact homepage logo variants already exist; several full SVG wrappers embed large raster data.
- `drywalltoolbox/wp/wp-content/mu-plugins/dtb-marketing/Gemini/GeminiStorefrontController.php` — provider defer setting/config.
- `drywalltoolbox/wp/wp-content/mu-plugins/dtb-platform/Security/FrontendSecurity.php` — CSP opt-in behavior.

## Official guidance

- [PageSpeed Insights and lab vs field data](https://developers.google.com/speed/docs/insights/v5/about)
- [Core Web Vitals thresholds](https://developers.google.com/search/docs/appearance/core-web-vitals)
- [Responsive image sizing](https://developer.chrome.com/docs/lighthouse/performance/uses-responsive-images)
- [Lighthouse image optimization](https://developer.chrome.com/docs/lighthouse/performance/uses-optimized-images)
- [Render-blocking requests](https://developer.chrome.com/docs/performance/insights/render-blocking)
- [Unused CSS coverage](https://developer.chrome.com/docs/lighthouse/performance/unused-css-rules)
- [Chrome guidance on third-party scripts](https://developer.chrome.com/blog/third-party-scripts)
- [Layout and DOM performance guidance](https://developer.chrome.com/docs/performance/insights/dom-size)
