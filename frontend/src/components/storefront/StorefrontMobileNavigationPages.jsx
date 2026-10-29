import { useEffect, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight, Package } from 'lucide-react';
import '../../styles/storefront-mobile-navigation-pages.css';

function CategoryThumbnail({ src, label }) {
  const [failed, setFailed] = useState(false);
  const showImage = Boolean(src) && !failed;

  return (
    <span className="dtb-menu-pages__thumbnail" aria-hidden="true">
      {showImage ? (
        <img
          src={src}
          alt=""
          loading="lazy"
          decoding="async"
          onError={() => setFailed(true)}
        />
      ) : (
        <Package className="dtb-menu-pages__thumbnail-fallback" size={23} strokeWidth={1.65} />
      )}
    </span>
  );
}

/**
 * In-drawer navigation: category selections push a new menu page instead of
 * expanding inline. The shell, header, cart and search retain their owners.
 */
export default function StorefrontMobileNavigationPages({ isOpen, sections, links, onNavigate }) {
  const [stack, setStack] = useState([]);
  const [direction, setDirection] = useState('forward');
  const contentRef = useRef(null);
  const headingRef = useRef(null);
  const current = stack[stack.length - 1];
  const pageTitle = current?.title || 'Browse Drywall Toolbox';

  useEffect(() => {
    if (isOpen) return;
    setStack([]);
    setDirection('back');
  }, [isOpen]);

  const openPage = (page) => {
    setDirection('forward');
    setStack((previous) => [...previous, page]);
    contentRef.current?.scrollTo({ top: 0, behavior: 'instant' });
    window.requestAnimationFrame(() => headingRef.current?.focus({ preventScroll: true }));
  };
  const back = () => {
    if (!stack.length) return;
    setDirection('back');
    setStack((previous) => previous.slice(0, -1));
    contentRef.current?.scrollTo({ top: 0, behavior: 'instant' });
    window.requestAnimationFrame(() => headingRef.current?.focus({ preventScroll: true }));
  };
  const goTo = (target) => {
    if (target) onNavigate(target);
  };

  const productSection = sections.find((section) => section.id === 'products');
  const secondarySections = sections.filter((section) => section.id !== 'products');
  const rows = current?.items || productSection?.items || sections;
  const showSecondarySections = !current && secondarySections.length > 0;
  return (
    <nav className={`dtb-menu-pages${current ? '' : ' dtb-menu-pages--root'}`} aria-label="Mobile navigation" data-direction={direction}>
      <div className="dtb-menu-pages__heading">
        {current ? (
          <button type="button" className="dtb-menu-pages__back" onClick={back} aria-label="Previous menu">
            <ChevronLeft size={20} aria-hidden="true" /> Back
          </button>
        ) : <span className="dtb-menu-pages__eyebrow">EXPLORE</span>}
        <h2 ref={headingRef} tabIndex={-1} className="dtb-menu-pages__title" key={pageTitle}>{pageTitle}</h2>
        {current?.to && (
          <button className="dtb-menu-pages__all" type="button" onClick={() => goTo(current.to)}>
            View All <ChevronRight size={17} aria-hidden="true" />
          </button>
        )}
      </div>
      <div ref={contentRef} className="dtb-menu-pages__scroll" key={stack.map((p) => p.id).join('/') || 'root'}>
        <div className="dtb-menu-pages__list" key={stack.map((p) => p.id).join('/') || 'root'}>
          {rows.map((item, index) => {
            const hasChildren = Array.isArray(item.items) && item.items.length > 0;
            const label = item.label || item.name;
            const isParentCategory = !current;
            const showThumbnail = isParentCategory || Boolean(item.thumbnail);
            return (
              <button
                type="button"
                key={item.id || item.slug || item.to || `${label}-${index}`}
                className={`dtb-menu-pages__row${showThumbnail ? ' dtb-menu-pages__row--with-image' : ''}`}
                onClick={() => isParentCategory ? goTo(item.to) : hasChildren ? openPage({ id: item.id || item.slug || label, title: label, items: item.items, to: item.to }) : goTo(item.to)}
              >
                {showThumbnail ? (
                  <CategoryThumbnail key={item.thumbnail || item.slug || label} src={item.thumbnail} label={label} />
                ) : null}
                <span className="dtb-menu-pages__row-label">{label}</span>
                {(hasChildren || isParentCategory) && <ChevronRight size={19} strokeWidth={2} aria-hidden="true" />}
              </button>
            );
          })}
          {showSecondarySections && (
            <div className="dtb-menu-pages__secondary">
              <p className="dtb-menu-pages__section-label">MORE FROM DRYWALL TOOLBOX</p>
              {secondarySections.map((item) => (
                <button type="button" className="dtb-menu-pages__row dtb-menu-pages__row--utility"
                  key={item.id} onClick={() => openPage({ id: item.id, title: item.label, items: item.items, to: item.to })}>
                  <span className="dtb-menu-pages__row-label">{item.label}</span>
                  <ChevronRight size={18} strokeWidth={2} aria-hidden="true" />
                </button>
              ))}
              {links.map((item) => (
                <button type="button" className="dtb-menu-pages__row dtb-menu-pages__row--utility"
                  key={item.to} onClick={() => goTo(item.to)}>
                  <span className="dtb-menu-pages__row-label">{item.label}</span>
                  <ChevronRight size={18} strokeWidth={2} aria-hidden="true" />
                </button>
              ))}
            </div>
          )}
        </div>
      </div>
    </nav>
  );
}
