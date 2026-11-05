import test from 'node:test';
import assert from 'node:assert/strict';
import {
  getCartCompatibilitySkus,
  getVisibleCartRecommendations,
  getVisibleCrossSells,
  MAX_CART_COMPATIBLE_PARTS,
  storePrice,
} from '../src/utils/cartCrossSells.js';

test('cart cross-sells exclude cart items, duplicates, unavailable products, and cap output', () => {
  const cart = {
    cross_sells: [
      { id: 5 },
      { id: 6 },
      { id: 6 },
      { id: 0 },
      { id: 7, is_in_stock: false },
      { id: 8, is_purchasable: false },
      { id: 9 },
      { id: 10 },
      { id: 11 },
      { id: 12 },
    ],
  };

  assert.deepEqual(
    getVisibleCrossSells(cart, [{ id: 5 }, { id: 100, variation_id: 200 }]).map((product) => product.id),
    [6, 9, 10, 11],
  );
});

test('cart cross-sells handle absent or malformed server data without throwing', () => {
  assert.deepEqual(getVisibleCrossSells(null), []);
  assert.deepEqual(getVisibleCrossSells({ cross_sells: 'not-an-array' }), []);
  assert.deepEqual(getVisibleCrossSells({ cross_sells: [{ id: 'not-a-product' }] }), []);
});

test('Store API prices respect the currency minor unit', () => {
  assert.equal(storePrice('1298', 2), 12.98);
  assert.equal(storePrice('12.98', 2), 12.98);
  assert.equal(storePrice('1000', 0), 1000);
  assert.equal(storePrice('unavailable', 2), null);
});

test('cart compatibility requests retain exact unique SKUs and stay bounded', () => {
  const cartItems = [
    { sku: '07TT' },
    { sku: '07TT' },
    { sku: '07tt' },
    { sku: 'BAD/SKU' },
    ...Array.from({ length: 22 }, (_, index) => ({ sku: `TOOL-${index}` })),
  ];

  assert.deepEqual(getCartCompatibilitySkus(cartItems), [
    '07TT', '07tt', ...Array.from({ length: 18 }, (_, index) => `TOOL-${index}`),
  ]);
});

test('cart recommendations prefer native cross-sells then exact-fit parts and filter conflicts', () => {
  const recommendations = getVisibleCartRecommendations(
    { cross_sells: [{ id: 2 }] },
    [{ id: 1, sku: '07TT' }],
    [
      { id: 2, compatible_with_skus: ['07TT'] },
      { id: 3, compatible_with_skus: ['07tt'] },
      { id: 4, compatible_with_skus: ['07TT'], stock_status: 'outofstock' },
      { id: 5, compatible_with_skus: ['UNKNOWN'] },
      { id: 6, compatible_with_skus: ['07TT'] },
    ],
  );

  assert.deepEqual(recommendations.map(({ type, product, compatibleWithSku }) => ({
    type,
    id: product.id,
    compatibleWithSku,
  })), [
    { type: 'cross_sell', id: 2, compatibleWithSku: undefined },
    { type: 'compatible_part', id: 6, compatibleWithSku: '07TT' },
  ]);
});

test('cart fitment candidates scan the API maximum before availability and cart filtering', () => {
  assert.equal(MAX_CART_COMPATIBLE_PARTS, 100);

  const candidates = [
    ...Array.from({ length: 12 }, (_, index) => ({
      id: index + 2,
      compatible_with_skus: ['07TT'],
      stock_status: 'outofstock',
    })),
    { id: 20, compatible_with_skus: ['07TT'] },
  ];

  const recommendations = getVisibleCartRecommendations(
    { cross_sells: [] },
    [{ id: 1, sku: '07TT' }],
    candidates,
  );

  assert.deepEqual(recommendations.map(({ product }) => product.id), [20]);
});

test('variable part recommendations require and retain one exact compatible variation', () => {
  const recommendations = getVisibleCartRecommendations(
    { cross_sells: [] },
    [{ id: 1, sku: '03AH' }],
    [
      { id: 10, type: 'variable', compatible_with_skus: ['2AH'], compatible_part_variations: [
        { id: 11, sku: 'AH3-3', permalink: 'https://drywalltoolbox.com/product/anglehead-blades/?attribute_pa_size=3', price: '15.00', compatible_with_skus: ['03AH'], attributes: { attribute_pa_size: '3' } },
      ] },
      { id: 20, type: 'variable', compatible_part_variations: [] },
    ],
  );

  assert.equal(recommendations.length, 1);
  assert.equal(recommendations[0].product.id, 10);
  assert.equal(recommendations[0].compatibleWithSku, '03AH');
  assert.equal(recommendations[0].matchedVariation.id, 11);
  assert.equal(recommendations[0].matchedVariation.price, '15.00');
});
