import assert from 'node:assert/strict';
import test from 'node:test';

import { shouldShowCompareAtPrice } from '../src/utils/productPricing.js';

test('a non-sale product does not show a duplicate crossed-out regular price', () => {
  assert.equal(shouldShowCompareAtPrice(24.87, 24.87), false);
});

test('a genuine sale shows its higher regular price as compare-at', () => {
  assert.equal(shouldShowCompareAtPrice(24.87, 29.99), true);
});

test('equal or lower compare-at values are not presented as a discount', () => {
  assert.equal(shouldShowCompareAtPrice(24.87, 24.87), false);
  assert.equal(shouldShowCompareAtPrice(29.99, 24.87), false);
});

test('missing and invalid prices do not render compare-at', () => {
  assert.equal(shouldShowCompareAtPrice(null, 29.99), false);
  assert.equal(shouldShowCompareAtPrice(24.87, null), false);
  assert.equal(shouldShowCompareAtPrice('invalid', 29.99), false);
});
