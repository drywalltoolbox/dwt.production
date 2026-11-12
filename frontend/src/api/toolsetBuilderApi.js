import {
  fetchCatalogProducts,
  fetchCatalogProductVariations,
} from '../services/catalogPlatformCache.js';

const TOOLSET_CATALOG_PAGE_SIZE = 48;

function normalizeBrandFilter(value = '') {
  const brand = String(value || '').trim();
  return brand ? [brand] : [];
}

/**
 * Read builder candidates from the canonical catalog endpoint.
 *
 * This adapter intentionally does not call the legacy /toolsets template APIs.
 * Product eligibility, compatibility, pricing, and final cart validation remain
 * server-owned. During the frontend-first phase this layer only requests the
 * catalog slice needed for the current functional tool family.
 */
export function fetchToolsetProducts({
  toolFamily,
  displayCategory = '',
  brand = '',
  search = '',
  page = 1,
  perPage = TOOLSET_CATALOG_PAGE_SIZE,
  sort = 'popular',
} = {}) {
  if (!toolFamily) {
    return Promise.resolve({
      items: [],
      pagination: { page: 1, perPage, total: 0, totalPages: 1 },
    });
  }

  return fetchCatalogProducts({
    toolFamily,
    displayCategory: displayCategory ? [displayCategory] : [],
    brands: normalizeBrandFilter(brand),
    search,
    page,
    perPage,
    sort,
    productKind: 'tool',
    isParts: 0,
  });
}

export function fetchToolsetVariations(productId) {
  const id = Number(productId);
  if (!Number.isInteger(id) || id <= 0) {
    return Promise.resolve({ productId: 0, variations: [], count: 0 });
  }

  return fetchCatalogProductVariations(id);
}

export function normalizeToolsetSelection(product, variation = null) {
  const selected = variation || product || {};
  const productId = Number(product?.id || 0);
  const variationId = variation ? Number(variation?.id || variation?.variationId || 0) : 0;
  const brand = product?.brand?.label || product?.brandLabel || selected?.brand?.label || '';
  const image = selected?.media?.image
    || selected?.image
    || product?.media?.image
    || product?.cardProduct?.image
    || '';

  const priceValue = selected?.price?.value
    ?? selected?.price
    ?? product?.cardProduct?.price
    ?? product?.price?.value
    ?? null;

  return {
    key: variationId > 0 ? 'variation:' + variationId : 'product:' + productId,
    productId,
    variationId: variationId || null,
    sku: selected?.sku || product?.sku || '',
    name: product?.name || selected?.name || '',
    variationLabel: variation?.variation?.label
      || variation?.variation?.value
      || variation?.variationLabel
      || '',
    brand,
    image,
    price: Number.isFinite(Number(priceValue)) ? Number(priceValue) : null,
    stockStatus: selected?.inventory?.stockStatus
      || selected?.stockStatus
      || selected?.stock_status
      || product?.inventory?.stockStatus
      || 'instock',
    toolFamily: product?.toolFamily || '',
    type: product?.type || 'simple',
  };
}
