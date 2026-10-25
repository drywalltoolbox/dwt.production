import { useState, useEffect, useRef, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Eye, SlidersHorizontal } from 'lucide-react';
import ProductCardImage from '../product/ProductCardImage';
import AddToCartButton from '../ui/AddToCartButton.jsx';
import '../../styles/contractor-shopping.css';
import { preloadRoute } from '../../routing/routeModules.js';
import { preloadQuickViewModules } from '../../routing/quickViewModules.js';
import { preloadProductDetail } from '../../hooks/useProductDetail.js';

function useIsMobile() {
  const [isMobile, setIsMobile] = useState(() => typeof window !== 'undefined' && window.matchMedia('(max-width: 767px)').matches);
  useEffect(() => {
    const mq = window.matchMedia('(max-width: 767px)');
    const onChange = () => setIsMobile(mq.matches);
    mq.addEventListener('change', onChange);
    return () => mq.removeEventListener('change', onChange);
  }, []);
  return isMobile;
}

function money(value) {
  const numeric = typeof value === 'number' ? value : parseFloat(String(value || '0'));
  return Number.isFinite(numeric) ? numeric.toFixed(2) : '0.00';
}

function parsePrice(value) {
  const numeric = typeof value === 'number' ? value : parseFloat(String(value ?? ''));
  return Number.isFinite(numeric) ? numeric : null;
}


function isNestedInteractiveTarget(target) {
  return target instanceof Element && Boolean(target.closest('button, a, input, select, textarea, [data-dtb-card-action]'));
}

export default function StorefrontProductTile({ product, cardProduct, onOpenModal, onAddToCart, index = 0, variant = 'grid' }) {
  const isVariable = Boolean(product?.is_variable);
  const variationContext = isVariable ? cardProduct : null;
  const displayProduct = product || {};
  const commerceProduct = variationContext || displayProduct;
  const stockStatus = commerceProduct.stock_status || displayProduct.stock_status || 'instock';
  const outOfStock = stockStatus === 'outofstock';
  const name = displayProduct.name || commerceProduct.name || displayProduct.part_number || 'Product';

  // Variable parents are grouping records, not manufacturer sellable identities.
  // Never expose their internal/canonical parent SKU on customer product cards.
  // A real variation SKU belongs to the selected variation and is surfaced by the PDP/selector.
  const sku = isVariable ? '' : (displayProduct.sku || commerceProduct.sku || '');

  const variableMinPrice = isVariable ? parsePrice(displayProduct.min_price) : null;
  const variableMaxPrice = isVariable ? parsePrice(displayProduct.max_price) : null;
  const priceStr = isVariable
    ? (variableMinPrice !== null && variableMaxPrice !== null
      ? `$${money(variableMinPrice)}–$${money(variableMaxPrice)}`
      : 'View options')
    : `$${money(commerceProduct.price ?? displayProduct.price ?? 0)}`;

  const salePriceValue = parsePrice(commerceProduct.sale_price ?? displayProduct.sale_price);
  const regularPriceValue = parsePrice(commerceProduct.regular_price ?? displayProduct.regular_price ?? commerceProduct.compare_at_price ?? displayProduct.compare_at_price ?? commerceProduct.min_regular_price ?? displayProduct.min_regular_price);
  // A regular price is only a comparison price when the product has a real,
  // lower sale price. Never strike through an equal/current regular price.
  const comparePriceStr = !isVariable
    && salePriceValue !== null
    && regularPriceValue !== null
    && salePriceValue < regularPriceValue
    ? `$${money(regularPriceValue)}`
    : null;
  const image = variationContext?.image_thumbnail || variationContext?.image || displayProduct.image_thumbnail || displayProduct.image;
  const imageSrcset = variationContext?.image_srcset || displayProduct.image_srcset;
  const slug = displayProduct.slug || commerceProduct.slug;
  const productUrl = slug ? `/products/${slug}` : null;
  const isMobile = useIsMobile();
  const showDesktopOverlay = !isMobile && variant !== 'list';
  const [overlayActive, setOverlayActive] = useState(false);
  const [overlayHasFocus, setOverlayHasFocus] = useState(false);
  const cardRef = useRef(null);
  const imageButtonRef = useRef(null);
  const overlayRef = useRef(null);
  const navigate = useNavigate();

  const closeOverlay = useCallback(() => {
    const activeEl = document.activeElement;
    if (overlayRef.current && activeEl instanceof HTMLElement && overlayRef.current.contains(activeEl)) imageButtonRef.current?.focus();
    setOverlayActive(false);
  }, []);

  useEffect(() => {
    if (!overlayActive || !isMobile) return undefined;
    const handler = (e) => { if (cardRef.current && !cardRef.current.contains(e.target)) setOverlayActive(false); };
    document.addEventListener('pointerdown', handler);
    return () => document.removeEventListener('pointerdown', handler);
  }, [overlayActive, isMobile]);

  const openModalFromMobileCard = useCallback((event) => {
    if (!isMobile || event?.defaultPrevented || isNestedInteractiveTarget(event?.target)) return;
    closeOverlay(); onOpenModal?.();
  }, [closeOverlay, isMobile, onOpenModal]);

  const handleImageClick = useCallback((event) => {
    event?.stopPropagation();
    if (isMobile) { closeOverlay(); onOpenModal?.(); return; }
    if (productUrl) navigate(productUrl);
  }, [closeOverlay, isMobile, navigate, onOpenModal, productUrl]);

  const warmProductRoute = useCallback(() => {
    if (productUrl) preloadRoute(productUrl);
    if (slug) {
      preloadQuickViewModules().catch(() => null);
      preloadProductDetail(slug).catch(() => null);
    }
  }, [productUrl, slug]);
  // Do not warm PDP/quick-view modules on touch pointerdown. A finger touching
  // the image is often the beginning of a rail swipe, not purchase intent.
  // Keyboard focus and precise mouse/pen hover retain intent-based preloading.
  const handlePointerIntent = useCallback((event) => {
    if (event.pointerType === 'mouse' || event.pointerType === 'pen') warmProductRoute();
  }, [warmProductRoute]);
  const handleMouseEnter = useCallback(() => { if (showDesktopOverlay) { warmProductRoute(); setOverlayActive(true); } }, [showDesktopOverlay, warmProductRoute]);
  const handleMouseLeave = useCallback(() => { if (showDesktopOverlay) closeOverlay(); }, [closeOverlay, showDesktopOverlay]);
  const handleTitleClick = useCallback((event) => {
    event.stopPropagation(); closeOverlay();
    if (isMobile) { if (productUrl) navigate(productUrl); return; }
    onOpenModal?.();
  }, [closeOverlay, isMobile, navigate, onOpenModal, productUrl]);
  const handleAddButtonClick = useCallback((event) => {
    event.stopPropagation(); closeOverlay();
    try { const result = onAddToCart?.(); result?.catch?.(() => {}); } catch { /* owning surface renders cart error */ }
  }, [closeOverlay, onAddToCart]);
  const handleQuickView = useCallback((e) => { e.stopPropagation(); closeOverlay(); onOpenModal?.(); }, [closeOverlay, onOpenModal]);

  // Search uses shared product identities and existing cart/variation actions.\n  // Never present a variable parent's internal SKU as a sellable variation SKU.
  if (variant === 'search') {
    return (
      <article className="dtb-search-result" aria-label={name}>
        <div className="dtb-search-result__visual">
          <button type="button" className="dtb-search-result__image" onClick={handleImageClick} onFocus={warmProductRoute} onPointerEnter={handlePointerIntent} aria-label={`Quick view ${name}`}>
            <ProductCardImage product={displayProduct} src={image} srcSet={imageSrcset} sizes="(max-width: 767px) 84px, 108px" alt="" className="dtb-search-result__img" padding="0" fit="contain" preferThumbnail eager={false} />
          </button>
        </div>
        <div className="dtb-search-result__details">
          {displayProduct.brand ? <span className="dtb-search-result__brand">{displayProduct.brand}</span> : null}
          <button type="button" className="dtb-search-result__name" onClick={handleTitleClick} onFocus={warmProductRoute} onPointerEnter={handlePointerIntent}>{name}</button>
          {sku ? <span className="dtb-search-result__sku">SKU: {sku}</span> : null}
          <div className="dtb-search-result__bottom">
            <span className="dtb-search-result__prices">
              <strong className="dtb-search-result__price">{priceStr}</strong>
              {comparePriceStr ? <span className="dtb-search-result__compare">{comparePriceStr}</span> : null}
            </span>
            {isVariable ? (
              <button type="button" className="dtb-search-result__action dtb-search-result__options" onClick={handleQuickView} onFocus={warmProductRoute} aria-label={`Choose options for ${name}`}>
                <SlidersHorizontal size={14} aria-hidden="true" /> Options
              </button>
            ) : outOfStock ? (
              <button type="button" className="dtb-search-result__action" onClick={handleTitleClick} aria-label={`View ${name}`}>View</button>
            ) : (
              <AddToCartButton onClick={handleAddButtonClick} className="dtb-search-result__action" size="card" label="Add" productId={displayProduct.id} aria-label={`Add ${name} to cart`} data-dtb-card-action="add" />
            )}
          </div>
        </div>
      </article>
    );
  }

  return (
    <article ref={cardRef} className={`dtb-product-card dtb-product-card--${variant} storefront-motion-card${isVariable ? ' dtb-product-card--variable' : ''}`} style={{ '--dtb-card-delay': `${Math.min(index, 8) * 30}ms` }} onMouseEnter={handleMouseEnter} onMouseLeave={handleMouseLeave} onClick={openModalFromMobileCard}>
      <div className="dtb-product-card__image">
        <div ref={imageButtonRef} role="button" tabIndex={0} className="dtb-product-card__image-hit" onFocus={warmProductRoute} onPointerEnter={handlePointerIntent} onClick={handleImageClick} onKeyDown={(e) => { if (e.target !== e.currentTarget) return; if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); handleImageClick(e); } }} aria-label={isMobile ? `Quick view ${name}` : `View ${name}`}>
          <ProductCardImage product={displayProduct} src={image} srcSet={imageSrcset} sizes={variant === 'rail' ? '(max-width: 767px) 44vw, 188px' : variant === 'list' ? '(max-width: 767px) 32vw, 240px' : '(max-width: 767px) 50vw, (max-width: 1024px) 33vw, 240px'} alt={name} className="dtb-product-card__img" padding="0" fit="contain" preferThumbnail eager={false} />
        </div>
        <span className={`dtb-product-card__badge dtb-product-card__badge--${outOfStock ? 'out' : 'in'} dtb-product-card__badge--right`}>{outOfStock ? 'Out of Stock' : 'In Stock'}</span>
        {showDesktopOverlay && <div ref={overlayRef} aria-hidden={!overlayActive && !overlayHasFocus} className={`dtb-product-card__qv-overlay${overlayActive ? ' dtb-product-card__qv-overlay--active' : ''}`} onFocusCapture={() => setOverlayHasFocus(true)} onBlurCapture={(e) => { if (!e.currentTarget.contains(e.relatedTarget)) setOverlayHasFocus(false); }} onClick={(e) => { e.stopPropagation(); closeOverlay(); if (productUrl) navigate(productUrl); }}><div className={`dtb-product-card__qv-actions${overlayActive ? ' dtb-product-card__qv-actions--active' : ''}`}><button type="button" tabIndex={overlayActive ? 0 : -1} className="dtb-product-card__qv-btn" onClick={handleQuickView} aria-label={`Quick view ${name}`}><Eye size={14} strokeWidth={2.2} /><span>Quick View</span></button></div></div>}
      </div>
      <div className="dtb-product-card__meta">
        {displayProduct.brand ? <span className="dtb-product-card__brand">{displayProduct.brand}</span> : null}
        <button type="button" onClick={handleTitleClick} className="dtb-product-card__name" onFocus={warmProductRoute} onPointerEnter={handlePointerIntent} data-dtb-card-action="title" aria-label={isMobile ? `Open full page for ${name}` : `View product details for ${name}`}>{name}</button>
        <span className={`dtb-product-card__sku${sku ? '' : ' dtb-product-card__sku--empty'}`} aria-hidden={sku ? undefined : true}>{sku ? `SKU: ${sku}` : '\u00a0'}</span>
        <div className="dtb-product-card__divider" />
        <div className="dtb-product-card__footer">
        <div className="dtb-product-card__price-col">
          <div className="dtb-product-card__price-group">
            <strong className="dtb-product-card__price" style={{ color: outOfStock ? 'var(--dtb-muted)' : 'var(--dtb-text)' }}>{priceStr}</strong>
            {comparePriceStr ? <span className="dtb-product-card__compare-price">{comparePriceStr}</span> : null}
          </div>
        </div>
        {isVariable ? (
          <button
            type="button"
            className="dtb-add-to-cart dtb-add-to-cart--card dtb-product-card__action dtb-product-card__action--mobile-icon"
            onClick={handleQuickView}
            onFocus={warmProductRoute}
            onPointerEnter={handlePointerIntent}
            aria-label={`Choose options for ${name}`}
            data-dtb-card-action="options"
          >
            <span className="dtb-add-to-cart__surface" aria-hidden="true" />
            <span className="dtb-add-to-cart__content">
              <SlidersHorizontal className="dtb-add-to-cart__cart" aria-hidden="true" />
              <span className="dtb-add-to-cart__label">Options</span>
            </span>
          </button>
        ) : (
          <AddToCartButton onClick={handleAddButtonClick} disabled={outOfStock} className="dtb-product-card__action dtb-product-card__action--mobile-icon" size="card" label="Add" productId={displayProduct.id} aria-label={`Add ${name} to cart`} data-dtb-card-action="add" />
        )}
        </div>
      </div>
    </article>
  );
}
