import { DEFAULT_PUBLIC_SITE_URL } from './siteUrl.js';

function buildCheckoutUrl(path) {
  const origin = typeof window !== 'undefined' && window.location?.origin
    ? window.location.origin
    : DEFAULT_PUBLIC_SITE_URL;
  return new URL(path, origin).toString();
}

/**
 * One canonical WooCommerce checkout, shared by the root storefront and
 * the /staging frontend preview. Preserve the same-origin Woo cart session.
 */
export function getWooCheckoutUrl() {
  return buildCheckoutUrl('/checkout/');
}

/**
 * Direct shared-WordPress fallback used only when the public checkout rewrite
 * is accidentally unavailable. It remains a direct WordPress handoff and is
 * never served by a storefront route rule.
 */
export function getWooCheckoutFallbackUrl() {
  return buildCheckoutUrl('/wp/index.php?pagename=checkout');
}
