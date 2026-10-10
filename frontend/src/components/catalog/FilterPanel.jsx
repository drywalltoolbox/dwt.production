import { useState, useEffect, useMemo, useRef } from 'react';
import { m as Motion, useReducedMotion } from 'framer-motion';
import MotionPresence from '../motion/MotionPresence.jsx';
import { dtbDistance, dtbSpring, dtbTransition, reducedTransition } from '../../motion/dtbMotion.js';
import { X, ChevronDown, Sliders } from 'lucide-react';
import '../../styles/filter-panel.css';

const VISIBLE_ITEM_LIMIT = 8;
const FOCUSABLE_SELECTOR = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

function normalizeFilterBrands(brands = []) {
  if (!Array.isArray(brands) || brands.length === 0) return [];
  const seen = new Set();

  return brands
    .map((brand) => String(brand || '').trim())
    .filter((brand) => {
      if (!brand) return false;
      const key = brand.toLowerCase();
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    });
}

export default function FilterPanel({
  isOpen,
  onClose,
  categories,
  brands,
  maxPrice,
  selectedBrands,
  selectedCategories,
  priceRange,
  onBrandChange,
  onCategoryChange,
  onPriceChange,
  onClearFilters,
  resultsCount,
}) {
  const [expandedSections, setExpandedSections] = useState({
    categories: true,
    brands: true,
    price: true,
  });

  const filterBrands = useMemo(() => normalizeFilterBrands(brands), [brands]);
  const reduceMotion = useReducedMotion();
  const panelRef = useRef(null);
  const closeButtonRef = useRef(null);
  const onCloseRef = useRef(onClose);

  useEffect(() => { onCloseRef.current = onClose; }, [onClose]);

  // The mobile filter sheet is modal. Keep focus inside while it is open and
  // restore the actual opener after dismissal rather than trapping tab focus in
  // the document behind the backdrop.
  useEffect(() => {
    if (!isOpen) return undefined;

    const opener = document.activeElement;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    const focusFrame = window.requestAnimationFrame(() => {
      closeButtonRef.current?.focus({ preventScroll: true });
    });

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
      if (opener instanceof HTMLElement && opener.isConnected
        && activePanel?.contains(document.activeElement)) {
        opener.focus({ preventScroll: true });
      }
    };
  }, [isOpen]);

  const toggleSection = (section) => {
    setExpandedSections(prev => ({
      ...prev,
      [section]: !prev[section],
    }));
  };

  const hasActiveFilters =
    selectedBrands.length > 0 ||
    selectedCategories.length > 0 ||
    priceRange[0] !== 0 ||
    priceRange[1] !== maxPrice;

  return (
    <>
      {/* Desktop Sidebar - Always Visible */}
      <aside className="hidden lg:block lg:w-80 shrink-0">
        <div className="lg:sticky lg:top-24 h-full">
          <FilterContent
            categories={categories}
            brands={filterBrands}
            maxPrice={maxPrice}
            selectedBrands={selectedBrands}
            selectedCategories={selectedCategories}
            priceRange={priceRange}
            onBrandChange={onBrandChange}
            onCategoryChange={onCategoryChange}
            onPriceChange={onPriceChange}
            onClearFilters={onClearFilters}
            expandedSections={expandedSections}
            toggleSection={toggleSection}
            hasActiveFilters={hasActiveFilters}
            resultsCount={resultsCount}
          />
        </div>
      </aside>

      {/* Mobile Sidebar - Slide In */}
      <MotionPresence initial={false}>
        {isOpen ? (
        <Motion.div
          key="mobile-filters"
          className="fixed inset-0 z-50 lg:hidden"
          style={{ top: 'var(--header-height)' }}
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          transition={reduceMotion ? reducedTransition : dtbTransition.fast}
        >
          <div
            className="absolute inset-0 bg-black/35 backdrop-blur-[1px] filter-mobile-overlay-enter"
            onClick={(e) => {
              if (e.target === e.currentTarget) {
                onClose();
              }
            }}
          />

          <Motion.div
            className="absolute inset-x-0 top-0"
            initial={{ y: reduceMotion ? 0 : -dtbDistance.medium }}
            animate={{ y: 0 }}
            exit={{ y: reduceMotion ? 0 : -dtbDistance.small }}
            transition={reduceMotion ? reducedTransition : dtbSpring.gentle}
          >
            <div
              ref={panelRef}
              role="dialog"
              aria-modal="true"
              aria-labelledby="dtb-mobile-filter-title"
              tabIndex={-1}
              className="w-full bg-white shadow-2xl overflow-hidden flex flex-col rounded-b-2xl filter-mobile-sheet-enter"
              style={{
                maxHeight: 'min(78dvh, calc(100dvh - var(--header-height) - 0.5rem))',
                paddingBottom: 'env(safe-area-inset-bottom)',
              }}
            >
              <div className="sticky top-0 bg-white border-b border-slate-200 px-4 py-4 flex items-center justify-between shrink-0">
                <h2 id="dtb-mobile-filter-title" className="text-base font-semibold text-slate-900 flex items-center gap-2">
                  <Sliders size={18} />
                  Advanced Filters
                </h2>
                <button
                  ref={closeButtonRef}
                  type="button"
                  onClick={onClose}
                  className="p-2 hover:bg-slate-100 rounded-xl transition-colors touch-target"
                  aria-label="Close filters"
                  title="Close filters (ESC)"
                >
                  <X size={22} className="text-slate-600" />
                </button>
              </div>

              <div className="flex-1 min-h-0 overflow-y-auto overscroll-contain px-3 py-3">
                <FilterContent
                  categories={categories}
                  brands={filterBrands}
                  maxPrice={maxPrice}
                  selectedBrands={selectedBrands}
                  selectedCategories={selectedCategories}
                  priceRange={priceRange}
                  onBrandChange={onBrandChange}
                  onCategoryChange={onCategoryChange}
                  onPriceChange={onPriceChange}
                  onClearFilters={onClearFilters}
                  expandedSections={expandedSections}
                  toggleSection={toggleSection}
                  hasActiveFilters={hasActiveFilters}
                  resultsCount={resultsCount}
                  isMobile
                />
              </div>

              <div className="sticky bottom-0 bg-white border-t border-slate-200 px-4 py-3 shrink-0">
                <div className="flex gap-3">
                  <button
                    onClick={onClearFilters}
                    className="flex-1 border border-slate-300 text-slate-700 font-medium py-2.5 px-4 rounded-xl hover:bg-slate-50 transition-colors"
                  >
                    Clear All
                  </button>
                  <button
                    onClick={onClose}
                    className="flex-1 bg-primary-600 hover:bg-primary-700 text-white font-semibold py-2.5 px-4 rounded-xl transition-colors"
                  >
                    Apply
                  </button>
                </div>
              </div>
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
  maxPrice,
  selectedBrands,
  selectedCategories,
  priceRange,
  onBrandChange,
  onCategoryChange,
  onPriceChange,
  onClearFilters,
  expandedSections,
  toggleSection,
  hasActiveFilters,
  resultsCount,
  isMobile,
}) {
  return (
    <div
      className={`overflow-hidden border border-slate-200 bg-white ${
        isMobile ? 'rounded-2xl' : 'rounded-2xl shadow-sm'
      }`}
    >
      <div className="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3.5">
        <h3 className="text-xs font-bold tracking-wider text-slate-900 uppercase">Filters</h3>
        {hasActiveFilters && (
          <button
            type="button"
            onClick={onClearFilters}
            className="text-xs font-semibold text-primary-600 hover:text-primary-700 hover:underline"
          >
            Clear all
          </button>
        )}
      </div>

      {resultsCount !== undefined && (
        <p className="px-4 pt-3 text-xs text-slate-600">
          <span className="font-semibold text-primary-700">{resultsCount}</span> products found
        </p>
      )}

      <div className="space-y-4 px-4 py-3">
        {/* Categories Section - only shown when categories are provided */}
        {categories && categories.length > 0 && (
          <FilterCheckboxGroup
            title="Category"
            items={categories.map((category) => ({ id: category.id, label: category.name, count: category.count }))}
            selectedIds={selectedCategories}
            onToggle={onCategoryChange}
          />
        )}

        {/* Brands Section */}
        <FilterCheckboxGroup
          title="Brand"
          items={brands.map((brand) => ({ id: brand, label: brand }))}
          selectedIds={selectedBrands}
          onToggle={onBrandChange}
        />

        {/* Price Range Section - only shown when maxPrice is set */}
        {maxPrice > 0 && (
        <FilterSection
          title="Price Range"
          isExpanded={expandedSections.price}
          onToggle={() => toggleSection('price')}
          isMobile={isMobile}
        >
          <div className="space-y-3 p-1">
            {/* Price Display */}
            <div className="flex items-center justify-between bg-primary-50 px-3 py-2 rounded-lg border border-primary-200">
              <div className="flex items-baseline gap-1">
                <span className="text-base font-bold text-primary-600">
                  ${priceRange[0].toFixed(0)}
                </span>
                <span className="text-xs text-gray-500">to</span>
                <span className="text-base font-bold text-primary-600">
                  ${priceRange[1].toFixed(0)}
                </span>
              </div>
            </div>

            {/* Range Slider */}
            <div className="space-y-3">
              {/* Min Price Input */}
              <div>
                <label className="text-xs font-semibold text-slate-600 block mb-2">
                  Min Price
                </label>
                <input
                  type="range"
                  min="0"
                  max={maxPrice}
                  step="50"
                  value={priceRange[0]}
                  onChange={(e) => {
                    const newMin = parseInt(e.target.value, 10);
                    if (newMin <= priceRange[1]) {
                      onPriceChange([newMin, priceRange[1]]);
                    }
                  }}
                  className="w-full h-2 bg-slate-200 rounded-lg appearance-none cursor-pointer slider-thumb"
                />
                <div className="flex justify-between text-xs text-slate-500 mt-1">
                  <span>$0</span>
                  <span>${maxPrice}</span>
                </div>
              </div>

              {/* Max Price Input */}
              <div>
                <label className="text-xs font-semibold text-slate-600 block mb-2">
                  Max Price
                </label>
                <input
                  type="range"
                  min="0"
                  max={maxPrice}
                  step="50"
                  value={priceRange[1]}
                  onChange={(e) => {
                    const newMax = parseInt(e.target.value, 10);
                    if (newMax >= priceRange[0]) {
                      onPriceChange([priceRange[0], newMax]);
                    }
                  }}
                  className="w-full h-2 bg-slate-200 rounded-lg appearance-none cursor-pointer slider-thumb"
                />
              </div>
            </div>
          </div>
        </FilterSection>
        )}
      </div>
    </div>
  );
}

/**
 * Inline checkbox-style list for a filter group (Category / Brand), with a
 * "Show more" expander once the list exceeds `VISIBLE_ITEM_LIMIT` — keeps
 * long category/brand lists from dominating the sidebar by default while
 * staying reachable in one click. Selection/toggle logic is unchanged from
 * the previous button-list implementation; this only restyles it as an
 * inline checkbox row instead of a bordered pill.
 */
function FilterCheckboxGroup({ title, items, selectedIds, onToggle }) {
  const [showAll, setShowAll] = useState(false);
  const visibleItems = showAll ? items : items.slice(0, VISIBLE_ITEM_LIMIT);
  const hiddenCount = items.length - visibleItems.length;

  if (!items || items.length === 0) return null;

  return (
    <div>
      <h4 className="mb-2 text-xs font-bold tracking-wider text-slate-500 uppercase">{title}</h4>
      <ul className="space-y-1">
        {visibleItems.map((item) => {
          const isSelected = selectedIds.includes(item.id);
          return (
            <li key={item.id}>
              <label className="flex min-h-11 w-full cursor-pointer items-center gap-2.5 rounded-lg px-2 py-1.5 hover:bg-slate-50">
                <input
                  type="checkbox"
                  checked={isSelected}
                  onChange={() => onToggle(item.id)}
                  className="h-4 w-4 shrink-0 rounded border-slate-300 text-primary-600 accent-primary-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500"
                />
                <span className="flex min-w-0 flex-1 items-center justify-between gap-2 text-sm text-slate-700">
                  <span className={`truncate ${isSelected ? 'font-semibold text-slate-900' : ''}`}>{item.label}</span>
                  {typeof item.count === 'number' && item.count > 0 && (
                    <span className="shrink-0 text-xs text-slate-400">{item.count}</span>
                  )}
                </span>
              </label>
            </li>
          );
        })}
      </ul>

      {items.length > VISIBLE_ITEM_LIMIT && (
        <button
          type="button"
          onClick={() => setShowAll((current) => !current)}
          className="group mt-0.5 flex h-6 w-full items-center justify-center gap-0.5 rounded text-[10px] font-medium leading-none text-primary-600 transition-colors hover:text-primary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-1"
          aria-expanded={showAll}
        >
          <span>{showAll ? 'Show fewer' : `Show ${hiddenCount} more`}</span>
          <ChevronDown
            size={10}
            className={`shrink-0 text-primary-500 transition-transform duration-200 group-hover:text-primary-700 ${showAll ? 'rotate-180' : ''}`}
            aria-hidden="true"
          />
        </button>
      )}
    </div>
  );
}

function FilterSection({
  title,
  isExpanded,
  onToggle,
  itemCount,
  children,
  isMobile,
}) {
  return (
    <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
      <button
        onClick={onToggle}
        className={`w-full flex items-center justify-between gap-2 transition-colors hover:bg-slate-50 ${
          isMobile ? 'px-3 py-3' : 'px-4 py-3.5'
        }`}
      >
        <span className="flex items-center gap-2">
          <span className="text-sm font-semibold text-slate-900">{title}</span>
          {itemCount > 0 && (
            <span className="inline-flex items-center rounded-full bg-primary-100 px-2 py-0.5 text-xs font-semibold text-primary-700">
              {itemCount}
            </span>
          )}
        </span>
        <ChevronDown
          size={18}
          className={`text-slate-500 transition-transform duration-200 shrink-0 ${
            isExpanded ? 'rotate-180' : ''
          }`}
        />
      </button>

      {isExpanded && (
        <div className={`${isMobile ? 'px-3 pb-3' : 'px-4 pb-4'} border-t border-slate-100 bg-slate-50/60`}>
          {children}
        </div>
      )}
    </div>
  );
}
