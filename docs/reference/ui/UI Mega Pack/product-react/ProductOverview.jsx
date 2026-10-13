import React, { useMemo, useState } from 'react';
import ProductGallery from './components/ProductGallery.jsx';
import ProductOptions from './components/ProductOptions.jsx';
import ProductPurchaseActions from './components/ProductPurchaseActions.jsx';
import ProductDetails from './components/ProductDetails.jsx';
import './product-overview.css';

const currency = (amount, currencyCode = 'USD', locale) => new Intl.NumberFormat(locale, {
  style: 'currency', currency: currencyCode, maximumFractionDigits: 2,
}).format(amount);

/** Data-driven, standalone product overview template. Claims and commerce copy are caller-owned. */
export default function ProductOverview({
  product,
  onAddToCart,
  onBuyNow,
  onWishlistChange,
  initialSelection = {},
  initialQuantity = 1,
  defaultTheme = 'dark',
  showThemeToggle = true,
  className = '',
}) {
  const safeProduct = product || {};
  const [selection, setSelection] = useState(() => ({ ...(product?.variants?.[0]?.options || {}), ...initialSelection }));
  const [quantity, setQuantity] = useState(Math.max(1, initialQuantity));
  const [theme, setTheme] = useState(defaultTheme);
  const [wishlisted, setWishlisted] = useState(false);
  const [notice, setNotice] = useState('');
  const currencyCode = safeProduct.currency || 'USD';
  const selectedVariant = useMemo(() => {
    if (!safeProduct.variants?.length) return null;
    return safeProduct.variants.find((variant) => variant.id === selection.variantId)
      || safeProduct.variants.find((variant) => Object.entries(selection).every(([key, value]) => variant.options?.[key] === value))
      || (Object.keys(selection).length === 0 ? safeProduct.variants[0] : null);
  }, [safeProduct.variants, selection]);
  const price = selectedVariant?.price ?? safeProduct.price;
  const compareAtPrice = selectedVariant?.compareAtPrice ?? safeProduct.compareAtPrice;
  const discount = Number.isFinite(price) && Number.isFinite(compareAtPrice) && compareAtPrice > price
    ? Math.round((1 - price / compareAtPrice) * 100) : null;

  const updateSelection = (key, value) => setSelection((current) => ({ ...current, [key]: value }));
  const handleWishlist = () => {
    const next = !wishlisted;
    setWishlisted(next);
    onWishlistChange?.({ product, wishlisted: next });
  };
  const handleAdd = async () => {
    if (!onAddToCart) return;
    setNotice('');
    try {
      await onAddToCart({ product, variant: selectedVariant, quantity, selection });
      setNotice(safeProduct.messages?.addedToCart || '');
    } catch {
      setNotice(safeProduct.messages?.cartError || 'Unable to add this item right now. Please try again.');
    }
  };
  const handleBuy = async () => {
    setNotice('');
    try {
      await onBuyNow({ product, variant: selectedVariant, quantity, selection });
    } catch {
      setNotice(safeProduct.messages?.checkoutError || 'Unable to continue to checkout right now. Please try again.');
    }
  };

  if (!product) return null;
  const images = safeProduct.images?.length ? safeProduct.images : safeProduct.image ? [safeProduct.image] : [];
  const productUnavailable = safeProduct.available === false || selectedVariant?.available === false;
  const canPurchase = !productUnavailable && Number.isFinite(price);

  return (
    <main className={`product-overview product-overview--${theme} ${className}`.trim()}>
      <div className="product-overview__layout">
        <ProductGallery images={images} productName={product.name} />
        <section className="product-overview__info" aria-labelledby="product-overview-title">
          {safeProduct.breadcrumbs?.length > 0 && (
            <nav className="product-overview__breadcrumbs" aria-label="Breadcrumb">
              {safeProduct.breadcrumbs.map((crumb, index) => (
                <React.Fragment key={`${crumb.label}-${index}`}>
                  {index > 0 && <span aria-hidden="true">/</span>}
                  {crumb.href ? <a href={crumb.href}>{crumb.label}</a> : <span>{crumb.label}</span>}
                </React.Fragment>
              ))}
            </nav>
          )}
          {(safeProduct.badge || safeProduct.rating) && (
            <div className="product-overview__social-proof">
              {safeProduct.badge && <span className="product-overview__badge">{safeProduct.badge}</span>}
              {safeProduct.rating && <div className="product-overview__rating" aria-label={`Rated ${safeProduct.rating.value} out of 5${safeProduct.rating.count ? ` from ${safeProduct.rating.count} reviews` : ''}`}>
                <span className="product-overview__stars" aria-hidden="true">{Array.from({ length: 5 }, (_, i) => <span key={i} className={i < Math.round(safeProduct.rating.value) ? 'is-filled' : ''}>★</span>)}</span>
                {safeProduct.rating.count != null && <span className="product-overview__rating-count">({safeProduct.rating.count})</span>}
              </div>}
            </div>
          )}
          <h1 id="product-overview-title" className="product-overview__title">{safeProduct.name}</h1>
          {Number.isFinite(price) && <div className="product-overview__price" aria-label="Price">
            <span>{currency(price, currencyCode, safeProduct.locale)}</span>
            {Number.isFinite(compareAtPrice) && compareAtPrice > price && <>
              <del>{currency(compareAtPrice, currencyCode, safeProduct.locale)}</del>
              {safeProduct.showDiscount !== false && discount != null && <span className="product-overview__discount">-{discount}%</span>}
            </>}
          </div>}
          {safeProduct.description && <p className="product-overview__description">{safeProduct.description}</p>}

          <ProductOptions groups={safeProduct.optionGroups || []} selection={selection} onChange={updateSelection} />
          <ProductPurchaseActions
            quantity={quantity}
            onQuantityChange={setQuantity}
            onAdd={onAddToCart ? handleAdd : undefined}
            onBuy={onBuyNow ? handleBuy : undefined}
            onWishlist={handleWishlist}
            wishlisted={wishlisted}
            canPurchase={canPurchase}
            addLabel={safeProduct.purchaseLabels?.addToCart}
            buyLabel={safeProduct.purchaseLabels?.buyNow}
          />
          {notice && <p className="product-overview__notice" role="status">{notice}</p>}
          {safeProduct.benefits?.length > 0 && <ul className="product-overview__benefits" aria-label="Purchase information">
            {safeProduct.benefits.map((benefit) => <li key={benefit.id || benefit.label}>
              {benefit.icon && <span className="product-overview__benefit-icon" aria-hidden="true">{benefit.icon}</span>}
              <span>{benefit.label}</span>
            </li>)}
          </ul>}
          {safeProduct.details?.length > 0 && <ProductDetails items={safeProduct.details} />}
        </section>
      </div>
      {showThemeToggle && <button className="product-overview__theme" type="button" onClick={() => setTheme((value) => value === 'dark' ? 'light' : 'dark')} aria-label={`Switch to ${theme === 'dark' ? 'light' : 'dark'} theme`}>
        <span>Theme</span><span className="product-overview__theme-track" aria-hidden="true"><span /></span>
      </button>}
    </main>
  );
}
