import { Link, useLocation, useNavigate } from 'react-router-dom';
import { startTransition, useState, useEffect, useRef, useMemo, useCallback } from 'react';
import { m as Motion, useReducedMotion } from 'framer-motion';
import { collapseTransition, reducedTransition } from '../../motion/dtbMotion.js';
import { useCart } from '../../context/CartContext';
import { useAuthContext } from '../../auth/AuthContext.js';
import { ShoppingCart, X, ChevronRight, User, Headset, Phone } from 'lucide-react';
import { DTB_LOGO_WHITE_URL } from '../../utils/dtbLogoAssets.js';
import StorefrontSearchOverlay from './StorefrontSearchOverlay';
import StorefrontMobileDrawer from './StorefrontMobileDrawer';
import AccountHubSheet from '../account/AccountHubSheet.jsx';
import { searchProducts } from '../../services/catalog';
import StorefrontSearchDock from './StorefrontSearchDock';
import StorefrontDesktopNavigation from './StorefrontDesktopNavigation.jsx';
import StorefrontCatalogAutocomplete from './StorefrontCatalogAutocomplete.jsx';
import { useCatalogFacets } from '../../hooks/useCatalogFacets.js';
import { getRepairPackageGroups } from '../../data/repairPackages.js';
import { SCHEMATIC_BRANDS } from '../../data/schematicBrands.js';
import { getBrandLogo } from '../../utils/brandAssets.js';
import { resolveCategoryThumbnail } from '../../utils/categoryThumbnailImages.js';
import '../../styles/mobile-hamburger.css';
import '../../styles/mobile-header-actions.css';
import '../../styles/storefront-header-utility-bar.css';
import {
  buildCategoryPageUrl,
  buildDisplayCategoryUrl,
  mapCatalogBrands,
  mergeCatalogDisplayCategories,
  normalizeCatalogNavigationGroups,
  normalizeDisplayCategorySlug,
} from '../../utils/catalogFacets.js';

const SEARCH_OVERLAY_EXIT_MS = 360;
const MOBILE_SEARCH_DELAY_MS = 220;
const MAX_SEARCH_PRODUCTS = 6;

const DRAWER_NAV_ROWS = [
  { to: '/products?sort=newest', label: 'New Arrivals' },
  { to: '/calculators', label: 'Calculators' },
  { to: '/faq', label: 'FAQ' },
  { to: '/contact', label: 'Contact' },
];

function MobileDrawerChevron({ expanded = false, className = '' }) {
  return (
    <ChevronRight
      size={18}
      strokeWidth={2.35}
      className={`storefront-mobile-drawer__chevron${expanded ? ' is-expanded' : ''}${className ? ` ${className}` : ''}`}
      aria-hidden="true"
    />
  );
}

function MobileHamburgerToggle({ checked, onCheckedChange }) {
  const label = checked ? 'Close menu' : 'Open menu';

  return (
    <label className="header-mobile-toggle header-icon hamburger" aria-label={label}>
      <input
        type="checkbox"
        checked={checked}
        onChange={(event) => onCheckedChange(event.target.checked)}
        aria-label={label}
        aria-expanded={checked}
      />
      <svg viewBox="0 0 32 32" aria-hidden="true" focusable="false">
        <path
          className="line line-top-bottom"
          d="M27 10 13 10C10.8 10 9 8.2 9 6 9 3.5 10.8 2 13 2 15.2 2 17 3.8 17 6L17 26C17 28.2 18.8 30 21 30 23.2 30 25 28.2 25 26 25 23.8 23.2 22 21 22L7 22"
        />
        <path className="line" d="M7 16 27 16" />
      </svg>
    </label>
  );
}

function MobileTaxonomyGroup({ item, itemKey, expanded, onToggle, onNavigate }) {
  const children = Array.isArray(item.children) ? item.children : [];
  const childrenId = `storefront-mobile-taxonomy-${String(itemKey).replace(/[^a-z0-9_-]+/gi, '-')}`;

  return (
    <div
      className={`storefront-mobile-drawer__taxonomy-group${expanded ? ' is-expanded' : ''}`}
      role="group"
      aria-label={item.label}
    >
      <div className="storefront-mobile-drawer__taxonomy-heading-row">
        <button
          type="button"
          className="storefront-mobile-drawer__taxonomy-heading"
          onClick={() => onNavigate(item)}
        >
          {item.label}
        </button>
        <button
          type="button"
          className="storefront-mobile-drawer__taxonomy-toggle"
          onClick={onToggle}
          aria-label={`${expanded ? 'Collapse' : 'Expand'} ${String(item.label || 'category').toLowerCase()}`}
          aria-expanded={expanded}
          aria-controls={childrenId}
        >
          <MobileDrawerChevron expanded={expanded} />
        </button>
      </div>
      <div
        id={childrenId}
        className={`storefront-mobile-drawer__taxonomy-children${expanded ? ' is-expanded' : ''}`}
        inert={!expanded}
      >
        {children.map((child) => (
          <button
            key={`${itemKey}-${child.slug || child.to || child.label}`}
            type="button"
            className="storefront-mobile-drawer__taxonomy-child"
            onClick={() => onNavigate(child)}
          >
            {child.label}
          </button>
        ))}
      </div>
    </div>
  );
}

const buildProductsBrandRoute = (slug) => `/products/brands/${slug}`;
const buildPartsBrandRoute = (slug) => `/parts?brand=${encodeURIComponent(slug)}`;
const buildSchematicsBrandRoute = (slug) => `/schematics?brand=${encodeURIComponent(slug)}`;

function toSearchProduct(product) {
  return {
    ...product,
    priceText: typeof product?.price === 'number' ? `$${product.price.toFixed(2)}` : 'View product',
    source: product?.source || 'dtb-catalog',
  };
}

export default function Header({ onCartToggle, onMobileMenuOpen }) {
  const reduceMotion = useReducedMotion();
  const location = useLocation();
  const navigate = useNavigate();
  const { getCartCount } = useCart();
  const { user, isAuthenticated, isLoading, login, register, logout } = useAuthContext();
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [productsExpanded, setProductsExpanded] = useState(false);
  const [expandedProductGroupKey, setExpandedProductGroupKey] = useState(null);
  const [brandsExpanded, setBrandsExpanded] = useState(false);
  const [partsExpanded, setPartsExpanded] = useState(false);
  const [repairsExpanded, setRepairsExpanded] = useState(false);
  const [schematicsExpanded, setSchematicsExpanded] = useState(false);
  const [desktopNavOpen, setDesktopNavOpen] = useState(null);
  const [, setAccountDropdownOpen] = useState(false);
  const [accountHubOpen, setAccountHubOpen] = useState(false);
  const [accountUnreadCount, setAccountUnreadCount] = useState(0);
  const [desktopSearchOpen, setDesktopSearchOpen] = useState(false);
  const [desktopSearchQuery, setDesktopSearchQuery] = useState('');
  const [searchOverlayOpen, setSearchOverlayOpen] = useState(false);
  const [mobileSearchQuery, setMobileSearchQuery] = useState('');
  const [mobileSearchResults, setMobileSearchResults] = useState([]);
  const [searchLoading, setSearchLoading] = useState(false);
  const { facets } = useCatalogFacets();
  const { facets: partsFacets } = useCatalogFacets({ isParts: 1 });
  const accountDropdownRef = useRef(null);
  const desktopSearchRef = useRef(null);
  const desktopSearchInputRef = useRef(null);
  const mobileSearchInputRef = useRef(null);
  const searchOverlayRequestIdRef = useRef(0);
  const searchOverlayResetTimerRef = useRef(null);
  const prevPathnameRef = useRef(location.pathname);
  const [isTablet, setIsTablet] = useState(() => {
    try { return typeof window !== 'undefined' && window.matchMedia('(min-width: 641px) and (max-width: 1024px)').matches; }
    catch { return false; }
  });

  const isActive = (path) => location.pathname === path;
  const drawerBrands = useMemo(() => mapCatalogBrands(facets?.brands), [facets]);
  const partsBrands = useMemo(() => mapCatalogBrands(partsFacets?.brands), [partsFacets]);
  const repairPackageGroups = useMemo(() => getRepairPackageGroups().filter(({ id }) => id !== 'diagnostic'), []);
  const drawerRepairPackages = useMemo(() => repairPackageGroups.map(({ id, label }) => ({
    id,
    label,
    to: `/repairs/packages?tool=${encodeURIComponent(id)}`,
  })), [repairPackageGroups]);
  const drawerCategoryLinks = useMemo(() => {
    const displayCategories = mergeCatalogDisplayCategories(facets?.displayCategoriesByBrand || {})
      .filter((category) => category?.slug)
      .map((category) => ({
        ...category,
        to: buildDisplayCategoryUrl(category.slug),
      }))
      .sort((a, b) => String(a.label || '').localeCompare(String(b.label || '')));

    if (displayCategories.length > 0) {
      return displayCategories;
    }

    return (Array.isArray(facets?.categories) ? facets.categories : [])
      .map((category) => {
        const label = category?.label || category?.name || category?.key || '';
        const slug = category?.slug || category?.key || normalizeDisplayCategorySlug(label);
        return {
          slug,
          label,
          count: Number(category?.productCount || category?.count || 0),
          to: buildDisplayCategoryUrl(slug),
        };
      })
      .filter((category) => category.slug && category.label && category.count > 0)
      .sort((a, b) => String(a.label).localeCompare(String(b.label)));
  }, [facets]);

  const canonicalProductNavigation = useMemo(
    () => normalizeCatalogNavigationGroups(facets?.navigationGroups),
    [facets],
  );

  const drawerProductNavigation = useMemo(
    () => canonicalProductNavigation.length > 0 ? canonicalProductNavigation : drawerCategoryLinks,
    [canonicalProductNavigation, drawerCategoryLinks],
  );

  const desktopProductNavigation = useMemo(() => canonicalProductNavigation.map((group) => ({
    ...group,
    to: buildCategoryPageUrl(group.slug),
    children: (Array.isArray(group.children) ? group.children : []).map((child) => ({
      ...child,
      to: buildCategoryPageUrl(child.slug),
      thumbnail: resolveCategoryThumbnail(child),
    })),
  })), [canonicalProductNavigation]);

  const desktopNavItems = useMemo(() => [
    {
      id: 'products',
      label: 'Shop',
      landingTo: '/products',
      landingLabel: 'View all products',
      landingDescription: 'Browse our complete collection of professional finishing tools.',
      description: 'Browse professional finishing tools by system and function.',
      heading: 'Shop tools built for every phase of the job.',
      size: 'wide',
      columns: 2,
      activePrefixes: ['/products'],
      items: desktopProductNavigation,
    },
    {
      id: 'brands',
      label: 'Brands',
      landingTo: '/products/brands',
      landingLabel: 'View all brands',
      landingDescription: 'Browse our complete collection of professional tool brands.',
      description: 'Shop every professional brand in the catalog.',
      heading: 'Shop professional brands you trust.',
      size: 'wide',
      columns: 2,
      activePrefixes: ['/products/brands'],
      items: drawerBrands.map(({ name, slug }) => ({
        label: name,
        to: buildProductsBrandRoute(slug),
        description: `View ${name}`,
        logo: getBrandLogo(slug) || getBrandLogo(name),
      })),
    },
    {
      id: 'parts',
      label: 'Parts',
      landingTo: '/parts',
      landingLabel: 'View all replacement parts',
      landingDescription: 'Browse our complete collection of replacement parts.',
      description: 'Choose a brand with available replacement parts.',
      heading: 'Find OEM parts for your tools.',
      size: 'wide',
      columns: 2,
      activePrefixes: ['/parts'],
      items: partsBrands.map(({ name, slug }) => ({
        label: name,
        to: buildPartsBrandRoute(slug),
        description: `Parts for ${name}`,
        logo: getBrandLogo(slug) || getBrandLogo(name),
      })),
    },
    {
      id: 'new-arrivals',
      label: 'New Arrivals',
      landingTo: '/products?sort=newest',
      activePrefixes: [],
      items: [],
    },
    {
      id: 'repairs',
      label: 'Repair Services',
      landingTo: '/repairs',
      landingLabel: 'View all repair services',
      landingDescription: 'Browse our complete collection of repair packages.',
      description: 'Compare repair packages for your tool type.',
      heading: 'Get your tools back in the field, fast.',
      size: 'wide',
      columns: 2,
      hideIcon: true,
      activePrefixes: ['/repairs'],
      items: repairPackageGroups.map(({ id, label }) => ({
        label,
        to: `/repairs/packages?tool=${encodeURIComponent(id)}`,
        description: `View ${label} repair packages`,
      })),
    },
    {
      id: 'schematics',
      label: 'Schematics',
      landingTo: '/schematics',
      landingLabel: 'View all schematics',
      landingDescription: 'Browse all supported brands and tool schematics.',
      description: 'Explore our seamless, interactive schematic diagrams to locate matching parts quickly and confidently.',
      heading: 'Find the exact replacement part for your tool.',
      size: 'wide',
      columns: 2,
      activePrefixes: ['/schematics'],
      items: SCHEMATIC_BRANDS.map(({ name, slug }) => ({
        label: name,
        to: buildSchematicsBrandRoute(slug),
        description: `View ${name} schematics`,
        logo: getBrandLogo(slug) || getBrandLogo(name),
      })),
    },
    {
      id: 'calculators',
      label: 'Calculators',
      landingTo: '/calculators',
      activePrefixes: ['/calculators'],
      items: [],
    },
    {
      id: 'support',
      label: 'Support',
      landingTo: '/contact',
      activePrefixes: ['/contact'],
      items: [],
    },
  ], [desktopProductNavigation, drawerBrands, partsBrands, repairPackageGroups]);

  const closeMobileMenu = () => setMobileMenuOpen(false);
  const closeMenus = () => {
    setDesktopNavOpen(null);
    setMobileMenuOpen(false);
    setAccountDropdownOpen(false);
    setDesktopSearchOpen(false);
  };

  const closeSearchOverlay = useCallback(() => {
    if (searchOverlayResetTimerRef.current) {
      window.clearTimeout(searchOverlayResetTimerRef.current);
    }
    searchOverlayRequestIdRef.current += 1;
    setSearchOverlayOpen(false);
    searchOverlayResetTimerRef.current = window.setTimeout(() => {
      setMobileSearchQuery('');
      setMobileSearchResults([]);
      setSearchLoading(false);
      searchOverlayResetTimerRef.current = null;
    }, SEARCH_OVERLAY_EXIT_MS);
  }, []);

  const openSearchOverlay = useCallback(() => {
    if (searchOverlayResetTimerRef.current) {
      window.clearTimeout(searchOverlayResetTimerRef.current);
      searchOverlayResetTimerRef.current = null;
    }
    setSearchOverlayOpen(true);
  }, []);

  useEffect(() => () => {
    if (searchOverlayResetTimerRef.current) {
      window.clearTimeout(searchOverlayResetTimerRef.current);
    }
  }, []);

  const handleMobileMenuCheckedChange = useCallback((checked) => {
    if (checked) {
      onMobileMenuOpen?.();
      setAccountDropdownOpen(false);
      closeSearchOverlay();
    }
    setMobileMenuOpen(checked);
  }, [closeSearchOverlay, onMobileMenuOpen]);

  const handleCartToggle = useCallback(() => {
    setMobileMenuOpen(false);
    closeSearchOverlay();
    onCartToggle?.();
  }, [closeSearchOverlay, onCartToggle]);

  const resetDrawerExpansions = useCallback(() => {
    setProductsExpanded(false);
    setExpandedProductGroupKey(null);
    setBrandsExpanded(false);
    setPartsExpanded(false);
    setRepairsExpanded(false);
    setSchematicsExpanded(false);
  }, []);

  useEffect(() => {
    if (mobileMenuOpen) return;
    let cancelled = false;
    window.queueMicrotask(() => {
      if (!cancelled) resetDrawerExpansions();
    });
    return () => { cancelled = true; };
  }, [mobileMenuOpen, resetDrawerExpansions]);

  useEffect(() => {
    if (prevPathnameRef.current === location.pathname) return;
    prevPathnameRef.current = location.pathname;
    const t = setTimeout(() => { closeMenus(); closeSearchOverlay(); resetDrawerExpansions(); }, 0);
    return () => clearTimeout(t);
  }, [location.pathname, closeSearchOverlay, resetDrawerExpansions]);

  useEffect(() => {
    const handleKeyDown = (e) => {
      if (e.key === 'Escape') {
        closeMenus();
        closeSearchOverlay();
        resetDrawerExpansions();
        desktopSearchInputRef.current?.blur();
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [closeSearchOverlay, resetDrawerExpansions]);

  useEffect(() => {
    const mq = window.matchMedia('(min-width: 641px) and (max-width: 1024px)');
    const handler = (e) => setIsTablet(e.matches);
    if (mq.addEventListener) mq.addEventListener('change', handler);
    else mq.addListener(handler);
    return () => {
      if (mq.removeEventListener) mq.removeEventListener('change', handler);
      else mq.removeListener(handler);
    };
  }, []);

  // Keep the mobile header visually and geometrically identical throughout
  // native document scrolling. Updating React state at a scroll threshold used
  // to restyle persistent chrome regardless of which homepage section was on
  // screen. Mobile browser UI can also resize the visual viewport during touch
  // scrolling; neither event should animate this fixed commerce shell.
  useEffect(() => {
    const handleClickOutside = (e) => {
      const header = document.querySelector('.site-header');
      if (header && !header.contains(e.target)) {
        setDesktopNavOpen(null);
        setDesktopSearchOpen(false);
      }
      if (accountDropdownRef.current && !accountDropdownRef.current.contains(e.target)) setAccountDropdownOpen(false);
      if (desktopSearchRef.current && !desktopSearchRef.current.contains(e.target)) setDesktopSearchOpen(false);
    };
    document.addEventListener('click', handleClickOutside);
    return () => document.removeEventListener('click', handleClickOutside);
  }, []);

  useEffect(() => {
    if (!desktopSearchOpen || !window.matchMedia('(min-width: 1025px)').matches) return undefined;

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    return () => {
      document.body.style.overflow = previousOverflow;
    };
  }, [desktopSearchOpen]);

  useEffect(() => {
    const root = document.documentElement;
    const header = document.querySelector('.site-header');
    if (!root || !header) return undefined;

    let scheduledFrame = 0;
    let lastHeight = -1;

    const updateHeaderHeight = () => {
      const { height } = header.getBoundingClientRect();
      if (!Number.isFinite(height) || height <= 0) return;

      // Use intrinsic height, never the viewport-relative bottom coordinate.
      // Feeding `bottom` into body padding creates an unbounded layout loop:
      // padding moves the header, its next bottom grows, then padding grows again.
      const nextHeight = Math.min(320, Math.ceil(height));
      if (nextHeight === lastHeight) return;
      lastHeight = nextHeight;
      root.style.setProperty('--header-height', `${nextHeight}px`);
    };

    const scheduleHeaderHeightUpdate = () => {
      if (scheduledFrame) return;
      scheduledFrame = window.requestAnimationFrame(() => {
        scheduledFrame = 0;
        updateHeaderHeight();
      });
    };

    // The initial synchronous measurement happens after this effect's first
    // paint. Let the single scheduled read handle it so CSS/header writes are
    // batched with other frame work instead of forcing two layouts on boot.
    scheduleHeaderHeightUpdate();
    const resizeObserver = typeof ResizeObserver !== 'undefined'
      ? new ResizeObserver(scheduleHeaderHeightUpdate)
      : null;

    resizeObserver?.observe(header);
    window.addEventListener('resize', scheduleHeaderHeightUpdate);
    window.addEventListener('orientationchange', scheduleHeaderHeightUpdate);

    return () => {
      if (scheduledFrame) window.cancelAnimationFrame(scheduledFrame);
      resizeObserver?.disconnect();
      window.removeEventListener('resize', scheduleHeaderHeightUpdate);
      window.removeEventListener('orientationchange', scheduleHeaderHeightUpdate);
    };
  }, [mobileMenuOpen, isTablet]);

  useEffect(() => {
    const query = mobileSearchQuery.trim();
    const requestId = searchOverlayRequestIdRef.current + 1;
    searchOverlayRequestIdRef.current = requestId;

    if (!query) {
      let cancelled = false;
      window.queueMicrotask(() => {
        if (cancelled || searchOverlayRequestIdRef.current !== requestId) return;
        setMobileSearchResults([]);
        setSearchLoading(false);
      });
      return () => { cancelled = true; };
    }

    const t = window.setTimeout(async () => {
      setSearchLoading(true);
      try {
        const products = (await searchProducts(query))
          .slice(0, MAX_SEARCH_PRODUCTS)
          .map(toSearchProduct);
        if (searchOverlayRequestIdRef.current !== requestId) return;
        startTransition(() => {
          setMobileSearchResults(products);
        });
      } catch (err) {
        if (searchOverlayRequestIdRef.current === requestId) {
          console.error('Mobile catalog search error:', err);
          setMobileSearchResults([]);
        }
      } finally {
        if (searchOverlayRequestIdRef.current === requestId) setSearchLoading(false);
      }
    }, MOBILE_SEARCH_DELAY_MS);

    return () => {
      window.clearTimeout(t);
    };
  }, [mobileSearchQuery]);

  const handleDesktopResultClick = (product) => {
    const target = product?.slug ? `/products/${product.slug}` : `/product/${product?.id}`;
    navigate(target);
    setDesktopSearchOpen(false);
    setDesktopSearchQuery('');
  };

  const handleDesktopViewAll = () => {
    const q = desktopSearchQuery.trim();
    navigate(`/products${q ? `?search=${encodeURIComponent(q)}` : ''}`);
    setDesktopSearchOpen(false);
  };

  const handleMobileAccountClick = () => {
    setMobileMenuOpen(false);
    setAccountHubOpen(true);
  };

  const navigateShopDestination = useCallback((to, { closeMobile = false } = {}) => {
    resetDrawerExpansions();
    setDesktopNavOpen(null);
    if (closeMobile) setMobileMenuOpen(false);
    navigate(to);
  }, [navigate, resetDrawerExpansions]);

  const closeDrawerAndNavigate = (to) => navigateShopDestination(to, { closeMobile: true });

  const handleDrawerBrandNavigate = (slug) => closeDrawerAndNavigate(buildProductsBrandRoute(slug));
  const handleDrawerPartsBrandNavigate = (slug) => closeDrawerAndNavigate(buildPartsBrandRoute(slug));
  const handleDrawerSchematicsBrandNavigate = (slug) => closeDrawerAndNavigate(buildSchematicsBrandRoute(slug));
  const handleDrawerBrandsLanding = () => closeDrawerAndNavigate('/products/brands');
  const handleDrawerPartsLanding = () => closeDrawerAndNavigate('/parts');
  const handleDrawerRepairsLanding = () => closeDrawerAndNavigate('/repairs');
  const handleDrawerRepairPackageNavigate = (repairPackage) => closeDrawerAndNavigate(repairPackage.to);
  const handleDrawerSchematicsLanding = () => closeDrawerAndNavigate('/schematics');
  const handleDrawerProductsLanding = () => closeDrawerAndNavigate('/products');
  const handleDrawerProductCategoryNavigate = (to) => closeDrawerAndNavigate(to);

  const handleMobileViewAll = useCallback(() => {
    const q = mobileSearchQuery.trim();
    navigate(`/products${q ? `?search=${encodeURIComponent(q)}` : ''}`);
    closeSearchOverlay();
  }, [mobileSearchQuery, navigate, closeSearchOverlay]);

  const desktopSearchHasQuery = desktopSearchQuery.trim().length > 0;
  const desktopSearchVisible = desktopSearchOpen && desktopSearchHasQuery;
  const isDesktopNavItemActive = useCallback((item) => {
    if (item.id === 'new-arrivals') {
      return location.pathname === '/products' && new URLSearchParams(location.search).get('sort') === 'newest';
    }
    if (item.id === 'products') {
      return location.pathname.startsWith('/products')
        && !location.pathname.startsWith('/products/brands')
        && new URLSearchParams(location.search).get('sort') !== 'newest';
    }
    return item.activePrefixes?.some((prefix) => location.pathname.startsWith(prefix)) || false;
  }, [location.pathname, location.search]);

  const renderDrawerListSection = ({ id, label, expanded, onToggle, onLanding, items, onItemNavigate }) => (
    <div className="storefront-mobile-drawer__row-wrap" key={id}>
      <div className="storefront-mobile-drawer__row">
        <button type="button" className="storefront-mobile-drawer__row-label" onClick={onLanding}>
          {label}
        </button>
        <button
          type="button"
          className="storefront-mobile-drawer__row-toggle"
          onClick={onToggle}
          aria-label={`${expanded ? 'Collapse' : 'Expand'} ${label.toLowerCase()}`}
          aria-expanded={expanded}
          aria-controls={`storefront-mobile-drawer-${id}`}
        >
          <MobileDrawerChevron expanded={expanded} />
        </button>
      </div>
      <Motion.div
        id={`storefront-mobile-drawer-${id}`}
        className={`storefront-mobile-drawer__brands${expanded ? ' is-expanded' : ''}`}
        inert={!expanded}
        initial={false}
        animate={{ height: expanded ? 'auto' : 0, opacity: expanded ? 1 : 0 }}
        transition={reduceMotion ? reducedTransition : collapseTransition}
      >
        {items.map((item) => {
          const children = Array.isArray(item.children) ? item.children : [];
          const itemKey = `${id}-${item.slug || item.to || item.name || item.label}`;

          if (children.length === 0) {
            return (
              <button
                key={itemKey}
                type="button"
                className="storefront-mobile-drawer__brand-link"
                onClick={() => onItemNavigate(item)}
              >
                {item.name || item.label}
              </button>
            );
          }

          return (
            <MobileTaxonomyGroup
              key={itemKey}
              item={item}
              itemKey={itemKey}
              expanded={expandedProductGroupKey === itemKey}
              onToggle={() => setExpandedProductGroupKey((current) => (current === itemKey ? null : itemKey))}
              onNavigate={onItemNavigate}
            />
          );
        })}
      </Motion.div>
    </div>
  );

  const renderDrawerBrandSection = ({ id, label, expanded, onToggle, onLanding, items = drawerBrands, onBrandNavigate }) => renderDrawerListSection({
    id,
    label,
    expanded,
    onToggle,
    onLanding,
    items,
    onItemNavigate: (brand) => onBrandNavigate(brand.slug),
  });

  return (
    <>
      <header className="site-header site-header--no-ticker" role="banner">
        <div className="dtb-header-utility-bar">
          <Link to="/contact" className="dtb-header-utility-bar__item">
            <Headset size={17} strokeWidth={2} aria-hidden="true" />
            Expert Support
          </Link>
          <a href="tel:+16098665269" className="dtb-header-utility-bar__item dtb-header-utility-bar__phone">
            <Phone size={17} strokeWidth={2} aria-hidden="true" />
            (609) 866-5269
          </a>
        </div>
        <div className="site-header-inner">
          <div className="header-mobile-layout" style={{ display: isTablet ? 'flex' : undefined }}>
            <div className="header-mobile-slot header-mobile-slot--left">
              <MobileHamburgerToggle checked={mobileMenuOpen} onCheckedChange={handleMobileMenuCheckedChange} />
            </div>

            <Link to="/" className="header-mobile-logo" onClick={closeMobileMenu}>
              <img src={DTB_LOGO_WHITE_URL} alt="Drywall Toolbox Logo" className="logo-image-mobile" width="162" height="50" decoding="async" />
            </Link>

            <div className="header-mobile-slot header-mobile-slot--right">
              <button
                type="button"
                onClick={() => { if (!isLoading) handleMobileAccountClick(); }}
                className={`header-mobile-account-toggle header-icon${isLoading ? ' is-loading' : ''}`}
                aria-label={isLoading ? 'Loading account' : isAuthenticated ? 'Open account hub' : 'Sign in'}
                aria-busy={isLoading}
                disabled={isLoading}
              >
                <span className="header-account-toggle__icon" aria-hidden="true"><User size={20} /></span>
                {isAuthenticated && accountUnreadCount > 0 ? <span className="account-alert-badge">{accountUnreadCount > 99 ? '99+' : accountUnreadCount}</span> : null}
              </button>
              <button
                type="button"
                onClick={handleCartToggle}
                className="header-mobile-cart-toggle cart-toggle header-icon"
                aria-label="Open cart"
              >
                <ShoppingCart size={20} />
                {getCartCount() > 0 ? (
                  <span className="cart-badge" aria-label={`${getCartCount()} items in cart`}>
                    {getCartCount()}
                  </span>
                ) : null}
              </button>
            </div>
          </div>

          <div className={`header-desktop-layout${desktopSearchOpen ? ' is-desktop-search-open' : ''}`} style={{ display: isTablet ? 'none' : undefined }}>
            <div className="header-left"><Link to="/" className="header-logo-link" aria-label="Drywall Toolbox home"><img src={DTB_LOGO_WHITE_URL} alt="Drywall Toolbox Logo" className="logo-image" width="230" height="70" decoding="async" /></Link></div>
            <div className="header-desktop-nav-row">
              <StorefrontDesktopNavigation
                items={desktopNavItems}
                openMenuId={desktopNavOpen}
                onOpen={(id) => setDesktopNavOpen(id)}
                onClose={() => setDesktopNavOpen(null)}
                onNavigate={() => setDesktopNavOpen(null)}
                isItemActive={isDesktopNavItemActive}
              />
            </div>
            <div className="header-center header-center--desktop-search">
              <div ref={desktopSearchRef} className="dtb-desktop-search dtb-desktop-search--header" data-results-open={desktopSearchVisible ? 'true' : 'false'}>
                <StorefrontCatalogAutocomplete
                  inputRef={desktopSearchInputRef}
                  query={desktopSearchQuery}
                  open={desktopSearchVisible}
                  onOpenChange={(nextOpen) => setDesktopSearchOpen(nextOpen)}
                  onQueryChange={(nextQuery) => {
                    setDesktopSearchQuery(nextQuery);
                    setDesktopSearchOpen(true);
                  }}
                  onFocus={() => {
                    setDesktopNavOpen(null);
                    setDesktopSearchOpen(true);
                  }}
                  onProductSelect={handleDesktopResultClick}
                  onViewAll={handleDesktopViewAll}
                />
              </div>
            </div>
            <div className="header-right header-desktop-actions">
              <div ref={accountDropdownRef} className="header-account">
                <button
                  type="button"
                  onClick={() => {
                    if (isLoading) return;
                    setAccountDropdownOpen(false);
                    setAccountHubOpen(true);
                  }}
                  aria-label={isLoading ? 'Loading account' : `Open account hub${accountUnreadCount ? `, ${accountUnreadCount} unread notifications` : ''}`}
                  aria-expanded={accountHubOpen}
                  aria-busy={isLoading}
                  disabled={isLoading}
                  className={`header-account-toggle header-icon${isLoading ? ' is-loading' : ''}`}
                >
                  <span className="header-account-toggle__icon" aria-hidden="true"><User size={24} strokeWidth={1.9} /></span>
                  {isAuthenticated && accountUnreadCount > 0 ? <span className="account-alert-badge">{accountUnreadCount > 99 ? '99+' : accountUnreadCount}</span> : null}
                </button>
              </div>
              <div className="cart-area"><button onClick={handleCartToggle} className="cart-toggle header-icon" aria-label="Toggle cart"><ShoppingCart size={24} strokeWidth={1.9} />{getCartCount() > 0 && <span className="cart-badge">{getCartCount()}</span>}</button></div>
            </div>
          </div>
        </div>

        <div className="header-mobile-search-dock">
          <StorefrontSearchDock
            inputRef={mobileSearchInputRef}
            value={mobileSearchQuery}
            active={searchOverlayOpen}
            onChange={(event) => {
              const nextQuery = event.target.value;
              setMobileSearchQuery(nextQuery);
              if (nextQuery.trim()) {
                if (!searchOverlayOpen) openSearchOverlay();
              }
            }}
            onFocus={() => {
              setMobileMenuOpen(false);
              openSearchOverlay();
            }}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                event.preventDefault();
                handleMobileViewAll();
              }
            }}
            endAdornment={searchOverlayOpen && mobileSearchQuery.trim() ? (
              <button
                type="button"
                className="storefront-search-dock__clear"
                onClick={(event) => {
                  event.preventDefault();
                  event.stopPropagation();
                  closeSearchOverlay();
                }}
                aria-label="Close search"
              >
                <X size={16} />
              </button>
            ) : null}
          />
        </div>

      </header>

      <button
        type="button"
        className={`dtb-desktop-search-backdrop${desktopSearchOpen ? ' is-open' : ''}`}
        onClick={() => {
          setDesktopSearchOpen(false);
          desktopSearchInputRef.current?.blur();
        }}
        aria-label="Close product search"
        tabIndex={desktopSearchOpen ? 0 : -1}
        aria-hidden={!desktopSearchOpen}
      />

      <StorefrontMobileDrawer isOpen={mobileMenuOpen} onClose={closeMobileMenu}>
        <nav className="storefront-mobile-drawer__nav" aria-label="Mobile navigation">
          {renderDrawerListSection({
            id: 'products',
            label: 'Shop',
            expanded: productsExpanded,
            onToggle: () => {
              if (productsExpanded) setExpandedProductGroupKey(null);
              setProductsExpanded((open) => !open);
            },
            onLanding: handleDrawerProductsLanding,
            items: drawerProductNavigation,
            onItemNavigate: (category) => handleDrawerProductCategoryNavigate(category.to),
          })}
          {renderDrawerBrandSection({
            id: 'brands',
            label: 'Brands',
            expanded: brandsExpanded,
            onToggle: () => setBrandsExpanded((open) => !open),
            onLanding: handleDrawerBrandsLanding,
            items: drawerBrands,
            onBrandNavigate: handleDrawerBrandNavigate,
          })}
          {renderDrawerBrandSection({
            id: 'parts',
            label: 'Parts',
            expanded: partsExpanded,
            onToggle: () => setPartsExpanded((open) => !open),
            onLanding: handleDrawerPartsLanding,
            items: partsBrands,
            onBrandNavigate: handleDrawerPartsBrandNavigate,
          })}
          {renderDrawerListSection({
            id: 'repairs',
            label: 'Repair Services',
            expanded: repairsExpanded,
            onToggle: () => setRepairsExpanded((open) => !open),
            onLanding: handleDrawerRepairsLanding,
            items: drawerRepairPackages,
            onItemNavigate: handleDrawerRepairPackageNavigate,
          })}
          {renderDrawerBrandSection({
            id: 'schematics',
            label: 'Schematics',
            expanded: schematicsExpanded,
            onToggle: () => setSchematicsExpanded((open) => !open),
            onLanding: handleDrawerSchematicsLanding,
            items: SCHEMATIC_BRANDS,
            onBrandNavigate: handleDrawerSchematicsBrandNavigate,
          })}
          {DRAWER_NAV_ROWS.map(({ to, label }) => (
            <Link
              key={to}
              to={to}
              className={`storefront-mobile-drawer__row-link${isActive(to) ? ' is-active' : ''}`}
              onClick={closeMobileMenu}
            >
              {label}
            </Link>
          ))}
        </nav>
      </StorefrontMobileDrawer>

      <StorefrontSearchOverlay
        isOpen={searchOverlayOpen}
        query={mobileSearchQuery}
        setQuery={setMobileSearchQuery}
        results={mobileSearchResults}
        loading={searchLoading}
        onClose={closeSearchOverlay}
        onViewAll={handleMobileViewAll}
      />

      <AccountHubSheet
        isOpen={accountHubOpen}
        onClose={() => setAccountHubOpen(false)}
        user={user}
        onLogin={login}
        onRegister={register}
        authLoading={isLoading}
        onLogout={logout}
        onUnreadCountChange={setAccountUnreadCount}
      />
    </>
  );
}
