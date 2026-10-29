import { useMemo } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import ProductsCatalogPlatform from './ProductsCatalogPlatform.jsx';
import InteriorPageHero from '../components/shared/InteriorPageHero.jsx';
import { getBrandLogo } from '../utils/brandAssets.js';
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

function PartsHero({ activeFilters = [], onRemoveFilter = null, brand = '' }) {
  const brandLabel = brand ? canonicalBrandLabel(brand) : '';
  const brandLogo = brandLabel ? getBrandLogo(brandLabel) || getBrandLogo(brand) : '';
  return (
    <InteriorPageHero
      id="dtb-parts-hero-title"
      title={brandLabel ? `${brandLabel} Replacement Parts` : 'All Parts'}
      description={brandLabel
        ? `Browse replacement parts for ${brandLabel}. Use a model diagram to confirm the part number and fit before ordering.`
        : 'Find replacement parts by brand or use a model diagram to identify the part number. Confirm fit before ordering.'}
      breadcrumbs={[{ label: 'Home', path: '/' }, { label: 'Parts' }]}
      activeFilters={activeFilters}
      onRemoveFilter={onRemoveFilter}
      brandLogo={brandLogo}
      brandLabel={brandLabel}
      titleVisuallyHidden={Boolean(brandLabel)}
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
      <PartsHero activeFilters={activeFilters} onRemoveFilter={removeFilter} brand={query.brands?.[0] || ''} />
      <ProductsCatalogPlatform forceProductGrid title="Parts" isPartsFilter={1} />
    </div>
  );
}
