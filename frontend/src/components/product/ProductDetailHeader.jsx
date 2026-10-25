import { Link } from 'react-router-dom';
import { shouldShowCompareAtPrice } from '../../utils/productPricing.js';
import ProductRating from './ProductRating.jsx';
import '../../styles/product-detail-overview-refinements.css';
import '../../styles/product-detail-brand-refinements.css';

function getProductUrl(product) {
  const slug = product?.slug || product?.post_name || '';
  if (slug) return `/products/${slug}`;
  const id = product?.id || product?.part_number || product?.sku || '';
  return id ? `/product/${encodeURIComponent(id)}` : '';
}

function toFinitePrice(value) {
  const parsed = Number.parseFloat(value);
  return Number.isFinite(parsed) ? parsed : null;
}

export default function ProductDetailHeader({
  product,
  productUrl: productUrlOverride,
  effectiveName,
  effectiveSku,
  brandLabel,
  brandLogoSrc,
  brandLogoClassName = '',
  isOutOfStock,
  displayPrice,
  pricePrefix,
  compareAt,
  rawPrice,
  money,
  onProductTitleClick,
  onReviewsClick,
  rating = 0,
  ratingCount = 0,
}) {
  const productUrl = productUrlOverride || getProductUrl(product);
  const title = effectiveName || product.sku || product.part_number;
  // ProductDetail owns variation resolution and passes the effective selected
  // variation's price explicitly. Do not re-read price fields from `product`
  // here: for variable PDPs that object is the parent/default-card context and
  // can legitimately carry the first/default variation price.
  const rawPriceValue = toFinitePrice(rawPrice);
  const resolvedRegularPrice = toFinitePrice(compareAt);
  const primaryPrice = rawPriceValue != null ? money(rawPriceValue) : displayPrice;

  return (
    <header className="dtb-pdp-header">
      {brandLogoSrc ? (
        <div className="dtb-pdp-header__brand-logo-wrap">
          <img
            src={brandLogoSrc}
            alt={`${brandLabel || 'Product brand'} logo`}
            className={`dtb-pdp-header__brand-logo ${brandLogoClassName}`.trim()}
            loading="eager"
            decoding="async"
          />
        </div>
      ) : brandLabel ? (
        <div className="dtb-pdp-header__brand-eyebrow">{brandLabel}</div>
      ) : null}

      <button
        type="button"
        onClick={onReviewsClick}
        aria-label="Write a review"
        className="dtb-pdp-header__reviews dtb-pdp-header__reviews--inline"
      >
        <ProductRating rating={rating} ratingCount={ratingCount} />
      </button>

      <h1 className="dtb-pdp-header__title">
        {productUrl ? (
          <Link
            to={productUrl}
            className="dtb-pdp-header__title-link"
            onClick={onProductTitleClick}
          >
            {title}
          </Link>
        ) : title}
      </h1>

      <div className="dtb-pdp-header__identity-row">
        {effectiveSku ? (
          <span className="dtb-pdp-header__identity-sku">SKU: {effectiveSku}</span>
        ) : null}
        <span className={`dtb-pdp-header__meta-stock${isOutOfStock ? ' is-out' : ''}`}>
          <span className="dtb-pdp-header__meta-stock-dot" aria-hidden="true" />
          {isOutOfStock ? 'Out of Stock' : 'In Stock'}
        </span>
      </div>

      <div className="dtb-pdp-header__price-block">
        <div className="dtb-pdp-header__price-row" aria-live="polite" aria-atomic="true">
          <span className="dtb-pdp-header__price">
            {pricePrefix}{primaryPrice}
          </span>
          {shouldShowCompareAtPrice(rawPriceValue, resolvedRegularPrice) ? (
            <span className="dtb-pdp-header__compare-at">
              ${money(resolvedRegularPrice)}
            </span>
          ) : null}
        </div>
      </div>

    </header>
  );
}
