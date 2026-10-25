import { useMemo } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import ProductsCatalogPlatform from './ProductsCatalogPlatform.jsx';
import InteriorPageHero from '../components/shared/InteriorPageHero.jsx';
import {
  buildCatalogUrl,
  canonicalBrandLabel,
  parseCatalogQuery,
} from '../utils/catalogUrlState.js';
import './parts.css';

function formatFilterLabel(value = '') {
  return String(value || '')
    .replace(/[_-]+/g, ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase());
}

function PartsHero({ activeFilters = [], onRemoveFilter = null }) {
  return (
    <InteriorPageHero
      id="dtb-parts-hero-title"
      title="All Parts"
      description="Find replacement parts by brand or use a model diagram to identify the part number. Confirm fit before ordering."
      breadcrumbs={[{ label: 'Home', path: '/' }, { label: 'Parts' }]}
      activeFilters={activeFilters}
      onRemoveFilter={onRemoveFilter}
    />
  );
}

export default function Parts() {
  const location = useLocation();
  const navigate = useNavigate();
  const query = useMemo(
    () => parseCatalogQuery(new URLSearchParams(location.search), { basePath: '/parts' }),
    [location.search],
  );

  const activeFilters = useMemo(() => {
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
  }, [query.brands, query.displayCategory]);

  const removeFilter = (filter) => {
    if (!filter) return;
    if (filter.type === 'brand') {
      navigate(buildCatalogUrl({ ...query, brands: [], displayCategory: [], page: 1 }, { basePath: '/parts' }));
      return;
    }
    if (filter.type === 'displayCategory') {
      navigate(buildCatalogUrl({
        ...query,
        displayCategory: (query.displayCategory || []).filter((slug) => slug !== filter.value),
        page: 1,
      }, { basePath: '/parts' }));
    }
  };

  return (
    <div className="dtb-parts-page">
      <PartsHero activeFilters={activeFilters} onRemoveFilter={removeFilter} />
      <ProductsCatalogPlatform forceProductGrid title="Parts" isPartsFilter={1} />
    </div>
  );
}
