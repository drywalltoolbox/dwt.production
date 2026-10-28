import { useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { ArrowRight, ChevronRight, Wrench } from 'lucide-react';
import StorefrontRail from '../storefront/StorefrontRail.jsx';
import { MediaSelectorCard } from '../selectors/SelectorCards.jsx';
import { buildCategoryPageUrl } from '../../utils/catalogFacets.js';
import { resolveCategoryThumbnail } from '../../utils/categoryThumbnailImages.js';
import '../../styles/category-hero.css';
import '../../styles/tool-type-merchandising.css';
import '../../styles/selector-cards.css';

export function ToolTypeTile({ category, showSelectorChevron = false, showCount = true, brand = '' }) {
  const [imageFailed, setImageFailed] = useState(false);
  const image = resolveCategoryThumbnail(category);
  const hasImage = Boolean(image) && !imageFailed;
  const description = String(category?.description || '').replace(/<[^>]*>/g, '').trim();

  if (brand && !showSelectorChevron) {
    const count = Number(category.count || 0);
    return (
      <MediaSelectorCard
        to={buildCategoryPageUrl(category.slug, brand)}
        title={category.name}
        meta={count > 0 ? `${count.toLocaleString()} ${count === 1 ? 'product' : 'products'}` : ''}
        media={hasImage ? <img src={image} alt="" className="dtb-selector-card__image" loading="lazy" decoding="async" onError={() => setImageFailed(true)} /> : undefined}
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
            alt=""
            className="dtb-tool-type-tile__image"
            loading="lazy"
            decoding="async"
            onError={() => setImageFailed(true)}
          />
        ) : (
          <Wrench size={22} strokeWidth={1.75} aria-hidden="true" className="dtb-tool-type-tile__icon" />
        )}
      </span>
      <span className="dtb-tool-type-tile__label">{category.name}</span>
      {showCount && Number(category.count) > 0 && <span className="dtb-tool-type-tile__count">{Number(category.count).toLocaleString()} products</span>}
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
export default function ShopByToolType({ categories = [], onOpenFilters, parentLabel = '', brand = '' }) {
  const location = useLocation();
  const items = Array.isArray(categories) ? categories : [];
  const isAllProductsRoute = location.pathname === '/products';
  if (items.length === 0) return null;

  const handleViewAll = (event) => {
    if (typeof window === 'undefined') return;
    const target = document.getElementById('dtb-category-filters');
    if (!target) return;
    event.preventDefault();
    onOpenFilters?.();
    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  return (
    <div className={`dtb-tool-type-section mb-6 sm:mb-8${isAllProductsRoute ? ' dtb-tool-type-section--all-products' : ''}`}>
      <div className="storefront-section__head">
        <div className="storefront-section__head-text">
          <h2 className="storefront-section__title">{parentLabel ? `Shop ${parentLabel} by Category` : 'Shop by Tool Type'}</h2>
          <p className="storefront-section__subtitle">{parentLabel ? `Choose a ${parentLabel.toLowerCase()} category to browse products.` : 'Go directly to the tool family you need, then refine by brand and product attributes.'}</p>
        </div>
        <a href="#dtb-category-filters" className="storefront-section__view-all" onClick={handleViewAll}>
          View all tool types
          <ArrowRight size={14} aria-hidden="true" />
        </a>
      </div>

      {isAllProductsRoute ? (
        <StorefrontRail label="Shop by tool type" className="dtb-tool-type-rail">
          {items.map((category) => (
            <ToolTypeTile key={category.slug} category={category} showSelectorChevron showCount={false} brand={brand} />
          ))}
          <a
            href="#dtb-category-filters"
            className="dtb-tool-type-view-all"
            onClick={handleViewAll}
            aria-label="View all product categories in filters"
          >
            <span className="dtb-tool-type-view-all__icon" aria-hidden="true">
              <ArrowRight size={20} strokeWidth={2} />
            </span>
            <span>View All<br />Categories</span>
          </a>
        </StorefrontRail>
      ) : (
        <div className="dtb-tool-type-grid" aria-label={`${parentLabel || 'Product'} categories`}>
          {items.map((category) => <ToolTypeTile key={category.slug} category={category} brand={brand} />)}
        </div>
      )}
    </div>
  );
}
