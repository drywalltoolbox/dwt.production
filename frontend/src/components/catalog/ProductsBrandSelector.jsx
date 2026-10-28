import { useMemo } from 'react';
import InteriorPageHero from '../shared/InteriorPageHero.jsx';
import { dedupeCatalogBrandEntries } from '../../utils/catalogFacets.js';
import { resolveProductBrandLogo } from '../../utils/brandLogoAssets.js';
import { resolveFeaturedBrandPresentation } from '../../utils/brandSelectorPresentation.js';
import { BrandSelectorCard, SelectorGrid } from '../selectors/SelectorCards.jsx';
import './products-selector.css';

function normalizeBrandList(brands = [], { includeSchematicOnly = false } = {}) {
  if (!Array.isArray(brands) || brands.length === 0) return [];
  return dedupeCatalogBrandEntries(brands).map((brand) => {
    const presentation = resolveFeaturedBrandPresentation(brand, { includeSchematicOnly });
    return {
      ...brand,
      logo: presentation?.logo || resolveProductBrandLogo(brand),
      selectorClassName: presentation?.className || '',
      selectorStyle: presentation?.cardStyle,
      selectorLogoStyle: presentation?.logoStyle,
    };
  });
}

export default function ProductsBrandSelector({ brands, onSelectBrand, showHero = true, includeSchematicOnly = false }) {
  const sortedBrands = useMemo(
    () => normalizeBrandList(brands, { includeSchematicOnly }),
    [brands, includeSchematicOnly],
  );

  return (
    <div className="products-brand-selector">
      {showHero && (
        <InteriorPageHero
          id="dtb-brands-hero-title"
          title="Shop the brands professionals rely on."
          description="Browse professional drywall tools, replacement parts, and equipment by manufacturer."
          breadcrumbs={[{ label: 'Home', path: '/' }, { label: 'Brands' }]}
        />
      )}

      <SelectorGrid variant="brands" className="products-brand-grid">
        {sortedBrands.map((brand) => {
          const label = brand.label || brand.key || '';
          return (
            <BrandSelectorCard
              key={brand.slug || brand.key || label}
              name={label}
              logo={brand.logo}
              className={brand.selectorClassName}
              style={brand.selectorStyle}
              logoStyle={brand.selectorLogoStyle}
              onClick={() => onSelectBrand(brand)}
            />
          );
        })}
      </SelectorGrid>
    </div>
  );
}
