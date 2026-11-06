import { useState, useEffect, useRef } from 'react';
import {
  fetchCatalogProductSnapshot,
  fetchCatalogProducts,
  getRenderableCatalogProducts,
} from '../services/catalogPlatformCache.js';

const DEFAULT_PAGINATION = { page: 1, perPage: 25, total: 0, totalPages: 0 };

function normalizeItems(data) {
  return Array.isArray(data?.items) ? data.items : [];
}

function normalizePagination(data) {
  return data?.pagination ?? DEFAULT_PAGINATION;
}

export function useCatalogProducts(query = {}, options = {}) {
  const enabled = options.enabled !== false;
  const initialCached = enabled ? getRenderableCatalogProducts(query) : null;
  const [items, setItems] = useState(() => normalizeItems(initialCached?.data));
  const [pagination, setPagination] = useState(() => normalizePagination(initialCached?.data));
  const [loading, setLoading] = useState(() => enabled && !initialCached);
  const [refreshing, setRefreshing] = useState(() => enabled && Boolean(initialCached?.isStale));
  const [cacheSource, setCacheSource] = useState(() => initialCached?.source || 'none');
  const [error, setError] = useState(null);

  const queryKey = JSON.stringify({ query, enabled });
  const prevKey = useRef(null);
  const hasRenderableItems = useRef(Boolean(initialCached?.data));

  useEffect(() => {
    if (queryKey === prevKey.current) return undefined;
    prevKey.current = queryKey;
    if (!enabled) return undefined;

    let cancelled = false;
    const cached = getRenderableCatalogProducts(query);

    if (cached?.data) {
      Promise.resolve().then(() => {
        if (cancelled) return;
        setItems(normalizeItems(cached.data));
        setPagination(normalizePagination(cached.data));
        setLoading(false);
        setRefreshing(true);
        setCacheSource(cached.source || 'cache');
        setError(null);
        hasRenderableItems.current = true;
      });
    } else {
      Promise.resolve().then(() => {
        if (cancelled) return;
        setLoading(true);
        setRefreshing(false);
        setCacheSource('none');
        setError(null);
      });
    }

    const load = async () => {
      try {
        if (!cached?.data) {
          const snapshot = await fetchCatalogProductSnapshot(query);
          if (!cancelled && snapshot) {
            setItems(normalizeItems(snapshot));
            setPagination(normalizePagination(snapshot));
            setLoading(false);
            setRefreshing(true);
            setCacheSource('snapshot');
            setError(null);
            hasRenderableItems.current = true;
          }
        }

        const data = await fetchCatalogProducts(query);
        if (cancelled) return;
        setItems(normalizeItems(data));
        setPagination(normalizePagination(data));
        setLoading(false);
        setRefreshing(false);
        setCacheSource('network');
        setError(null);
        hasRenderableItems.current = true;
      } catch (err) {
        if (cancelled) return;
        setError(err);
        setLoading(false);
        setRefreshing(false);
      }
    };

    const delay = hasRenderableItems.current ? 120 : 0;
    const timer = delay > 0 ? setTimeout(load, delay) : null;
    if (!timer) load();

    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
    };
  }, [queryKey]); // eslint-disable-line react-hooks/exhaustive-deps

  if (!enabled) {
    return {
      items: [],
      pagination: DEFAULT_PAGINATION,
      loading: false,
      refreshing: false,
      cacheSource: 'disabled',
      error: null,
    };
  }

  return { items, pagination, loading, refreshing, cacheSource, error };
}

export default useCatalogProducts;
