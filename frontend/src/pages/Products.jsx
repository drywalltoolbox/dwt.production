import { useMemo } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import ProductsCatalogPlatform from './ProductsCatalogPlatform.jsx';
import InteriorPageHero from '../components/shared/InteriorPageHero.jsx';
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
    <InteriorPageHero
      id="dtb-products-hero-title"
      title="All Products"
      description="Professional drywall tools, parts and supplies."
      breadcrumbs={[{ label: 'Home', path: '/' }, { label: 'All Products' }]}
      activeFilters={activeFilters}
      onRemoveFilter={onRemoveFilter}
    />
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
