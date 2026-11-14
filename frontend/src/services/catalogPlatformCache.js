import { apiClient } from '../api/client.js';
import {
  brandToSlug,
  canonicalDisplayCategorySlug,
  isAllProductsCategorySlug,
  parseCatalogQuery,
} from '../utils/catalogUrlState.js';

const FRESH_CACHE_TTL = 5 * 60 * 1000;
const STALE_CACHE_TTL = 24 * 60 * 60 * 1000;
const NAVIGATION_STALE_CACHE_TTL = 10 * 60 * 1000;
const PRODUCT_CACHE_LIMIT = 24;
const FACETS_CACHE_LIMIT = 16;
const CATEGORY_CACHE_LIMIT = 64;
const NAVIGATION_CACHE_LIMIT = 1;
const CACHE_VERSION = 'v18-explicit-default-variation';
export const CATALOG_FACETS_CONTRACT_VERSION = '2.3';
// Bump the local cache namespace when canonical taxonomy relationships change,
// even when the response shape (contract) remains the same.
const FACETS_CACHE_VERSION = 'v18-contract-2-3-taxonomy';
const CATEGORY_CACHE_VERSION = 'v18-contract-2-3-taxonomy';
const PRODUCT_STORAGE_PREFIX = `dtb:catalog-products:${CACHE_VERSION}:`;
const FACETS_STORAGE_PREFIX = `dtb:catalog-facets:${FACETS_CACHE_VERSION}:`;
const CATEGORY_STORAGE_PREFIX = `dtb:catalog-category:${CATEGORY_CACHE_VERSION}:`;
const NAVIGATION_STORAGE_PREFIX = 'dtb:storefront-navigation:v1:';
const PRODUCT_STORAGE_ROOT = 'dtb:catalog-products:';
const FACETS_STORAGE_ROOT = 'dtb:catalog-facets:';
const CATEGORY_STORAGE_ROOT = 'dtb:catalog-category:';
const NAVIGATION_STORAGE_ROOT = 'dtb:storefront-navigation:';

const productCache = new Map();
const facetsCache = new Map();
const facetsInflight = new Map();
const categoryCache = new Map();
const categoryInflight = new Map();
const navigationCache = new Map();
const catalogReadInflight = new Map();
let obsoleteCatalogStoragePruned = false;

function coalesceCatalogRead(key, options, request) {
  if (options?.signal) return request();
  if (catalogReadInflight.has(key)) return catalogReadInflight.get(key);
  const pending = request().finally(() => catalogReadInflight.delete(key));
  catalogReadInflight.set(key, pending);
  return pending;
}

function canUseStorage() {
  return typeof window !== 'undefined' && Boolean(window.localStorage);
}

function cacheLimitForPrefix(prefix) {
  if (prefix === PRODUCT_STORAGE_PREFIX) return PRODUCT_CACHE_LIMIT;
  if (prefix === FACETS_STORAGE_PREFIX) return FACETS_CACHE_LIMIT;
  if (prefix === NAVIGATION_STORAGE_PREFIX) return NAVIGATION_CACHE_LIMIT;
  return CATEGORY_CACHE_LIMIT;
}

function touchCacheEntry(cache, key, entry, limit) {
  cache.delete(key);
  cache.set(key, entry);
  while (cache.size > limit) {
    cache.delete(cache.keys().next().value);
  }
}

function pruneObsoleteCatalogStorage() {
  if (obsoleteCatalogStoragePruned || !canUseStorage()) return;
  obsoleteCatalogStoragePruned = true;

  const currentPrefixes = new Set([
    PRODUCT_STORAGE_PREFIX,
    FACETS_STORAGE_PREFIX,
    CATEGORY_STORAGE_PREFIX,
    NAVIGATION_STORAGE_PREFIX,
  ]);
  const roots = [PRODUCT_STORAGE_ROOT, FACETS_STORAGE_ROOT, CATEGORY_STORAGE_ROOT, NAVIGATION_STORAGE_ROOT];

  try {
    for (let index = window.localStorage.length - 1; index >= 0; index -= 1) {
      const storageKey = window.localStorage.key(index);
      if (!storageKey || !roots.some((root) => storageKey.startsWith(root))) continue;
      if (!Array.from(currentPrefixes).some((prefix) => storageKey.startsWith(prefix))) {
        window.localStorage.removeItem(storageKey);
      }
    }
  } catch {
    // Persistent caching remains opportunistic if storage is restricted.
  }
}

function prunePersistentEntries(prefix, limit) {
  const entries = [];
  const invalidKeys = [];
  const now = Date.now();

  for (let index = 0; index < window.localStorage.length; index += 1) {
    const storageKey = window.localStorage.key(index);
    if (!storageKey?.startsWith(prefix)) continue;

    try {
      const parsed = JSON.parse(window.localStorage.getItem(storageKey) || 'null');
      const cachedAt = Number(parsed?.cachedAt) || 0;
      if (!cachedAt || now - cachedAt >= STALE_CACHE_TTL) {
        invalidKeys.push(storageKey);
        continue;
      }
      entries.push({ key: storageKey, cachedAt });
    } catch {
      invalidKeys.push(storageKey);
    }
  }

  invalidKeys.forEach((storageKey) => window.localStorage.removeItem(storageKey));
  entries.sort((a, b) => a.cachedAt - b.cachedAt);
  while (entries.length > limit) {
    window.localStorage.removeItem(entries.shift().key);
  }
}

function readPersistent(prefix, key) {
  if (!canUseStorage()) return null;

  try {
    pruneObsoleteCatalogStorage();
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
    pruneObsoleteCatalogStorage();
    window.localStorage.setItem(`${prefix}${key}`, JSON.stringify({ data, cachedAt: Date.now() }));
    prunePersistentEntries(prefix, cacheLimitForPrefix(prefix));
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
    touchCacheEntry(cache, key, memoryEntry, cacheLimitForPrefix(prefix));
    const age = now - memoryEntry.cachedAt;
    if (age < FRESH_CACHE_TTL) {
      return { data: memoryEntry.data, cachedAt: memoryEntry.cachedAt, isStale: false, source: 'memory' };
    }
    if (allowStale && age < STALE_CACHE_TTL) {
      return { data: memoryEntry.data, cachedAt: memoryEntry.cachedAt, isStale: true, source: 'memory' };
    }
  }

  const persistentEntry = readPersistent(prefix, key);
  if (!persistentEntry?.data) return null;

  const persistentAge = now - persistentEntry.cachedAt;
  if (persistentAge >= STALE_CACHE_TTL) {
    removePersistent(prefix, key);
    return null;
  }

  touchCacheEntry(cache, key, persistentEntry, cacheLimitForPrefix(prefix));
  if (persistentAge < FRESH_CACHE_TTL) {
    return { data: persistentEntry.data, cachedAt: persistentEntry.cachedAt, isStale: false, source: 'persistent' };
  }

  return allowStale
    ? { data: persistentEntry.data, cachedAt: persistentEntry.cachedAt, isStale: true, source: 'persistent' }
    : null;
}

function setCacheEntry(cache, key, prefix, data) {
  if (!data) return data;

  const entry = { data, cachedAt: Date.now() };
  touchCacheEntry(cache, key, entry, cacheLimitForPrefix(prefix));
  writePersistent(prefix, key, data);
  return data;
}

function sortedKey(value = {}) {
  return JSON.stringify(Object.entries(value).sort(([a], [b]) => a.localeCompare(b)));
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
        .then((data) => {
          if (data?.contractVersion !== CATALOG_FACETS_CONTRACT_VERSION) {
            throw new Error(
              `Catalog facets API contract mismatch: expected ${CATALOG_FACETS_CONTRACT_VERSION}, received ${data?.contractVersion || 'unknown'}.`,
            );
          }
          return setCacheEntry(facetsCache, key, FACETS_STORAGE_PREFIX, data);
        })
        .finally(() => {
          facetsInflight.delete(key);
        }),
    );
  }

  return facetsInflight.get(key);
}

function normalizeNavigationBrands(value) {
  if (!Array.isArray(value)) return [];
  return value.map((brand) => {
    const label = String(brand?.label || brand?.name || '').trim();
    const slug = String(brand?.slug || '').trim();
    if (!label || !slug) return null;
    return { label, slug };
  }).filter(Boolean);
}

function normalizeStorefrontNavigation(value) {
  if (value?.contractVersion !== 1 || !Array.isArray(value?.shop) || !Array.isArray(value?.brands)) {
    throw new Error('Storefront navigation API contract mismatch.');
  }

  const normalizeGroup = (group) => {
    const label = String(group?.label || '').trim();
    const slug = String(group?.slug || '').trim();
    if (!label || !slug) return null;
    const children = (Array.isArray(group?.children) ? group.children : []).map((child) => {
      const childLabel = String(child?.label || '').trim();
      const childSlug = String(child?.slug || '').trim();
      if (!childLabel || !childSlug) return null;
      return {
        key: String(child?.key || childSlug),
        label: childLabel,
        slug: childSlug,
        sort: Number(child?.sort) || 0,
        description: String(child?.description || ''),
        image: String(child?.image || ''),
      };
    }).filter(Boolean);
    return {
      key: String(group?.key || slug),
      label,
      slug,
      sort: Number(group?.sort) || 0,
      description: String(group?.description || ''),
      image: String(group?.image || ''),
      children,
    };
  };

  const shop = value.shop.map(normalizeGroup).filter(Boolean);
  if (shop.length === 0) throw new Error('Storefront navigation did not include any shop categories.');

  return {
    contractVersion: 1,
    shop,
    brands: normalizeNavigationBrands(value.brands),
    parts: {
      landing: {
        label: String(value?.parts?.landing?.label || 'Browse all replacement parts'),
        href: String(value?.parts?.landing?.href || '/parts'),
      },
      brands: normalizeNavigationBrands(value?.parts?.brands),
    },
  };
}

/** Return the bounded public header navigation cache, including brief stale data. */
export function getCachedStorefrontNavigation({ allowStale = true } = {}) {
  const key = 'public';
  const entry = getCacheEntry(navigationCache, key, NAVIGATION_STORAGE_PREFIX, { allowStale });
  if (!entry) return null;

  if (Date.now() - entry.cachedAt >= NAVIGATION_STALE_CACHE_TTL) {
    navigationCache.delete(key);
    removePersistent(NAVIGATION_STORAGE_PREFIX, key);
    return null;
  }

  return entry;
}

/** Fetch the count-free header navigation once and coalesce concurrent reads. */
export function fetchStorefrontNavigation() {
  const key = 'public';
  const cached = getCachedStorefrontNavigation({ allowStale: false });
  if (cached?.data) return Promise.resolve(cached.data);

  return coalesceCatalogRead(`navigation:${key}`, {}, () => apiClient('/wp-json/dtb/v1/catalog/navigation'))
    .then((payload) => setCacheEntry(
      navigationCache,
      key,
      NAVIGATION_STORAGE_PREFIX,
      normalizeStorefrontNavigation(payload),
    ));
}

export function buildCatalogProductParams(query = {}) {
  // Collection consumers render cards. Detail content is fetched from the
  // dedicated detail route, keeping list/search payloads bounded by contract.
  const params = { projection: 'card' };
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
  if (query.includeTotal === false) params.include_total = 0;
  if (query.sort && query.sort !== 'popular') params.sort = query.sort;
  return params;
}

export function buildCatalogProductsUrl(query = {}) {
  const qs = new URLSearchParams(buildCatalogProductParams(query)).toString();
  return `/wp-json/dtb/v1/catalog/products${qs ? `?${qs}` : ''}`;
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

export function getRenderableCatalogProducts(query = {}, { maxStaleAge = STALE_CACHE_TTL } = {}) {
  const entry = getCachedCatalogProducts(query, { allowStale: true, returnEntry: true });
  if (entry?.isStale && Date.now() - entry.cachedAt > maxStaleAge) return null;
  return entry;
}

/**
 * Fetch authoritative catalog data from the live DTB catalog endpoint.
 * A valid live response may be cached for the short storefront TTL, but static
 * snapshots are never returned from this function.
 */
export function fetchCatalogProducts(query = {}, options = {}) {
  const key = sortedKey(buildCatalogProductParams(query));
  const cached = getCacheEntry(productCache, key, PRODUCT_STORAGE_PREFIX);
  if (cached?.data) return Promise.resolve(cached.data);

  // An abortable request must own its network operation; sharing it would let
  // one caller's cancellation reject another caller's catalog read.
  if (options.signal) {
    return apiClient(buildCatalogProductsUrl(query), { signal: options.signal })
      .then((data) => setCacheEntry(productCache, key, PRODUCT_STORAGE_PREFIX, data));
  }

  return coalesceCatalogRead(`products:${key}`, options, () => apiClient(buildCatalogProductsUrl(query)))
    .then((data) => setCacheEntry(productCache, key, PRODUCT_STORAGE_PREFIX, data));
}

export function fetchCatalogProductDetail(slug, options = {}) {
  const normalizedSlug = String(slug || '').trim();
  if (!normalizedSlug) return Promise.reject(new TypeError('A product slug is required.'));
  return coalesceCatalogRead(`detail:${normalizedSlug.toLowerCase()}`, options, () => apiClient(
    `/wp-json/dtb/v1/catalog/products/${encodeURIComponent(normalizedSlug)}/detail`, options,
  ));
}

export function fetchCatalogProductVariations(productId, options = {}) {
  const normalizedId = String(productId || '').trim();
  if (!/^\d+$/.test(normalizedId) || Number(normalizedId) <= 0) {
    return Promise.reject(new TypeError('A valid product ID is required.'));
  }
  return coalesceCatalogRead(`variations:${normalizedId}`, options, () => apiClient(
    `/wp-json/dtb/v1/catalog/products/${encodeURIComponent(normalizedId)}/variations`, options,
  ));
}

/** Return null only for confirmed 404; propagate catalog and transport failures. */
export async function resolveCatalogProductSku(sku, options = {}) {
  const exactSku = String(sku || '').trim();
  if (!exactSku) return null;
  try {
    return await coalesceCatalogRead(`sku:${exactSku}`, options, () => apiClient(
      `/wp-json/dtb/v1/catalog/resolve-sku?sku=${encodeURIComponent(exactSku)}`, options,
    ));
  } catch (error) {
    // Only the canonical API's explicit missing-item envelope is a confirmed
    // miss. A missing route, proxy/static 404, or unrelated REST 404 is an
    // integration failure and must remain visible to the caller.
    if (Number(error?.status || 0) === 404 && error?.code === 'not_found') return null;
    throw error;
  }
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
  catalogReadInflight.clear();
  facetsCache.clear();
  facetsInflight.clear();
  categoryCache.clear();
  categoryInflight.clear();
  navigationCache.clear();

  if (!canUseStorage()) return;

  try {
    Object.keys(window.localStorage)
      .filter((key) => key.startsWith(PRODUCT_STORAGE_PREFIX) || key.startsWith('dtb:catalog-facets:') || key.startsWith('dtb:catalog-category:') || key.startsWith(NAVIGATION_STORAGE_ROOT))
      .forEach((key) => window.localStorage.removeItem(key));
  } catch {
    // Non-critical cleanup.
  }
}

export function parseCatalogUrlSearch(search = '') {
  return parseCatalogQuery(new URLSearchParams(search));
}
