/**
 * frontend/src/hooks/useCatalogFacets.js
 *
 * Fetches scoped catalog facets from GET /wp-json/dtb/v1/catalog/facets.
 * Canonical storefront navigation hierarchy is supplied by the backend-owned
 * `navigationGroups` facet derived from WooCommerce product_cat terms.
 * `displayCategoriesByBrand` remains a merchandising/filtering facet only.
 */

import { useState, useEffect } from 'react';
import {
  fetchCatalogFacets,
  getCachedCatalogFacets,
  invalidateCatalogPlatformCache,
  normalizeCatalogScope,
} from '../services/catalogPlatformCache.js';

export function useCatalogFacets(scope = {}) {
  const normalizedScope = normalizeCatalogScope(scope);
  const scopeKey = JSON.stringify(Object.entries(normalizedScope).sort(([a], [b]) => a.localeCompare(b)));
  const initialCached = getCachedCatalogFacets(normalizedScope, { allowStale: true, returnEntry: true });

  const [facets, setFacets] = useState(() => initialCached?.data || null);
  const [loading, setLoading] = useState(() => !initialCached?.data);
  const [refreshing, setRefreshing] = useState(() => Boolean(initialCached?.isStale));
  const [error, setError] = useState(null);

  useEffect(() => {
    let mounted = true;
    const cached = getCachedCatalogFacets(normalizedScope, { allowStale: true, returnEntry: true });

    if (cached?.data) {
      Promise.resolve().then(() => {
        if (!mounted) return;
        setFacets(cached.data);
        setLoading(false);
        setRefreshing(true);
        setError(null);
      });
    } else {
      Promise.resolve().then(() => {
        if (!mounted) return;
        setFacets(null);
        setLoading(true);
        setRefreshing(false);
        setError(null);
      });
    }

    fetchCatalogFacets(normalizedScope)
      .then((data) => {
        if (!mounted) return;
        setFacets(data);
        setLoading(false);
        setRefreshing(false);
        setError(null);
      })
      .catch((err) => {
        if (!mounted) return;
        setError(err);
        setLoading(false);
        setRefreshing(false);
      });

    return () => { mounted = false; };
  }, [scopeKey]); // eslint-disable-line react-hooks/exhaustive-deps

  return { facets, loading, refreshing, error };
}

/** Invalidate the module-level facets cache (e.g. after an admin action). */
export function invalidateCatalogFacetsCache() {
  invalidateCatalogPlatformCache();
}

export default useCatalogFacets;
