/**
 * frontend/src/components/schematics/BrandSelector.jsx
 *
 * Schematics owns its brand data/count semantics; shared selector primitives
 * own the cross-storefront brand-card presentation and responsive geometry.
 */
import ProductsBrandSelector from '../catalog/ProductsBrandSelector.jsx';
import { resolveBrandLogo } from '../../utils/brandLogoAssets.js';

export function resolveSchematicBrandLogo(name) {
  return resolveBrandLogo(name) || null;
}

export function SchematicBrandLogo({ brand, className = '' }) {
  const name = brand?.name || brand?.id || 'Brand';
  const logo = resolveBrandLogo(brand || name);

  if (!logo) return null;

  return (
    <img
      src={logo}
      alt={`${name} logo`}
      className={className}
      loading="eager"
      decoding="async"
    />
  );
}

export default function BrandSelector({ brands, onSelectBrand }) {
  if (brands.length === 0) {
    return (
      <div className="dtb-schematics-empty" role="status">
        <p>No schematic brands are available right now.</p>
      </div>
    );
  }

  return (
    <ProductsBrandSelector
      brands={brands.map((brand) => ({
        ...brand,
        key: brand.id,
        slug: brand.id,
        label: brand.name,
      }))}
      onSelectBrand={(brand) => onSelectBrand(brand.slug)}
      showHero={false}
      includeSchematicOnly={true}
    />
  );
}
