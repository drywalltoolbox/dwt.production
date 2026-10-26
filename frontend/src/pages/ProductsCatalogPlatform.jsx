import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Filter, LayoutGrid, List, ShoppingCart } from 'lucide-react';
import SEOHead from '../components/shared/SEOHead';
import BackButton from '../components/shared/BackButton';
import SearchBar from '../components/catalog/SearchBar';
import Dropdown from '../components/ui/Dropdown';
import FilterPanel from '../components/catalog/FilterPanel';
import Pagination from '../components/catalog/Pagination';
import ProductShoppingCard from '../components/ui/ProductShoppingCard';
import ProductModal from '../components/product/ProductModal';
import ProductDetailPlatform from '../components/product/ProductDetailPlatform';
import Toast from '../components/ui/Toast';
import { ProductSkeletonGrid, SelectorSkeletonGrid } from '../components/catalog/ProductShoppingCardSkeleton';
import ProductsBrandSelector from '../components/catalog/ProductsBrandSelector.jsx';
import ProductsCategorySelector from '../components/catalog/ProductsCategorySelector.jsx';
import CategoryHero from '../components/catalog/CategoryHero.jsx';
import ShopByToolType from '../components/catalog/ShopByToolType.jsx';
import Breadcrumb from '../components/shared/Breadcrumb.jsx';
import LoadingCardTransition from '../components/shared/LoadingCardTransition.jsx';
import { SORT_OPTIONS } from '../constants/sortOptions';
import { useCatalogFacets } from '../hooks/useCatalogFacets';
import { useCatalogProducts } from '../hooks/useCatalogProducts';
import { useCatalogCategory } from '../hooks/useCatalogCategory';
import { useCart } from '../context/CartContext';
import { useWorkflowTransition } from '../context/WorkflowTransitionContext.jsx';
import { buildBreadcrumbSchema, buildCategorySchema, buildSiteLinksSearchBoxSchema } from '../utils/schema';
import { getBrandLogo } from '../utils/brandAssets.js';
import {
  brandToSlug,
  buildCatalogUrl,
  canonicalBrandLabel,
  parseCatalogQuery,
} from '../utils/catalogUrlState.js';
import {
  buildCategoryPageUrl,
  dedupeCatalogBrandEntries,
  flattenCatalogNavigationGroups,
  normalizeCatalogNavigationGroups,
  normalizeDisplayCategorySlug,
} from '../utils/catalogFacets.js';
import { searchProducts } from '../services/catalog.js';
import { fetchCatalogProducts } from '../services/catalogPlatformCache.js';
import { normalizeCatalogDisplayName } from '../utils/catalogDtoAdapters.js';

// Canonical display category labels for the customer-facing filter UI.
// Mirrors CategoryNormalizer::DISPLAY_CATEGORY_LABELS on the backend.
const DISPLAY_CATEGORY_LABELS = {
  automatic_tapers:      'Automatic Tapers',
  automatic_angle_heads: 'Angle Heads',
  automatic_corner_finishers: 'Corner Finishers',
  automatic_compound_tubes: 'Compound Tubes',
  automatic_compound_applicators: 'Compound Applicators',
  automatic_corner_flushers: 'Corner Flushers',
  automatic_handles_extensions: 'Handles',
  automatic_loading_pumps: 'Loading Pumps',
  automatic_tool_sets:   'Tool Sets',
  nail_spotters:         'Nail Spotters',
  finishing_boxes:       'Finishing Boxes',
  handles:               'Handles',
  pumps:                 'Mud Pans & Pumps',
  corner_tools:          'Corner Tools',
  accessories:           'Accessories',
  smoothing_blades:      'Smoothing Blades',
  toolsets:              'Tool Sets & Kits',
  parts:                 'Parts',
  bead_tools:            'Bead Tools',
  bead_hoppers:          'Bead Hoppers',
  bead_rollers:          'Corner Bead Rollers',
  mud_pans:              'Mud Pans',
  mud_mixing_tools:      'Mud Mixing Tools',
  drywall_squares:       'Drywall Squares',
  drywall_saws:          'Drywall Saws',
  fastener_setting_tools: 'Fastener Setting Tools',
  mud_pan_accessories:   'Mud Pan Accessories',
  sealants:              'Sealants',
  drywall_hand_tools:    'Drywall Hand Tools',
  drywall_screws_fasteners: 'Drywall Screws & Fasteners',
  drywall_sealants:      'Drywall Sealants',
  stilts:                'Stilts',
  semi_automatic_tools:  'Semi-Automatic Tools',
  predator_family:       'Predator Family',
};

// Alias map: normalized raw slug → canonical slug.
// Mirrors CategoryNormalizer::DISPLAY_CATEGORY_ALIASES on the backend.
// Keys are lowercased with spaces/hyphens collapsed to underscores.
const DISPLAY_CATEGORY_ALIASES = {
  automatic_taping_tools:    'automatic_tapers',
  automatic_tapers:          'automatic_tapers',
  automatic_angle_heads:     'automatic_angle_heads',
  automatic_corner_finishers: 'automatic_corner_finishers',
  angle_heads:                'automatic_angle_heads',
  angle_head:                 'automatic_angle_heads',
  corner_finishers:           'automatic_corner_finishers',
  corner_finisher:            'automatic_corner_finishers',
  automatic_taper:           'automatic_tapers',
  taper:                     'automatic_tapers',
  automatic_compound_tubes: 'automatic_compound_tubes',
  semi_compound_tubes:      'automatic_compound_tubes',
  compound_tubes:           'automatic_compound_tubes',
  automatic_compound_applicators: 'automatic_compound_applicators',
  semi_compound_applicators: 'automatic_compound_applicators',
  compound_applicators:     'automatic_compound_applicators',
  automatic_corner_flushers: 'automatic_corner_flushers',
  semi_corner_flushers:     'automatic_corner_flushers',
  automatic_handles_extensions: 'handles',
  automatic_loading_pumps: 'automatic_loading_pumps',
  semi_handles_extensions:  'automatic_handles_extensions',
  automatic_tool_sets:      'automatic_tool_sets',
  semi_tool_sets:           'automatic_tool_sets',
  nail_spotters:             'nail_spotters',
  nail_spotter:              'nail_spotters',
  nailspotter:               'nail_spotters',
  nailspotters:              'nail_spotters',
  spotter:                   'nail_spotters',
  finishing_boxes:           'finishing_boxes',
  finishing_box:             'finishing_boxes',
  flat_box:                  'finishing_boxes',
  flatbox:                   'finishing_boxes',
  drywall_box:               'finishing_boxes',
  handles:                   'handles',
  handle:                    'handles',
  handles_and_extensions:    'handles',
  pumps:                     'pumps',
  pump:                      'pumps',
  mud_pans_and_pumps:        'pumps',
  corner_tools:              'corner_tools',
  corner_tool:               'corner_tools',
  accessories:               'accessories',
  smoothing_blades:          'smoothing_blades',
  smoothing_blade:           'smoothing_blades',
  toolsets:                  'toolsets',
  toolset:                   'toolsets',
  tool_sets_and_kits:        'toolsets',
  parts:                     'parts',
  bead_tools:                'bead_tools',
  bead_hoppers:              'bead_hoppers',
  bead_rollers:              'bead_rollers',
  mud_pans:                  'mud_pans',
  mud_mixing_tools:          'mud_mixing_tools',
  drywall_squares:           'drywall_squares',
  drywall_saws:              'drywall_saws',
  fastener_setting_tools:    'fastener_setting_tools',
  mud_pan_accessories:       'mud_pan_accessories',
  sealants:                  'sealants',
  drywall_hand_tools:        'drywall_hand_tools',
  drywall_screws_fasteners:  'drywall_screws_fasteners',
  drywall_sealants:          'drywall_sealants',
  replacement_parts:         'parts',
  stilts:                    'stilts',
  semi_automatic_tools:      'semi_automatic_tools',
  semi_automatic_tapers:     'semi_automatic_tools',
  predator_family:           'predator_family',
  predator:                  'predator_family',
};

function canonicalDisplayCategoryId(raw = '') {
  const normalized = normalizeDisplayCategorySlug(raw);
  return DISPLAY_CATEGORY_ALIASES[normalized] || normalized;
}

function toCardProduct(dto) {
  const card = dto?.cardProduct || null;
  const categoryKey = dto?.category?.key || '';
  const displayCategoryKey = dto?.displayCategory?.slug || dto?.displayCategory?.key || '';
  const effectivePrice = dto?.price?.effective ?? dto?.price?.current ?? dto?.price?.value;
  const regularPrice = dto?.price?.regular ?? card?.regularPrice ?? card?.regular_price ?? null;
  const salePrice = dto?.price?.sale ?? card?.salePrice ?? card?.sale_price ?? null;
  const price = effectivePrice ?? dto?.price?.min ?? card?.price ?? 0;

  const mapped = {
    ...dto,
    brand: dto?.brand?.label || dto?.brandLabel || '',
    category: categoryKey,
    display_category: displayCategoryKey,
    display_category_label: dto?.displayCategory?.label || displayCategoryKey,
    image: dto?.media?.image || card?.image || '',
    images: dto?.media?.images || [],
    price: typeof price === 'number' ? price : parseFloat(String(price || 0)),
    regular_price: regularPrice,
    sale_price: salePrice,
    compare_at_price: regularPrice,
    stock_status: dto?.inventory?.stockStatus || card?.stockStatus || 'instock',
    name: normalizeCatalogDisplayName(dto?.name || '', {
      sku: dto?.sku || '',
      brand: dto?.brand?.label || dto?.brandLabel || '',
    }),
    is_variable: dto?.type === 'variable',
    is_parts: Boolean(dto?.isParts),
    min_price: dto?.price?.min ?? null,
    max_price: dto?.price?.max ?? null,
    min_regular_price: dto?.price?.minRegular ?? dto?.price?.min_regular ?? null,
    variation_attributes: dto?.variationAttributes || dto?.attributes || [],
  };

  mapped.cardProduct = card
    ? {
        ...card,
        parent_id: card?.parentId || card?.parent_id || null,
        stock_status: card?.stockStatus || card?.stock_status || mapped.stock_status,
        image: card?.image || mapped.image,
        images: card?.images || mapped.images,
        image_thumbnail: card?.imageThumbnail || card?.image_thumbnail || mapped.image_thumbnail,
        image_srcset: card?.imageSrcset || card?.image_srcset || mapped.image_srcset,
        price: card?.price ?? mapped.price,
        regular_price: card?.regularPrice ?? card?.regular_price ?? mapped.regular_price,
        sale_price: card?.salePrice ?? card?.sale_price ?? mapped.sale_price,
        compare_at_price: card?.regularPrice ?? card?.regular_price ?? mapped.compare_at_price,
        brand: mapped.brand,
        name: normalizeCatalogDisplayName(card?.name || mapped.name || '', {
          sku: card?.sku || '',
          parentSku: dto?.sku || '',
          brand: mapped.brand,
        }),
        parentName: mapped.name,
        variation_label: card?.variationLabel || card?.variation_label || '',
      }
    : null;

  return mapped;
}

function canonicalCategoryKey(category = {}) {
  const raw = category?.slug || category?.key || category?.label || category?.name || '';
  return canonicalDisplayCategoryId(raw);
}

function mergeCategoryEntries(items = []) {
  const merged = new Map();

  (Array.isArray(items) ? items : []).forEach((cat) => {
    const id = canonicalCategoryKey(cat);
    if (!id) return;

    const existing = merged.get(id);
    const canonicalLabel = DISPLAY_CATEGORY_LABELS[id];
    const slug = cat?.slug || String(id).replace(/_/g, '-');
    merged.set(id, {
      id: slug,
      key: cat?.key || existing?.key || id,
      slug,
      name: canonicalLabel || cat?.label || cat?.name || existing?.name || formatCategoryLabel(id),
      count: (existing?.count || 0) + Number(cat?.productCount || cat?.count || 0),
      image: existing?.image || cat?.image || '',
    });
  });

  return Array.from(merged.values())
    .filter((cat) => cat.count > 0)
    .sort((a, b) => a.name.localeCompare(b.name));
}

function mergeDisplayCategories(displayCategoriesByBrand = {}) {
  const allCategories = [];
  Object.values(displayCategoriesByBrand || {}).forEach((items) => {
    if (Array.isArray(items)) allCategories.push(...items);
  });
  return mergeCategoryEntries(allCategories);
}

function getDisplayCategoriesForBrand(displayCategoriesByBrand = {}, brandFacet = null, selectedBrand = '', routeBrandSlug = '') {
  if (!displayCategoriesByBrand || typeof displayCategoriesByBrand !== 'object') return [];

  const selectedLabel = canonicalBrandLabel(selectedBrand);
  const candidates = [
    brandFacet?.key,
    brandFacet?.slug,
    routeBrandSlug,
    selectedBrand,
    selectedLabel,
    brandToSlug(selectedBrand),
    brandToSlug(selectedLabel),
  ].filter(Boolean);

  for (const candidate of candidates) {
    const direct = displayCategoriesByBrand[candidate];
    if (Array.isArray(direct)) return direct;

    const slug = brandToSlug(candidate);
    if (slug && Array.isArray(displayCategoriesByBrand[slug])) return displayCategoriesByBrand[slug];
  }

  const scopedEntries = Object.values(displayCategoriesByBrand).filter(Array.isArray);
  return scopedEntries.length === 1 ? scopedEntries[0] : [];
}

function toBrandFacet(rawBrand = {}) {
  const label = canonicalBrandLabel(rawBrand.label || rawBrand.name || rawBrand.key || rawBrand.slug || '');
  const slug = brandToSlug(label);
  return {
    key: rawBrand.key || slug,
    label,
    slug,
    logo: getBrandLogo(label || slug) || rawBrand.logo || rawBrand.image || rawBrand.imageUrl || '',
    productCount: rawBrand.productCount || rawBrand.count || 0,
  };
}

function formatCategoryLabel(value) {
  return String(value || '')
    .replace(/[_-]+/g, ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase());
}

function CatalogError({ title, message, onRetry }) {
  return (
    <div className="rounded-2xl border border-red-100 bg-red-50 p-6 text-red-900">
      <h2 className="text-lg font-bold mb-2">{title}</h2>
      <p className="text-sm mb-3">{message}</p>
      {onRetry && (
        <button type="button" onClick={onRetry} className="inline-flex items-center justify-center px-4 py-2 rounded-lg bg-red-600 text-white text-sm font-semibold hover:bg-red-700">
          Reload catalog
        </button>
      )}
    </div>
  );
}

export default function ProductsCatalogPlatform({ forceProductGrid = false, title = 'Products', isPartsFilter = null } = {}) {
  const location = useLocation();
  const navigate = useNavigate();
  const { brandSlug, categorySlug, categoryPathSlug } = useParams();
  const { addToCart } = useCart();
  const { runWorkflow } = useWorkflowTransition();

  const [showFilters, setShowFilters] = useState(false);
  const [displayMode, setDisplayMode] = useState(() => {
    if (typeof window === 'undefined') return 'grid';
    const saved = window.localStorage.getItem('dtb-catalog-display-mode');
    return saved === 'grid' || saved === 'list' ? saved : 'grid';
  });
  const [modalProduct, setModalProduct] = useState(null);
  const modalClearTimerRef = useRef(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [toast, setToast] = useState(null);

  const isPartsPage = isPartsFilter === 1;
  const pathParams = useMemo(
    () => ({ brandSlug, categorySlug, categoryPathSlug, basePath: isPartsPage ? '/parts' : '/products' }),
    [brandSlug, categorySlug, categoryPathSlug, isPartsPage],
  );
  const isCategoryPageRoute = Boolean(categoryPathSlug);
  const query = useMemo(() => parseCatalogQuery(new URLSearchParams(location.search), pathParams), [location.search, pathParams]);
  const selectedBrand = query.brands?.[0] || '';
  const [searchInput, setSearchInput] = useState(() => query.search || '');
  const [searchSuggestions, setSearchSuggestions] = useState([]);
  const [searchSuggestionsLoading, setSearchSuggestionsLoading] = useState(false);
  const searchRequestIdRef = useRef(0);
  const committedSearchRef = useRef(query.search || '');

  const isBrandSelectorRoute = location.pathname === '/products/brands';
  const isBrandCategorySelectorRoute = Boolean(brandSlug) && !categorySlug && location.pathname.startsWith('/products/brands/');
  const isAllProductsRoute = location.pathname === '/products' && !query.search.trim();
  const isSelectorRoute = !forceProductGrid && (isBrandSelectorRoute || isBrandCategorySelectorRoute);
  const productsEnabled = !isSelectorRoute || Boolean(query.search);

  // There are two storefront catalog scopes: the general Products catalog and
  // the dedicated Parts catalog. Products is intentionally unconstrained and
  // may contain both tools and parts; Parts alone applies is_parts=1.
  const effectivePartsFilter = isPartsPage ? 1 : null;
  const scopedFacets = effectivePartsFilter === null
    ? { brand: selectedBrand, category: query.category, search: query.search }
    : { isParts: effectivePartsFilter, brand: selectedBrand, category: query.category, search: query.search };
  const productQuery = effectivePartsFilter === null ? query : { ...query, isParts: effectivePartsFilter };

  const { facets, loading: facetsLoading, error: facetsError } = useCatalogFacets(scopedFacets);
  const { items, pagination, loading: itemsLoading, error: productsError } = useCatalogProducts(productQuery, { enabled: productsEnabled });
  const { category: categoryMeta, loading: categoryMetaLoading, error: categoryMetaError } = useCatalogCategory(isCategoryPageRoute ? categoryPathSlug : null);

  const brandFacets = useMemo(
    () => dedupeCatalogBrandEntries(
      Array.isArray(facets?.brands) ? facets.brands.map(toBrandFacet).filter((brand) => brand.label) : []
    ),
    [facets]
  );
  const selectedBrandFacet = useMemo(() => {
    if (!selectedBrand) return null;
    const selectedSlug = brandToSlug(selectedBrand);
    const selectedLabel = canonicalBrandLabel(selectedBrand);
    return brandFacets.find((brand) => brand.label === selectedLabel || brand.slug === selectedSlug || brand.key === selectedSlug) || null;
  }, [brandFacets, selectedBrand]);

  const mappedProducts = useMemo(() => (Array.isArray(items) ? items.map(toCardProduct) : []), [items]);

  const filterCategories = useMemo(() => {
    if (!facets) return [];
    if (selectedBrand) {
      const byBrand = getDisplayCategoriesForBrand(facets.displayCategoriesByBrand, selectedBrandFacet, selectedBrand, brandSlug);
      if (!Array.isArray(byBrand)) return [];
      return mergeCategoryEntries(byBrand);
    }
    return mergeDisplayCategories(facets.displayCategoriesByBrand);
  }, [brandSlug, facets, selectedBrand, selectedBrandFacet]);

  const brandCategoryCards = useMemo(() => {
    if (!selectedBrand) return [];
    return normalizeCatalogNavigationGroups(facets?.navigationGroups)
      .filter((group) => group.count > 0)
      .map((group) => ({
        ...group,
        name: group.label,
        key: group.key || group.slug,
        preview: group.image ? { url: group.image } : undefined,
      }));
  }, [facets?.navigationGroups, selectedBrand]);

  const selectedDisplayCategories = useMemo(
    () => query.displayCategory || [],
    [query.displayCategory],
  );

  const selectedCategoryLabel = useMemo(() => {
    if (selectedDisplayCategories.length === 0) return '';
    const pool = [...filterCategories, ...brandCategoryCards];
    const labels = selectedDisplayCategories.map((slug) => {
      const match = pool.find((cat) => cat.slug === slug || cat.key === slug || cat.id === slug);
      return match?.name || formatCategoryLabel(slug);
    });
    if (labels.length === 1) return labels[0];
    return `${labels[0]} +${labels.length - 1} more`;
  }, [brandCategoryCards, filterCategories, selectedDisplayCategories]);

  const activeBreadcrumbFilters = useMemo(() => {
    const filters = [];

    if (selectedBrand) {
      filters.push({
        id: `brand:${brandToSlug(selectedBrand)}`,
        type: 'brand',
        value: selectedBrand,
        label: selectedBrandFacet?.label || canonicalBrandLabel(selectedBrand),
      });
    }

    selectedDisplayCategories.forEach((slug) => {
      const match = [...filterCategories, ...brandCategoryCards]
        .find((category) => category.slug === slug || category.key === slug || category.id === slug);
      filters.push({
        id: `display-category:${slug}`,
        type: 'displayCategory',
        value: slug,
        label: match?.name || formatCategoryLabel(slug),
      });
    });

    return filters;
  }, [brandCategoryCards, filterCategories, selectedBrand, selectedBrandFacet, selectedDisplayCategories]);

  const activeFilterCount = (selectedBrand ? 1 : 0) + selectedDisplayCategories.length;
  const categoryScopeLabel = selectedBrandFacet?.label || selectedBrand;
  const searchQuery = query.search.trim();
  const pageHeading = searchQuery
    ? 'Search results'
    : selectedCategoryLabel
      ? `${categoryScopeLabel ? `${categoryScopeLabel} ` : ''}${selectedCategoryLabel}`
      : title;
  const isCategoryProductRoute = Boolean(selectedCategoryLabel);
  const page = Number(pagination?.page || query.page || 1);
  const totalPages = Math.max(1, Number(pagination?.totalPages || 1));
  const total = Number(pagination?.total || mappedProducts.length || 0);
  const perPage = Number(pagination?.perPage || query.perPage || 24);
  const pageStart = (page - 1) * perPage;
  const desktopHeading = isCategoryProductRoute ? pageHeading : title;
  const unifiedHeadingTitle = isCategoryProductRoute ? selectedCategoryLabel : desktopHeading;
  const unifiedHeadingMeta = searchQuery
    ? (itemsLoading ? `Searching the catalog for “${searchQuery}”…` : `Showing ${total.toLocaleString()} result${total === 1 ? '' : 's'} for “${searchQuery}”.`)
    : isCategoryProductRoute
      ? `${categoryScopeLabel || 'All brands'}${total > 0 ? ` · ${total.toLocaleString()} product${total === 1 ? '' : 's'}` : ''}`
      : `${isPartsPage ? 'Replacement parts and service components' : 'All brands and categories'}${total > 0 ? ` · ${total.toLocaleString()} product${total === 1 ? '' : 's'}` : ''}`;
  const canonicalCategorySlug = categoryPathSlug || query.category || '';
  const canonicalUrl = canonicalCategorySlug
    ? buildCategoryPageUrl(canonicalCategorySlug, selectedBrand)
    : (isPartsPage ? '/parts' : '/products');
  const seoDescription = searchQuery
    ? `Products matching “${searchQuery}” in the Drywall Toolbox catalog.`
    : isPartsPage
      ? 'Shop professional drywall replacement parts, service kits, and repair components from leading brands.'
      : 'Shop professional drywall tools and accessories from leading drywall brands.';

  const categoryBreadcrumbs = useMemo(() => {
    if (!isCategoryPageRoute || !categoryMeta) return [];
    const brandFilter = selectedBrandFacet?.slug || selectedBrand;
    const crumbs = [{ label: 'Home', path: '/' }];
    if (categoryMeta.parent?.slug) {
      crumbs.push({ label: categoryMeta.parent.label, path: buildCategoryPageUrl(categoryMeta.parent.slug, brandFilter) });
    }
    crumbs.push({ label: categoryMeta.label, path: buildCategoryPageUrl(categoryPathSlug, brandFilter) });
    return crumbs;
  }, [categoryMeta, categoryPathSlug, isCategoryPageRoute, selectedBrand, selectedBrandFacet]);

  const categoryToolTypes = useMemo(
    () => {
      const navGroup = normalizeCatalogNavigationGroups(facets?.navigationGroups)
        .find((group) => group.slug === categoryPathSlug);
      const children = selectedBrand
        ? (navGroup?.children || [])
        : (navGroup?.children?.length ? navGroup.children : (Array.isArray(categoryMeta?.children) ? categoryMeta.children : []));
      return children
        .map((child) => ({
          ...child,
          name: child?.label || child?.name || '',
          count: Number(child?.productCount || child?.count || 0),
        }))
        .filter((child) => child.name && child.slug && child.count > 0);
    },
    [categoryMeta, categoryPathSlug, facets?.navigationGroups, selectedBrand],
  );

  const allProductsToolTypes = useMemo(
    () => flattenCatalogNavigationGroups(facets?.navigationGroups)
      .map((category) => ({
        ...category,
        name: category.label,
      }))
      .filter((category) => category.name && category.slug && category.count > 0),
    [facets?.navigationGroups],
  );

  const allProductsBreadcrumbs = useMemo(() => [
    { label: 'Home', path: '/' },
    { label: 'All Products', path: '/products' },
  ], []);

  const categorySeoTitle = searchQuery
    ? `Search: ${searchQuery}`
    : isCategoryPageRoute
      ? `${selectedBrandFacet?.label ? `${selectedBrandFacet.label} ` : ''}${categoryMeta?.label || (categoryMetaError ? 'Category not found' : 'Loading category…')}`
      : pageHeading;
  const categorySeoDescription = isCategoryPageRoute
    ? (categoryMeta?.description || seoDescription)
    : seoDescription;
  const categorySeoSchema = isCategoryPageRoute
    ? [buildCategorySchema(categoryMeta, total), buildBreadcrumbSchema(categoryBreadcrumbs)].filter(Boolean)
    : buildSiteLinksSearchBoxSchema();

  const setQuery = useCallback((patch, options = {}) => {
    const next = { ...query, ...patch };
    if (options.resetPage !== false) next.page = patch.page ?? 1;
    navigate(buildCatalogUrl(next, pathParams), { replace: options.replace ?? false });
  }, [navigate, pathParams, query]);

  const commitSearch = useCallback((rawValue, { replace = true } = {}) => {
    const search = String(rawValue || '').trim();
    committedSearchRef.current = search;
    setQuery(
      search
        ? { search, displayCategory: [], category: '' }
        : { search: '' },
      { replace },
    );
  }, [setQuery]);

  useEffect(() => {
    if (query.search === committedSearchRef.current) return;
    committedSearchRef.current = query.search || '';
    setSearchInput(query.search || '');
  }, [query.search]);

  useEffect(() => {
    const pendingSearch = searchInput.trim();
    if (pendingSearch === query.search) return undefined;
    const timer = window.setTimeout(() => commitSearch(pendingSearch), 420);
    return () => window.clearTimeout(timer);
  }, [commitSearch, query.search, searchInput]);

  const searchTooShortForSuggestions = searchInput.trim().length < 2;

  useEffect(() => {
    const search = searchInput.trim();
    if (search.length < 2) return undefined;

    const requestId = searchRequestIdRef.current + 1;
    searchRequestIdRef.current = requestId;

    const timer = window.setTimeout(async () => {
      setSearchSuggestionsLoading(true);
      try {
        const platformData = await fetchCatalogProducts({
          search,
          ...(selectedBrand ? { brands: [selectedBrand] } : {}),
          perPage: 6,
          page: 1,
        });
        const platformItems = Array.isArray(platformData?.items)
          ? platformData.items.slice(0, 6)
          : [];
        if (searchRequestIdRef.current === requestId) {
          setSearchSuggestions(platformItems.map(toCardProduct).filter(Boolean));
        }
      } catch {
        try {
          const legacyResults = await searchProducts(search);
          const canonicalBrand = canonicalBrandLabel(selectedBrand);
          const scoped = canonicalBrand
            ? legacyResults.filter((p) => canonicalBrandLabel(p?.brand || '') === canonicalBrand)
            : legacyResults;
          if (searchRequestIdRef.current === requestId) {
            setSearchSuggestions(scoped.slice(0, 6));
          }
        } catch {
          if (searchRequestIdRef.current === requestId) setSearchSuggestions([]);
        }
      } finally {
        if (searchRequestIdRef.current === requestId) setSearchSuggestionsLoading(false);
      }
    }, 280);

    return () => window.clearTimeout(timer);
  }, [searchInput, selectedBrand]);

  const handleAddToCart = async (product, quantity = 1) => {
    try {
      await runWorkflow(
        {
          label: 'Adding to cart…',
          sublabel: product?.name || 'Updating your cart securely.',
          blocking: false,
        },
        () => addToCart(product, quantity),
      );
    } catch (err) {
      setToast({ message: err?.message || 'Could not add item to cart. Please try again.', type: 'error' });
      throw err;
    }
  };

  const openModal = (product) => {
    if (modalClearTimerRef.current) {
      window.clearTimeout(modalClearTimerRef.current);
      modalClearTimerRef.current = null;
    }
    setModalProduct({ product });
    setIsModalOpen(true);
  };

  const closeModal = () => {
    setIsModalOpen(false);
    if (modalClearTimerRef.current) window.clearTimeout(modalClearTimerRef.current);
    modalClearTimerRef.current = window.setTimeout(() => {
      setModalProduct(null);
      modalClearTimerRef.current = null;
    }, 220);
  };

  const toggleBrand = (brand) => {
    const canonical = canonicalBrandLabel(brand);
    const nextBrand = canonicalBrandLabel(selectedBrand) === canonical ? '' : canonical;
    setQuery({ brands: nextBrand ? [nextBrand] : [], displayCategory: [], category: '', search: query.search || '', sort: query.sort || 'popular', perPage: query.perPage || 24 });
  };

  const toggleDisplayCategory = (displayCategory) => {
    const current = query.displayCategory || [];
    const next = current.includes(displayCategory)
      ? current.filter((slug) => slug !== displayCategory)
      : [...current, displayCategory];
    setQuery({ displayCategory: next, category: '', search: query.search || '' });
  };

  const removeBreadcrumbFilter = useCallback((filter) => {
    if (!filter) return;
    if (filter.type === 'brand') {
      setQuery({
        brands: [],
        displayCategory: [],
        category: '',
        search: query.search || '',
        sort: query.sort || 'popular',
        perPage: query.perPage || 24,
      });
      return;
    }
    if (filter.type === 'displayCategory') {
      const next = (query.displayCategory || []).filter((slug) => slug !== filter.value);
      setQuery({ displayCategory: next, category: '', search: query.search || '' });
    }
  }, [query, setQuery]);
  const resetToBrandList = () => navigate('/products/brands');
  const resetToCategoryCards = () => navigate(`/products/brands/${brandToSlug(selectedBrand)}`);
  const getCardDisplayProduct = useCallback((product) => product?.cardProduct || null, []);

  const showBrandLanding = !forceProductGrid && isBrandSelectorRoute && !query.search;
  const showCategoryLanding = !forceProductGrid && isBrandCategorySelectorRoute && !query.search;
  const showProductGrid = !showBrandLanding && !showCategoryLanding;

  const handleDisplayModeChange = useCallback((mode) => {
    setDisplayMode(mode);
    if (typeof window !== 'undefined') {
      window.localStorage.setItem('dtb-catalog-display-mode', mode);
    }
  }, []);

  const productGridContent = (
    <>
      <div id={isCategoryPageRoute ? "dtb-category-products" : undefined} className={`dtb-product-grid dtb-product-grid--${displayMode}${mappedProducts.length === 1 ? ' dtb-product-grid--single' : ''}`}>
        {mappedProducts.map((product, index) => {
          const cardProduct = getCardDisplayProduct(product);
          return <ProductShoppingCard key={product.id} product={product} cardProduct={cardProduct} variant={displayMode} hasSelectedVariation={Boolean(product.is_variable && cardProduct?.parent_id)} onOpenModal={() => openModal(product)} onAddToCart={() => handleAddToCart(cardProduct || product, 1)} index={index} />;
        })}
      </div>

      {mappedProducts.length > 0 && (
        <Pagination
          currentPage={page}
          totalPages={totalPages}
          totalItems={total}
          startItem={pageStart + 1}
          endItem={Math.min(pageStart + mappedProducts.length, total)}
          itemLabel="products"
          onPageChange={(next) => {
            setQuery({ page: next }, { resetPage: false });
            if (typeof window !== 'undefined') window.scrollTo({ top: 0, behavior: 'smooth' });
          }}
        />
      )}

      {mappedProducts.length === 0 && !productsError && !itemsLoading && (
        <div className="text-center py-16">
          <ShoppingCart className="h-24 w-24 mx-auto mb-6 text-gray-300" />
          <h2 className="text-2xl font-bold text-gray-900 mb-4">No products found</h2>
          <p className="text-gray-600 mb-6">Try adjusting your filters to see more products.</p>
          <button onClick={() => navigate(isPartsPage ? '/parts' : '/products')} className="inline-flex items-center gap-2 bg-primary-600 hover:bg-primary-700 text-white px-6 py-3 rounded-lg font-semibold transition-colors">Clear Filters</button>
        </div>
      )}
    </>
  );

  useEffect(() => () => {
    if (modalClearTimerRef.current) window.clearTimeout(modalClearTimerRef.current);
  }, []);

  return (
    <div className="min-h-screen page-wrapper" data-route-readiness={itemsLoading || (isCategoryPageRoute && categoryMetaLoading) ? 'pending' : 'ready'}>
      <SEOHead title={categorySeoTitle} description={categorySeoDescription} canonical={canonicalUrl} schema={categorySeoSchema} noindex={Boolean(searchQuery) || (isCategoryPageRoute && (categoryMetaLoading || itemsLoading || Boolean(categoryMetaError)))} />
      <div className={`dtb-container dtb-container--fluid py-4 pt-6${isCategoryPageRoute ? ' dtb-catalog-category-layout' : ''}${showBrandLanding ? ' dtb-catalog-brand-layout' : ''}`}>
        {isCategoryPageRoute && (
          categoryMeta ? (
            <>
              <CategoryHero
                category={categoryMeta}
                breadcrumbs={categoryBreadcrumbs}
                activeFilters={activeBreadcrumbFilters}
                brand={selectedBrandFacet?.slug || selectedBrand}
                onRemoveFilter={removeBreadcrumbFilter}
                productCount={total}
                products={mappedProducts}
                brands={brandFacets}
                onAddToCart={handleAddToCart}
                onOpenProduct={openModal}
              />
              {Array.isArray(categoryMeta.children) && categoryMeta.children.length > 0 && (
                <ShopByToolType categories={categoryToolTypes} parentLabel={categoryMeta.label} brand={selectedBrandFacet?.slug || selectedBrand} onOpenFilters={() => setShowFilters(true)} />
              )}
            </>
          ) : categoryMetaError ? (
            <div className="mb-6 sm:mb-8">
              <h1 className="dtb-listing-heading__title">{formatCategoryLabel(categoryPathSlug)}</h1>
              <p className="dtb-listing-heading__meta">
                {categoryMetaError?.status === 404 ? 'This category could not be found.' : 'Unable to load category details right now.'}
              </p>
            </div>
          ) : (
            <CategoryHero category={null} />
          )
        )}

        {searchQuery && (
          <section className="dtb-search-results-intro" aria-labelledby="dtb-search-results-title">
            <Breadcrumb items={[{ label: 'Home', path: '/' }, { label: 'Search' }]} />
            <div className="dtb-search-results-intro__copy">
              <h1 id="dtb-search-results-title">Search results</h1>
              <p>{itemsLoading ? `Searching the catalog for “${searchQuery}”…` : <>Showing <strong>{total.toLocaleString()}</strong> result{total === 1 ? '' : 's'} for <strong>“{searchQuery}”</strong>.</>}</p>
            </div>
          </section>
        )}

        {isAllProductsRoute && !showCategoryLanding && !showBrandLanding && (
          <>
            <Breadcrumb items={allProductsBreadcrumbs} activeFilters={activeBreadcrumbFilters} onRemoveFilter={removeBreadcrumbFilter} />
            {allProductsToolTypes.length > 0 && (
              <ShopByToolType categories={allProductsToolTypes} onOpenFilters={() => setShowFilters(true)} />
            )}
          </>
        )}

        {!searchQuery && !isCategoryPageRoute && !isAllProductsRoute && !showCategoryLanding && !showBrandLanding && (
          <div className="mb-5 sm:mb-8">
            <div className={`dtb-listing-heading${isCategoryProductRoute ? '' : ' dtb-listing-heading--standard'}`}>
              {selectedBrand && getBrandLogo(categoryScopeLabel) ? (
                <div className="dtb-brand-listing-header">
                  <button
                    type="button"
                    className="dtb-brand-listing-header__back"
                    onClick={selectedDisplayCategories.length > 0 ? resetToCategoryCards : resetToBrandList}
                    aria-label={selectedDisplayCategories.length > 0 ? 'Back to brand categories' : 'Back to brands'}
                    title={selectedDisplayCategories.length > 0 ? 'Back to brand categories' : 'Back to brands'}
                  >
                    <ArrowLeft size={22} strokeWidth={2} aria-hidden="true" />
                  </button>
                  <img
                    className="dtb-brand-listing-header__logo"
                    src={getBrandLogo(categoryScopeLabel)}
                    alt={`${categoryScopeLabel} logo`}
                  />
                  {isCategoryProductRoute && <h1 className="dtb-brand-listing-header__category">{unifiedHeadingTitle}</h1>}
                  {!isCategoryProductRoute && <p className="dtb-brand-listing-header__meta">{unifiedHeadingMeta}</p>}
                </div>
              ) : isCategoryProductRoute ? (
                <div className="dtb-listing-heading__title-row hidden sm:flex">
                  <div className="dtb-listing-heading__nav-col shrink-0 lg:w-80">
                    <BackButton
                      onClick={selectedBrand ? (selectedDisplayCategories.length > 0 ? resetToCategoryCards : resetToBrandList) : () => navigate('/products')}
                      label={selectedBrand ? (selectedDisplayCategories.length > 0 ? selectedBrand : 'Brands') : 'Products'}
                      className="dtb-product-nav-back"
                    />
                  </div>
                  <div className="dtb-listing-heading__title-group flex-1">
                    <h1 className="dtb-listing-heading__title">{unifiedHeadingTitle}</h1>
                    <p className="dtb-listing-heading__meta">{unifiedHeadingMeta}</p>
                  </div>
                </div>
              ) : (
                <>
                  <h1 className="dtb-listing-heading__title">{unifiedHeadingTitle}</h1>
                  <p className="dtb-listing-heading__meta">{unifiedHeadingMeta}</p>
                </>
              )}
            </div>
          </div>
        )}

        {facetsError && brandFacets.length === 0 && (
          <CatalogError title="Unable to load brands" message="We couldn't load the brand list just now — give it another try." onRetry={() => window.location.reload()} />
        )}

        {productsError && !itemsLoading && mappedProducts.length === 0 && showProductGrid && (
          <CatalogError title="Unable to load products" message="We couldn't load products right now — give it another try." onRetry={() => window.location.reload()} />
        )}

        {showBrandLanding ? (
          <LoadingCardTransition
            loading={facetsLoading}
            skeleton={<SelectorSkeletonGrid mode="brands" />}
            label="Loading product brands"
          >
            <ProductsBrandSelector
              brands={brandFacets}
              onSelectBrand={(brand) => navigate(`/products/brands/${brand.slug || brandToSlug(brand.label)}`)}
            />
          </LoadingCardTransition>
        ) : showCategoryLanding ? (
          <LoadingCardTransition
            loading={facetsLoading}
            skeleton={<SelectorSkeletonGrid mode="categories" />}
            label="Loading product categories"
          >
            <ProductsCategorySelector
              brand={selectedBrandFacet?.label || selectedBrand}
              brandLogo={selectedBrandFacet?.logo || getBrandLogo(selectedBrand)}
              categories={brandCategoryCards}
              onBack={resetToBrandList}
              onSelectCategory={(cat) => cat.isAllProducts
                ? navigate(`/products/brands/${selectedBrandFacet?.slug || brandToSlug(selectedBrand)}/categories/all-products`)
                : navigate(buildCategoryPageUrl(cat.slug, selectedBrandFacet?.slug || selectedBrand))}
            />
          </LoadingCardTransition>
        ) : (
          <>
            <div className="dtb-listing-search">
              <SearchBar
                placeholder={isPartsPage ? 'Search parts by name, SKU, or brand...' : 'Search products by name, SKU, or brand...'}
                value={searchInput}
                onChange={(event) => setSearchInput(event.target.value)}
                suggestions={searchTooShortForSuggestions ? [] : searchSuggestions}
                loading={searchTooShortForSuggestions ? false : searchSuggestionsLoading}
                onSubmit={(value) => commitSearch(value, { replace: false })}
                onClear={() => {
                  setSearchInput('');
                  setSearchSuggestions([]);
                  commitSearch('');
                }}
                onSelectSuggestion={(product) => navigate(product?.slug ? `/products/${product.slug}` : `/product/${product?.id}`)}
              />
            </div>
            <div id="dtb-category-filters" className="flex flex-col lg:flex-row gap-8">
              <FilterPanel
                isOpen={showFilters}
                onClose={() => setShowFilters(false)}
                categories={filterCategories}
                brands={brandFacets}
                maxPrice={0}
                selectedBrands={selectedBrand ? [canonicalBrandLabel(selectedBrand)] : []}
                selectedCategories={selectedDisplayCategories}
                priceRange={[0, 0]}
                onBrandChange={toggleBrand}
                onCategoryChange={toggleDisplayCategory}
                onPriceChange={() => {}}
                onClearFilters={() => setQuery({ brands: [], category: '', displayCategory: [], search: query.search || '', sort: 'popular' })}
                resultsCount={total}
              />

              <div className="flex-1">
                <div className="dtb-listing-toolbar mb-5 sm:mb-6">
                  <button onClick={() => setShowFilters(!showFilters)} className="dtb-listing-toolbar__pill" aria-label={`Toggle filters${activeFilterCount > 0 ? ` (${activeFilterCount} active)` : ''}`}>
                    <Filter size={18} />
                    <span>Filters</span>
                    {activeFilterCount > 0 && (
                      <span className="dtb-listing-toolbar__filter-count" aria-hidden="true">{activeFilterCount}</span>
                    )}
                  </button>
                  <div className="dtb-listing-toolbar__sort">
                    <Dropdown value={query.sort} onChange={(value) => setQuery({ sort: value })} options={SORT_OPTIONS} />
                  </div>
                  <div className="dtb-listing-toolbar__view-toggle" role="group" aria-label="Product display mode">
                    <button
                      type="button"
                      className={`dtb-listing-toolbar__view-button${displayMode === 'list' ? ' is-active' : ''}`}
                      onClick={() => handleDisplayModeChange('list')}
                      aria-pressed={displayMode === 'list'}
                    >
                      <List size={16} />
                      <span>List</span>
                    </button>
                    <button
                      type="button"
                      className={`dtb-listing-toolbar__view-button${displayMode === 'grid' ? ' is-active' : ''}`}
                      onClick={() => handleDisplayModeChange('grid')}
                      aria-pressed={displayMode === 'grid'}
                    >
                      <LayoutGrid size={16} />
                      <span>Grid</span>
                    </button>
                  </div>
                  {!itemsLoading && (
                    <div className="dtb-listing-toolbar__count" aria-live="polite">
                      {`${total.toLocaleString()} result${total === 1 ? '' : 's'}`}
                    </div>
                  )}
                </div>

                <LoadingCardTransition
                  loading={itemsLoading}
                  skeleton={<ProductSkeletonGrid count={24} variant={displayMode} />}
                  label="Loading catalog products"
                >
                  {productGridContent}
                </LoadingCardTransition>
              </div>
            </div>
          </>
        )}
      </div>

      {toast && <Toast message={toast.message} type={toast.type} onClose={() => setToast(null)} />}

      <ProductModal isOpen={isModalOpen && !!modalProduct} product={modalProduct?.product || modalProduct} onClose={closeModal}>
        {modalProduct && <ProductDetailPlatform key={`${modalProduct.product?.id || modalProduct.id}:${modalProduct.initialResolvedVariation?.id || 'parent'}`} product={modalProduct.product || modalProduct} onAddToCart={handleAddToCart} onClose={closeModal} onOpenProduct={(relatedProduct) => openModal(relatedProduct)} initialResolvedVariation={modalProduct.initialResolvedVariation} initialSelectedAttrs={modalProduct.initialSelectedAttrs} />}
      </ProductModal>
    </div>
  );
}
