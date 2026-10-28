function SkeletonBar({ className = '' }) {
  return <span className={`dtb-loading-bar ${className}`.trim()} aria-hidden="true" />;
}

/**
 * Layout-matched product-card skeleton used by grids, list results, and rails.
 */
export default function ProductShoppingCardSkeleton({ variant = 'grid' }) {
  return (
    <article className={`dtb-product-card-skeleton dtb-product-card-skeleton--${variant}`} aria-hidden="true">
      <span className="dtb-product-card-skeleton__image">
        <span className="dtb-product-card-skeleton__image-shimmer" />
      </span>

      <span className="dtb-product-card-skeleton__meta">
        <SkeletonBar className="dtb-product-card-skeleton__brand" />
        <span className="dtb-product-card-skeleton__name-lines">
          <SkeletonBar className="dtb-product-card-skeleton__name" />
          <SkeletonBar className="dtb-product-card-skeleton__name dtb-product-card-skeleton__name--short" />
        </span>
        <SkeletonBar className="dtb-product-card-skeleton__sku" />
        <span className="dtb-product-card-skeleton__divider" />
        <span className="dtb-product-card-skeleton__footer">
          <SkeletonBar className="dtb-product-card-skeleton__price" />
          <span className="dtb-product-card-skeleton__controls">
            <SkeletonBar className="dtb-product-card-skeleton__quantity" />
            <SkeletonBar className="dtb-product-card-skeleton__action" />
          </span>
        </span>
      </span>
    </article>
  );
}

export function ProductSkeletonGrid({ count = 24, variant = 'grid', className = '' }) {
  const classes = [
    'dtb-product-skeleton-grid',
    `dtb-product-skeleton-grid--${variant}`,
    className,
  ].filter(Boolean).join(' ');

  return (
    <div className={classes} aria-hidden="true">
      {Array.from({ length: count }, (_, index) => (
        <ProductShoppingCardSkeleton key={index} variant={variant} />
      ))}
    </div>
  );
}

function BrandSelectorSkeletonCard() {
  return (
    <div className="dtb-selector-skeleton-card dtb-selector-skeleton-card--brand" aria-hidden="true">
      <span className="dtb-selector-skeleton-card__brand-frame">
        <SkeletonBar className="dtb-selector-skeleton-card__brand-logo" />
      </span>
      <SkeletonBar className="dtb-selector-skeleton-card__label" />
    </div>
  );
}

function CategorySelectorSkeletonCard() {
  return (
    <div className="dtb-selector-skeleton-card dtb-selector-skeleton-card--category" aria-hidden="true">
      <span className="dtb-selector-skeleton-card__image-shimmer" />
      <span className="dtb-selector-skeleton-card__category-copy">
        <SkeletonBar className="dtb-selector-skeleton-card__category-name" />
        <SkeletonBar className="dtb-selector-skeleton-card__category-count" />
      </span>
    </div>
  );
}

export function SelectorSkeletonGrid({ mode = 'brands', count }) {
  const resolvedCount = count ?? (mode === 'categories' ? 8 : 6);
  const Card = mode === 'categories' ? CategorySelectorSkeletonCard : BrandSelectorSkeletonCard;

  return (
    <div className={`dtb-selector-skeleton-grid dtb-selector-skeleton-grid--${mode}`} aria-hidden="true">
      {Array.from({ length: resolvedCount }, (_, index) => <Card key={index} />)}
    </div>
  );
}
