import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, ChevronRight, Wrench } from 'lucide-react';
import StorefrontRail from '../storefront/StorefrontRail.jsx';
import { MediaSelectorCard } from '../selectors/SelectorCards.jsx';
import { buildCategoryPageUrl } from '../../utils/catalogFacets.js';
import { resolveCategoryThumbnail, resolveCategoryThumbnailSet } from '../../utils/categoryThumbnailImages.js';
import '../../styles/category-hero.css';
import '../../styles/tool-type-merchandising.css';
import '../../styles/selector-cards.css';

export function ToolTypeTile({ category, showSelectorChevron = false, brand = '', imageLoading = 'lazy' }) {
  const [imageFailed, setImageFailed] = useState(false);
  const image = resolveCategoryThumbnail(category);
  const responsiveImage = resolveCategoryThumbnailSet(category);
  const hasImage = Boolean(image) && !imageFailed;
  const description = String(category?.description || '').replace(/<[^>]*>/g, '').trim();

  if (brand && !showSelectorChevron) {
    return (
      <MediaSelectorCard
        to={buildCategoryPageUrl(category.slug, brand)}
        title={category.name}
        media={hasImage ? <img src={responsiveImage.src || image} srcSet={responsiveImage.srcSet} sizes="(max-width: 767px) 264px, 180px" alt="" className="dtb-selector-card__image" loading="lazy" decoding="async" onError={() => setImageFailed(true)} /> : undefined}
      />
    );
  }

  return (
    <Link
      to={buildCategoryPageUrl(category.slug, brand)}
      className={`dtb-tool-type-tile${hasImage ? '' : ' dtb-tool-type-tile--no-image'}${showSelectorChevron ? ' dtb-tool-type-tile--selector' : ''}`}
    >
      <span className="dtb-tool-type-tile__media">
        {hasImage ? (
          <img
            src={image}
            srcSet={responsiveImage.srcSet}
            sizes="(max-width: 767px) 264px, 180px"
            alt=""
            className="dtb-tool-type-tile__image"
            loading={imageLoading}
            decoding="async"
            onError={() => setImageFailed(true)}
          />
        ) : (
          <Wrench size={22} strokeWidth={1.75} aria-hidden="true" className="dtb-tool-type-tile__icon" />
        )}
      </span>
      <span className="dtb-tool-type-tile__label">{category.name}</span>
      {description && !showSelectorChevron && <span className="dtb-tool-type-tile__description">{description}</span>}
      {showSelectorChevron && <ChevronRight size={15} strokeWidth={2} className="dtb-tool-type-tile__chevron" aria-hidden="true" />}
    </Link>
  );
}

/**
 * "Shop by Tool Type" row for the `/category/:slug` hero — renders the
 * authoritative WooCommerce child terms returned by the category metadata
 * endpoint. Display-category metadata remains a filtering/merchandising facet
 * and must not define the storefront taxonomy architecture.
 *
 * Category descriptions are presentation-only metadata from the same
 * authoritative child-term payload. They are never synthesized into category
 * identity or used to drive product filtering.
 *
 * Category thumbnail source assets intentionally have intrinsic, tool-specific
 * aspect ratios. The fixed media viewport and `object-fit: contain` CSS own
 * layout stability and fitment; the image element must not assert a legacy
 * 348x128 intrinsic ratio.
 */
export default function ShopByToolType({ categories = [], parentLabel = '', brand = '', viewAllHref = '', homeVariant = false }) {
  const items = Array.isArray(categories) ? categories : [];
  if (items.length === 0) return null;

  const categoryRail = (
    <StorefrontRail label={parentLabel ? `Shop ${parentLabel} by category` : 'Shop by tool type'} className="dtb-tool-type-rail">
      {items.map((category, index) => (
        <ToolTypeTile
          key={category.slug}
          category={category}
          showSelectorChevron
          brand={brand}
          imageLoading={homeVariant && index === 0 ? 'eager' : 'lazy'}
        />
      ))}
    </StorefrontRail>
  );

  return (
    <div className={`dtb-tool-type-section mb-6 sm:mb-8${homeVariant ? ' dtb-tool-type-section--home' : ''}`}>
      {homeVariant && !parentLabel && (
        <div className="storefront-section__head">
          <div className={`storefront-section__head-text${homeVariant ? ' dtb-home-section-heading' : ''}`}>
            <h2 className="storefront-section__title">Shop by Tool Type</h2>
            <p className="storefront-section__subtitle">Explore drywall tools by type and find the right fit for your next project.</p>
          </div>
          {viewAllHref ? (
            <Link to={viewAllHref} className="storefront-section__view-all">
              View all tool types
              <ArrowRight size={14} aria-hidden="true" />
            </Link>
          ) : null}
        </div>
      )}

      {categoryRail}
    </div>
  );
}
