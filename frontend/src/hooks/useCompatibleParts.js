import { useEffect, useState } from 'react';
import { apiClient } from '../api/client.js';
import { toLegacyProductCardDTO } from '../utils/catalogDtoAdapters.js';

const COMPATIBLE_PARTS_CACHE_TTL = 60 * 1000;
const compatiblePartsCache = new Map();

function normalizedSku(sku) {
  return String(sku || '').trim().toUpperCase();
}

function readCachedParts(sku) {
  const entry = compatiblePartsCache.get(sku);
  if (!entry) return null;
  if (Date.now() - entry.cachedAt > COMPATIBLE_PARTS_CACHE_TTL) {
    compatiblePartsCache.delete(sku);
    return null;
  }
  return entry.products;
}

export default function useCompatibleParts(sku) {
  const key = normalizedSku(sku);
  const cachedProducts = key ? readCachedParts(key) : null;
  const [state, setState] = useState(() => ({
    sku: key,
    products: cachedProducts || [],
    status: key ? (cachedProducts ? 'ready' : 'loading') : 'idle',
  }));

  useEffect(() => {
    if (!key) return undefined;

    let active = true;
    const cached = readCachedParts(key);
    if (cached !== null) return () => { active = false; };

    const endpoint = `/wp-json/dtb/v1/products/${encodeURIComponent(key)}/compatible-parts?limit=12`;
    apiClient(endpoint)
      .then((response) => {
        const products = Array.isArray(response?.products)
          ? response.products.map((product) => toLegacyProductCardDTO(product))
          : [];
        compatiblePartsCache.set(key, { products, cachedAt: Date.now() });
        if (active) setState({ sku: key, products, status: 'ready' });
      })
      .catch(() => {
        if (active) setState({ sku: key, products: [], status: 'error' });
      });

    return () => { active = false; };
  }, [key]);

  if (state.sku !== key) {
    return {
      products: cachedProducts ?? [],
      status: !key ? 'idle' : (cachedProducts !== null ? 'ready' : 'loading'),
    };
  }

  return { products: state.products, status: state.status };
}
