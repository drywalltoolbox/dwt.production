/**
 * frontend/src/hooks/useProductDetail.js
 *
 * Fetches the canonical product detail envelope from
 * GET /wp-json/dtb/v1/catalog/products/:slug/detail.
 *
 * Returns:
 *   { product, variations, relatedProducts, computed, status, error }
 *
 * Status values:
 *   'idle'      — no slug provided
 *   'loading'   — fetch in progress
 *   'ready'     — data loaded successfully
 *   'not_found' — server returned 404 or no product envelope
 *   'error'     — network or server error
 */

import { useReducer, useEffect } from 'react';
import { apiClient } from '../api/client.js';

const INIT = { product: null, variations: [], relatedProducts: [], computed: null, status: 'idle', error: null };
const DETAIL_CACHE_TTL = 2 * 60 * 1000;
const detailCache = new Map();
const detailInflight = new Map();

function cacheKey(slug) {
  return String(slug || '').trim().toLowerCase();
}

function getCachedDetail(slug) {
  const entry = detailCache.get(cacheKey(slug));
  if (!entry) return null;
  if ((Date.now() - entry.cachedAt) > DETAIL_CACHE_TTL) {
    detailCache.delete(cacheKey(slug));
    return null;
  }
  return entry.data;
}

function setCachedDetail(slug, data) {
  if (!slug || !data?.product) return;
  detailCache.set(cacheKey(slug), { data, cachedAt: Date.now() });
}

export function preloadProductDetail(slug) {
  const key = cacheKey(slug);
  if (!key) return Promise.resolve(null);

  const cached = getCachedDetail(key);
  if (cached?.product) return Promise.resolve(cached);

  if (!detailInflight.has(key)) {
    const encodedSlug = encodeURIComponent(slug);
    const url = `/wp-json/dtb/v1/catalog/products/${encodedSlug}/detail`;
    detailInflight.set(
      key,
      apiClient(url)
        .then((data) => {
          if (data?.product) setCachedDetail(key, data);
          return data;
        })
        .finally(() => {
          detailInflight.delete(key);
        }),
    );
  }

  return detailInflight.get(key);
}

function reducer(_state, action) {
  switch (action.type) {
    case 'reset':
      return { ...INIT, routeSlug: action.routeSlug, status: 'loading' };
    case 'idle':
      return { ...INIT, routeSlug: action.routeSlug, status: 'idle' };
    case 'ready':
      return {
        routeSlug: action.routeSlug,
        product: action.product,
        variations: action.variations,
        relatedProducts: action.relatedProducts,
        computed: action.computed,
        status: 'ready',
        error: null,
      };
    case 'not_found':
      return { ...INIT, routeSlug: action.routeSlug, status: 'not_found', error: action.error || null };
    case 'error':
      return { ...INIT, routeSlug: action.routeSlug, status: 'error', error: action.error };
    default:
      return _state;
  }
}

function initialStateForSlug(slug) {
  if (!slug) return INIT;
  const cached = getCachedDetail(slug);
  if (!cached?.product) return INIT;
  return {
    product: cached.product,
    variations: Array.isArray(cached.variations) ? cached.variations : [],
    relatedProducts: Array.isArray(cached.relatedProducts) ? cached.relatedProducts : [],
    computed: cached.computed ?? null,
    status: 'ready',
    error: null,
  };
}

export function useProductDetail(slug) {
  const [state, dispatch] = useReducer(reducer, slug, (initialSlug) => ({ ...initialStateForSlug(initialSlug), routeSlug: cacheKey(initialSlug) }));
  // On slug changes, never render the previous product while the new request starts.
  const currentState = state.routeSlug === cacheKey(slug)
    ? state
    : { ...initialStateForSlug(slug), routeSlug: cacheKey(slug) };

  useEffect(() => {
    if (!slug) {
      dispatch({ type: 'idle', routeSlug: cacheKey(slug) });
      return;
    }

    let cancelled = false;
    const cached = getCachedDetail(slug);
    if (cached?.product) {
      dispatch({
        type: 'ready',
        routeSlug: cacheKey(slug),
        product: cached.product,
        variations: Array.isArray(cached.variations) ? cached.variations : [],
        relatedProducts: Array.isArray(cached.relatedProducts) ? cached.relatedProducts : [],
        computed: cached.computed ?? null,
      });
    } else {
      dispatch({ type: 'reset', routeSlug: cacheKey(slug) });
    }

    preloadProductDetail(slug)
      .then((data) => {
        if (cancelled) return;
        if (!data || !data.product) {
          if (cached?.product) return;
          dispatch({ type: 'not_found', routeSlug: cacheKey(slug), error: 'Product not found.' });
          return;
        }
        setCachedDetail(slug, data);
        dispatch({
          type: 'ready',
          routeSlug: cacheKey(slug),
          product: data.product,
          variations: Array.isArray(data.variations) ? data.variations : [],
          relatedProducts: Array.isArray(data.relatedProducts) ? data.relatedProducts : [],
          computed: data.computed ?? null,
        });
      })
      .catch((err) => {
        if (cancelled) return;
        if (cached?.product) return;
        const is404 = err?.status === 404 || /404/.test(err?.message || '');
        dispatch({ type: is404 ? 'not_found' : 'error', routeSlug: cacheKey(slug), error: err?.message || 'Failed to load product.' });
      });

    return () => { cancelled = true; };
  }, [slug]);

  return currentState;
}

export default useProductDetail;
