import { useMemo } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import ProductsCatalogPlatform from './ProductsCatalogPlatform.jsx';
import Breadcrumb from '../components/shared/Breadcrumb.jsx';
import productsHeroImage from '../assets/media/hero-background.webp';
import {
  buildCatalogUrl,
  canonicalBrandLabel,
  parseCatalogQuery,
} from '../utils/catalogUrlState.js';
import './products.css';
import './products-selector.css';
import '../styles/tool-selector.css';
import '../styles/selector-cards.css';

function formatFilterLabel(value = '') {
  return String(value || '')
    .replace(/[_-]+/g, ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase());
}

function ProductsHero({ activeFilters = [], onRemoveFilter = null }) {
  return (
    <>
      <header className="dtb-products-hero" aria-labelledby="dtb-products-hero-title">
        <div className="dtb-products-hero__inner">
          <div className="dtb-products-breadcrumb-shell">
            <Breadcrumb
              items={[{ label: 'Home', path: '/' }, { label: 'All Products' }]}
              activeFilters={activeFilters}
              onRemoveFilter={onRemoveFilter}
              className="dtb-breadcrumb-bar--compact"
            />
          </div>
          <div className="dtb-products-hero__content">
            <div className="dtb-products-hero__eyebrow-row">
              <span className="dtb-products-hero__eyebrow">Products</span>
              <span className="dtb-products-hero__eyebrow-rule" aria-hidden="true" />
            </div>
            <h1 id="dtb-products-hero-title" className="dtb-products-hero__title">All Products</h1>
            <p className="dtb-products-hero__description">Professional drywall tools, parts and supplies.</p>
          </div>

          <div className="dtb-products-hero__media" aria-hidden="true">
            <img
              src={productsHeroImage}
              alt=""
              className="dtb-products-hero__image"
              loading="eager"
              fetchPriority="high"
              decoding="async"
            />
          </div>
        </div>
      </header>
    </>
  );
}

export default function Products(props) {
  const location = useLocation();
  const navigate = useNavigate();
  const isAllProductsRoute = location.pathname === '/products';
  const query = useMemo(
    () => parseCatalogQuery(new URLSearchParams(location.search), { basePath: '/products' }),
    [location.search],
  );

  const isSearchResultsPage = isAllProductsRoute && Boolean(query.search.trim());

  const activeFilters = useMemo(() => {
    if (!isAllProductsRoute) return [];
    const filters = [];

    (query.brands || []).forEach((brand) => {
      const label = canonicalBrandLabel(brand);
      filters.push({
        id: `brand:${label}`,
        type: 'brand',
        value: label,
        label,
      });
    });

    (query.displayCategory || []).forEach((slug) => {
      filters.push({
        id: `display-category:${slug}`,
        type: 'displayCategory',
        value: slug,
        label: formatFilterLabel(slug),
      });
    });

    return filters;
  }, [isAllProductsRoute, query.brands, query.displayCategory]);

  const removeFilter = (filter) => {
    if (!filter) return;
    if (filter.type === 'brand') {
      navigate(buildCatalogUrl({ ...query, brands: [], displayCategory: [], page: 1 }, { basePath: '/products' }));
      return;
    }
    if (filter.type === 'displayCategory') {
      navigate(buildCatalogUrl({
        ...query,
        displayCategory: (query.displayCategory || []).filter((slug) => slug !== filter.value),
        page: 1,
      }, { basePath: '/products' }));
    }
  };

  return (
    <div className="dtb-products-page">
      {isAllProductsRoute && !isSearchResultsPage ? <ProductsHero activeFilters={activeFilters} onRemoveFilter={removeFilter} /> : null}
      <ProductsCatalogPlatform {...props} />
    </div>
  );
}
