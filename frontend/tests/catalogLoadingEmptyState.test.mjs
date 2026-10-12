import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const repoRoot = new URL('../../', import.meta.url);

test('catalog empty state stays hidden until the current product request has settled', async () => {
  const source = await readFile(new URL('frontend/src/pages/ProductsCatalogPlatform.jsx', repoRoot), 'utf8');

  assert.ok(source.includes('mappedProducts.length === 0 && !productsError && !itemsLoading'));
});
