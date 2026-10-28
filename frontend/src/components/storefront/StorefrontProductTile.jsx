import { useState, useEffect, useRef, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Check, ChevronDown, Eye, SlidersHorizontal, X } from 'lucide-react';
import { createPortal } from 'react-dom';
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

const currencyFormatter = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function money(value) {
  const numeric = typeof value === 'number' ? value : parseFloat(String(value || '0'));
  return Number.isFinite(numeric) ? currencyFormatter.format(numeric) : '0.00';
}

function parsePrice(value) {
  const numeric = typeof value === 'number' ? value : parseFloat(String(value ?? ''));
  return Number.isFinite(numeric) ? numeric : null;
}


function isNestedInteractiveTarget(target) {
  return target instanceof Element && Boolean(target.closest('button, a, input, select, textarea, [data-dtb-card-action]'));
}

function getQuantityMenuPosition(trigger) {
  if (!trigger || typeof window === 'undefined') return null;
  const rect = trigger.getBoundingClientRect();
  const viewportWidth = document.documentElement.clientWidth || window.innerWidth;
  const viewportHeight = window.innerHeight;
  const gutter = Math.min(12, viewportWidth / 2);
  const gap = 8;
  const preferredHeight = 196;
  const width = Math.max(0, Math.min(104, viewportWidth - gutter * 2));
  const minLeft = gutter;
  const maxLeft = Math.max(minLeft, viewportWidth - width - gutter);
  const left = Math.min(Math.max(minLeft, rect.left + (rect.width - width) / 2), maxLeft);
  const availableBelow = Math.max(0, viewportHeight - rect.bottom - gap - gutter);
  const availableAbove = Math.max(0, rect.top - gap - gutter);
  const openBelow = availableBelow >= preferredHeight || availableBelow >= availableAbove;
  const availableHeight = openBelow ? availableBelow : availableAbove;
  const maxHeight = Math.min(preferredHeight, availableHeight);
  if (maxHeight < 40 || width === 0) return null;
  const top = openBelow ? rect.bottom + gap : rect.top - gap - maxHeight;
  return { top, left, width, maxHeight };
}

export default function StorefrontProductTile({ product, cardProduct, onOpenModal, onAddToCart, index = 0, variant = 'grid' }) {
  const isVariable = Boolean(product?.is_variable || product?.type === 'variable');
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
  const [overlayActive, setOverlayActive] = useState(false);
  const [overlayHasFocus, setOverlayHasFocus] = useState(false);
  const isMobile = useIsMobile();
  const showDesktopOverlay = !isMobile && variant !== 'list';

  const variableMinPrice = isVariable ? parsePrice(displayProduct.min_price ?? displayProduct.price?.min) : null;
  const variableMaxPrice = isVariable ? parsePrice(displayProduct.max_price ?? displayProduct.price?.max) : null;
  const hasVariableRange = variableMinPrice !== null && variableMaxPrice !== null;
  const priceStr = isVariable
    ? (hasVariableRange
      ? `$${money(variableMinPrice)}\n-$${money(variableMaxPrice)}`
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
  const productUrl = slug ? `/products/${slug}${displayProduct.includedVariation ? `?variant=${encodeURIComponent(displayProduct.id)}` : ''}` : null;
  const [quantity, setQuantity] = useState(1);
  const [quantityOpen, setQuantityOpen] = useState(false);
  const [quantityMenuPosition, setQuantityMenuPosition] = useState(null);
  const quantityTriggerRef = useRef(null);
  const quantitySheetRef = useRef(null);
  const quantityMenuRef = useRef(null);
  const firstQuantityOptionRef = useRef(null);
  const imageButtonRef = useRef(null);
  const overlayRef = useRef(null);
  const navigate = useNavigate();

  const closeOverlay = useCallback(() => {
    const activeElement = document.activeElement;
    if (overlayRef.current?.contains(activeElement)) imageButtonRef.current?.focus();
    setOverlayActive(false);
  }, []);

  useEffect(() => {
    if (!quantityOpen || !isMobile) return undefined;
    const focusFrame = window.requestAnimationFrame(() => firstQuantityOptionRef.current?.focus());
    const handleKeyDown = (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        setQuantityOpen(false);
        window.requestAnimationFrame(() => quantityTriggerRef.current?.focus());
        return;
      }
      if (event.key !== 'Tab' || !quantitySheetRef.current) return;
      const focusable = Array.from(quantitySheetRef.current.querySelectorAll('button:not(:disabled)'));
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };
    document.addEventListener('keydown', handleKeyDown);
    return () => {
      window.cancelAnimationFrame(focusFrame);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [isMobile, quantityOpen]);

  useEffect(() => {
    if (!quantityOpen || isMobile) return undefined;
    const updatePosition = () => setQuantityMenuPosition(getQuantityMenuPosition(quantityTriggerRef.current));
    const handlePointerDown = (event) => {
      if (quantityTriggerRef.current?.contains(event.target) || quantityMenuRef.current?.contains(event.target)) return;
      setQuantityOpen(false);
    };
    const handleFocusIn = (event) => {
      if (quantityTriggerRef.current?.contains(event.target) || quantityMenuRef.current?.contains(event.target)) return;
      setQuantityOpen(false);
    };
    const handleKeyDown = (event) => {
      if (event.key !== 'Escape') return;
      event.preventDefault();
      setQuantityOpen(false);
      window.requestAnimationFrame(() => quantityTriggerRef.current?.focus());
    };
    updatePosition();
    window.requestAnimationFrame(() => quantityMenuRef.current?.querySelector('[aria-pressed="true"]')?.focus());
    document.addEventListener('pointerdown', handlePointerDown);
    document.addEventListener('focusin', handleFocusIn);
    document.addEventListener('keydown', handleKeyDown);
    window.addEventListener('resize', updatePosition);
    window.addEventListener('scroll', updatePosition, true);
    return () => {
      document.removeEventListener('pointerdown', handlePointerDown);
      document.removeEventListener('focusin', handleFocusIn);
      document.removeEventListener('keydown', handleKeyDown);
      window.removeEventListener('resize', updatePosition);
      window.removeEventListener('scroll', updatePosition, true);
    };
  }, [isMobile, quantityOpen]);

  const openQuantitySelector = useCallback((event) => {
    event.stopPropagation();
    if (quantityOpen) {
      setQuantityOpen(false);
      return;
    }
    if (isMobile) {
      setQuantityOpen(true);
      return;
    }
    setQuantityMenuPosition(getQuantityMenuPosition(quantityTriggerRef.current));
    setQuantityOpen(true);
  }, [isMobile, quantityOpen]);

  const closeQuantitySheet = useCallback(() => {
    setQuantityOpen(false);
    window.requestAnimationFrame(() => quantityTriggerRef.current?.focus());
  }, []);

  const openModalFromMobileCard = useCallback((event) => {
    if (!isMobile || event?.defaultPrevented || isNestedInteractiveTarget(event?.target)) return;
    closeOverlay();
    onOpenModal?.();
  }, [closeOverlay, isMobile, onOpenModal]);

  const handleImageClick = useCallback((event) => {
    event?.stopPropagation();
    closeOverlay();
    if (isMobile) { onOpenModal?.(); return; }
    if (productUrl) navigate(productUrl);
  }, [closeOverlay, isMobile, navigate, onOpenModal, productUrl]);

  const warmProductRoute = useCallback(() => {
    if (productUrl) preloadRoute(productUrl);
    if (slug) {
      preloadQuickViewModules().catch(() => null);
      preloadProductDetail(slug).catch(() => null);
    }
  }, [productUrl, slug]);
  const handleMouseEnter = useCallback(() => {
    if (!showDesktopOverlay) return;
    warmProductRoute();
    setOverlayActive(true);
  }, [showDesktopOverlay, warmProductRoute]);
  const handleMouseLeave = useCallback(() => {
    if (showDesktopOverlay) closeOverlay();
  }, [closeOverlay, showDesktopOverlay]);
  // Do not warm PDP/quick-view modules on touch pointerdown. A finger touching
  // the image is often the beginning of a rail swipe, not purchase intent.
  // Keyboard focus and precise mouse/pen hover retain intent-based preloading.
  const handlePointerIntent = useCallback((event) => {
    if (event.pointerType === 'mouse' || event.pointerType === 'pen') warmProductRoute();
  }, [warmProductRoute]);
  const handleTitleClick = useCallback((event) => {
    event.stopPropagation();
    closeOverlay();
    if (isMobile) { if (productUrl) navigate(productUrl); return; }
    onOpenModal?.();
  }, [closeOverlay, isMobile, navigate, onOpenModal, productUrl]);
  const handleAddButtonClick = useCallback((event) => {
    event.stopPropagation();
    closeOverlay();
    try { const result = onAddToCart?.(quantity); result?.catch?.(() => {}); } catch { /* owning surface renders cart error */ }
  }, [closeOverlay, onAddToCart, quantity]);
  const handleQuickView = useCallback((event) => {
    event.stopPropagation();
    closeOverlay();
    onOpenModal?.();
  }, [closeOverlay, onOpenModal]);

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
    <article className={`dtb-product-card dtb-product-card--${variant} storefront-motion-card${isVariable ? ' dtb-product-card--variable' : ''}`} style={{ '--dtb-card-delay': `${Math.min(index, 8) * 30}ms` }} onMouseEnter={handleMouseEnter} onMouseLeave={handleMouseLeave} onClick={openModalFromMobileCard}>
      <div className="dtb-product-card__image">
        <div ref={imageButtonRef} role="button" tabIndex={0} className="dtb-product-card__image-hit" onFocus={warmProductRoute} onPointerEnter={handlePointerIntent} onClick={handleImageClick} onKeyDown={(e) => { if (e.target !== e.currentTarget) return; if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); handleImageClick(e); } }} aria-label={isMobile ? `Quick view ${name}` : `View ${name}`}>
          <ProductCardImage product={displayProduct} src={image} srcSet={imageSrcset} sizes={variant === 'rail' ? '(max-width: 767px) 44vw, 188px' : variant === 'list' ? '(max-width: 767px) 32vw, 240px' : '(max-width: 767px) 50vw, (max-width: 1024px) 33vw, 240px'} alt={name} className="dtb-product-card__img" padding="0" fit="contain" preferThumbnail eager={false} />
        </div>
        {showDesktopOverlay && (
          <div
            ref={overlayRef}
            aria-hidden={!overlayActive && !overlayHasFocus}
            className={`dtb-product-card__qv-overlay${overlayActive ? ' dtb-product-card__qv-overlay--active' : ''}`}
            onFocusCapture={() => setOverlayHasFocus(true)}
            onBlurCapture={(event) => {
              if (!event.currentTarget.contains(event.relatedTarget)) setOverlayHasFocus(false);
            }}
            onClick={(event) => {
              event.stopPropagation();
              closeOverlay();
              if (productUrl) navigate(productUrl);
            }}
          >
            <div className={`dtb-product-card__qv-actions${overlayActive ? ' dtb-product-card__qv-actions--active' : ''}`}>
              <button
                type="button"
                tabIndex={overlayActive ? 0 : -1}
                className="dtb-product-card__qv-btn"
                onClick={handleQuickView}
                aria-label={`Quick view ${name}`}
              >
                <Eye size={14} strokeWidth={2.2} aria-hidden="true" />
                <span>Quick View</span>
              </button>
            </div>
          </div>
        )}
      </div>
      <div className="dtb-product-card__meta">
        {displayProduct.brand ? <span className="dtb-product-card__brand">{displayProduct.brand}</span> : null}
        <button type="button" onClick={handleTitleClick} className="dtb-product-card__name" onFocus={warmProductRoute} onPointerEnter={handlePointerIntent} data-dtb-card-action="title" aria-label={isMobile ? `Open full page for ${name}` : `View product details for ${name}`}>{name}</button>
        <span className={`dtb-product-card__sku${sku ? '' : ' dtb-product-card__sku--empty'}`} aria-hidden={sku ? undefined : true}>{sku ? `SKU: ${sku}` : '\u00a0'}</span>
        <div className="dtb-product-card__divider" />
        <div className="dtb-product-card__footer">
        <div className="dtb-product-card__price-col">
          <div className="dtb-product-card__price-group">
            <strong className={`dtb-product-card__price${hasVariableRange ? ' dtb-product-card__price--range' : ''}`} style={{ color: outOfStock ? 'var(--dtb-muted)' : 'var(--dtb-text)' }} aria-label={hasVariableRange ? `${money(variableMinPrice)} to ${money(variableMaxPrice)}` : undefined}>
              {hasVariableRange ? <><span className="dtb-product-card__range-min" aria-hidden="true">${money(variableMinPrice)}</span><span className="dtb-product-card__range-max" aria-hidden="true"><span className="dtb-product-card__range-dash">–</span>${money(variableMaxPrice)}</span></> : priceStr}
            </strong>
            {comparePriceStr ? <span className="dtb-product-card__compare-price">{comparePriceStr}</span> : null}
          </div>
        </div>
        <div className="dtb-product-card__commerce-controls">
        {!isVariable && !outOfStock && <div className="dtb-product-card__quantity-wrap">
          <button
            ref={quantityTriggerRef}
            type="button"
            className="dtb-product-card__quantity"
            onClick={openQuantitySelector}
            onPointerDown={(event) => event.stopPropagation()}
            aria-label={`Quantity: ${quantity}`}
            aria-haspopup="dialog"
            aria-expanded={quantityOpen}
            data-dtb-card-action="quantity"
          >
            <span>{quantity}</span>
            <ChevronDown size={15} strokeWidth={2} aria-hidden="true" />
          </button>
        </div>}
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
              <span className="dtb-add-to-cart__label"><span className="dtb-card-action__desktop-label">View Options</span><span className="dtb-card-action__mobile-label">View Options</span></span>
            </span>
          </button>
        ) : (
          <AddToCartButton onClick={handleAddButtonClick} disabled={outOfStock} className="dtb-product-card__action dtb-product-card__action--mobile-icon" size="card" label="Add" productId={displayProduct.id} aria-label={`Add ${name} to cart`} data-dtb-card-action="add"><span className="dtb-card-action__desktop-label">Add to Cart</span><span className="dtb-card-action__mobile-label">Add to cart</span></AddToCartButton>
        )}
        </div>
        </div>
      </div>
      {!isMobile && quantityOpen && quantityMenuPosition && createPortal(
        <div
          ref={quantityMenuRef}
          className="dtb-quantity-menu"
          role="dialog"
          aria-label={`Choose quantity for ${name}`}
          style={quantityMenuPosition}
          onClick={(event) => event.stopPropagation()}
        >
          {Array.from({ length: 10 }, (_, optionIndex) => optionIndex + 1).map((value) => (
            <button
              key={value}
              type="button"
              className={`dtb-quantity-menu__option${quantity === value ? ' dtb-quantity-menu__option--selected' : ''}`}
              aria-pressed={quantity === value}
              onClick={() => { setQuantity(value); closeQuantitySheet(); }}
            >
              <span>{value}</span>
              {quantity === value ? <Check size={15} strokeWidth={2.5} aria-hidden="true" /> : null}
            </button>
          ))}
        </div>,
        document.body,
      )}
      {isMobile && quantityOpen && createPortal(
        <div className="dtb-quantity-sheet-backdrop" onClick={(event) => { event.stopPropagation(); closeQuantitySheet(); }}>
          <section
            ref={quantitySheetRef}
            className="dtb-quantity-sheet"
            role="dialog"
            aria-modal="true"
            aria-labelledby="dtb-quantity-sheet-title"
            onClick={(event) => event.stopPropagation()}
          >
            <div className="dtb-quantity-sheet__handle" aria-hidden="true" />
            <header className="dtb-quantity-sheet__header">
              <div>
                <span className="dtb-quantity-sheet__eyebrow">QUANTITY</span>
                <h2 id="dtb-quantity-sheet-title">Choose quantity</h2>
              </div>
              <button type="button" className="dtb-quantity-sheet__close" onClick={closeQuantitySheet} aria-label="Close quantity selector">
                <X size={18} aria-hidden="true" />
              </button>
            </header>
            <p className="dtb-quantity-sheet__product" title={name}>{name}</p>
            <div className="dtb-quantity-sheet__options" aria-label="Available quantities">
              {Array.from({ length: 10 }, (_, optionIndex) => optionIndex + 1).map((value) => (
                <button
                  key={value}
                  ref={value === quantity ? firstQuantityOptionRef : undefined}
                  type="button"
                  className={`dtb-quantity-sheet__option${quantity === value ? ' dtb-quantity-sheet__option--selected' : ''}`}
                  aria-pressed={quantity === value}
                  onClick={() => { setQuantity(value); closeQuantitySheet(); }}
                >
                  <span>{value}</span>
                  {quantity === value ? <Check size={17} strokeWidth={2.5} aria-hidden="true" /> : null}
                </button>
              ))}
            </div>
          </section>
        </div>,
        document.body,
      )}
    </article>
  );
}
