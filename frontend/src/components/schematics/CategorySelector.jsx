/**
 * frontend/src/components/schematics/CategorySelector.jsx
 *
 * Schematics owns category identity/count/preview derivation. The shared
 * selector primitives own the 3:2 media-card presentation used consistently
 * by schematics and product brand/category discovery.
 */
import ProductsCategorySelector from '../catalog/ProductsCategorySelector.jsx';
import { resolveSchematicBrandLogo } from './BrandSelector.jsx';

export default function CategorySelector({ brandId, brandName, categories, onBack, onSelectCategory }) {
  if (categories.length === 0) {
    return (
      <div className="dtb-schematics-empty" role="status">
        <p>No categories are available for {brandName || 'this brand'} yet.</p>
      </div>
    );
  }

  return (
    <ProductsCategorySelector
      brand={brandName || brandId}
      headerTitle={`${brandName || brandId} schematics`}
      headerDescription="Choose a category to find the diagram for your tool."
      schematicSelector
      brandLogo={resolveSchematicBrandLogo({ id: brandId, name: brandName })}
      categories={categories.map((category) => ({ ...category, key: category.id, slug: category.id }))}
      onBack={onBack}
      onSelectCategory={(category) => onSelectCategory(category.id)}
      includeAllProducts={false}
    />
  );
}
