import InteriorPageHero from '../shared/InteriorPageHero.jsx';
import '../../styles/category-hero.css';
import CategoryMerchandising from './CategoryMerchandising.jsx';
import ToolSetsKitsExperience from './ToolSetsKitsExperience.jsx';

const CATEGORY_DESCRIPTIONS = {
  'angle-boxes-corner-applicators': 'Browse angle boxes and corner applicators for applying joint compound to inside corners.',
  'applicator-heads': 'Explore applicator heads and compatible components for professional drywall corner finishing.',
  'automatic-tapers': 'Browse professional drywall automatic tapers for efficient, consistent tape application.',
  'automatic-taping-tools': 'Explore professional drywall taping and finishing tools for every stage of the workflow.',
  'automatic-tool-sets-kits': 'Compare coordinated drywall tool sets and kits for taping and finishing work.',
  'bead-hoppers': 'Shop bead hoppers and related tools for applying compound to drywall corner bead.',
  'compound-tubes': 'Find compound tubes for loading and applying joint compound with compatible finishing tools.',
  'corner-bead-rollers': 'Explore corner bead rollers for pressing and seating bead during drywall finishing.',
  'corner-bead-tools': 'Browse drywall corner bead tools for installing and finishing inside and outside corners.',
  'corner-finishers': 'Shop professional drywall corner finishers for applying and smoothing compound on inside corners.',
  'corner-flushers': 'Find corner flushers for smoothing compound and finishing inside drywall corners.',
  'corner-rollers': 'Browse corner rollers for pressing and smoothing drywall tape in inside corners.',
  'drywall-saws': 'Shop drywall saws for cutting openings and making precise adjustments on the jobsite.',
  'drywall-squares': 'Explore drywall squares for measuring, marking, and checking layout on the jobsite.',
  'drywall-stilts': 'Browse drywall stilts and related equipment for elevated interior finishing work.',
  'drywall-supplies': 'Find drywall supplies and jobsite essentials for taping, finishing, and repair work.',
  'flat-boxes': 'Browse drywall flat boxes for smooth, consistent compound application across flat joints.',
  'goosenecks-box-fillers-adapters': 'Find goosenecks, box fillers, and adapters for connecting compatible drywall finishing tools.',
  handles: 'Shop handles for compatible drywall finishing boxes, applicators, and other tools.',
  'hanging-tools': 'Explore drywall hanging tools for lifting, positioning, and installing panels.',
  'loading-pumps': 'Browse loading pumps for transferring joint compound into compatible drywall finishing tools.',
  'mud-mixing-tools': 'Find mud mixing tools for preparing joint compound and other drywall materials.',
  'mud-pan-accessories': 'Explore accessories designed for use with drywall mud pans.',
  'mud-pans': 'Shop drywall mud pans for holding joint compound during taping and finishing.',
  'nail-spotters': 'Browse nail spotters for applying compound over drywall fasteners during finishing.',
  'powered-compound-applicators': 'Explore powered compound applicators for applying joint compound with compatible systems.',
  'replacement-parts': 'Find drywall tool replacement parts by brand, tool, or part number to identify the right component.',
  sealants: 'Browse sealants for drywall, interior finishing, and related jobsite applications.',
  'semi-automatic-tapers-banjos': 'Explore drywall banjos and semi-automatic tapers for controlled tape application.',
  'semi-automatic-tool-sets-kits': 'Compare semi-automatic drywall tool sets for taping and finishing workflows.',
  'taping-finishing-tools': 'Shop professional drywall taping and finishing tools for efficient jobsite workflows.',
  'tool-cases': 'Browse tool cases for storing and transporting drywall tools and equipment.',
};

function getCategoryDescription(category) {
  const slug = String(category?.slug || '').trim().toLowerCase();
  if (CATEGORY_DESCRIPTIONS[slug]) return CATEGORY_DESCRIPTIONS[slug];

  const label = String(category?.label || '').trim();
  return label ? `Explore ${label.toLowerCase()} for professional drywall work.` : '';
}

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
  brand = '',
  brandLogo = '',
  brandLabel = '',
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
        description={description || getCategoryDescription(category)}
        breadcrumbs={breadcrumbs}
        brandLogo={brandLogo}
        brandLabel={brandLabel || brand}
      />
      <CategoryMerchandising category={category} brand={brand} />
    </>
  );
}
