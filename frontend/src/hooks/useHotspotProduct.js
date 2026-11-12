/**
 * frontend/src/hooks/useHotspotProduct.js
 *
 * Live per-SKU WooCommerce lookup for a schematic hotspot part, ported from
 * the legacy `Schematics.jsx` hotspot-product effect
 * (docs/_working/frontend-legacy/frontend/src/pages/Schematics.jsx ~L3154-3203).
 *
 * Current schematic responses include a compact product projection. This
 * lookup is retained for backward compatibility with older cached/deployed
 * responses that only carry relationship fields. It uses a module-level cache (so
 * re-opening the same hotspot resolves synchronously with no loading
 * flicker), the same 10s timeout guard against a stalled/failing bootstrap,
 * and the same three-state stock resolution.
 *
 * Uses the canonical exact-SKU catalog endpoint so product and variation SKUs
 * resolve in one request. Temporary failures remain distinct from confirmed
 * missing catalog identities.
 *
 * @param {string|undefined|null} sku
 * @returns {{ product: object|null, stockStatus: 'instock'|'outofstock'|'unknown'|null, isLoading: boolean }}
 *   `stockStatus` is `null` only while the live lookup is in flight — every
 *   other terminal state (found, not found, no sku, error, timeout) resolves
 *   to a non-null string so callers never have to special-case a fourth state.
 */
import { useEffect, useState } from 'react';
import { getHotspotProductBySku } from '../api/products.js';

// Module-level so the cache survives remounts (e.g. Strict Mode double-invoke,
// or re-opening the same hotspot later in the session) — mirrors the legacy
// `_hotspotSkuCache` module-level Map.
const _hotspotSkuCache = new Map();
const LOOKUP_TIMEOUT_MS = 10000;

function computeInitialState(sku) {
  if (!sku) return { product: null, stockStatus: 'unknown', lookupStatus: 'not_found' };
  const cached = _hotspotSkuCache.get(sku);
  return cached || { product: null, stockStatus: null };
}

export function useHotspotProduct(sku) {
  const [state, setState] = useState(() => computeInitialState(sku));

  // Reset synchronously during render when the sku prop changes (e.g. the
  // active hotspot changes to a different part) — mirrors the existing
  // `prevPageId` render-time-reset idiom in DiagramViewer.jsx rather than
  // setting state from inside the effect for a value we can already derive.
  const [prevSku, setPrevSku] = useState(sku);
  if (prevSku !== sku) {
    setPrevSku(sku);
    setState(computeInitialState(sku));
  }

  useEffect(() => {
    // No SKU on this part, or this sku's result is already cached (handled
    // by the render-time reset above) — nothing left for the effect to do.
    if (!sku || _hotspotSkuCache.has(sku)) return undefined;

    let cancelled = false;
    let timedOut = false;
    const controller = new AbortController();

    const timeoutId = setTimeout(() => {
      if (cancelled) return;
      timedOut = true;
      controller.abort();
      const fallback = { product: null, stockStatus: 'unknown', lookupStatus: 'unavailable' };
      setState(fallback);
    }, LOOKUP_TIMEOUT_MS);

    getHotspotProductBySku(sku, { signal: controller.signal })
      .then((wcProduct) => {
        if (cancelled) return;
        clearTimeout(timeoutId);
        const resolved = {
          product: wcProduct || null,
          stockStatus: wcProduct ? (wcProduct.stock_status || 'instock') : 'unknown',
          lookupStatus: wcProduct ? 'found' : 'not_found',
        };
        _hotspotSkuCache.set(sku, resolved);
        setState(resolved);
      })
      .catch(() => {
        if (cancelled || timedOut) return;
        clearTimeout(timeoutId);
        // A failed request is not a confirmed missing product; do not cache it.
        const fallback = { product: null, stockStatus: 'unknown', lookupStatus: 'unavailable' };
        setState(fallback);
      });

    return () => {
      cancelled = true;
      clearTimeout(timeoutId);
      controller.abort();
    };
  }, [sku]);

  return {
    product: state.product,
    stockStatus: state.stockStatus,
    lookupStatus: state.lookupStatus || (state.product ? 'found' : 'not_found'),
    isLoading: state.stockStatus === null,
  };
}
