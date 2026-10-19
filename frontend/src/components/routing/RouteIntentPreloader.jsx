import { useEffect } from 'react';
import { preloadRoute, resolveRouteModuleKey } from '../../routing/routeModules.js';

const HOVER_DWELL_MS = 110;

function preloadDestinationData(pathname) {
  const match = /^\/products\/([^/]+)(?:\/variations\/[^/]+)?\/?$/.exec(pathname);
  if (!match || match[1] === 'brands') return;
  try {
    const slug = decodeURIComponent(match[1]);
    // Product detail uses this same cache when the route mounts.
    void import('../../hooks/useProductDetail.js')
      .then(({ preloadProductDetail }) => preloadProductDetail(slug))
      .catch(() => null);
  } catch {
    // Invalid URI encoding must never interrupt navigation.
  }
}

function getEligibleAnchor(target) {
  if (!(target instanceof Element)) return null;
  const anchor = target.closest('a[href]');
  if (!anchor || anchor.hasAttribute('download')) return null;
  if (anchor.getAttribute('target') && anchor.getAttribute('target') !== '_self') return null;
  if (anchor.getAttribute('rel')?.split(/\s+/).includes('external')) return null;

  try {
    const url = new URL(anchor.href, window.location.href);
    if (url.origin !== window.location.origin) return null;
    if (url.pathname === window.location.pathname) return null;
    // The native WooCommerce checkout must remain a full-document handoff.
    if (url.pathname === '/checkout' || url.pathname.startsWith('/checkout/')) return null;
    return resolveRouteModuleKey(url.pathname) ? { anchor, pathname: url.pathname } : null;
  } catch {
    return null;
  }
}

function shouldDeferSpeculation() {
  const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
  return Boolean(connection?.saveData);
}

export default function RouteIntentPreloader() {
  useEffect(() => {
    if (typeof document === 'undefined') return undefined;

    const hoverTimers = new Map();
    const clearHover = (anchor) => {
      const timer = hoverTimers.get(anchor);
      if (timer !== undefined) {
        clearTimeout(timer);
        hoverTimers.delete(anchor);
      }
    };
    const warm = (target, { preloadData = false } = {}) => {
      if (shouldDeferSpeculation()) return;
      const route = getEligibleAnchor(target);
      if (route) {
        void preloadRoute(route.pathname);
        if (preloadData) preloadDestinationData(route.pathname);
      }
    };
    const onPointerOver = (event) => {
      if (event.pointerType === 'touch' || shouldDeferSpeculation()) return;
      const route = getEligibleAnchor(event.target);
      if (!route || (event.relatedTarget instanceof Node && route.anchor.contains(event.relatedTarget))) return;
      clearHover(route.anchor);
      hoverTimers.set(route.anchor, setTimeout(() => {
        hoverTimers.delete(route.anchor);
        warm(route.anchor);
      }, HOVER_DWELL_MS));
    };
    const onPointerOut = (event) => {
      const anchor = event.target instanceof Element ? event.target.closest('a[href]') : null;
      if (anchor && !(event.relatedTarget instanceof Node && anchor.contains(event.relatedTarget))) clearHover(anchor);
    };
    const onPointerDown = (event) => {
      if (event.button !== 0 || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) return;
      warm(event.target, { preloadData: true });
    };
    const onFocusIn = (event) => warm(event.target, { preloadData: true });

    document.addEventListener('pointerover', onPointerOver, { passive: true });
    document.addEventListener('pointerout', onPointerOut, { passive: true });
    document.addEventListener('pointerdown', onPointerDown, { passive: true });
    document.addEventListener('focusin', onFocusIn);

    return () => {
      document.removeEventListener('pointerover', onPointerOver);
      document.removeEventListener('pointerout', onPointerOut);
      document.removeEventListener('pointerdown', onPointerDown);
      document.removeEventListener('focusin', onFocusIn);
      for (const timer of hoverTimers.values()) clearTimeout(timer);
      hoverTimers.clear();
    };
  }, []);

  return null;
}
