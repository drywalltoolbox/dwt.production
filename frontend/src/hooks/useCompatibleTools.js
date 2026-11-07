import { useEffect, useState } from 'react';
import { apiClient } from '../api/client.js';
import { toLegacyProductCardDTO } from '../utils/catalogDtoAdapters.js';

const COMPATIBLE_TOOLS_CACHE_TTL = 60 * 1000;
const compatibleToolsCache = new Map();

function normalizedSku(sku) {
  return String(sku || '').trim().toUpperCase();
}

function readCachedTools(sku) {
  const entry = compatibleToolsCache.get(sku);
  if (!entry) return null;
  if (Date.now() - entry.cachedAt > COMPATIBLE_TOOLS_CACHE_TTL) {
    compatibleToolsCache.delete(sku);
    return null;
  }
  return entry.products;
}

export default function useCompatibleTools(sku) {
  const key = normalizedSku(sku);
  const cachedProducts = key ? readCachedTools(key) : null;
  const [state, setState] = useState(() => ({
    sku: key,
    products: cachedProducts || [],
    status: key ? (cachedProducts !== null ? 'ready' : 'loading') : 'idle',
  }));

  useEffect(() => {
    if (!key) return undefined;

    let active = true;
    const cached = readCachedTools(key);
    if (cached !== null) return () => { active = false; };

    const endpoint = `/wp-json/dtb/v1/parts/${encodeURIComponent(key)}/compatible-tools`;
    apiClient(endpoint)
      .then((response) => {
        const products = Array.isArray(response?.products)
          ? response.products.map((product) => toLegacyProductCardDTO(product))
          : [];
        compatibleToolsCache.set(key, { products, cachedAt: Date.now() });
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
