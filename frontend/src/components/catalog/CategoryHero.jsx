import InteriorPageHero from '../shared/InteriorPageHero.jsx';
import '../../styles/category-hero.css';
import CategoryMerchandising from './CategoryMerchandising.jsx';
import ToolSetsKitsExperience from './ToolSetsKitsExperience.jsx';

export function CategoryHeroSkeleton() {
  return (
    <div className="dtb-interior-hero" role="status" aria-label="Loading category">
      <div className="dtb-interior-hero__inner">
        <div className="dtb-interior-hero__body">
          <span className="dtb-category-loading-placeholder" aria-hidden="true" />
        </div>
      </div>
    </div>
  );
}

/** Renders a taxonomy category hero and brand-scoped merchandising context. */
export default function CategoryHero({
  category,
  breadcrumbs = [],
  activeFilters = [],
  brand = '',
  brandLogo = '',
  brandLabel = '',
  onRemoveFilter = null,
  products = [],
  brands = [],
  onAddToCart,
  onOpenProduct,
}) {
  if (!category) return <CategoryHeroSkeleton />;
  if (category.slug === 'tool-sets-kits') {
    return <ToolSetsKitsExperience
      category={category}
      breadcrumbs={breadcrumbs}
      products={products}
      brands={brands}
      brand={brand}
      brandLogo={brandLogo}
      brandLabel={brandLabel}
      onAddToCart={onAddToCart}
      onOpenProduct={onOpenProduct}
    />;
  }
  const { label, description } = category;
  return (
    <>
      <InteriorPageHero
        id="dtb-category-hero-title"
        title={label}
        description={description || `Browse our full selection of ${label} for professional drywall work.`}
        breadcrumbs={breadcrumbs}
        activeFilters={activeFilters}
        onRemoveFilter={onRemoveFilter}
        brandLogo={brandLogo}
        brandLabel={brandLabel || brand}
      />
      <CategoryMerchandising category={category} brand={brand} />
    </>
  );
}
