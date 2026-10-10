import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = (path) => readFile(new URL(`../src/${path}`, import.meta.url), 'utf8');

test('mobile header uses thresholded passive scrolling and retains shell geometry', async () => {
  const [header, responsive, shell] = await Promise.all([
    source('components/storefront/StorefrontHeader.jsx'),
    source('styles/unified-responsive.css'),
    source('styles/storefront-shell.css'),
  ]);

  assert.match(header, /window\.scrollY > 24/);
  assert.match(header, /requestAnimationFrame\(sample\)/);
  assert.match(header, /addEventListener\('scroll', schedule, \{ passive: true \}\)/);
  assert.match(header, /site-header--mobile-scrolled/);
  assert.match(responsive, /site-header--mobile-scrolled \.header-mobile-search-dock/);
  assert.match(shell, /padding-top: var\(--header-height/);
  assert.doesNotMatch(header, /addEventListener\('touchmove'/);
});

test('mobile navigation confines scroll and keyboard focus while preserving existing motion', async () => {
  const [drawer, header, styles] = await Promise.all([
    source('components/storefront/StorefrontMobileDrawer.jsx'),
    source('components/storefront/StorefrontHeader.jsx'),
    source('styles/storefront-drawer.css'),
  ]);

  assert.match(drawer, /<MotionDrawer/);
  assert.match(drawer, /variants=\{navigationDrawerVariants\}/);
  assert.match(drawer, /panelRef\.current\.querySelectorAll\(FOCUSABLE_SELECTOR\)/);
  assert.match(drawer, /event\.key !== 'Tab'/);
  assert.match(drawer, /previouslyFocused\.focus\(\{ preventScroll: true \}\)/);
  assert.match(header, /inert=\{!expanded\}/);
  assert.match(styles, /overscroll-behavior-y: contain/);
  assert.match(styles, /-webkit-overflow-scrolling: touch/);
});

test('storefront rails keep native touch momentum and bounded advance loading', async () => {
  const [rail, css, productRail] = await Promise.all([
    source('components/storefront/StorefrontRail.jsx'),
    source('styles/storefront-sections.css'),
    source('components/storefront/StorefrontProductRail.jsx'),
  ]);

  assert.match(css, /scroll-snap-type:x mandatory/);
  assert.match(css, /scroll-snap-type: x proximity/);
  assert.match(css, /-webkit-overflow-scrolling:touch/);
  assert.match(rail, /scrollBy/);
  assert.match(productRail, /IntersectionObserver/);
  assert.match(productRail, /rootMargin: '280px 0px'/);
  assert.doesNotMatch(productRail, /touchmove|preventDefault\(/);
});

test('mobile card prices are not truncated and remain server-projected values', async () => {
  const [card, styles] = await Promise.all([
    source('components/storefront/StorefrontProductTile.jsx'),
    source('styles/storefront-product-card.css'),
  ]);

  assert.match(card, /salePriceValue < regularPriceValue/);
  assert.match(card, /priceStr/);
  assert.match(styles, /\.dtb-product-card--rail \.dtb-product-card__price-group/);
  assert.match(styles, /flex-wrap: wrap/);
  assert.match(styles, /\.dtb-product-card__compare-price \{ min-width:0;white-space:nowrap; \}/);
  assert.doesNotMatch(card, /fetch\(|axios\./);
});

test('touch rail swipes do not eagerly load product detail or modal bundles', async () => {
  const card = await source('components/storefront/StorefrontProductTile.jsx');
  assert.match(card, /event\.pointerType === 'mouse' \|\| event\.pointerType === 'pen'/);
  assert.match(card, /onPointerEnter=\{handlePointerIntent\}/);
  assert.doesNotMatch(card, /onPointerDown=\{warmProductRoute\}/);
  assert.match(card, /onFocus=\{warmProductRoute\}/);
});
