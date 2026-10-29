import { useEffect, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import '../../styles/storefront-mobile-navigation-pages.css';

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

  const rows = current?.items || sections;
  return (
    <nav className="dtb-menu-pages" aria-label="Mobile navigation" data-direction={direction}>
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
            return (
              <button
                type="button"
                key={item.id || item.slug || item.to || `${label}-${index}`}
                className="dtb-menu-pages__row"
                onClick={() => hasChildren ? openPage({ id: item.id || item.slug || label, title: label, items: item.items, to: item.to }) : goTo(item.to)}
              >
                <span>{label}</span>
                {hasChildren && <ChevronRight size={19} strokeWidth={2} aria-hidden="true" />}
              </button>
            );
          })}
          {stack.length === 0 && links.map((item) => (
            <button type="button" className="dtb-menu-pages__row dtb-menu-pages__row--utility"
              key={item.to} onClick={() => goTo(item.to)}>{item.label}</button>
          ))}
        </div>
      </div>
    </nav>
  );
}
