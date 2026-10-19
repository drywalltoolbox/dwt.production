import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), 'utf8');

test('route loading is centralized and exposes truthful, route-aware pending status', async () => {
  const [app, routeModules, pendingSurface] = await Promise.all([
    read('src/App.jsx'),
    read('src/routing/routeModules.js'),
    read('src/components/routing/RouteLoadingSurface.jsx'),
  ]);

  assert.match(app, /createLazyRoute\('productDetail'\)/);
  assert.match(app, /<RouteIntentPreloader \/>/);
  assert.match(app, /<RouteLoadingSurface pathname=\{location\.pathname\} \/>/);
  assert.doesNotMatch(app, /RouteChunkFallback/);
  assert.match(routeModules, /export function preloadRoute/);
  assert.match(routeModules, /modulePromises = new Map\(\)/);
  assert.match(pendingSurface, /role="status"/);
  assert.match(pendingSurface, /aria-live="polite"/);
  assert.match(pendingSurface, /aria-busy="true"/);
  assert.match(pendingSurface, /getRouteLoadingLabel\(pathname\)/);
  assert.match(pendingSurface, /Loading products/);
});

test('route motion never hides the rendered page and async replacement stays compact', async () => {
  const [motion, tokens, loading, storefrontMotion] = await Promise.all([
    read('src/motion/dtbMotion.js'),
    read('src/styles/storefront-tokens.css'),
    read('src/styles/loading-transitions.css'),
    read('src/styles/storefront-motion.css'),
  ]);

  assert.match(motion, /routeVariants[\s\S]*initial:\s*\{[\s\S]*opacity:\s*1/);
  assert.match(motion, /async:\s*0\.22/);
  assert.match(tokens, /--dtb-motion-duration-async:\s*220ms/);
  assert.match(loading, /--dtb-loading-crossfade-duration:\s*var\(--dtb-motion-duration-async, 220ms\)/);
  assert.match(storefrontMotion, /--dtb-loading-crossfade-duration:\s*var\(--dtb-motion-duration-async\)/);
  assert.doesNotMatch(storefrontMotion, /dtb-card-loading-transition__content[\s\S]{0,160}duration-slow/);
});

test('remediated route roots do not replay opacity-zero page entrances', async () => {
  const [cart, repair, tracking] = await Promise.all([
    read('src/pages/Cart.jsx'),
    read('src/pages/RepairStatus.jsx'),
    read('src/pages/OrderTracking.jsx'),
  ]);

  assert.doesNotMatch(cart, /initial=\{\{ opacity: 0, y: 20 \}\}[\s\S]{0,120}dtb-cart-empty/);
  assert.doesNotMatch(cart, /initial=\{\{ opacity: 0, y: -8 \}\}[\s\S]{0,160}dtb-listing-heading/);
  assert.doesNotMatch(cart, /initial=\{\{ opacity: 0, y: 20 \}\}[\s\S]{0,180}dtb-cart-sheet/);
  assert.doesNotMatch(repair, /initial=\{\{ opacity: 0 \}\}[\s\S]{0,180}dtb-container dtb-container--narrow/);
  assert.doesNotMatch(tracking, /initial=\{\{ opacity: 0, y: 12 \}\}[\s\S]{0,180}dtb-order-tracking-shell/);
});


test('auth and repair entry surfaces render opaque on first paint', async () => {
  const [login, register, forgot, reset, repair] = await Promise.all([
    read('src/pages/Login.jsx'),
    read('src/pages/Register.jsx'),
    read('src/pages/ForgotPassword.jsx'),
    read('src/pages/ResetPassword.jsx'),
    read('src/pages/RepairStatus.jsx'),
  ]);

  for (const source of [login, register, forgot, reset]) {
    assert.doesNotMatch(source, /const cardVariants =/);
    assert.doesNotMatch(source, /variants=\{cardVariants\}/);
  }

  assert.doesNotMatch(repair, /initial=\{\{ opacity: 0, y: 24 \}\}/);
  assert.doesNotMatch(repair, /initial=\{\{ opacity: 0, scale: 0\.96, y: 12 \}\}/);
  assert.doesNotMatch(repair, /initial=\{\{ opacity: 0, y: -10 \}\}/);
});


test('initial boot handoff and history navigation preserve visual continuity', async () => {
  const [app, main, html, pageTransition, motionCss] = await Promise.all([
    read('src/App.jsx'),
    read('src/main.jsx'),
    read('index.html'),
    read('src/components/routing/PageTransition.jsx'),
    read('src/styles/storefront-motion.css'),
  ]);

  assert.match(app, /useNavigationType/);
  assert.match(app, /navigationType === 'POP'/);
  assert.match(app, /scrollPositionsRef = useRef\(new Map\(\)\)/);
  assert.match(app, /lastKnownScrollRef = useRef/);
  assert.match(app, /window\.addEventListener\('scroll', captureScroll/);
  assert.match(app, /scrollPositionsRef\.current\.set\(previousLocation\.key, lastKnownScrollRef\.current\)/);
  assert.match(app, /scrollPositionsRef\.current\.size > 100/);
  assert.match(app, /<LazyMotion features=\{loadMotionFeatures\} strict>/);

  assert.match(main, /requestAnimationFrame\(\(\) => \{[\s\S]*requestAnimationFrame\(\(\) => \{/);
  assert.match(main, /data-dtb-app-mounted/);
  assert.match(main, /getElementById\('dtb-app-boot-shell'\)/);

  assert.match(html, /id="dtb-app-boot-shell"/);
  assert.match(html, /html\[data-dtb-app-mounted="true"\] #dtb-app-boot-shell/);
  assert.match(html, /html\.dtb-checkout-route-booting #dtb-app-boot-shell\{display:none\}/);
  assert.match(html, /prefers-reduced-motion:reduce/);
  assert.match(html, /if \(bootShell\) bootShell\.remove\(\)/);

  assert.match(app, /key=\{location\.pathname\}/);
  assert.match(app, /initialPathname !== location\.pathname/);
  assert.match(pageTransition, /data-route-enter/);
  assert.match(motionCss, /\.dtb-page-transition\[data-route-enter='true'\][\s\S]*animation: dtb-route-enter/);
  assert.match(motionCss, /@keyframes dtb-route-enter[\s\S]*from \{ opacity: 0; \}[\s\S]*to \{ opacity: 1; \}/);
  assert.match(motionCss, /prefers-reduced-motion: reduce[\s\S]*\.dtb-page-transition\[data-route-enter='true'\][\s\S]*animation: none/);
  assert.doesNotMatch(motionCss, /dtb-route-enter[\s\S]{0,220}(?:height|block-size|transform):/);
});


test('route entrance leaves natural page height and compositor geometry intact', async () => {
  const [pageTransition, motionCss] = await Promise.all([
    read('src/components/routing/PageTransition.jsx'),
    read('src/styles/storefront-motion.css'),
  ]);

  assert.match(pageTransition, /data-route-enter=\{animate \? 'true' : undefined\}/);
  assert.doesNotMatch(pageTransition, /willChange:\s*'transform'/);
  assert.doesNotMatch(pageTransition, /backfaceVisibility:\s*'hidden'/);
  assert.match(motionCss, /\.dtb-page-transition\[data-route-enter='true'\]/);
  assert.doesNotMatch(motionCss, /\.dtb-page-transition[\s\S]{0,260}(?:height|block-size):/);
});

test('desktop Quick View keeps geometry stable through open and exit', async () => {
  const [modal, motion, quickViewCss, catalog, trending, rail, searchOverlay, cartSheet] = await Promise.all([
    read('src/components/product/ProductModal.jsx'),
    read('src/motion/dtbMotion.js'),
    read('src/styles/product-quick-view-desktop.css'),
    read('src/pages/ProductsCatalogPlatform.jsx'),
    read('src/components/catalog/TrendingProducts.jsx'),
    read('src/components/storefront/StorefrontProductRail.jsx'),
    read('src/components/storefront/StorefrontSearchOverlay.jsx'),
    read('src/components/storefront/StorefrontCartSheet.jsx'),
  ]);

  assert.match(modal, /scrollbarWidth = Math\.max\(0, window\.innerWidth - document\.documentElement\.clientWidth\)/);
  assert.match(modal, /computedPaddingRight \+ scrollbarWidth/);
  assert.match(modal, /reduceMotion \? 20 : 190/);
  assert.doesNotMatch(modal, /willChange:\s*'transform, opacity'/);
  assert.doesNotMatch(modal, /translateZ\(0\)/);
  assert.doesNotMatch(modal, /contain:\s*layout paint/);
  assert.doesNotMatch(modal, /<style>\{`/);
  assert.doesNotMatch(modal, /layout="position"/);
  assert.match(modal, /<MotionPresence mode="sync" initial>/);
  assert.match(modal, /variants=\{[\s\S]*productModalDesktopCardVariants/);

  assert.match(motion, /productModalDesktopVariants[\s\S]*hidden:\s*\{ opacity: 1 \}[\s\S]*visible:\s*\{ opacity: 1 \}[\s\S]*exit:\s*\{ opacity: 1 \}/);
  assert.doesNotMatch(motion, /productModalDesktopVariants[\s\S]{0,260}scale:/);
  assert.doesNotMatch(motion, /productModalDesktopVariants[\s\S]{0,260}y:/);
  assert.match(motion, /productModalDesktopCardVariants[\s\S]*hidden:\s*\{ opacity: 0, y: dtbDistance\.micro \}[\s\S]*exit:\s*\{[\s\S]*opacity: 1/);
  assert.match(motion, /productModalBackdropTransition = \{[\s\S]*duration: dtbDuration\.fast/);

  assert.match(quickViewCss, /product-modal-scroll-shell[\s\S]*top:\s*0/);
  assert.match(quickViewCss, /product-modal-scroll-inner[\s\S]*min-height:\s*100dvh/);

  for (const owner of [catalog, trending, rail, searchOverlay]) {
    assert.match(owner, /220/);
    assert.match(owner, /setIsModalOpen\(false\)/);
  }

  assert.match(cartSheet, /isProductModalOpen/);
  assert.match(cartSheet, /productModalClearTimerRef/);
  assert.match(cartSheet, /isOpen=\{isProductModalOpen && Boolean\(productModalState\?\.product\)\}/);
});


test('Quick View content does not re-own document geometry or cold-load after intent', async () => {
  const [detail, detailHook, tile, quickViewModules, desktopPolish, gallery, quickViewCss] = await Promise.all([
    read('src/components/product/ProductDetail.jsx'),
    read('src/hooks/useProductDetail.js'),
    read('src/components/storefront/StorefrontProductTile.jsx'),
    read('src/routing/quickViewModules.js'),
    read('src/styles/product-detail-desktop-polish.css'),
    read('src/components/product/ProductImageGallery.jsx'),
    read('src/styles/product-quick-view-desktop.css'),
  ]);

  assert.doesNotMatch(detail, /document\.body\.style\.overflow\s*=\s*'hidden'/);
  assert.doesNotMatch(detail, /document\.body\.style\.paddingRight/);

  assert.match(detailHook, /export function preloadProductDetail/);
  assert.match(detailHook, /detailInflight = new Map\(\)/);
  assert.match(detailHook, /preloadProductDetail\(slug\)/);

  assert.match(tile, /preloadQuickViewModules\(\)/);
  assert.match(tile, /preloadProductDetail\(slug\)/);
  assert.match(quickViewModules, /import\('\.\.\/components\/product\/ProductModal\.jsx'\)/);
  assert.match(quickViewModules, /import\('\.\.\/components\/product\/ProductDetail\.jsx'\)/);

  assert.doesNotMatch(desktopPolish, /product-modal-card-shell\.dtb-product-page-shell/);
  assert.match(gallery, /zIndex:\s*3, pointerEvents:\s*'none'/);
  assert.match(gallery, /stableGalleryRef = useRef/);
  assert.match(gallery, /stableGalleryHasOnlyPlaceholder/);
  assert.match(gallery, /const stableImageMeta = stableGalleryRef\.current\.images/);
  assert.doesNotMatch(gallery, /style=\{\{ zIndex: 2, backfaceVisibility/);
  assert.match(quickViewCss, /product-modal-card-shell \.product-image-gallery__skeleton[\s\S]*animation:\s*none/);
});
