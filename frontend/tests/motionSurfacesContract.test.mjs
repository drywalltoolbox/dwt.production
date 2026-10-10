import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const SRC = resolve(dirname(fileURLToPath(import.meta.url)), '../src');
const read = (path) => readFileSync(resolve(SRC, path), 'utf8');

test('one canonical Framer Motion feature provider and semantic presets', () => {
  const app = read('App.jsx');
  const motion = read('motion/dtbMotion.js');
  assert.match(app, /<LazyMotion features=\{loadMotionFeatures\} strict>/);
  assert.match(motion, /export const dtbSpring/);
  assert.match(motion, /export const dtbTransition/);
});

test('quick view retains modal focus containment and connected focus restoration', () => {
  const src = read('components/product/ProductModal.jsx');
  assert.match(src, /document\.addEventListener\('keydown', handler, true\)/);
  assert.match(src, /dialog\.contains\(document\.activeElement\)/);
  assert.match(src, /openerRef\.current\.isConnected/);
  assert.match(src, /useReducedMotion/);
});

test('mobile filters have Framer-owned transitions, dialog semantics, and focus containment', () => {
  const panel = read('components/catalog/FilterPanel.jsx');
  const styles = read('styles/filter-panel.css');
  assert.match(panel, /<MotionPresence initial=\{false\}>/);
  assert.match(panel, /aria-modal="true"/);
  assert.match(panel, /ref=\{closeButtonRef\}/);
  assert.match(panel, /document\.addEventListener\('keydown', onKeyDown, true\)/);
  assert.match(panel, /transition=\{reduceMotion \? reducedTransition : dtbSpring\.gentle\}/);
  assert.match(styles, /\.filter-mobile-overlay-enter,\s*\.filter-mobile-sheet-enter\s*\{[^}]*animation: none/s);
});

test('search suggestions remain reachable from the header input without a false modal boundary', () => {
  const overlay = read('components/storefront/StorefrontSearchOverlay.jsx');
  const css = read('styles/storefront-drawer.css');
  assert.match(overlay, /role="region" aria-hidden=\{!isOpen\} inert=\{!isOpen \? true : undefined\}/);
  assert.doesNotMatch(overlay, /aria-modal=\{isOpen/);
  assert.match(css, /\.storefront-search-overlay\[data-open="true"\] \.storefront-search-overlay__panel/);
});

test('builder workflow and confirmed selections use restrained motion without altering domain selection', () => {
  const landing = read('pages/ToolsetBuilder.jsx');
  const product = read('features/toolset-builder/ToolsetBuilderProductCard.jsx');
  assert.match(landing, /<Motion\.button/);
  assert.match(landing, /whileTap=\{reduceMotion \? undefined/);
  assert.match(product, /<AnimatePresence initial=\{false\}>/);
  assert.match(product, /aria-pressed=\{isSelected\}/);
  assert.match(product, /aria-pressed=\{activeVariationAlreadySelected\}/);
});

test('schematic mobile dialog keeps focus within the card without animating hotspot geometry', () => {
  const src = read('components/schematics-v2/SchematicPartDialog.jsx');
  assert.match(src, /mobileDialogRef/);
  assert.match(src, /document\.addEventListener\('keydown', onKeyDown, true\)/);
  assert.match(src, /opener\.isConnected/);
  assert.match(src, /position: 'absolute'/);
});

test('cart, repair packages and dashboard suppress positional motion when reduced motion is requested', () => {
  const cart = read('pages/Cart.jsx');
  const repairs = read('pages/RepairPackages.jsx');
  const account = read('components/dashboard/AccountHub.jsx');
  assert.match(cart, /layout=\{!reduceMotion\}/);
  assert.match(cart, /dtbTransition\.standard/);
  assert.match(repairs, /whileHover=\{reduceMotion \? undefined/);
  assert.match(account, /useReducedMotion\(\)/);
  assert.match(account, /reduceMotion \? reducedTransition/);
});

test('mobile header never changes class from document scroll and hero uses stable viewport geometry', () => {
  const header = read('components/storefront/StorefrontHeader.jsx');
  const responsive = read('styles/unified-responsive.css');
  const hero = read('styles/home-hero.css');
  assert.doesNotMatch(header, /mobileHeaderScrolled|setMobileHeaderScrolled|window\.scrollY > 24/);
  assert.doesNotMatch(header, /addEventListener\('scroll', schedule/);
  assert.doesNotMatch(responsive, /site-header--mobile-scrolled/);
  assert.match(hero, /@media \(max-width: 1024px\)\s*\{\s*\.home-hero__stage/);
  assert.match(hero, /100svh - var\(--header-height/);
  assert.match(hero, /100vh - var\(--header-height/);
});

test('mobile navigation expansion uses intrinsic Motion layout, not hard-coded menu height caps', () => {
  const header = read('components/storefront/StorefrontHeader.jsx');
  const drawer = read('styles/storefront-drawer.css');
  const taxonomy = read('styles/storefront-navigation-taxonomy.css');
  assert.match(header, /<Motion\.div[\s\S]*?animate=\{\{ height: expanded \? 'auto' : 0/);
  assert.match(header, /inert=\{!expanded\}/);
  assert.doesNotMatch(drawer, /\.storefront-mobile-drawer__brands\.is-expanded\s*\{\s*max-height:/);
  assert.doesNotMatch(taxonomy, /#storefront-mobile-drawer-products\.storefront-mobile-drawer__brands\.is-expanded/);
});
