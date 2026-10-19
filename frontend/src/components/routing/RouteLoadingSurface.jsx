import { useEffect, useState } from 'react';

const PENDING_ANNOUNCEMENT_DELAY_MS = 200;

export default function RouteLoadingSurface({ pathname = '/', label = 'Loading page' }) {
  const [announcePending, setAnnouncePending] = useState(false);
  useEffect(() => {
    setAnnouncePending(false);
    const timer = window.setTimeout(() => setAnnouncePending(true), PENDING_ANNOUNCEMENT_DELAY_MS);
    return () => window.clearTimeout(timer);
  }, [pathname]);

  const variant = /^\/products\/[^/]+(?:\/variations\/[^/]+)?\/?$/.test(pathname) && !pathname.startsWith('/products/brands')
    ? 'product'
    : pathname.startsWith('/products') || pathname.startsWith('/parts') || pathname.startsWith('/category')
      ? 'catalog'
      : 'content';

  return (
    <section className={`dtb-route-pending dtb-route-pending--${variant}`} aria-busy="true" aria-label={label}>
      {variant === 'product' ? (
        <div className="dtb-route-pending__product" aria-hidden="true">
          <div className="dtb-route-pending__block dtb-route-pending__media" />
          <div className="dtb-route-pending__stack">
            <div className="dtb-route-pending__line dtb-route-pending__line--short" />
            <div className="dtb-route-pending__line dtb-route-pending__line--title" />
            <div className="dtb-route-pending__line dtb-route-pending__line--medium" />
            <div className="dtb-route-pending__block dtb-route-pending__panel" />
          </div>
        </div>
      ) : variant === 'catalog' ? (
        <div className="dtb-route-pending__catalog" aria-hidden="true">
          <div className="dtb-route-pending__heading">
            <div className="dtb-route-pending__line dtb-route-pending__line--short" />
            <div className="dtb-route-pending__line dtb-route-pending__line--title" />
          </div>
          <div className="dtb-route-pending__grid">
            {Array.from({ length: 6 }, (_, index) => (
              <div className="dtb-route-pending__block dtb-route-pending__card" key={index} />
            ))}
          </div>
        </div>
      ) : (
        <div className="dtb-route-pending__content" aria-hidden="true">
          <div className="dtb-route-pending__line dtb-route-pending__line--short" />
          <div className="dtb-route-pending__line dtb-route-pending__line--title" />
          <div className="dtb-route-pending__block dtb-route-pending__panel" />
        </div>
      )}
      <span className="sr-only" role="status" aria-live="polite">
        {announcePending ? label : ''}
      </span>
    </section>
  );
}
