import { useMemo, useState, useEffect, useRef } from 'react';
import { m as Motion, useReducedMotion } from 'framer-motion';
import MotionPresence from '../motion/MotionPresence.jsx';
import { dtbSpring, dtbTransition, reducedTransition } from '../../motion/dtbMotion.js';
import { Check, ChevronDown, Search, Sliders, X } from 'lucide-react';
import '../../styles/filter-panel.css';

const VISIBLE_ITEM_LIMIT = 8;
const FOCUSABLE_SELECTOR = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

function normalizeFilterItems(items = [], { category = false } = {}) {
  if (!Array.isArray(items)) return [];
  const seen = new Set();

  return items.map((item) => {
    const label = String(typeof item === 'string' ? item : (item?.label || item?.name || '')).trim();
    const id = String(typeof item === 'string'
      ? item
      : (category ? (item?.id || item?.slug || item?.key || label) : label)).trim();
    return {
      id,
      label,
      count: Number(item?.count ?? item?.productCount ?? 0),
    };
  }).filter((item) => {
    if (!item.id || !item.label || seen.has(item.id)) return false;
    seen.add(item.id);
    return true;
  });
}

export default function FilterPanel({
  isOpen,
  onClose,
  categories = [],
  brands = [],
  selectedBrands = [],
  selectedCategories = [],
  onBrandChange,
  onCategoryChange,
  onClearFilters,
  resultCount,
}) {
  const [expandedSections, setExpandedSections] = useState({ categories: true, brands: true });
  const reduceMotion = useReducedMotion();
  const panelRef = useRef(null);
  const closeButtonRef = useRef(null);
  const onCloseRef = useRef(onClose);

  const filterCategories = useMemo(() => normalizeFilterItems(categories, { category: true }), [categories]);
  const filterBrands = useMemo(() => normalizeFilterItems(brands), [brands]);
  const selectedBrandIds = Array.isArray(selectedBrands) ? selectedBrands : [];
  const selectedCategoryIds = Array.isArray(selectedCategories) ? selectedCategories : [];
  const activeFilterCount = selectedBrandIds.length + selectedCategoryIds.length;
  const hasActiveFilters = activeFilterCount > 0;

  useEffect(() => { onCloseRef.current = onClose; }, [onClose]);

  // The mobile filter sheet is modal. Keep focus inside while it is open and
  // restore the actual opener after dismissal rather than trapping focus behind it.
  useEffect(() => {
    if (!isOpen) return undefined;

    const opener = document.activeElement;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const focusFrame = window.requestAnimationFrame(() => closeButtonRef.current?.focus({ preventScroll: true }));

    const onKeyDown = (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        onCloseRef.current?.();
        return;
      }
      if (event.key !== 'Tab' || !panelRef.current) return;
      const focusable = Array.from(panelRef.current.querySelectorAll(FOCUSABLE_SELECTOR))
        .filter((element) => element.getClientRects().length > 0 && !element.closest('[inert]'));
      if (!focusable.length) {
        event.preventDefault();
        panelRef.current.focus({ preventScroll: true });
        return;
      }
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (!panelRef.current.contains(document.activeElement)) {
        event.preventDefault();
        (event.shiftKey ? last : first).focus({ preventScroll: true });
      } else if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus({ preventScroll: true });
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus({ preventScroll: true });
      }
    };

    const activePanel = panelRef.current;
    document.addEventListener('keydown', onKeyDown, true);
    return () => {
      window.cancelAnimationFrame(focusFrame);
      document.removeEventListener('keydown', onKeyDown, true);
      document.body.style.overflow = previousOverflow;
      if (opener instanceof HTMLElement && opener.isConnected && activePanel?.contains(document.activeElement)) {
        opener.focus({ preventScroll: true });
      }
    };
  }, [isOpen]);

  const toggleSection = (section) => setExpandedSections((previous) => ({
    ...previous,
    [section]: !previous[section],
  }));

  const contentProps = {
    categories: filterCategories,
    brands: filterBrands,
    selectedBrands: selectedBrandIds,
    selectedCategories: selectedCategoryIds,
    onBrandChange,
    onCategoryChange,
    onClearFilters,
    expandedSections,
    toggleSection,
    hasActiveFilters,
    resultCount,
  };

  return (
    <>
      <aside className="dtb-filter-sidebar hidden lg:block">
        <div className="dtb-filter-sidebar__sticky">
          <FilterContent {...contentProps} showTitle />
        </div>
      </aside>

      <MotionPresence initial={false}>
        {isOpen ? (
          <Motion.div
            key="mobile-filters"
            className="dtb-filter-drawer__overlay fixed inset-0 lg:hidden"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={reduceMotion ? reducedTransition : dtbTransition.fast}
          >
            <Motion.div
              className="dtb-filter-drawer"
              initial={{ y: reduceMotion ? 0 : '100%' }}
              animate={{ y: 0 }}
              exit={{ y: reduceMotion ? 0 : '100%' }}
              transition={reduceMotion ? reducedTransition : dtbSpring.gentle}
            >
              <div
                ref={panelRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby="dtb-mobile-filter-title"
                tabIndex={-1}
                className="dtb-filter-drawer__panel"
              >
                <header className="dtb-filter-drawer__header">
                  <div className="dtb-filter-drawer__heading">
                    <span className="dtb-filter-drawer__icon" aria-hidden="true"><Sliders size={19} /></span>
                    <div>
                      <h2 id="dtb-mobile-filter-title">Filters</h2>
                      <p>{typeof resultCount === 'number' ? `${resultCount.toLocaleString()} products` : 'Refine your products'}</p>
                    </div>
                    {hasActiveFilters && <span className="dtb-filter-drawer__active-count">{activeFilterCount} active</span>}
                  </div>
                  <button
                    ref={closeButtonRef}
                    type="button"
                    onClick={onClose}
                    className="dtb-filter-drawer__close"
                    aria-label="Close filters"
                  >
                    <X size={21} />
                  </button>
                </header>

                <div className="dtb-filter-drawer__body">
                  <FilterContent {...contentProps} />
                </div>

                <footer className="dtb-filter-drawer__footer">
                  <button type="button" onClick={onClearFilters} className="dtb-filter-drawer__clear" disabled={!hasActiveFilters}>
                    Clear all
                  </button>
                  <button type="button" onClick={onClose} className="dtb-filter-drawer__apply">
                    View {typeof resultCount === 'number' ? `${resultCount.toLocaleString()} products` : 'products'}
                  </button>
                </footer>
              </div>
            </Motion.div>
          </Motion.div>
        ) : null}
      </MotionPresence>
    </>
  );
}

function FilterContent({
  categories,
  brands,
  selectedBrands,
  selectedCategories,
  onBrandChange,
  onCategoryChange,
  onClearFilters,
  expandedSections,
  toggleSection,
  hasActiveFilters,
  resultCount,
  showTitle = false,
}) {
  return (
    <section className="dtb-filter-content" aria-label="Product filters">
      {showTitle && (
        <header className="dtb-filter-content__header">
          <div>
            <h2>Filters</h2>
            {typeof resultCount === 'number' && <p>{resultCount.toLocaleString()} products</p>}
          </div>
          {hasActiveFilters && (
            <button type="button" className="dtb-filter-content__reset" onClick={onClearFilters}>Clear all</button>
          )}
        </header>
      )}

      {(selectedBrands.length > 0 || selectedCategories.length > 0) && (
        <div className="dtb-filter-content__active" aria-label="Active filters">
          {[...selectedCategories.map((id) => ({ id, label: findFilterLabel(categories, id), remove: onCategoryChange, type: 'category' })),
            ...selectedBrands.map((id) => ({ id, label: findFilterLabel(brands, id), remove: onBrandChange, type: 'brand' }))]
            .map((filter) => (
              <button key={`${filter.type}:${filter.id}`} type="button" onClick={() => filter.remove(filter.id)}>
                <span>{filter.label}</span><X size={13} aria-hidden="true" />
              </button>
            ))}
        </div>
      )}

      <div className="dtb-filter-content__groups">
        <FilterCheckboxGroup
          title="Category"
          section="categories"
          items={categories}
          selectedIds={selectedCategories}
          onToggle={onCategoryChange}
          isExpanded={expandedSections.categories}
          onSectionToggle={toggleSection}
        />
        <FilterCheckboxGroup
          title="Brand"
          section="brands"
          items={brands}
          selectedIds={selectedBrands}
          onToggle={onBrandChange}
          isExpanded={expandedSections.brands}
          onSectionToggle={toggleSection}
        />
      </div>
    </section>
  );
}

function findFilterLabel(items, id) {
  return items.find((item) => item.id === id)?.label || String(id).replace(/[_-]+/g, ' ');
}

function FilterCheckboxGroup({ title, section, items, selectedIds, onToggle, isExpanded, onSectionToggle }) {
  const [search, setSearch] = useState('');
  const [showAll, setShowAll] = useState(false);
  const searchable = items.length > VISIBLE_ITEM_LIMIT;
  const filteredItems = useMemo(() => {
    const term = search.trim().toLocaleLowerCase();
    return term ? items.filter((item) => item.label.toLocaleLowerCase().includes(term)) : items;
  }, [items, search]);
  const visibleItems = showAll || search.trim()
    ? filteredItems
    : filteredItems.slice(0, VISIBLE_ITEM_LIMIT);
  const hiddenCount = filteredItems.length - visibleItems.length;

  if (items.length === 0) return null;

  return (
    <section className="dtb-filter-group">
      <button
        type="button"
        className="dtb-filter-group__heading"
        aria-expanded={isExpanded}
        onClick={() => onSectionToggle(section)}
      >
        <span>{title}<span className="dtb-filter-group__total">{items.length}</span></span>
        <ChevronDown size={17} aria-hidden="true" />
      </button>

      {isExpanded && (
        <div className="dtb-filter-group__body">
          {searchable && (
            <div className="dtb-filter-group__search">
              <Search size={15} aria-hidden="true" />
              <input
                type="search"
                aria-label={`Search ${title.toLowerCase()}`}
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder={`Search ${title.toLowerCase()}`}
              />
              {search && <button type="button" onClick={() => setSearch('')} aria-label={`Clear ${title.toLowerCase()} search`}><X size={14} /></button>}
            </div>
          )}

          {visibleItems.length > 0 ? (
            <ul className="dtb-filter-group__options">
              {visibleItems.map((item) => {
                const checked = selectedIds.includes(item.id);
                return (
                  <li key={item.id}>
                    <label className={`dtb-filter-option${checked ? ' is-selected' : ''}`}>
                      <input type="checkbox" checked={checked} onChange={() => onToggle(item.id)} />
                      <span className="dtb-filter-option__box" aria-hidden="true">{checked && <Check size={12} strokeWidth={3} />}</span>
                      <span className="dtb-filter-option__label">{item.label}</span>
                      {item.count > 0 && <span className="dtb-filter-option__count">{item.count.toLocaleString()}</span>}
                    </label>
                  </li>
                );
              })}
            </ul>
          ) : (
            <p className="dtb-filter-group__empty">No matching {title.toLowerCase()}.</p>
          )}

          {!search.trim() && hiddenCount > 0 && (
            <button type="button" className="dtb-filter-group__more" onClick={() => setShowAll(true)}>
              Show {hiddenCount} more <ChevronDown size={13} aria-hidden="true" />
            </button>
          )}
          {!search.trim() && showAll && items.length > VISIBLE_ITEM_LIMIT && (
            <button type="button" className="dtb-filter-group__more" onClick={() => setShowAll(false)}>
              Show fewer <ChevronDown size={13} aria-hidden="true" className="is-flipped" />
            </button>
          )}
        </div>
      )}
    </section>
  );
}
