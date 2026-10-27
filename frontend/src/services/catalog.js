/**
 * frontend/src/services/catalog.js
 *
 * Single source-of-truth for all product data.
 *
 * Loading strategy — Stale-While-Revalidate (SWR):
 *
 *   1. IndexedDB hit (fresh  < 5 min)  → return instantly, NO network call.
 *   2. IndexedDB hit (stale  < 24 h)   → return instantly, fire background refresh.
 *   3. IndexedDB miss / expired        → fetch from WooCommerce REST API, cache result.
 *
 * This guarantees that every visit after the first loads products with
 * ZERO network wait time — the UI renders immediately from the local cache
 * while fresh data silently updates in the background.
 *
 * All paths return objects in the normalizeProduct() shape from services/api.js.
 */

import {
  fetchProducts as proxyFetchProducts,
  fetchProduct as proxyFetchProduct,
  searchProductsByVariationSku as proxySearchProductsByVariationSku,
} from '../api/products.js';
import { normalizeProduct } from '../services/api.js';
import { toLegacyProductCardDTO } from '../utils/catalogDtoAdapters.js';
import { readCache, writeCache, bustCache, isCacheAvailable } from './productCache.js';
import { fetchCatalogProducts as fetchPlatformProducts } from './catalogPlatformCache.js';

// ─── In-memory promise cache ──────────────────────────────────────────────────
// Prevents duplicate in-flight requests within the same page session.

let _cache  = null;   // Promise<Product[]> once populated this session
let _source = null;   // 'idb-fresh' | 'idb-stale' | 'idb-expired-fallback' | 'api'

export function getCatalogSource() { return _source; }

// ─── Fetchers ─────────────────────────────────────────────────────────────────

/**
 * Fetch all products from the canonical DTB catalog endpoint (all pages).
 * Throws on network or auth error.
 */
async function fetchFromApi() {
  // Public storefront reads use the same canonical catalog endpoint as the PLP.
  // The legacy drywall/v1 proxy requires server credentials and is not a
  // storefront product authority.

  let all   = [];
  let page  = 1;
  const PER = 100;
  // Per-request timeout: abort a page fetch that takes longer than 15 s.
  // Without this, a hanging fetch (slow API cold-start or poor mobile network)
  // keeps loadCatalog() pending forever since apiClient's inflightGetRequests
  // dedup causes all components to wait on the same stalled promise.
  const PAGE_TIMEOUT_MS = 15000;

  let done = false;
  while (!done) {
    const controller = new AbortController();
    const timeoutId  = setTimeout(() => controller.abort(), PAGE_TIMEOUT_MS);
    let batch;
    let result;
    try {
      result = await Promise.race([
        fetchPlatformProducts({ perPage: PER, page, sort: 'popular' }),
        new Promise((_, reject) => {
          controller.signal.addEventListener('abort', () => {
            reject(Object.assign(new Error('Catalog request timed out.'), { name: 'AbortError' }));
          }, { once: true });
        }),
      ]);
      clearTimeout(timeoutId);
      batch = (Array.isArray(result?.items) ? result.items : [])
        .map(toLegacyProductCardDTO)
        .filter(Boolean)
        // WooCommerce /wc/v3/products should never return 'variation' type
        // products in the main list, but guard against misconfigured imports
        // that might leak them through (they would show as confusing duplicates
        // alongside their parent variable product).
        .filter((p) => p.type !== 'variation');
    } catch (pageErr) {
      clearTimeout(timeoutId);
      // Treat an abort (timeout) as a distinct failure type so callers can
      // distinguish it from a generic network error if needed.
      const err = pageErr?.name === 'AbortError'
        ? Object.assign(new Error('Catalog page fetch timed out after 15 s.'), { code: 'timeout' })
        : pageErr;
      if (all.length > 0) break;
      throw err;
    }
    all = all.concat(batch);
    const totalPages = Number(result?.pagination?.totalPages || 0);
    if ((totalPages > 0 && page >= totalPages) || batch.length < PER) { done = true; break; }
    page++;
  }

  // Deduplicate by product ID — guards against products being imported multiple
  // times into WooCommerce (e.g. repeated CSV imports that create duplicate rows).
  const seenIds = new Set();
  return all.filter((p) => {
    if (!p.id || seenIds.has(p.id)) return false;
    seenIds.add(p.id);
    return true;
  });
}

/**
 * Background revalidation — fetch fresh data and update IndexedDB + memory.
 * Never throws; failures are silently swallowed.
 */
function revalidateInBackground() {
  fetchFromApi()
    .then((fresh) => {
      if (!Array.isArray(fresh) || fresh.length === 0) return;
      _source = 'api';
      _cache  = Promise.resolve(fresh);
      writeCache(fresh).catch(() => {});
      console.info(`[catalog] Background revalidated: ${fresh.length} products`);
    })
    .catch((err) => {
      console.warn('[catalog] Background revalidation failed:', err.message);
    });
}

// ─── Cache loader ─────────────────────────────────────────────────────────────

/**
 * Populate (or return already-populated) the product catalog.
 *
 * SWR flow:
 *   fresh IDB → return instantly
 *   stale IDB → return instantly + trigger background refresh
 *   miss/expired → block on API fetch, then cache
 *
 * @returns {Promise<Object[]>}
 */
export function loadCatalog() {
  // Already resolved this session — return the in-memory promise immediately.
  if (_cache) return _cache;

  _cache = (async () => {
    // ── 1. Try IndexedDB ────────────────────────────────────────────────────
    let cached = null;
    if (isCacheAvailable()) {
      cached = await readCache();

      if (cached && !cached.isExpired) {
        _source = cached.isFresh ? 'idb-fresh' : 'idb-stale';
        console.info(
          `[catalog] Served ${cached.data.length} products from IndexedDB ` +
          `(${_source}, age ${Math.round(cached.age / 1000)}s)`
        );

        // If stale, kick off a background refresh so the next navigation is faster.
        if (!cached.isFresh) {
          revalidateInBackground();
        }

        return cached.data;
      }
    }

    // ── 2. IndexedDB miss / expired — fetch from API ────────────────────────
    try {
      const products = await fetchFromApi();
      _source = 'api';
      console.info(`[catalog] Loaded ${products.length} products from WooCommerce REST API`);

      // Persist to IndexedDB for future visits (non-blocking).
      writeCache(products).catch(() => {});

      return products;
    } catch (apiErr) {
      console.error('[catalog] WooCommerce API unavailable:', apiErr.message);
      if (cached?.data?.length) {
        _source = 'idb-expired-fallback';
        console.warn(
          `[catalog] Falling back to expired IndexedDB cache (${cached.data.length} products, age ${Math.round(cached.age / 1000)}s)`
        );
        return cached.data;
      }
      _cache = null; // allow retry on next call
      return [];
    }
  })();

  return _cache;
}

// ─── Public API ──────────────────────────────────────────────────────────────

/**
 * Return all published products (cached).
 * On return visits this resolves instantly from IndexedDB.
 *
 * @returns {Promise<Object[]>}
 */
export async function getProducts() {
  return loadCatalog();
}

/**
 * Pre-warm the catalog cache. Call this at app boot (main.jsx) so data is
 * ready before the user navigates to any product page.
 */
export function prewarmCatalog() {
  loadCatalog().catch(() => {});
}

/**
 * Return a single product by WooCommerce numeric ID or SKU string.
 * Attempts a direct REST API call for numeric IDs when the API is reachable,
 * otherwise searches the cached catalog.
 *
 * @param {string|number} idOrSku
 * @returns {Promise<Object|null>}
 */
export async function getProductById(idOrSku) {
  const key = String(idOrSku);

  // Direct API lookup for numeric IDs (fastest path)
  if (/^\d+$/.test(key)) {
    try {
      const p = await proxyFetchProduct(key);
      if (p) { _source = 'api'; return p; }
    } catch {
      // fall through to catalog search
    }
  }

  // Search cached catalog by ID, SKU, slug, or part_number
  const all = await loadCatalog();
  return (
    all.find(p => String(p.id) === key) ||
    all.find(p => p.slug         === key) ||
    all.find(p => p.sku          === key) ||
    all.find(p => p.part_number  === key) ||
    null
  );
}

function normalizeSearchValue(value) {
  return String(value || '').trim().toLowerCase();
}

function canonicalSearchValue(value) {
  return normalizeSearchValue(value).replace(/[^a-z0-9]/g, '');
}

function isLikelySkuQuery(value) {
  const normalized = normalizeSearchValue(value);
  if (!normalized || normalized.length < 3) return false;
  if (normalized.includes(' ')) return false;
  return /^[a-z0-9._-]+$/i.test(normalized);
}

/**
 * Map a DTB catalog-platform DTO to the flat product shape used by the
 * global header search dropdown and local-catalog search consumers.
 */
function adaptPlatformSearchResult(dto) {
  if (!dto?.id) return null;
  const card = dto?.cardProduct || null;
  const effectivePrice = dto?.price?.effective ?? dto?.price?.current ?? dto?.price?.min ?? card?.price;
  return {
    id: dto.id,
    name: dto.name || '',
    slug: dto.slug || '',
    sku: dto.sku || '',
    brand: dto?.brand?.label || dto?.brandLabel || '',
    image: dto?.media?.image || card?.image || '',
    price: typeof effectivePrice === 'number' ? effectivePrice : parseFloat(String(effectivePrice || '0')),
    min_price: dto?.price?.min ?? null,
    max_price: dto?.price?.max ?? null,
    is_variable: dto?.type === 'variable',
    type: dto?.type || 'simple',
  };
}

function flattenMetaValue(value) {
  if (value == null) return '';
  if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
    return String(value);
  }
  if (Array.isArray(value)) {
    return value.map(flattenMetaValue).filter(Boolean).join(' ');
  }
  if (typeof value === 'object') {
    return Object.values(value).map(flattenMetaValue).filter(Boolean).join(' ');
  }
  return '';
}

function scoreSearchResult(product, q, qCanonical) {
  const name = normalizeSearchValue(product?.name);
  const sku = normalizeSearchValue(product?.sku);
  const partNumber = normalizeSearchValue(product?.part_number);
  const upc = normalizeSearchValue(product?.upc);
  const brand = normalizeSearchValue(product?.brand);
  const slug = normalizeSearchValue(product?.slug);
  const description = normalizeSearchValue(`${product?.short_description || ''} ${product?.description_full || ''}`);
  const metaText = normalizeSearchValue(
    (Array.isArray(product?.meta_data) ? product.meta_data : [])
      .map((entry) => flattenMetaValue(entry?.value))
      .join(' ')
  );
  const categoryText = normalizeSearchValue(
    (Array.isArray(product?.categories) ? product.categories : [])
      .map((category) => category?.name || category?.slug || '')
      .join(' ')
  );

  const skuFields = [sku, partNumber, upc].filter(Boolean);
  const skuCanonicals = skuFields.map((value) => canonicalSearchValue(value));

  if (skuCanonicals.some((value) => value && value === qCanonical)) return 1200;
  if (skuCanonicals.some((value) => value && value.startsWith(qCanonical))) return 1100;
  if (skuFields.some((value) => value.includes(q))) return 1000;
  if (name.startsWith(q)) return 920;
  if (name.includes(q)) return 860;
  if (brand.startsWith(q)) return 820;
  if (brand.includes(q)) return 780;
  if (slug.includes(q)) return 730;
  if (categoryText.includes(q)) return 700;
  if (description.includes(q)) return 650;
  if (metaText.includes(q)) return 620;

  if (qCanonical.length >= 3) {
    const canonicalHaystacks = [
      canonicalSearchValue(name),
      canonicalSearchValue(brand),
      canonicalSearchValue(slug),
      canonicalSearchValue(categoryText),
      canonicalSearchValue(description),
      canonicalSearchValue(metaText),
    ];
    if (canonicalHaystacks.some((value) => value.includes(qCanonical))) return 560;
  }

  return 0;
}

/**
 * Full-text product search across name, SKU, UPC, and brand.
 * Uses the local catalog to avoid per-keystroke API traffic.
 *
 * @param {string} query
 * @returns {Promise<Object[]>}
 */
export async function searchProducts(query) {
  const trimmed = String(query || '').trim();
  if (!trimmed) return getProducts();

  const q = normalizeSearchValue(trimmed);
  const qCanonical = canonicalSearchValue(trimmed);
  const all = await loadCatalog();
  const byId = new Map(all.map((product) => [Number(product?.id || 0), product]));

  const rankedLocalResults = all
    .map((product) => ({ product, score: scoreSearchResult(product, q, qCanonical) }))
    .filter(({ score }) => score > 0)
    .sort((a, b) => {
      if (b.score !== a.score) return b.score - a.score;
      return String(a.product?.name || '').localeCompare(String(b.product?.name || ''));
    })
    .map(({ product }) => product);

  if (rankedLocalResults.length > 0) return rankedLocalResults;

  if (isLikelySkuQuery(trimmed)) {
    try {
      // Exact-SKU fallback against live WooCommerce data.
      // This catches child-variation SKUs that may not exist on parent name fields.
      const live = await proxyFetchProducts({ sku: trimmed, per_page: 20, status: 'publish' });
      const normalized = (Array.isArray(live) ? live : live?.products || [])
        .map(normalizeProduct)
        .filter(Boolean);

      const mappedToParents = normalized.map((item) => {
        if (item.type === 'variation' && item.parent_id) {
          return byId.get(Number(item.parent_id)) || item;
        }
        return item;
      });

      const deduped = [];
      const seen = new Set();
      mappedToParents.forEach((item) => {
        const key = String(item?.id || item?.slug || item?.sku || '');
        if (!key || seen.has(key)) return;
        seen.add(key);
        deduped.push(item);
      });

      if (deduped.length > 0) return deduped;

      // Partial variation-SKU fallback (e.g. "PAHC" token).
      const variationLive = await proxySearchProductsByVariationSku(trimmed, { limit: 24 });
      const variationNormalized = (Array.isArray(variationLive) ? variationLive : variationLive?.products || [])
        .map(normalizeProduct)
        .filter(Boolean);

      const variationDeduped = [];
      const variationSeen = new Set();
      variationNormalized.forEach((item) => {
        const key = String(item?.id || item?.slug || item?.sku || '');
        if (!key || variationSeen.has(key)) return;
        variationSeen.add(key);
        variationDeduped.push(item);
      });

      if (variationDeduped.length > 0) return variationDeduped;
    } catch {
      // Fall through to catalog platform fallback.
    }
  }

  // Final fallback: DTB catalog platform full-text search.
  // Covers queries not resolved by the local WC cache or the legacy variation-SKU
  // endpoints — e.g. SKU prefixes like "FFBS" that only appear on child variations.
  try {
    const platformData = await fetchPlatformProducts({ search: trimmed });
    const platformItems = Array.isArray(platformData?.items) ? platformData.items : [];
    if (platformItems.length > 0) {
      return platformItems.map(adaptPlatformSearchResult).filter(Boolean);
    }
  } catch {
    // Silently swallow — returning [] below is the safe default.
  }

  return [];
}

/**
 * Force a full reload of the catalog (clears both memory and IndexedDB cache).
 * Useful after a WooCommerce product sync.
 */
export function invalidateCatalog() {
  _cache  = null;
  _source = null;
  bustCache().catch(() => {});
}
