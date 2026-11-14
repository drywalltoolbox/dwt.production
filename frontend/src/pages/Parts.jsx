import { useMemo } from 'react';
import { useLocation } from 'react-router-dom';
import ProductsCatalogPlatform from './ProductsCatalogPlatform.jsx';
import InteriorPageHero from '../components/shared/InteriorPageHero.jsx';
import { getBrandLogo } from '../utils/brandAssets.js';
import { canonicalBrandLabel, parseCatalogQuery } from '../utils/catalogUrlState.js';
import './parts.css';

function PartsHero({ brand = '' }) {
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
      brandLogo={brandLogo}
      brandLabel={brandLabel}
      titleVisuallyHidden={Boolean(brandLabel)}
    />
  );
}

export default function Parts() {
  const location = useLocation();
  const query = useMemo(
    () => parseCatalogQuery(new URLSearchParams(location.search), { basePath: '/parts' }),
    [location.search],
  );

  return (
    <div className="dtb-parts-page">
      <PartsHero brand={query.brands?.[0] || ''} />
      <ProductsCatalogPlatform forceProductGrid title="Parts" isPartsFilter={1} />
    </div>
  );
}
