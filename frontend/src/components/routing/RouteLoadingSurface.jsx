export default function RouteLoadingSurface({ label = 'Loading page' }) {
  return <section className="dtb-route-brand-preloader" role="status" aria-live="polite" aria-busy="true" aria-label={label}><div className="dtb-route-brand-preloader__content"><div className="dtb-route-brand-preloader__logo" aria-hidden="true"><img className="dtb-route-brand-preloader__base" src="/logos/logo-white.svg" alt="" /><div className="dtb-route-brand-preloader__fill"><img src="/logos/logo-white.svg" alt="" /></div></div><p>{label === 'Loading page' ? 'Loading your page…' : label}</p></div></section>;
}
