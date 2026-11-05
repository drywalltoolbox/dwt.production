import { useEffect, useState } from 'react';
import { apiClient } from '../api/client.js';
import { toLegacyProductCardDTO } from '../utils/catalogDtoAdapters.js';
import { getCartCompatibilitySkus, MAX_CART_COMPATIBLE_PARTS } from '../utils/cartCrossSells.js';

const CACHE_TTL_MS = 60 * 1000;
const CACHE_MAX_ENTRIES = 30;
const compatiblePartsCache = new Map();

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

export default function useCartCompatibleParts(cartItems = [], enabled = true) {
  const skus = getCartCompatibilitySkus(cartItems);
  const key = enabled ? skus.join(',') : '';
  const cachedProducts = key ? readCache(key) : null;
  const [state, setState] = useState({ key: '', products: [] });

  useEffect(() => {
    if (!key || cachedProducts !== null) return undefined;

    let active = true;
    const endpoint = `/wp-json/dtb/v1/products/compatible-parts?skus=${encodeURIComponent(key)}&limit=${MAX_CART_COMPATIBLE_PARTS}`;
    apiClient(endpoint)
      .then((response) => {
        const products = Array.isArray(response?.products)
          ? response.products.map((product) => ({
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
          }))
          : [];
        cacheProducts(key, products);
        if (active) setState({ key, products });
      })
      .catch(() => {
        if (active) setState({ key, products: [] });
      });

    return () => {
      active = false;
    };
  }, [cachedProducts, key]);

  if (state.key === key && cachedProducts !== null) return state.products;
  return cachedProducts || [];
}
