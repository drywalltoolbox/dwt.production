let navigationPending = false;

function resetNavigationPending() {
  navigationPending = false;
}

if (typeof window !== 'undefined') {
  window.addEventListener('pageshow', resetNavigationPending);
}

/**
 * Convert an internal Drywall Toolbox URL into a React Router route.
 *
 * WordPress/WooCommerce may return canonical production URLs such as
 * `https://drywalltoolbox.com/?product=example`, while a staging build is
 * mounted below `/staging` via BrowserRouter's basename. React Router owns
 * application of that basename; callers must therefore receive an unmounted
 * route such as `/?product=example`, never `/staging/?product=example`.
 *
 * If an internal URL already contains the active PUBLIC_URL mount, strip it
 * before returning the route. This makes the operation idempotent and prevents
 * `/staging/staging/...` navigation. External URLs are returned unchanged.
 */
export function resolveStorefrontUrl(value) {
  const raw = String(value || '').trim();
  if (!raw) return '';

  const publicUrl = String(process.env.PUBLIC_URL || '/')
    .trim()
    .replace(/\/+$/, '');

  try {
    const baseOrigin = typeof window !== 'undefined'
      ? window.location.origin
      : 'https://drywalltoolbox.com';
    const parsed = new URL(raw, baseOrigin);
    const allowedHosts = new Set(['drywalltoolbox.com', 'www.drywalltoolbox.com']);

    if (!allowedHosts.has(parsed.hostname.toLowerCase())) {
      return raw;
    }

    let pathname = parsed.pathname.startsWith('/') ? parsed.pathname : `/${parsed.pathname}`;

    if (publicUrl && publicUrl !== '/' && (pathname === publicUrl || pathname.startsWith(`${publicUrl}/`))) {
      pathname = pathname.slice(publicUrl.length) || '/';
      if (!pathname.startsWith('/')) pathname = `/${pathname}`;
    }

    return `${pathname || '/'}${parsed.search}${parsed.hash}`;
  } catch {
    return raw;
  }
}

/**
 * Navigates outside the React router.
 *
 * Checkout transfers deliberately do not fade or hide the current document. A native
 * WooCommerce checkout request can be delayed by authentication/session convergence;
 * hiding the SPA before the browser commits the destination produces a misleading
 * blank screen while the address bar still shows the cart route. Keep the current
 * document visible and let the browser replace it only after the checkout response is
 * ready to commit.
 */
export function navigateDocument(url, { replace = false } = {}) {
  if (typeof window === 'undefined' || navigationPending) return;

  navigationPending = true;

  const commitNavigation = () => {
    if (replace) {
      window.location.replace(url);
    } else {
      window.location.assign(url);
    }
  };

  commitNavigation();
}
