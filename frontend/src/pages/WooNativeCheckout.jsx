import { useEffect } from 'react';

import SEOHead from '../components/shared/SEOHead.jsx';
import { navigateDocument } from '../utils/documentNavigation.js';
import { getWooCheckoutFallbackUrl, getWooCheckoutUrl } from '../utils/checkoutUrl.js';
import RouteLoadingSurface from '../components/routing/RouteLoadingSurface.jsx';

const HANDOFF_MARKER = 'dtb:native-checkout-handoff:v1';
const HANDOFF_LOOP_WINDOW_MS = 15000;

/**
 * Compatibility route only.
 *
 * Cart CTAs use a full-document link to native WooCommerce checkout. If React
 * Router reaches `/checkout` through client navigation, force document
 * navigation so WordPress/WooCommerce owns the runtime. A one-shot direct
 * WordPress fallback prevents an infinite reload loop if the root rewrite is
 * accidentally serving the SPA at `/checkout/`.
 */
export default function WooNativeCheckout() {
  useEffect(() => {
    let previousHandoff = 0;
    try {
      previousHandoff = Number(window.sessionStorage.getItem(HANDOFF_MARKER) || 0);
    } catch {
      previousHandoff = 0;
    }

    const now = Date.now();
    const likelyRoutingLoop = previousHandoff > 0 && (now - previousHandoff) < HANDOFF_LOOP_WINDOW_MS;

    if (likelyRoutingLoop) {
      try {
        window.sessionStorage.removeItem(HANDOFF_MARKER);
      } catch {
        // Session storage is optional; direct document navigation remains valid.
      }
      navigateDocument(getWooCheckoutFallbackUrl(), { replace: true, transition: 'checkout' });
      return;
    }

    try {
      window.sessionStorage.setItem(HANDOFF_MARKER, String(now));
    } catch {
      // Session storage is optional; the canonical checkout handoff still works.
    }
    navigateDocument(getWooCheckoutUrl(), { replace: true, transition: 'checkout' });
  }, []);

  return (<><SEOHead noindex title="Checkout" /><RouteLoadingSurface label="Preparing your secure checkout…" /></>);
}
