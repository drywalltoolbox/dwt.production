import assert from 'node:assert/strict';
import test from 'node:test';

import { buildCatalogUrl, parseCatalogQuery } from '../src/utils/catalogUrlState.js';
import { buildCatalogFacetsUrl, buildCatalogProductsUrl } from '../src/services/catalogPlatformCache.js';

test('search URLs preserve category filters alongside the submitted query', () => {
  const query = parseCatalogQuery(new URLSearchParams('search=col&display_category=parts'));

  assert.equal(query.search, 'col');
  assert.deepEqual(query.displayCategory, ['parts']);

  const url = new URL(buildCatalogUrl(query), 'https://drywalltoolbox.com');
  assert.equal(url.pathname, '/products');
  assert.equal(url.searchParams.get('search'), 'col');
  assert.equal(url.searchParams.get('display_category'), 'parts');
});

test('search product and facet requests use the same search scope', () => {
  const query = { search: 'parts tapetech', displayCategory: ['parts'], perPage: 24 };
  const products = new URL(buildCatalogProductsUrl(query), 'https://drywalltoolbox.com');
  const facets = new URL(buildCatalogFacetsUrl({ search: query.search }), 'https://drywalltoolbox.com');

  assert.equal(products.searchParams.get('search'), query.search);
  assert.equal(products.searchParams.get('display_category'), 'parts');
  assert.equal(facets.searchParams.get('search'), query.search);
});
