import { apiClient } from './client.js';
import { toLegacyProductCardDTO } from '../utils/catalogDtoAdapters.js';
import { getCartCompatibilitySkus, MAX_CART_COMPATIBLE_PARTS } from '../utils/cartCrossSells.js';

const CACHE_TTL_MS = 60 * 1000;
const CACHE_MAX_ENTRIES = 30;
const compatiblePartsCache = new Map();
const compatiblePartsRequests = new Map();

function readCache(key) {
  const entry = compatiblePartsCache.get(key);
  if (!entry) return null;
  if (Date.now() - entry.cachedAt > CACHE_TTL_MS) {
    compatiblePartsCache.delete(key);
    return null;
  }
  compatiblePartsCache.delete(key);
  compatiblePartsCache.set(key, entry);
  return entry.products;
}

function cacheProducts(key, products) {
  compatiblePartsCache.delete(key);
  compatiblePartsCache.set(key, { products, cachedAt: Date.now() });
  while (compatiblePartsCache.size > CACHE_MAX_ENTRIES) {
    compatiblePartsCache.delete(compatiblePartsCache.keys().next().value);
  }
}

function normalizeCompatibleParts(response) {
  if (!Array.isArray(response?.products)) return [];
  return response.products.map((product) => ({
    ...toLegacyProductCardDTO(product),
    price_available: (() => {
      const value = product?.price && typeof product.price === 'object'
        ? product.price.value
        : product?.price;
      return value !== null && value !== undefined && String(value).trim() !== '';
    })(),
    compatible_with_skus: Array.isArray(product?.compatible_with_skus)
      ? product.compatible_with_skus
      : [],
    compatible_part_variations: Array.isArray(product?.compatible_part_variations)
      ? product.compatible_part_variations.map((variation) => ({
        ...variation,
        compatible_with_skus: Array.isArray(variation?.compatible_with_skus)
          ? variation.compatible_with_skus
          : [],
      }))
      : [],
  }));
}

export function getCartCompatibleParts(cartItems = []) {
  const key = getCartCompatibilitySkus(cartItems).slice().sort().join(',');
  if (!key) return Promise.resolve([]);

  const cachedProducts = readCache(key);
  if (cachedProducts !== null) return Promise.resolve(cachedProducts);

  const existingRequest = compatiblePartsRequests.get(key);
  if (existingRequest) return existingRequest;

  const endpoint = `/wp-json/dtb/v1/products/compatible-parts?skus=${encodeURIComponent(key)}&limit=${MAX_CART_COMPATIBLE_PARTS}`;
  const request = apiClient(endpoint, { globalLoading: false })
    .then(normalizeCompatibleParts)
    .then((products) => {
      cacheProducts(key, products);
      return products;
    })
    .finally(() => compatiblePartsRequests.delete(key));

  compatiblePartsRequests.set(key, request);
  return request;
}

export function getCachedCartCompatibleParts(cartItems = []) {
  const key = getCartCompatibilitySkus(cartItems).slice().sort().join(',');
  return key ? readCache(key) : [];
}

export function prefetchCartCompatibleParts(cartItems = []) {
  void getCartCompatibleParts(cartItems).catch(() => undefined);
}
