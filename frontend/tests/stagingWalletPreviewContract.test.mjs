import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const read = path => readFileSync(new URL(path, import.meta.url), 'utf8');
test('wallet is staging, full-page and opt-in only', () => {
  const page = read('../src/pages/ProductDetailPage.jsx');
  const component = read('../src/components/product/StagingProductWallet.jsx');
  assert.match(page, /StagingProductWallet/);
  assert.match(component, /\/staging\/products\//);
  assert.match(component, /dtbWalletPreview/);
  assert.match(component, /event\.origin !== window\.location\.origin/);
  assert.match(component, /event\.source !== frameRef\.current\?\.contentWindow/);
});
test('quick view and standard purchase panel do not host provider scripts', () => {
  const detail = read('../src/components/product/ProductDetail.jsx');
  const panel = read('../src/components/product/ProductPurchasePanel.jsx');
  assert.doesNotMatch(detail, /StagingProductWallet/);
  assert.doesNotMatch(panel, /StagingProductWallet/);
});
