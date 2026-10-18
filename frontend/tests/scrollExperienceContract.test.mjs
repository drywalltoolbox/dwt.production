import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  isScrollExperienceEnabled,
  isDocumentScrollLocked,
  scrollMetrics,
} from '../src/utils/scrollExperiencePolicy.js';

const src = resolve(dirname(fileURLToPath(import.meta.url)), '../src');
const read = p => readFileSync(resolve(src, p), 'utf8');

test('checkout and interactive schematic routes cannot enable the shared scroll overlay', () => {
  assert.equal(isScrollExperienceEnabled('/checkout'), false);
  assert.equal(isScrollExperienceEnabled('/checkout/complete'), false);
  assert.equal(isScrollExperienceEnabled('/schematics', '?schematic=14'), false);
  assert.equal(isScrollExperienceEnabled('/schematics', ''), true);
  assert.equal(isScrollExperienceEnabled('/products/123'), true);
  assert.equal(isScrollExperienceEnabled('/'), true);
});

test('progress stays finite and bounded for changing and invalid document geometry', () => {
  assert.deepEqual(scrollMetrics(0, 500, 800), { progress: 0, showTop: false });
  assert.equal(scrollMetrics(600, 1800, 800).progress, 0.6);
  assert.deepEqual(scrollMetrics(Number.NaN, Infinity, 800), { progress: 0, showTop: false });
  assert.equal(scrollMetrics(999999, 1800, 800).progress, 1);
  assert.equal(scrollMetrics(-100, 1800, 800).progress, 0);
  assert.equal(scrollMetrics(2000, 4800, 800).showTop, true);
});

test('document scroll locks take precedence over back-to-top controls', () => {
  const root = { style: { overflow: '' } };
  const body = { style: { overflow: '' } };
  assert.equal(isDocumentScrollLocked(root, body), false);
  root.style.overflow = 'hidden';
  assert.equal(isDocumentScrollLocked(root, body), true);
  root.style.overflow = '';
  body.style.overflow = 'hidden';
  assert.equal(isDocumentScrollLocked(root, body), true);
});

test('shared controller never intercepts touch/wheel or overrides Router scroll history', () => {
  const scroll = read('components/routing/StorefrontScrollExperience.jsx');
  const css = read('styles/storefront-scroll-experience.css');
  const app = read('App.jsx');
  assert.match(app, /history\.scrollRestoration = 'manual'/);
  assert.match(app, /<ScrollToTop \/>/);
  assert.match(app, /<StorefrontScrollExperience \/>/);
  assert.match(scroll, /addEventListener\('scroll', schedule, \{ passive: true \}\)/);
  assert.match(scroll, /resizeObserver\.observe\(document\.body\)/);
  assert.doesNotMatch(scroll, /addEventListener\(['"](?:wheel|touchmove)['"]/);
  assert.doesNotMatch(scroll, /preventDefault\(/);
  assert.doesNotMatch(css, /(?:html|body)\s*\{[^}]*scroll-behavior:/s);
});

test('editorial motion is explicitly opted in; essential storefront surfaces remain unblocked', () => {
  const sections = read('components/storefront/StorefrontSection.jsx');
  const home = read('pages/Home.jsx');
  const css = read('styles/storefront-scroll-experience.css');
  const scroll = read('components/routing/StorefrontScrollExperience.jsx');
  assert.match(sections, /data-dtb-scroll-reveal=\{revealHeading/);
  assert.equal((home.match(/revealHeading/g) || []).length, 2);
  assert.match(scroll, /IntersectionObserver/);
  assert.match(scroll, /observer\.disconnect\(\)/);
  assert.match(scroll, /mutations\.disconnect\(\)/);
  assert.match(css, /prefers-reduced-motion:reduce/);
  assert.doesNotMatch(css, /\.dtb-storefront-product-card[^}]*opacity:\s*0/s);
});
