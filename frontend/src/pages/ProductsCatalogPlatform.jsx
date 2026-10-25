Warning: truncated output (original token count: 10791)
Total output lines: 924

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
  automatic_handles_extensions: 'Handles & Extensions',
  automatic_tool_sets:   'Tool Sets',
  nail_spotters:         'Nail Spotters',
  finishing_boxes:       'Finishing Boxes',
  handles:               'Handles & Extensions',
  pumps:                 'Mud Pans & Pumps',
  corner_tools:          'Corner Tools',
  accessories:           'Accessories',
  smoothing_blades:      'Smoothing Blades',
  toolsets:              'Tool Sets & Kits',
  parts:                 'Parts',
  bead_tools:            'Bead Tools',
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
  automatic_handles_extensions: 'automatic_handles_extensions',
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
  const label = canonicalBrandLabel(rawBrand.label || r…4791 tokens truncated…{Boolean(searchQuery) || (isCategoryPageRoute && (categoryMetaLoading || itemsLoading || Boolean(categoryMetaError)))} />
      <div className={`dtb-container dtb-container--fluid py-4 pt-6${isCategoryPageRoute ? ' dtb-catalog-category-layout' : ''}${showBrandLanding ? ' dtb-catalog-brand-layout' : ''}`}>
        {isCategoryPageRoute && (
          categoryMeta ? (
            <>
              <CategoryHero
                category={categoryMeta}
                breadcrumbs={categoryBreadcrumbs}
                activeFilters={activeBreadcrumbFilters}
                onRemoveFilter={removeBreadcrumbFilter}
                productCount={total}
                products={mappedProducts}
                brands={brandFacets}
                onAddToCart={handleAddToCart}
                onOpenProduct={openModal}
              />
              {Array.isArray(categoryMeta.children) && categoryMeta.children.length > 0 && (
                <ShopByToolType categories={categoryToolTypes} onOpenFilters={() => setShowFilters(true)} />
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
              onSelectCategory={(cat) => navigate(`/products/brands/${selectedBrandFacet?.slug || brandToSlug(selectedBrand)}/categories/${cat.slug}`)}
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
