import { useEffect, useId, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  ArrowRight,
  Award,
  Box,
  ChevronDown,
  ChevronRight,
  Hammer,
  Layers,
  Package,
  PenTool,
  Ruler,
  Settings2,
  ShoppingBag,
  Wrench,
} from 'lucide-react';
import '../../styles/storefront-desktop-navigation.css';
import '../../styles/storefront-navigation-taxonomy.css';
import automaticToolsIcon from '../../assets/media/category-icons/automatic-taping-tools.webp';
import drywallStiltsIcon from '../../assets/media/category-icons/drywall-stilts.webp';
import replacementPartsIcon from '../../assets/media/category-icons/replacement-parts.webp';

const RESILIENT_DROPDOWN_IDS = new Set(['products', 'brands', 'parts', 'repairs', 'schematics']);
const CATALOG_BACKED_DROPDOWN_IDS = new Set(['products', 'brands', 'parts']);
const POINTER_OPEN_INTENT_MS = 500;
const POINTER_SWITCH_INTENT_MS = 500;
const POINTER_CLOSE_DELAY_MS = 300;
const PARENT_CATEGORY_IMAGES = {
  'automatic-taping-tools': automaticToolsIcon,
  'drywall-stilts': drywallStiltsIcon,
  'replacement-parts': replacementPartsIcon,
};

const ENTRY_ICONS = [Wrench, Layers, Box, PenTool, Ruler, Hammer, Package, Settings2, ShoppingBag, Award];
const ENTRY_ICON_ELEMENTS_LARGE = ENTRY_ICONS.map((Icon, index) => (
  <Icon key={index} size={30} strokeWidth={1.6} />
));

const LOADING_SURFACE_STYLE = {
  position: 'relative',
  minHeight: '244px',
  padding: '24px',
  background: '#f3f6fa',
};

const LOADING_SPINNER_STYLE = {
  position: 'absolute',
  top: '20px',
  right: '24px',
  width: '22px',
  height: '22px',
};

function pickEntryIconIndex(label) {
  const text = String(label || '');
  let hash = 0;
  for (let i = 0; i < text.length; i += 1) {
    hash = (hash * 31 + text.charCodeAt(i)) >>> 0;
  }
  return hash % ENTRY_ICONS.length;
}

function usePrefersReducedMotion() {
  const [reducedMotion, setReducedMotion] = useState(() => {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return false;
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  });

  useEffect(() => {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return undefined;
    const query = window.matchMedia('(prefers-reduced-motion: reduce)');
    const onChange = (event) => setReducedMotion(event.matches);
    query.addEventListener?.('change', onChange);
    return () => query.removeEventListener?.('change', onChange);
  }, []);

  return reducedMotion;
}

function MegaMenuThumb({ label, logo, thumbnail }) {
  const [imageFailed, setImageFailed] = useState(false);
  const imageSrc = logo || thumbnail;

  if (imageSrc && !imageFailed) {
    return (
      <span className={`dtb-desktop-nav-editorial-thumb${logo ? ' dtb-desktop-nav-editorial-thumb--logo' : ''}`}>
        <img src={imageSrc} alt="" loading="eager" decoding="async" onError={() => setImageFailed(true)} />
      </span>
    );
  }

  const index = pickEntryIconIndex(label);
  return (
    <span className="dtb-desktop-nav-editorial-thumb dtb-desktop-nav-editorial-thumb--fallback" aria-hidden="true">
      {ENTRY_ICON_ELEMENTS_LARGE[index]}
    </span>
  );
}

function MegaMenuHero({ item, onNavigate }) {
  const eyebrow = item.eyebrow || (item.id === 'products' ? 'Our Products' : item.label);
  const ctaLabel = item.heroCtaLabel || item.landingLabel || `View all ${String(item.label || '').toLowerCase()}`;

  return (
    <div className="dtb-mega-menu__hero">
      <div className="dtb-mega-menu__hero-copy">
        <span className="dtb-mega-menu__eyebrow">{eyebrow}</span>
        <p className="dtb-mega-menu__heading">{item.heading || item.label}</p>
        {item.description ? <p className="dtb-mega-menu__description">{item.description}</p> : null}
        {item.landingTo ? (
          <Link to={item.landingTo} className="dtb-mega-menu__hero-cta" onClick={onNavigate}>
            <span>{ctaLabel}</span>
            <ArrowRight size={16} strokeWidth={2.2} aria-hidden="true" />
          </Link>
        ) : null}
      </div>

    </div>
  );
}

function MegaMenuLoadingSpinner({ reducedMotion }) {
  return (
    <svg viewBox="0 0 24 24" style={LOADING_SPINNER_STYLE} aria-hidden="true" focusable="false">
      <circle cx="12" cy="12" r="9" fill="none" stroke="rgba(20, 87, 245, 0.16)" strokeWidth="2.2" />
      <path d="M12 3a9 9 0 0 1 8.35 5.65" fill="none" stroke="var(--mega-blue, #2255EE)" strokeWidth="2.2" strokeLinecap="round">
        {!reducedMotion ? (
          <animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur="0.9s" repeatCount="indefinite" />
        ) : null}
      </path>
    </svg>
  );
}

function MegaMenuLoadingCard({ compact, index, reducedMotion }) {
  const delay = `${(index % 4) * 0.12}s`;
  return (
    <span
      aria-hidden="true"
      style={{
        display: 'grid',
        gridTemplateColumns: compact ? '76px minmax(0, 1fr)' : '118px minmax(0, 1fr)',
        alignItems: 'center',
        minHeight: compact ? '86px' : '104px',
        overflow: 'hidden',
        border: '1px solid rgba(15, 23, 42, 0.08)',
        borderRadius: '13px',
        background: '#fff',
        boxShadow: '0 2px 7px rgba(15, 23, 42, 0.035)',
      }}
    >
      <span style={{ height: '100%', borderRight: '1px solid #e7ecf3', background: '#edf2f7' }}>
        <svg viewBox="0 0 100 100" width="100%" height="100%" preserveAspectRatio="none">
          <rect width="100" height="100" fill="#edf2f7">
            {!reducedMotion ? <animate attributeName="opacity" values="0.48;0.86;0.48" dur="1.55s" begin={delay} repeatCount="indefinite" /> : null}
          </rect>
        </svg>
      </span>
      <span style={{ display: 'grid', gap: '10px', padding: compact ? '14px' : '18px 20px' }}>
        <svg viewBox="0 0 180 32" width="100%" height="32" preserveAspectRatio="none">
          <rect x="0" y="2" width="118" height="9" rx="4.5" fill="#dbe3ed">
            {!reducedMotion ? <animate attributeName="opacity" values="0.5;0.92;0.5" dur="1.55s" begin={delay} repeatCount="indefinite" /> : null}
          </rect>
          <rect x="0" y="20" width="78" height="7" rx="3.5" fill="#e6ebf2">
            {!reducedMotion ? <animate attributeName="opacity" values="0.42;0.78;0.42" dur="1.55s" begin={delay} repeatCount="indefinite" /> : null}
          </rect>
        </svg>
      </span>
    </span>
  );
}

function MegaMenuLoadingState({ item }) {
  const reducedMotion = usePrefersReducedMotion();
  const compact = item.id === 'products';
  const skeletonCount = compact ? 8 : 6;

  return (
    <div
      role="status"
      aria-live="polite"
      aria-label={`${item.label || 'Menu'} content is loading`}
      aria-busy="true"
      style={{ ...LOADING_SURFACE_STYLE, minHeight: compact ? '244px' : '306px' }}
    >
      <MegaMenuLoadingSpinner reducedMotion={reducedMotion} />
      <div
        aria-hidden="true"
        style={{
          display: 'grid',
          gridTemplateColumns: compact ? 'repeat(4, minmax(0, 1fr))' : 'repeat(2, minmax(0, 1fr))',
          gap: compact ? '8px' : '12px',
          paddingTop: '12px',
        }}
      >
        {Array.from({ length: skeletonCount }, (_, index) => (
          <MegaMenuLoadingCard key={index} compact={compact} index={index} reducedMotion={reducedMotion} />
        ))}
      </div>
    </div>
  );
}

function MegaMenuDeferredState({ item }) {
  return CATALOG_BACKED_DROPDOWN_IDS.has(item.id) ? <MegaMenuLoadingState item={item} /> : null;
}

function ProductCard({ entry, onNavigate }) {
  const productCount = Number(entry.count || entry.productCount || 0);

  return (
    <Link to={entry.to} className="dtb-mega-menu__product-card" onClick={onNavigate}>
      <MegaMenuThumb label={entry.label} thumbnail={entry.thumbnail} />
      <span className="dtb-desktop-nav-row-text dtb-mega-menu__product-card-copy">
        <span className="dtb-desktop-nav-row-title">{entry.label}</span>
        {productCount > 0 ? (
          <span className="dtb-mega-menu__product-card-meta">
            {productCount} {productCount === 1 ? 'product' : 'products'}
          </span>
        ) : null}
      </span>
      <span className="dtb-mega-menu__product-card-action" aria-hidden="true">
        <ChevronRight size={17} strokeWidth={2.35} />
      </span>
    </Link>
  );
}

function ProductsPanelRenderer({ item, onNavigate }) {
  const groups = Array.isArray(item.items) ? item.items : [];
  const [activeGroupKey, setActiveGroupKey] = useState(() => groups[0]?.slug || groups[0]?.label || '');
  const activeGroup = groups.find((group) => (group.slug || group.label) === activeGroupKey) || groups[0];
  const entries = activeGroup
    ? (Array.isArray(activeGroup.children) && activeGroup.children.length > 0 ? activeGroup.children : [activeGroup])
    : [];

  const parentIconFor = (group) => {
    const slug = String(group?.slug || '').toLowerCase();
    if (slug.includes('automatic')) return Wrench;
    if (slug.includes('taping') || slug.includes('finishing') || slug.includes('hand-tools')) return PenTool;
    if (slug.includes('corner') || slug.includes('bead')) return Ruler;
    if (slug.includes('stilt')) return Hammer;
    if (slug.includes('replacement') || slug.includes('parts')) return Settings2;
    if (slug.includes('accessor')) return ShoppingBag;
    if (slug.includes('material') || slug.includes('consumable')) return Package;
    return Layers;
  };

  return (
    <>
      <header className="dtb-shop-menu__header">
        <span className="dtb-mega-menu__eyebrow">Our Products</span>
        <h2 className="dtb-shop-menu__title">Shop tools by category</h2>
        <p className="dtb-shop-menu__description">Browse professional drywall tools, parts, materials, and accessories.</p>
      </header>
      {groups.length > 0 && activeGroup ? (
        <div className="dtb-shop-menu__workspace">
          <aside className="dtb-shop-menu__category-rail" aria-label="Shop categories">
            <div className="dtb-shop-menu__rail-heading">
              <span className="dtb-mega-menu__eyebrow">Shop Categories</span>
              <p>Explore our full range of professional drywall tools and supplies.</p>
            </div>
            <div className="dtb-shop-menu__parent-list" role="group" aria-label="Product categories">
              {groups.map((group) => {
                const key = group.slug || group.label;
                const selected = key === (activeGroup.slug || activeGroup.label);
                const Icon = parentIconFor(group);
                const categoryKey = String(group.slug || group.label || '').trim().toLowerCase().replace(/[_\s]+/g, '-');
                const categoryImage = PARENT_CATEGORY_IMAGES[categoryKey];
                return (
                  <button
                    key={key}
                    type="button"
                    className={`dtb-shop-menu__parent-card${selected ? ' is-active' : ''}`}
                    aria-pressed={selected}
                    onMouseEnter={() => setActiveGroupKey(key)}
                    onFocus={() => setActiveGroupKey(key)}
                    onClick={() => setActiveGroupKey(key)}
                  >
                    {categoryImage ? (
                      <img className="dtb-shop-menu__parent-image" src={categoryImage} alt="" width="42" height="42" decoding="async" />
                    ) : <Icon size={24} strokeWidth={1.9} aria-hidden="true" />}
                    <span>{group.label}</span>
                    <ChevronRight size={17} strokeWidth={2.1} aria-hidden="true" />
                  </button>
                );
              })}
            </div>
          </aside>
          <section className="dtb-shop-menu__category-content" aria-live="polite" aria-label={`${activeGroup.label} subcategories`}>
            <div className="dtb-shop-menu__category-heading">
              <div>
                <span className="dtb-mega-menu__eyebrow">{activeGroup.label}</span>
                <h3>{activeGroup.label}</h3>
                <p>{activeGroup.description || `Browse ${String(activeGroup.label || '').toLowerCase()} and related products.`}</p>
              </div>
              {activeGroup.to ? (
                <Link to={activeGroup.to} className="dtb-shop-menu__view-all" onClick={onNavigate}>
                  <span>View all in this category</span>
                  <ArrowRight size={16} strokeWidth={2.2} aria-hidden="true" />
                </Link>
              ) : null}
            </div>
            {entries.length > 0 ? (
              <div className="dtb-mega-menu__products-grid">
                {entries.map((entry) => (
                  <ProductCard key={entry.to || entry.slug || entry.label} entry={entry} onNavigate={onNavigate} />
                ))}
              </div>
            ) : (
              <MegaMenuDeferredState item={item} />
            )}
          </section>
        </div>
      ) : <MegaMenuDeferredState item={item} />}
    </>
  );
}

function BrandCard({ entry, onNavigate }) {
  return (
    <Link to={entry.to} className="dtb-mega-menu__brand-card" onClick={onNavigate}>
      <MegaMenuThumb label={entry.label} logo={entry.logo} thumbnail={entry.thumbnail} />
      <span className="dtb-desktop-nav-row-text">
        <span className="dtb-desktop-nav-row-title">{entry.label}</span>
        {entry.description ? <span className="dtb-desktop-nav-row-desc">{entry.description}</span> : null}
      </span>
      <ChevronRight size={17} className="dtb-desktop-nav-row-chevron" aria-hidden="true" />
    </Link>
  );
}

function BrandGridPanelRenderer({ item, onNavigate }) {
  const entries = Array.isArray(item.items) ? item.items : [];

  return (
    <>
      <MegaMenuHero item={item} onNavigate={onNavigate} />
      {entries.length > 0 ? (
        <div className="dtb-mega-menu__brand-grid">
          {entries.map((entry) => (
            <BrandCard key={entry.to || entry.slug || entry.label} entry={entry} onNavigate={onNavigate} />
          ))}
        </div>
      ) : (
        <MegaMenuDeferredState item={item} />
      )}
    </>
  );
}

function BrandsPanelRenderer(props) {
  return <BrandGridPanelRenderer {...props} />;
}

function PartsPanelRenderer({ item, onNavigate }) {
  const brands = Array.isArray(item.items) ? item.items : [];
  return (
    <>
      <MegaMenuHero item={item} onNavigate={onNavigate} />
      {brands.length > 0 ? (
        <section className="dtb-mega-menu__parts-section" aria-label="Shop parts by brand">
          <div className="dtb-mega-menu__section-heading"><h3>Shop Parts by Brand</h3></div>
          <div className="dtb-mega-menu__brand-grid">
            {brands.map((entry) => (
              <Link key={entry.to || entry.label} to={entry.to} className="dtb-mega-menu__selector-card dtb-mega-menu__selector-card--brand" onClick={onNavigate}>
                <MegaMenuThumb label={entry.label} logo={entry.logo} />
                <span className="dtb-mega-menu__selector-label">{entry.label}</span>
                <ChevronRight size={15} className="dtb-mega-menu__selector-chevron" aria-hidden="true" />
              </Link>
            ))}
          </div>
        </section>
      ) : <MegaMenuDeferredState item={item} />}
    </>
  );
}

function SchematicsPanelRenderer(props) {
  return <BrandGridPanelRenderer {...props} />;
}

function RepairCard({ entry, onNavigate }) {
  return (
    <Link
      to={entry.to}
      className="dtb-mega-menu__brand-card dtb-mega-menu__brand-card--repair"
      onClick={onNavigate}
    >
      <span className="dtb-desktop-nav-row-text">
        <span className="dtb-desktop-nav-row-title">{entry.label}</span>
      </span>
      <ChevronRight size={17} className="dtb-desktop-nav-row-chevron" aria-hidden="true" />
    </Link>
  );
}

function RepairsPanelRenderer({ item, onNavigate }) {
  const entries = Array.isArray(item.items) ? item.items : [];

  return (
    <>
      <MegaMenuHero item={item} onNavigate={onNavigate} />
      {entries.length > 0 ? (
        <>
          <div style={{ padding: '18px 32px 12px' }}>
            <Link
              to="/repairs/packages"
              onClick={onNavigate}
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: '8px',
                color: 'var(--mega-ink)',
                fontWeight: 760,
                textDecoration: 'none',
              }}
            >
              <span>View all repair service packages</span>
              <ArrowRight size={16} strokeWidth={2.2} aria-hidden="true" />
            </Link>
          </div>
          <div className="dtb-mega-menu__brand-grid dtb-mega-menu__brand-grid--repairs">
            {entries.map((entry) => (
              <RepairCard key={entry.to || entry.slug || entry.label} entry={entry} onNavigate={onNavigate} />
            ))}
          </div>
        </>
      ) : (
        <MegaMenuDeferredState item={item} />
      )}
    </>
  );
}

const PANEL_RENDERERS = {
  products: ProductsPanelRenderer,
  brands: BrandsPanelRenderer,
  parts: PartsPanelRenderer,
  repairs: RepairsPanelRenderer,
  schematics: SchematicsPanelRenderer,
};

function DeliberatePanelRenderer({ item, onNavigate }) {
  const Renderer = PANEL_RENDERERS[item.id];
  return Renderer ? <Renderer item={item} onNavigate={onNavigate} /> : <MegaMenuDeferredState item={item} />;
}

function DesktopNavTrigger({ item, isOpen, active, panelId, onOpen, onNavigate, onCloseImmediate }) {
  const triggerRef = useRef(null);

  const closeAndFocus = () => {
    triggerRef.current?.focus();
    onCloseImmediate();
  };

  return (
    <div className={`dtb-desktop-nav-menu${isOpen ? ' is-open' : ''}`} onPointerEnter={(event) => { if (event.pointerType === 'mouse') onOpen(false); }}>
      <Link
        ref={triggerRef}
        to={item.landingTo}

        className={`dtb-desktop-nav-tab${active ? ' is-active' : ''}`}
        aria-haspopup="true"
        aria-expanded={isOpen}
        aria-controls={panelId}
        onClick={() => { onCloseImmediate(); onNavigate(); }}
        onKeyDown={(event) => {
          if (event.key === 'Escape') {
            event.preventDefault();
            closeAndFocus();
          }
        }}
      >
        <span className="dtb-desktop-nav-tab__label">{item.label}</span>
        <ChevronDown size={14} aria-hidden="true" />
      </Link>
    </div>
  );
}

export default function StorefrontDesktopNavigation({ items, openMenuId, onOpen, onClose, onNavigate, isItemActive }) {
  const reducedMotion = usePrefersReducedMotion();
  const panelId = useId();
  const desktopItems = items.filter((item) => item.id !== 'support' && item.id !== 'new-arrivals');
  const dropdownItems = desktopItems.filter((item) => item.hasDropdown || RESILIENT_DROPDOWN_IDS.has(item.id) || item.items?.length);
  const dropdownItemsById = new Map(dropdownItems.map((item) => [item.id, item]));
  const [renderedMenuId, setRenderedMenuId] = useState(() => openMenuId || null);
  const [sheetVisible, setSheetVisible] = useState(false);
  const [mountedMenuIds, setMountedMenuIds] = useState(() => new Set(openMenuId ? [openMenuId] : []));
  const openTimerRef = useRef(null);
  const closeTimerRef = useRef(null);

  const renderedItem = renderedMenuId ? dropdownItemsById.get(renderedMenuId) : null;
  const mountedItems = dropdownItems.filter((item) => mountedMenuIds.has(item.id));
  const shellOpen = Boolean(openMenuId && renderedItem);

  const clearTimer = (ref) => {
    if (ref.current !== null) {
      window.clearTimeout(ref.current);
      ref.current = null;
    }
  };

  const rememberMountedMenu = (id) => {
    setMountedMenuIds((current) => {
      if (current.has(id)) return current;
      const next = new Set(current);
      next.add(id);
      return next;
    });
  };

  const cancelPendingClose = () => {
    clearTimer(closeTimerRef);
  };

  const commitOpen = (id) => {
    cancelPendingClose();
    rememberMountedMenu(id);

    // Keep the already-open desktop sheet continuously painted. Switching
    // taxonomy tabs is an atomic content replacement; deliberately fading the
    // current panel to zero and waiting multiple frames created a visible blank
    // interval on desktop.
    setRenderedMenuId(id);
    onOpen(id);
  };

  const requestOpen = (id, immediate = false) => {
    cancelPendingClose();
    clearTimer(openTimerRef);
    if (renderedMenuId === id && openMenuId === id) return;

    // Hover intent is an interaction safeguard, not an animation: retain
    // the dwell threshold when reduced motion is requested.
    const delay = immediate
      ? 0
      : openMenuId
        ? POINTER_SWITCH_INTENT_MS
        : POINTER_OPEN_INTENT_MS;

    if (delay === 0) {
      commitOpen(id);
      return;
    }

    openTimerRef.current = window.setTimeout(() => {
      openTimerRef.current = null;
      commitOpen(id);
    }, delay);
  };

  const closeImmediately = () => {
    clearTimer(openTimerRef);
    clearTimer(closeTimerRef);
    onClose();
  };

  const requestPointerClose = () => {
    clearTimer(openTimerRef);
    clearTimer(closeTimerRef);
    closeTimerRef.current = window.setTimeout(() => {
      closeTimerRef.current = null;
      onClose();
    }, POINTER_CLOSE_DELAY_MS);
  };

  // A newly mounted panel must paint one hidden frame before opening.
  // Switching taxonomy while already open does not restart the entrance.
  useEffect(() => {
    const frame = window.requestAnimationFrame(() => setSheetVisible(shellOpen));
    return () => window.cancelAnimationFrame(frame);
  }, [shellOpen]);

  useEffect(() => () => {
    clearTimer(openTimerRef);
    clearTimer(closeTimerRef);

  }, []);

  const shellTransition = reducedMotion
    ? 'none'
    : shellOpen
      ? 'opacity var(--dtb-motion-overlay), transform var(--dtb-motion-overlay), visibility 0s linear 0s'
      : 'opacity var(--dtb-motion-exit), transform var(--dtb-motion-exit), visibility 0s linear var(--dtb-motion-duration-fast)';

  return (
    <nav
      className={`dtb-desktop-nav${shellOpen ? ' is-mega-menu-open' : ''}`}
      aria-label="Primary navigation"
      onPointerEnter={cancelPendingClose}
      onPointerLeave={requestPointerClose}
      onBlur={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget)) requestPointerClose();
      }}
    >
      {desktopItems.map((item) => (item.hasDropdown || RESILIENT_DROPDOWN_IDS.has(item.id) || item.items?.length) ? (
        <DesktopNavTrigger
          key={item.id}
          item={item}
          panelId={panelId}
          isOpen={openMenuId === item.id}
          active={isItemActive(item)}
          onOpen={(immediate = false) => requestOpen(item.id, immediate)}
          onNavigate={onNavigate}
          onCloseImmediate={closeImmediately}
        />
      ) : (
        <Link
          key={item.id}
          to={item.landingTo}
          className={`dtb-desktop-nav-tab${isItemActive(item) ? ' is-active' : ''}`}
          onPointerEnter={closeImmediately}
          onClick={onNavigate}
        >
          <span className="dtb-desktop-nav-tab__label">{item.label}</span>
        </Link>
      ))}

      {renderedItem && mountedItems.length > 0 ? (
        <div
          className="dtb-desktop-nav-menu dtb-desktop-nav-menu--shared"
          style={{ position: 'relative', width: 0, minWidth: 0, height: '100%' }}
        >
          <section
            id={panelId}
            className={`dtb-desktop-nav-dropdown dtb-desktop-nav-dropdown--shared dtb-desktop-nav-dropdown--${renderedItem.id} dtb-desktop-nav-dropdown--${renderedItem.size || 'medium'}`}
            data-open={sheetVisible && shellOpen ? 'true' : 'false'}
            aria-label={`${renderedItem.label} navigation`}
            aria-busy={CATALOG_BACKED_DROPDOWN_IDS.has(renderedItem.id) && !renderedItem.items?.length ? true : undefined}
            onPointerEnter={cancelPendingClose}
            onPointerLeave={requestPointerClose}
            onKeyDown={(event) => {
              if (event.key === 'Escape') {
                event.preventDefault();
                closeImmediately();
              }
            }}
            style={{
              '--mega-panel-width': renderedItem.id === 'products' ? '1180px' : undefined,
              opacity: sheetVisible && shellOpen ? 1 : 0,
              visibility: shellOpen ? 'visible' : 'hidden',
              pointerEvents: shellOpen ? 'auto' : 'none',
              transform: sheetVisible && shellOpen
                ? 'translateX(-50%) translateY(0) scale(1)'
                : 'translateX(-50%) translateY(-9px) scale(.985)',
              transition: shellTransition,
            }}
          >
            <div className="dtb-desktop-nav-dropdown__scroller">
              {mountedItems.map((item) => {
                const isRendered = item.id === renderedItem.id;
                return (
                  <div
                    key={item.id}
                    hidden={!isRendered}
                    aria-hidden={!isRendered ? true : undefined}
                    style={{
                      opacity: 1,
                      transform: 'none',
                      transition: 'none',
                    }}
                  >
                    <DeliberatePanelRenderer item={item} onNavigate={onNavigate} />
                  </div>
                );
              })}
            </div>
          </section>
        </div>
      ) : null}
    </nav>
  );
}
