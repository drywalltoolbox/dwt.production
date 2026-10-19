import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), 'utf8');

test('route intent warming respects pointer dwell, keyboard focus and cleanup', async () => {
  const source = await read('src/components/routing/RouteIntentPreloader.jsx');
  assert.match(source, /HOVER_DWELL_MS = 110/);
  assert.match(source, /addEventListener\('pointerover'/);
  assert.match(source, /addEventListener\('pointerout'/);
  assert.match(source, /addEventListener\('pointerdown'/);
  assert.match(source, /addEventListener\('focusin'/);
  assert.match(source, /event\.button !== 0/);
  assert.match(source, /connection\?\.saveData/);
  assert.match(source, /clearTimeout\(timer\)/);
  assert.match(source, /startsWith\('\/checkout\/'\)/);
});

test('speculative chunks are bounded and navigation bypasses the queue', async () => {
  const source = await read('src/routing/routeModules.js');
  assert.match(source, /MAX_SPECULATIVE_IMPORTS = 2/);
  assert.match(source, /speculativeRequests = new Map\(\)/);
  assert.match(source, /activeSpeculativeImports < MAX_SPECULATIVE_IMPORTS/);
  assert.match(source, /modulePromises\.has\(key\)/);
  assert.match(source, /connection\?\.saveData/);
  assert.match(source, /key === 'checkout' \|\| key === 'checkoutReturn'/);
  assert.match(source, /return lazy\(\(\) => withChunkReload\(key\)\)/);
  assert.match(source, /speculativeRequests\.delete\(task\.key\)/);
});

test('slow lazy routes show geometry-preserving pending surfaces without transient spinner screens', async () => {
  const [fallback, app, productDetail, globalLoading] = await Promise.all([
    read('src/components/routing/RouteLoadingSurface.jsx'),
    read('src/App.jsx'),
    read('src/pages/ProductDetailPage.jsx'),
    read('src/styles/global-loading.css'),
  ]);
  assert.match(app, /<Suspense fallback={<RouteLoadingSurface pathname=\{location\.pathname\} \/>}>/);
  assert.match(fallback, /PENDING_ANNOUNCEMENT_DELAY_MS = 200/);
  assert.match(fallback, /clearTimeout\(timer\)/);
  assert.match(fallback, /dtb-route-pending--\$\{variant\}/);
  assert.match(fallback, /role="status" aria-live="polite"/);
  assert.match(globalLoading, /dtb-route-pending__product/);
  assert.match(globalLoading, /dtb-route-pending__catalog/);
  assert.match(productDetail, /<RouteLoadingSurface pathname=\{location\.pathname\} label="Loading product" \/>/);
  assert.ok(productDetail.indexOf("if (status === 'error')") < productDetail.indexOf("if (status === 'not_found'"));
});

test('product detail warms through the canonical cache and retains cancellation safeguards', async () => {
  const [intent, detail] = await Promise.all([
    read('src/components/routing/RouteIntentPreloader.jsx'),
    read('src/hooks/useProductDetail.js'),
  ]);
  assert.match(intent, /preloadDestinationData\(route\.pathname\)/);
  assert.match(intent, /import\('\.\.\/\.\.\/hooks\/useProductDetail\.js'\)/);
  assert.match(intent, /preloadProductDetail\(slug\)/);
  assert.match(detail, /detailInflight = new Map\(\)/);
  assert.match(detail, /DETAIL_CACHE_TTL/);
  assert.match(detail, /if \(cancelled\) return/);
});
