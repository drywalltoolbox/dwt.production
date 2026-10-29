import { useCallback, useEffect, useRef, useState } from 'react';
import { ArrowUpRight, ChevronRight } from 'lucide-react';
import { Link } from 'react-router-dom';
import '../../styles/storefront-mobile-navigation-pages.css';

/**
 * In-drawer navigation: category selections push a new menu page instead of
 * expanding inline. The shell, header, cart and search retain their owners.
 */
export default function StorefrontMobileNavigationPages({ isOpen, sections, links, onNavigate, onStackDepthChange, loadingCategories = false }) {
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

  const back = useCallback(() => {
    setDirection('back');
    setStack((previous) => previous.slice(0, -1));
    contentRef.current?.scrollTo({ top: 0, behavior: 'instant' });
    window.requestAnimationFrame(() => headingRef.current?.focus({ preventScroll: true }));
  }, []);

  useEffect(() => {
    onStackDepthChange?.(stack.length, stack.length ? back : null);
  }, [back, onStackDepthChange, stack.length]);

  useEffect(() => () => onStackDepthChange?.(0, null), [onStackDepthChange]);

  const openPage = (page) => {
    setDirection('forward');
    setStack((previous) => [...previous, page]);
    contentRef.current?.scrollTo({ top: 0, behavior: 'instant' });
    window.requestAnimationFrame(() => headingRef.current?.focus({ preventScroll: true }));
  };
  const goTo = (target) => {
    if (target) onNavigate(target);
  };

  const productSection = sections.find((section) => section.id === 'products');
  const secondarySections = sections.filter((section) => section.id !== 'products');
  const productRows = productSection?.items || [];
  const rows = current ? (current.items || []) : [productSection, ...secondarySections, ...links].filter(Boolean);
  const showCategorySkeleton = loadingCategories && productRows.length === 0 && current?.id === 'products';
  return (
    <nav className={`dtb-menu-pages${current ? '' : ' dtb-menu-pages--root'}`} aria-label="Mobile navigation" aria-busy={showCategorySkeleton} data-direction={direction}>
      <div className="dtb-menu-pages__heading">
        <h2 ref={headingRef} tabIndex={-1} className="dtb-menu-pages__title" key={pageTitle}>
          {current?.isParentCategory && current.to ? (
            <Link
              className="dtb-menu-pages__title-link"
              to={current.to}
              aria-label={`Open ${pageTitle} category`}
              onClick={(event) => {
                event.preventDefault();
                goTo(current.to);
              }}
            >
              {pageTitle}
              <ArrowUpRight size={18} strokeWidth={2.1} aria-hidden="true" />
            </Link>
          ) : pageTitle}
        </h2>
      </div>
      <div ref={contentRef} className="dtb-menu-pages__scroll" key={stack.map((p) => p.id).join('/') || 'root'}>
        <div className="dtb-menu-pages__list" key={stack.map((p) => p.id).join('/') || 'root'}>
          {showCategorySkeleton && (
            <div className="dtb-menu-pages__loading" role="status" aria-label="Loading product categories">
              {Array.from({ length: 6 }, (_, index) => (
                <div className="dtb-menu-pages__loading-row" aria-hidden="true" key={`category-placeholder-${index}`}>
                  <span />
                  <i />
                </div>
              ))}
            </div>
          )}
          {rows.map((item, index) => {
            const hasChildren = Array.isArray(item.items) && item.items.length > 0;
            const categoriesLoading = !current && item.id === 'products' && loadingCategories;
            const label = item.label || item.name;
            return (
              <button
                type="button"
                key={item.id || item.slug || item.to || `${label}-${index}`}
                className="dtb-menu-pages__row"
                onClick={() => (hasChildren || categoriesLoading) ? openPage({
                  id: item.id || item.slug || label,
                  title: label,
                  items: item.items,
                  to: item.to,
                  isParentCategory: current?.id === 'products',
                }) : goTo(item.to)}
              >
                <span className="dtb-menu-pages__row-label">{label}</span>
                <ChevronRight size={19} strokeWidth={2} aria-hidden="true" />
              </button>
            );
          })}
        </div>
      </div>
    </nav>
  );
}
