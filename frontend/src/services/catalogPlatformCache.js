import { apiClient } from '../api/client.js';
import {
  brandToSlug,
  canonicalDisplayCategorySlug,
  isAllProductsCategorySlug,
  parseCatalogQuery,
} from '../utils/catalogUrlState.js';

const FRESH_CACHE_TTL = 5 * 60 * 1000;
const STALE_CACHE_TTL = 24 * 60 * 60 * 1000;
const CACHE_VERSION = 'v14-tool-family';
export const CATALOG_FACETS_CONTRACT_VERSION = '2.1';
const FACETS_CACHE_VERSION = 'v15-contract-2-1';
const CATEGORY_CACHE_VERSION = 'v15-contract-2-1';
const PRODUCT_STORAGE_PREFIX = `dtb:catalog-products:${CACHE_VERSION}:`;
const FACETS_STORAGE_PREFIX = `dtb:catalog-facets:${FACETS_CACHE_VERSION}:`;
const CATALOG_SNAPSHOTS_ENABLED = /^(1|true|yes|on)$/i.test(
  String(process.env.REACT_APP_CATALOG_SNAPSHOTS_ENABLED || '').trim(),
);
const PUBLIC_ASSET_BASE = String(process.env.PUBLIC_URL || '').replace(/\/+$/, '');

const CATEGORY_STORAGE_PREFIX = `dtb:catalog-category:${CATEGORY_CACHE_VERSION}:`;

const productCache = new Map();
const productInflight = new Map();
const facetsCache = new Map();
const facetsInflight = new Map();
const snapshotInflight = new Map();
const categoryCache = new Map();
const categoryInflight = new Map();

function canUseStorage() {
  return typeof window !== 'undefined' && Boolean(window.localStorage);
}

function readPersistent(prefix, key) {
  if (!canUseStorage()) return null;

  try {
    const raw = window.localStorage.getItem(`${prefix}${key}`);
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed !== 'object' || !parsed.cachedAt || !parsed.data) return null;
    return parsed;
  } catch {
    return null;
  }
}

function writePersistent(prefix, key, data) {
  if (!canUseStorage() || !data) return;

  try {
    window.localStorage.setItem(`${prefix}${key}`, JSON.stringify({ data, cachedAt: Date.now() }));
  } catch {
    // Persistent cache is opportunistic. Storage quota/private mode failures are non-fatal.
  }
}

function removePersistent(prefix, key) {
  if (!canUseStorage()) return;

  try {
    window.localStorage.removeItem(`${prefix}${key}`);
  } catch {
    // Non-critical cleanup.
  }
}

function getCacheEntry(cache, key, prefix, { allowStale = false } = {}) {
  const now = Date.now();
  const memoryEntry = cache.get(key);

  if (memoryEntry?.data) {
    const age = now - memoryEntry.cachedAt;
    if (age < FRESH_CACHE_TTL) {
      return { data: memoryEntry.data, isStale: false, source: 'memory' };
    }
    if (allowStale && age < STALE_CACHE_TTL) {
      return { data: memoryEntry.data, isStale: true, source: 'memory' };
    }
  }

  const persistentEntry = readPersistent(prefix, key);
  if (!persistentEntry?.data) return null;

  const persistentAge = now - persistentEntry.cachedAt;
  if (persistentAge >= STALE_CACHE_TTL) {
    removePersistent(prefix, key);
    return null;
  }

  cache.set(key, persistentEntry);
  if (persistentAge < FRESH_CACHE_TTL) {
    return { data: persistentEntry.data, isStale: false, source: 'persistent' };
  }

  return allowStale
    ? { data: persistentEntry.data, isStale: true, source: 'persistent' }
    : null;
}

function setCacheEntry(cache, key, prefix, data) {
  if (!data) return data;

  const entry = { data, cachedAt: Date.now() };
  cache.set(key, entry);
  writePersistent(prefix, key, data);
  return data;
}

function sortedKey(value = {}) {
  return JSON.stringify(Object.entries(value).sort(([a], [b]) => a.localeCompare(b)));
}

function normalizePathSegment(value = '') {
  return String(value || '')
    .trim()
    .toLowerCase()
    .replace(/&/g, ' and ')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

function normalizeCategoryAlias(value = '') {
  return String(value || '')
    .trim()
    .toLowerCase()
    .replace(/[\s_]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

function isLiveOnlyCategory(value = '') {
  return ['compound-tubes', 'compound-tube', 'mud-tubes', 'mud-tube', 'cam-lock-tubes', 'camlock-tubes']
    .includes(normalizeCategoryAlias(value));
}

/**
 * `isParts: 1` is the only dedicated storefront scope. The general Products
 * surface intentionally remains unconstrained so brand "All Products" views
 * contain both tools and replacement parts. Tool display-category filters are
 * responsible for excluding parts where the backend domain rules require it.
 */
function normalizePartsScope(value) {
  return Number(value) === 1 ? '1' : null;
}

export function normalizeCatalogScope(scope = {}) {
  const normalized = {};

  if (scope.brand) {
    normalized.brand = brandToSlug(scope.brand) || String(scope.brand);
  }
  if (scope.category) normalized.category = String(scope.category);
  if (scope.search) normalized.search = String(scope.search).trim();
  if (scope.displayCategory && !isAllProductsCategorySlug(scope.displayCategory)) {
    normalized.display_category = String(scope.displayCategory);
  }
  if (scope.productKind) normalized.product_kind = String(scope.productKind);

  const partsScope = normalizePartsScope(scope.isParts);
  if (partsScope !== null) normalized.is_parts = partsScope;

  return normalized;
}

export function buildCatalogFacetsUrl(scope = {}) {
  const params = new URLSearchParams(normalizeCatalogScope(scope));
  const qs = params.toString();
  return `/wp-json/dtb/v1/catalog/facets${qs ? `?${qs}` : ''}`;
}

export function getCachedCatalogFacets(scope = {}, options = {}) {
  const entry = getCacheEntry(
    facetsCache,
    sortedKey(normalizeCatalogScope(scope)),
    FACETS_STORAGE_PREFIX,
    options,
  );
  if (entry?.data?.contractVersion !== CATALOG_FACETS_CONTRACT_VERSION) {
    const key = sortedKey(normalizeCatalogScope(scope));
    facetsCache.delete(key);
    removePersistent(FACETS_STORAGE_PREFIX, key);
    return null;
  }
  return options.returnEntry ? entry : entry?.data || null;
}

export function fetchCatalogFacets(scope = {}) {
  const normalized = normalizeCatalogScope(scope);
  const key = sortedKey(normalized);
  const cached = getCachedCatalogFacets(normalized, { returnEntry: true });
  if (cached?.data) return Promise.resolve(cached.data);

  if (!facetsInflight.has(key)) {
    facetsInflight.set(
      key,
      apiClient(buildCatalogFacetsUrl(normalized))
        .then((data) => data?.contractVersion === CATALOG_FACETS_CONTRACT_VERSION
          ? setCacheEntry(facetsCache, key, FACETS_STORAGE_PREFIX, data)
          : data)
        .finally(() => {
          facetsInflight.delete(key);
        }),
    );
  }

  return facetsInflight.get(key);
}

export function buildCatalogProductParams(query = {}) {
  const params = {};
  if (query.brands && query.brands.length > 0) {
    params.brand = brandToSlug(query.brands[0]);
  }
  if (query.category) params.category = query.category;
  if (Array.isArray(query.displayCategory) && query.displayCategory.length > 0) {
    const slugs = Array.from(new Set(
      query.displayCategory
        .map(canonicalDisplayCategorySlug)
        .filter((slug) => slug && !isAllProductsCategorySlug(slug))
    ));
    if (slugs.length > 0) params.display_category = slugs.join(',');
  }
  if (query.toolFamily) params.tool_family = query.toolFamily;
  if (query.productKind) params.product_kind = query.productKind;
  if (query.builderSlot) params.builder_slot = query.builderSlot;
  if (query.workflowScope) params.workflow_scope = query.workflowScope;

  const partsScope = normalizePartsScope(query.isParts);
  if (partsScope !== null) params.is_parts = Number(partsScope);

  if (query.search) params.search = query.search;
  if (query.page && query.page > 1) params.page = query.page;
  if (query.perPage) params.per_page = query.perPage;
  if (query.sort && query.sort !== 'popular') params.sort = query.sort;
  return params;
}

export function buildCatalogProductsUrl(query = {}) {
  const qs = new URLSearchParams(buildCatalogProductParams(query)).toString();
  return `/wp-json/dtb/v1/catalog/products${qs ? `?${qs}` : ''}`;
}

function buildCatalogSnapshotUrl(query = {}) {
  if (!CATALOG_SNAPSHOTS_ENABLED) return '';

  const params = buildCatalogProductParams(query);
  if (params.search || params.tool_family || params.builder_slot || params.workflow_scope || params.product_kind) {
    return '';
  }
  if (params.display_category && params.display_category.includes(',')) {
    return '';
  }
  if (isLiveOnlyCategory(params.display_category || params.category || '')) {
    return '';
  }

  const page = normalizePathSegment(params.page || 1) || '1';
  const sort = params.sort ? normalizePathSegment(params.sort) : '';
  const suffix = sort ? `-${sort}` : '';

  if (params.is_parts === 1 || params.is_parts === '1') {
    return `${PUBLIC_ASSET_BASE}/catalog-snapshots/parts/page-${page}${suffix}.json`;
  }

  const brand = normalizePathSegment(params.brand || '');
  const category = normalizePathSegment(params.display_category || params.category || '');

  if (brand && category) {
    return `${PUBLIC_ASSET_BASE}/catalog-snapshots/brands/${brand}/categories/${category}/page-${page}${suffix}.json`;
  }

  if (brand) {
    return `${PUBLIC_ASSET_BASE}/catalog-snapshots/brands/${brand}/page-${page}${suffix}.json`;
  }

  if (category) {
    return `${PUBLIC_ASSET_BASE}/catalog-snapshots/categories/${category}/page-${page}${suffix}.json`;
  }

  return `${PUBLIC_ASSET_BASE}/catalog-snapshots/products/page-${page}${suffix}.json`;
}

async function fetchJsonIfPresent(url) {
  if (!url || typeof window === 'undefined') return null;

  const absoluteUrl = new URL(url, window.location.origin).toString();
  const response = await fetch(absoluteUrl, {
    method: 'GET',
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
    cache: 'force-cache',
  }).catch(() => null);

  if (!response || !response.ok) return null;
  return response.json().catch(() => null);
}

export function getCachedCatalogProducts(query = {}, options = {}) {
  const entry = getCacheEntry(
    productCache,
    sortedKey(buildCatalogProductParams(query)),
    PRODUCT_STORAGE_PREFIX,
    options,
  );
  return options.returnEntry ? entry : entry?.data || null;
}

export function getRenderableCatalogProducts(query = {}) {
  return getCachedCatalogProducts(query, { allowStale: true, returnEntry: true });
}

/**
 * Static snapshots are bootstrap-only presentation data. They are deliberately
 * kept out of the authoritative live product cache so an old generated file can
 * never suppress the subsequent REST refresh or become the source of pagination
 * totals/filter truth.
 */
export function fetchCatalogProductSnapshot(query = {}) {
  const url = buildCatalogSnapshotUrl(query);
  if (!url) return Promise.resolve(null);

  const key = `snapshot:${url}`;
  if (!snapshotInflight.has(key)) {
    snapshotInflight.set(
      key,
      fetchJsonIfPresent(url)
        .finally(() => {
          snapshotInflight.delete(key);
        }),
    );
  }

  return snapshotInflight.get(key);
}

/**
 * Fetch authoritative catalog data from the live DTB catalog endpoint.
 * A valid live response may be cached for the short storefront TTL, but static
 * snapshots are never returned from this function.
 */
export function fetchCatalogProducts(query = {}) {
  const key = sortedKey(buildCatalogProductParams(query));
  const cached = getCacheEntry(productCache, key, PRODUCT_STORAGE_PREFIX);
  if (cached?.data) return Promise.resolve(cached.data);

  if (!productInflight.has(key)) {
    productInflight.set(
      key,
      apiClient(buildCatalogProductsUrl(query))
        .then((data) => setCacheEntry(productCache, key, PRODUCT_STORAGE_PREFIX, data))
        .finally(() => {
          productInflight.delete(key);
        }),
    );
  }

  return productInflight.get(key);
}

export function buildCatalogCategoryMetaUrl(slug) {
  return `/wp-json/dtb/v1/catalog/category?slug=${encodeURIComponent(String(slug || ''))}`;
}

/** Fetch term-level metadata (hero/description/breadcrumb/children) for a product_cat slug. */
export function fetchCatalogCategory(slug) {
  const key = String(slug || '').trim();
  if (!key) return Promise.resolve(null);

  const cached = getCacheEntry(categoryCache, key, CATEGORY_STORAGE_PREFIX);
  if (cached?.data) return Promise.resolve(cached.data);

  if (!categoryInflight.has(key)) {
    categoryInflight.set(
      key,
      apiClient(buildCatalogCategoryMetaUrl(key))
        .then((data) => setCacheEntry(categoryCache, key, CATEGORY_STORAGE_PREFIX, data))
        .finally(() => {
          categoryInflight.delete(key);
        }),
    );
  }

  return categoryInflight.get(key);
}

export function invalidateCatalogPlatformCache() {
  productCache.clear();
  productInflight.clear();
  facetsCache.clear();
  facetsInflight.clear();
  snapshotInflight.clear();
  categoryCache.clear();
  categoryInflight.clear();

  if (!canUseStorage()) return;

  try {
    Object.keys(window.localStorage)
      .filter((key) => key.startsWith(PRODUCT_STORAGE_PREFIX) || key.startsWith('dtb:catalog-facets:') || key.startsWith('dtb:catalog-category:'))
      .forEach((key) => window.localStorage.removeItem(key));
  } catch {
    // Non-critical cleanup.
  }
}

export function parseCatalogUrlSearch(search = '') {
  return parseCatalogQuery(new URLSearchParams(search));
}
