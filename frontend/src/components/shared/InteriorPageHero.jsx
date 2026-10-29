import Breadcrumb from './Breadcrumb.jsx';
import '../../styles/interior-page-hero.css';

/**
 * Shared presentation-only header for interior storefront discovery pages.
 * No image assets, commerce state, or route ownership.
 */
export default function InteriorPageHero({
  id,
  title,
  description,
  breadcrumbs = [],
  activeFilters = [],
  onRemoveFilter = null,
  brandLogo = '',
  brandLabel = '',
  titleVisuallyHidden = false,
  className = '',
}) {
  const brandedClass = brandLogo ? ' dtb-interior-hero--branded' : '';
  return (
    <header className={`dtb-interior-hero${brandedClass}${className ? ` ${className}` : ''}`} aria-labelledby={id}>
      <div className="dtb-interior-hero__inner">
        {breadcrumbs.length > 0 && (
          <Breadcrumb
            items={breadcrumbs}
            activeFilters={activeFilters}
            onRemoveFilter={onRemoveFilter}
            className="dtb-breadcrumb-bar--compact"
          />
        )}
        <div className="dtb-interior-hero__body">
          {brandLogo && <img className="dtb-interior-hero__brand-logo" src={brandLogo} alt={`${brandLabel || 'Manufacturer'} logo`} />}
          <h1 id={id} className={`dtb-interior-hero__title${titleVisuallyHidden ? ' dtb-visually-hidden' : ''}`}>{title}</h1>
          {description && <p className="dtb-interior-hero__description">{description}</p>}
        </div>
      </div>
    </header>
  );
}
