import { Link } from 'react-router-dom';
import { ShoppingCart, X, Package, Minus, Plus, ArrowRight, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

const CART_QTY_SYNC_DELAY_MS = 260;
const MAX_CART_QUANTITY = 99;
const FREE_SHIPPING_THRESHOLD = 75;
const CHECKOUT_HREF = `${ ( process.env.PUBLIC_URL || '' ).replace( /\/+$/, '' ) }/checkout`;

function getCartItemKey(item) {
  return String(item?.cartKey || item?.key || item?.id || '');
}

function getDisplayQuantity(item, localQuantities) {
  const key = getCartItemKey(item);
  const localQty = Number(localQuantities[key]);
  const itemQty = Number(item?.quantity);
  if (Number.isFinite(localQty) && localQty >= 1) return localQty;
  if (Number.isFinite(itemQty) && itemQty >= 1) return itemQty;
  return 1;
}

function getCartItemSku(item) {
  return String(item?.sku || item?.part_number || item?.raw?.sku || '').trim();
}

function getProductDetailHref(item) {
  // The Store API cart item points at the parent product. Preserve that URL
  // when present so variable-product line items navigate to their parent PDP.
  const raw = item?.raw || {};
  const permalink = String(raw.permalink || '').trim();
  let slug = String(raw.slug || item?.slug || '').trim();
  if (!slug && permalink) {
    try {
      const url = new URL(permalink, window.location.origin);
      const segments = url.pathname.split('/').filter(Boolean);
      slug = segments[segments.length - 1] || '';
    } catch { /* Use the existing product lookup route below. */ }
  }
  if (slug) {
    const variationId = Number(item?.variation_id || 0);
    return `/products/${encodeURIComponent(slug)}${variationId > 0 ? `?variant=${variationId}` : ''}`;
  }
  // Existing legacy route resolves SKUs and numeric IDs against WooCommerce.
  const fallbackKey = item?.parent_id || item?.sku || item?.part_number || item?.id;
  return fallbackKey ? `/product/${encodeURIComponent(String(fallbackKey))}` : '/products';
}

export default function StorefrontCartSheet({
  isOpen,
  onClose,
  cartItems = [],
  removeFromCart,
  updateQuantity,
  clearCart,
  isMutating = false,
}) {
  const overlayRef = useRef(null);
  const closeButtonRef = useRef(null);
  const previouslyFocusedRef = useRef(null);
  const syncTimersRef = useRef(new Map());
  const syncingKeysRef = useRef(new Set());
  const localQuantitiesRef = useRef({});

  const [removingKey, setRemovingKey] = useState(null);
  const [isClearing, setIsClearing] = useState(false);
  const [syncingKeys, setSyncingKeys] = useState(() => new Set());
  const [localQuantities, setLocalQuantities] = useState({});
  const setItemSyncing = useCallback((key, isSyncing) => {
    if (!key) return;
    const next = new Set(syncingKeysRef.current);
    if (isSyncing) next.add(key);
    else next.delete(key);
    syncingKeysRef.current = next;
    setSyncingKeys(next);
  }, []);

  const clearQuantityTimer = useCallback((key) => {
    const timer = syncTimersRef.current.get(key);
    if (timer) {
      window.clearTimeout(timer);
      syncTimersRef.current.delete(key);
    }
  }, []);

  const handleClose = useCallback(() => {
    if (overlayRef.current?.contains(document.activeElement)) {
      document.activeElement?.blur?.();
    }
    onClose?.();
  }, [onClose]);

  const handleNavAndClose = useCallback((event) => {
    event.currentTarget?.blur?.();
    handleClose();
  }, [handleClose]);

  const handleRemove = useCallback(async (key) => {
    if (!key || removingKey === key || isClearing) return;
    clearQuantityTimer(key);
    setItemSyncing(key, false);
    setRemovingKey(key);

    try {
      await removeFromCart?.(key);
    } finally {
      setRemovingKey((current) => (current === key ? null : current));
    }
  }, [clearQuantityTimer, isClearing, removeFromCart, removingKey, setItemSyncing]);

  const handleClearCart = useCallback(async () => {
    if (cartItems.length === 0 || isClearing || isMutating) return;

    syncTimersRef.current.forEach((timer) => window.clearTimeout(timer));
    syncTimersRef.current.clear();
    syncingKeysRef.current = new Set();
    setSyncingKeys(new Set());
    setIsClearing(true);

    try {
      await clearCart?.();
    } catch {
      // CartContext restores the optimistic snapshot and exposes the error.
    } finally {
      setIsClearing(false);
    }
  }, [cartItems.length, clearCart, isClearing, isMutating]);

  const scheduleQuantitySync = useCallback((key, quantity) => {
    clearQuantityTimer(key);
    setItemSyncing(key, true);

    const timer = window.setTimeout(async () => {
      syncTimersRef.current.delete(key);
      try {
        await updateQuantity?.(key, quantity);
      } finally {
        setItemSyncing(key, false);
      }
    }, CART_QTY_SYNC_DELAY_MS);

    syncTimersRef.current.set(key, timer);
  }, [clearQuantityTimer, setItemSyncing, updateQuantity]);

  const handleQtyChange = useCallback((key, delta, currentQty) => {
    if (!key || removingKey === key || isClearing) return;

    const baseQty = Number(localQuantitiesRef.current[key] ?? currentQty);
    const nextQuantity = (Number.isFinite(baseQty) ? baseQty : 1) + Number(delta || 0);

    if (nextQuantity < 1) {
      handleRemove(key);
      return;
    }

    if (nextQuantity > MAX_CART_QUANTITY) return;

    setLocalQuantities((current) => {
      const next = { ...current, [key]: nextQuantity };
      localQuantitiesRef.current = next;
      return next;
    });

    scheduleQuantitySync(key, nextQuantity);
  }, [handleRemove, isClearing, removingKey, scheduleQuantitySync]);

  useEffect(() => {
    let cancelled = false;
    const next = {};
    const activeSyncKeys = syncingKeysRef.current;

    for (const item of cartItems) {
      const key = getCartItemKey(item);
      if (!key) continue;
      const localQty = localQuantitiesRef.current[key];
      next[key] = activeSyncKeys.has(key) && Number.isFinite(Number(localQty))
        ? Number(localQty)
        : item.quantity;
    }

    localQuantitiesRef.current = next;
    window.queueMicrotask(() => {
      if (!cancelled) setLocalQuantities(next);
    });
    return () => { cancelled = true; };
  }, [cartItems]);

  useEffect(() => () => {
    syncTimersRef.current.forEach((timer) => window.clearTimeout(timer));
    syncTimersRef.current.clear();
  }, []);

  useEffect(() => {
    if (isOpen) {
      previouslyFocusedRef.current = document.activeElement;
      closeButtonRef.current?.focus?.();
      return;
    }

    let cancelled = false;
    window.queueMicrotask(() => {
      if (cancelled) return;
      previouslyFocusedRef.current?.focus?.();
    });
    return () => { cancelled = true; };
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen) return undefined;
    const onKeyDown = (event) => {
      if (event.key === 'Escape') {
        handleClose();
        return;
      }
      if (event.key !== 'Tab' || !overlayRef.current) return;

      const focusable = Array.from(overlayRef.current.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
      )).filter((element) => element.getClientRects().length > 0);
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
    const prevDocumentOverflow = document.documentElement.style.overflow;
    const prevBodyOverflow = document.body.style.overflow;
    document.documentElement.style.overflow = 'hidden';
    document.body.style.overflow = 'hidden';
    window.addEventListener('keydown', onKeyDown);
    return () => {
      document.documentElement.style.overflow = prevDocumentOverflow;
      document.body.style.overflow = prevBodyOverflow;
      window.removeEventListener('keydown', onKeyDown);
    };
  }, [isOpen, handleClose]);

  const subtotal = useMemo(
    () => cartItems.reduce((total, item) => total + (Number(item.price) || 0) * getDisplayQuantity(item, localQuantities), 0),
    [cartItems, localQuantities]
  );

  const shippingRemaining = Math.max(0, FREE_SHIPPING_THRESHOLD - subtotal);
  const shippingProgress = Math.min(100, Math.max(0, (subtotal / FREE_SHIPPING_THRESHOLD) * 100));

  const itemCount = useMemo(
    () => cartItems.reduce((count, item) => count + getDisplayQuantity(item, localQuantities), 0),
    [cartItems, localQuantities]
  );

  return (
    <div
      ref={overlayRef}
      className={`cart-overlay${isOpen ? ' active' : ''}`}
      onClick={handleClose}
      aria-hidden={!isOpen}
      inert={!isOpen ? true : undefined}
    >
      <aside
        className="cart-panel dtb-side-sheet__panel storefront-cart-sheet storefront-cart-sheet--premium"
        onClick={(event) => event.stopPropagation()}
        role="dialog"
        aria-modal={isOpen ? 'true' : 'false'}
        aria-label="Shopping cart"
      >
        <header className="scs-header">
          <div className="scs-header-left">
            <div className="scs-header-icon">
              <ShoppingCart size={15} strokeWidth={2.3} />
            </div>
            <h3 className="scs-title">Your Toolbox</h3>
            {itemCount > 0 && (
              <span className="scs-count">{itemCount}</span>
            )}
          </div>
          <div className="scs-header-actions">
            {cartItems.length > 0 ? (
              <button
                type="button"
                className="scs-clear-cart"
                onClick={handleClearCart}
                disabled={isClearing || isMutating}
                aria-label="Clear all items from cart"
                aria-busy={isClearing ? 'true' : 'false'}
              >
                <Trash2 size={14} strokeWidth={2.2} aria-hidden="true" />
                <span>{isClearing ? 'Clearing' : 'Clear cart'}</span>
              </button>
            ) : null}
            <button ref={closeButtonRef} type="button" onClick={handleClose} aria-label="Close cart" className="scs-close">
              <X size={17} strokeWidth={2.5} />
            </button>
          </div>
        </header>

        {cartItems.length > 0 && (
          <div className="scs-shipping-progress">
            <p className="scs-shipping-progress__message" aria-live="polite">
              {shippingRemaining > 0
                ? <><strong>${shippingRemaining.toFixed(2)}</strong> away from free shipping</>
                : <strong>You've reached the $75 free-shipping threshold</strong>}
            </p>
            <div
              className="scs-shipping-progress__track"
              role="progressbar"
              aria-label="Progress toward $75 free shipping"
              aria-valuemin={0}
              aria-valuemax={FREE_SHIPPING_THRESHOLD}
              aria-valuenow={Math.min(FREE_SHIPPING_THRESHOLD, Math.max(0, Number(subtotal.toFixed(2))))}
            >
              <span className="scs-shipping-progress__fill" style={{ width: `${shippingProgress}%` }} />
            </div>
          </div>
        )}

        <div className="scs-body">
          {cartItems.length === 0 ? (
            <div className="scs-empty">
              <div className="scs-empty-icon">
                <Package size={30} strokeWidth={1.3} />
              </div>
              <strong className="scs-empty-title">Your cart is empty</strong>
              <p className="scs-empty-body">Add products to get started.</p>
              <Link to="/products" onClick={handleNavAndClose} className="scs-browse-btn">
                Browse Products
              </Link>
            </div>
          ) : (
            <ul className="scs-item-list" role="list">
              {cartItems.map((item) => {
                const key = getCartItemKey(item);
                const quantity = getDisplayQuantity(item, localQuantities);
                const isSyncing = syncingKeys.has(key);
                const isRemoving = removingKey === key;
                const productHref = getProductDetailHref(item);
                const skuText = getCartItemSku(item);
                const lineTotal = ((Number(item.price) || 0) * quantity).toFixed(2);

                return (
                  <li
                    key={key}
                    className={`scs-item${isSyncing ? ' scs-item--syncing' : ''}${isRemoving ? ' scs-item--removing' : ''}`}
                    role="listitem"
                    aria-busy={isSyncing || isRemoving || isClearing ? 'true' : 'false'}
                  >
                    <Link
                      to={productHref}
                      className="scs-item-img scs-item-open-target"
                      onClick={handleNavAndClose}
                      aria-label={`View ${item.name} details`}
                    >
                      {item.image
                        ? <img src={item.image} alt="" loading="lazy" decoding="async" />
                        : <Package size={22} strokeWidth={1.4} className="scs-item-img-placeholder" />}
                    </Link>

                    <div className="scs-item-body">
                      <div className="scs-item-top">
                        <Link
                          to={productHref}
                          className="scs-item-name scs-item-name-button"
                          onClick={handleNavAndClose}
                          aria-label={`View ${item.name} details`}
                        >
                          {item.name}
                        </Link>
                        <button
                          type="button"
                          onClick={() => handleRemove(key)}
                          aria-label={`Remove ${item.name}`}
                          className="scs-item-remove"
                          disabled={isRemoving || isClearing}
                        >
                          <X size={13} strokeWidth={2.5} />
                        </button>
                      </div>

                      {skuText ? <span className="scs-item-sku">SKU: {skuText}</span> : null}

                      <div className="scs-item-bottom">
                        <div className="scs-item-qty-row" role="group" aria-label={`Quantity for ${item.name}`}>
                          <button
                            type="button"
                            className="scs-qty-btn"
                            onClick={() => handleQtyChange(key, -1, quantity)}
                            aria-label={quantity === 1 ? `Remove ${item.name}` : 'Decrease quantity'}
                            disabled={isRemoving || isClearing}
                          >
                            {quantity === 1
                              ? <Trash2 size={11} strokeWidth={2.2} />
                              : <Minus size={11} strokeWidth={2.5} />}
                          </button>
                          <span className="scs-qty-display" aria-live="polite">{quantity}</span>
                          <button
                            type="button"
                            className="scs-qty-btn"
                            onClick={() => handleQtyChange(key, 1, quantity)}
                            aria-label="Increase quantity"
                            disabled={isRemoving || isClearing || quantity >= MAX_CART_QUANTITY}
                          >
                            <Plus size={11} strokeWidth={2.5} />
                          </button>
                        </div>

                        <span className="scs-item-line-total">
                          {isSyncing ? <span className="scs-sync-dot" aria-hidden="true" /> : null}
                          ${lineTotal}
                        </span>
                      </div>
                    </div>
                  </li>
                );
              })}
            </ul>
          )}
        </div>

        {cartItems.length > 0 && (
          <footer className="scs-footer">
            <div className="scs-subtotal-row">
              <div className="scs-subtotal-info">
                <span className="scs-subtotal-label">Subtotal</span>
                <span className="scs-subtotal-hint">Shipping &amp; taxes calculated at checkout</span>
              </div>
              <strong className="scs-subtotal-amount">
                ${subtotal.toFixed(2)}
              </strong>
            </div>
            <a
              href={CHECKOUT_HREF}
              onClick={handleNavAndClose}
              className="scs-checkout-btn"
            >
              <span>Checkout</span>
              <ArrowRight size={16} strokeWidth={2.2} />
            </a>
            <Link
              to="/cart"
              onClick={handleNavAndClose}
              className="scs-view-cart-btn"
            >
              View full cart
            </Link>
          </footer>
        )}
      </aside>

    </div>
  );
}
