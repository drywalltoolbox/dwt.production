function getRouteLoadingLabel(pathname = '') {
  if (pathname === '/products' || pathname.startsWith('/products/')) return 'Loading products';
  if (pathname === '/parts') return 'Loading replacement parts';
  if (pathname.startsWith('/repairs')) return 'Loading repair services';
  if (pathname.startsWith('/returns')) return 'Loading returns';
  if (pathname.startsWith('/dashboard')) return 'Loading your account';
  if (pathname === '/cart') return 'Loading your cart';
  return 'Loading page';
}

export default function RouteLoadingSurface({ pathname, label }) {
  const statusLabel = label || getRouteLoadingLabel(pathname);
  const statusText = statusLabel === 'Loading page' ? 'Loading your page…' : `${statusLabel}…`;

  return (
    <section
      className="dtb-route-brand-preloader"
      role="status"
      aria-live="polite"
      aria-busy="true"
      aria-label={statusLabel}
    >
      <div className="dtb-route-brand-preloader__content">
        <div className="dtb-route-brand-preloader__logo" aria-hidden="true">
          <img className="dtb-route-brand-preloader__base" src="/logos/logo-white.svg" alt="" />
          <div className="dtb-route-brand-preloader__fill"><img src="/logos/logo-white.svg" alt="" /></div>
        </div>
        <p>{statusText}</p>
      </div>
    </section>
  );
}
