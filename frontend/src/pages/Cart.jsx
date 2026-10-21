// WooCommerce Store API remains authoritative for cart content, prices, and coupon state.
import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { m as Motion, AnimatePresence, useReducedMotion } from 'framer-motion';
import {
  AlertCircle, ArrowLeft, ArrowRight, Lock, Loader2, Minus, Plus,
  ShoppingBag, ShoppingCart, ShieldAlert, Tag, Trash2,
} from 'lucide-react';

import SEOHead from '../components/shared/SEOHead';
import BackButton from '../components/shared/BackButton';
import { Container } from '../components/layout';
import { useCart } from '../context/CartContext';
import { useAuthContext } from '../auth/AuthContext.js';
import { getWooCheckoutUrl } from '../utils/checkoutUrl.js';
import { beginCheckoutHandoff } from '../utils/checkoutHandoff.js';
import { useEditableComponent } from '../designer/useEditableComponent.js';
import '../styles/cart-page.css';

function parseStoreMoney(value, minorUnit) {
  const rawString = String(value ?? '').trim();
  const raw = typeof value === 'number' ? value : Number.parseFloat(rawString || '0');
  if (!Number.isFinite(raw)) return null;
  const unit = Number(minorUnit);
  if (Number.isFinite(unit) && unit >= 0 && Number.isInteger(raw) && !rawString.includes('.')) {
    return raw / (10 ** unit);
  }
  return raw;
}

function formatMoney(value, currency = 'USD') {
  const amount = Number(value);
  const safeAmount = Number.isFinite(amount) ? amount : 0;
  try {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: /^[A-Z]{3}$/.test(currency) ? currency : 'USD',
    }).format(safeAmount);
  } catch {
    return '$' + safeAmount.toFixed(2);
  }
}

function getCouponCode(coupon) {
  if (typeof coupon === 'string') return coupon;
  return String(coupon?.code || coupon?.name || '').trim();
}

export default function Cart() {
  const reduceMotion = useReducedMotion();
  const navigate = useNavigate();
  const {
    cart, cartItems, isLoading, error, updateQuantity, removeFromCart,
    applyCoupon, removeCoupon, refreshCart, isMutating,
  } = useCart();
  const { isAuthenticated, ensureNativeCheckoutReady } = useAuthContext();
  const [checkoutPending, setCheckoutPending] = useState(false);
  const [checkoutNotice, setCheckoutNotice] = useState('');
  const [couponCode, setCouponCode] = useState('');
  const [couponPending, setCouponPending] = useState(false);
  const [couponMessage, setCouponMessage] = useState('');
  const [couponError, setCouponError] = useState('');

  const itemsList = useEditableComponent('cart', 'cart-items-list');
  const orderSummary = useEditableComponent('cart', 'order-summary');
  const checkoutButton = useEditableComponent('cart', 'proceed-to-checkout');
  const checkoutWidth = checkoutButton.getValue('width', 'full') === 'auto' ? 'auto' : '100%';

  const minorUnit = cart?.totals?.currency_minor_unit;
  const currency = cart?.totals?.currency_code || cart?.currency || 'USD';
  const serverSubtotal = parseStoreMoney(cart?.totals?.total_items, minorUnit);
  const localSubtotal = cartItems.reduce(
    (sum, item) => sum + (Number(item?.price) || 0) * (Number(item?.quantity) || 1), 0
  );
  const subtotal = serverSubtotal ?? localSubtotal;
  const discount = parseStoreMoney(cart?.totals?.total_discount, minorUnit) || 0;
  const estimatedTax = parseStoreMoney(cart?.totals?.total_tax, minorUnit);
  const serverTotal = parseStoreMoney(cart?.totals?.total_price, minorUnit);
  const estimatedTotal = serverTotal ?? (subtotal + (estimatedTax || 0));
  const totalQuantity = cartItems.reduce((sum, item) => sum + (Number(item?.quantity) || 1), 0);
  const appliedCoupons = Array.isArray(cart?.coupons) ? cart.coupons : [];
  const hasUnavailableItems = cartItems.some(
    (item) => item?.raw?.is_in_stock === false || item?.raw?.is_purchasable === false
  );
  const checkoutDisabled = isMutating || checkoutPending || hasUnavailableItems;

  const handleCheckout = async (event) => {
    event.preventDefault();
    if (checkoutDisabled) return;
    setCheckoutPending(true);
    setCheckoutNotice('');
    try {
      await beginCheckoutHandoff({
        isAuthenticated,
        ensureNativeCheckoutReady,
        isCartMutating: () => isMutating,
        settleDelayMs: 0,
        onSessionReconciled: async () => { await refreshCart(); },
      });
    } catch (checkoutError) {
      const message = checkoutError?.message || 'We could not prepare your checkout session. Please try again.';
      if (checkoutError?.code === 'checkout_identity_reconciled') setCheckoutNotice(message);
      else window.alert(message);
    } finally {
      setCheckoutPending(false);
    }
  };

  const handleApplyCoupon = async (event) => {
    event.preventDefault();
    const code = couponCode.trim();
    if (!code || couponPending || isMutating) return;
    setCouponPending(true);
    setCouponMessage('');
    setCouponError('');
    try {
      await applyCoupon(code);
      setCouponCode('');
      setCouponMessage('Coupon applied.');
    } catch (couponApplyError) {
      setCouponError(couponApplyError?.message || 'We could not apply that coupon. Check the code and try again.');
    } finally {
      setCouponPending(false);
    }
  };

  const handleRemoveCoupon = async (code) => {
    if (!code || couponPending || isMutating) return;
    setCouponPending(true);
    setCouponMessage('');
    setCouponError('');
    try {
      await removeCoupon(code);
      setCouponMessage('Coupon removed.');
    } catch (couponRemoveError) {
      setCouponError(couponRemoveError?.message || 'We could not remove that coupon. Please try again.');
    } finally {
      setCouponPending(false);
    }
  };

  const handleQuantityChange = (itemKey, quantity) => {
    void updateQuantity(itemKey, quantity).catch(() => undefined);
  };
  const handleRemoveItem = (itemKey) => {
    void removeFromCart(itemKey).catch(() => undefined);
  };

  if (isLoading && cartItems.length === 0) {
    return (
      <div className="page-wrapper dtb-cart-page" aria-busy="true">
        <SEOHead noindex title="Shopping Cart" />
        <Container width="wide" className="dtb-cart-page__container">
          <div className="dtb-cart-page__heading-skeleton"><span /><span /></div>
          <div className="dtb-cart-sheet dtb-cart-sheet--loading">
            <div className="dtb-cart-sheet__items">
              <span className="dtb-cart-skeleton dtb-cart-skeleton--row" />
              <span className="dtb-cart-skeleton dtb-cart-skeleton--row" />
              <span className="dtb-cart-skeleton dtb-cart-skeleton--row" />
            </div>
            <span className="dtb-cart-skeleton dtb-cart-skeleton--summary" />
          </div>
        </Container>
      </div>
    );
  }

  if (cartItems.length === 0) {
    return (
      <div className="dtb-cart-empty">
        <SEOHead noindex title="Shopping Cart" />
        <div className="dtb-cart-empty__card">
          <div className="dtb-cart-empty__icon"><ShoppingBag aria-hidden="true" strokeWidth={1.5} /></div>
          <h1>Your cart is empty</h1>
          <p>Discover professional drywall tools and equipment for every job.</p>
          <Link to="/products" className="dtb-cart-empty__action">
            <ShoppingBag size={16} aria-hidden="true" />Browse products
          </Link>
        </div>
      </div>
    );
  }

  return (
    <div className="page-wrapper dtb-cart-page">
      <SEOHead noindex title="Shopping Cart" />
      <Container width="wide" className="dtb-cart-page__container">
        <header className="dtb-cart-page__heading">
          <div className="dtb-cart-page__heading-row">
            <Link to="/products" className="dtb-cart-page__mobile-back">
              <ArrowLeft size={15} aria-hidden="true" /><span>Products</span>
            </Link>
            <div className="dtb-cart-page__desktop-back">
              <BackButton onClick={() => navigate('/products')} label="Products" className="dtb-product-nav-back" />
            </div>
            <div className="dtb-cart-page__title-group">
              <h1>Shopping cart</h1>
              <p>{cartItems.length} item{cartItems.length !== 1 ? 's' : ''}</p>
            </div>
          </div>
          <p className="dtb-cart-page__description">
            Review your drywall tools, components, and hardware before proceeding to checkout.
          </p>
        </header>

        {checkoutNotice && (
          <div role="status" className="dtb-cart-page__notice">
            <ShieldAlert aria-hidden="true" />
            <div><p><strong>Checkout session refreshed</strong></p><p>{checkoutNotice}</p></div>
          </div>
        )}
        {error && !couponError && (
          <div role="alert" className="dtb-cart-page__error">
            <AlertCircle aria-hidden="true" /><p>{error}</p>
          </div>
        )}

        <div className="dtb-cart-sheet">
          <section {...itemsList.rootProps} className="dtb-cart-sheet__items" aria-label="Cart items">
            <AnimatePresence mode="popLayout" initial={false}>
              {cartItems.map((item) => {
                const itemKey = item.cartKey || item.key || item.id;
                const quantity = Math.max(1, Number(item.quantity) || 1);
                const unitPrice = Number(item.price) || 0;
                const itemSubtotal = unitPrice * quantity;
                const optionText = Array.isArray(item.variation_attribute_values)
                  ? item.variation_attribute_values.map((attribute) => attribute.option).filter(Boolean).join(' / ')
                  : '';
                const isUnavailable = item?.raw?.is_in_stock === false || item?.raw?.is_purchasable === false;
                const maxQuantity = Number(item?.raw?.quantity_limits?.maximum);
                const productPath = item?.raw?.slug ? '/products/' + encodeURIComponent(item.raw.slug) : null;
                const itemImage = item.image || item?.raw?.images?.[0]?.src || '';

                return (
                  <Motion.article
                    key={itemKey}
                    layout={!reduceMotion}
                    initial={false}
                    animate={{ opacity: 1, y: 0 }}
                    exit={reduceMotion
                      ? { opacity: 1, transition: { duration: 0 } }
                      : { opacity: 0, x: -28, transition: { duration: 0.18 } }}
                    transition={reduceMotion ? { duration: 0 } : { duration: 0.24, ease: [0.22, 1, 0.36, 1] }}
                    className="dtb-cart-row"
                  >
                    <div className="dtb-cart-row__image">
                      {itemImage ? (
                        <img src={itemImage} alt={item?.raw?.images?.[0]?.alt || item.name} loading="lazy" decoding="async" />
                      ) : (
                        <div className="dtb-cart-row__placeholder">
                          <ShoppingCart size={24} aria-hidden="true" strokeWidth={1.5} />
                        </div>
                      )}
                    </div>
                    <div className="dtb-cart-row__content">
                      <div className="dtb-cart-row__heading">
                        <div>
                          {item.brand && <p className="dtb-cart-row__brand">{item.brand}</p>}
                          {productPath
                            ? <Link to={productPath} className="dtb-cart-row__name">{item.name}</Link>
                            : <h2 className="dtb-cart-row__name">{item.name}</h2>}
                          {item.sku && <p className="dtb-cart-row__sku">SKU: {item.sku}</p>}
                          {optionText && <p className="dtb-cart-row__option">{optionText}</p>}
                        </div>
                        <button
                          type="button"
                          onClick={() => handleRemoveItem(itemKey)}
                          disabled={isMutating}
                          className="dtb-cart-row__remove"
                          aria-label={'Remove ' + item.name + ' from cart'}
                        >
                          <Trash2 size={16} aria-hidden="true" /><span>Remove</span>
                        </button>
                      </div>
                      {isUnavailable && (
                        <p className="dtb-cart-row__availability" role="status">
                          Availability needs review before checkout.
                        </p>
                      )}
                      <div className="dtb-cart-row__footer">
                        <div className="dtb-cart-row__quantity" role="group" aria-label={'Quantity for ' + item.name}>
                          <button
                            type="button"
                            onClick={() => handleQuantityChange(itemKey, quantity - 1)}
                            disabled={isMutating || quantity <= 1}
                            aria-label={'Decrease quantity of ' + item.name}
                          >
                            <Minus size={13} aria-hidden="true" strokeWidth={2.5} />
                          </button>
                          <span aria-live="polite">{quantity}</span>
                          <button
                            type="button"
                            onClick={() => handleQuantityChange(itemKey, quantity + 1)}
                            disabled={isMutating || (Number.isFinite(maxQuantity) && maxQuantity > 0 && quantity >= maxQuantity)}
                            aria-label={'Increase quantity of ' + item.name}
                          >
                            <Plus size={13} aria-hidden="true" strokeWidth={2.5} />
                          </button>
                        </div>
                        <div className="dtb-cart-row__price">
                          <strong>{formatMoney(itemSubtotal, currency)}</strong>
                          {quantity > 1 && <span>{formatMoney(unitPrice, currency)} each</span>}
                        </div>
                      </div>
                    </div>
                  </Motion.article>
                );
              })}
            </AnimatePresence>
            <div className="dtb-cart-sheet__continue">
              <Link to="/products" className="dtb-cart-continue">
                <ArrowLeft size={15} aria-hidden="true" />Continue shopping
              </Link>
            </div>
          </section>

          <aside {...orderSummary.rootProps} className="dtb-cart-sheet__summary" aria-labelledby="dtb-cart-summary-title">
            <div className="dtb-cart-sheet__summary-heading">
              <h2 id="dtb-cart-summary-title">Order summary</h2>
              <span>{totalQuantity} item{totalQuantity !== 1 ? 's' : ''}</span>
            </div>

            <form className="dtb-cart-coupon" onSubmit={handleApplyCoupon}>
              <label htmlFor="dtb-cart-coupon-code">Discount code</label>
              {appliedCoupons.length > 0 ? (
                <div className="dtb-cart-coupon__applied">
                  {appliedCoupons.map((coupon, index) => {
                    const code = getCouponCode(coupon);
                    if (!code) return null;
                    return (
                      <div className="dtb-cart-coupon__chip" key={'coupon-' + index}>
                        <span><Tag size={15} aria-hidden="true" />{code}</span>
                        <button
                          type="button"
                          onClick={() => void handleRemoveCoupon(code)}
                          disabled={couponPending || isMutating}
                          aria-label={'Remove coupon ' + code}
                        >
                          Remove
                        </button>
                      </div>
                    );
                  })}
                </div>
              ) : (
                <div className="dtb-cart-coupon__controls">
                  <input
                    id="dtb-cart-coupon-code"
                    type="text"
                    autoComplete="off"
                    value={couponCode}
                    onChange={(event) => setCouponCode(event.target.value)}
                    placeholder="Enter code"
                    disabled={couponPending || isMutating}
                  />
                  <button type="submit" disabled={couponPending || isMutating || !couponCode.trim()}>
                    {couponPending
                      ? <Loader2 size={16} className="dtb-cart-spin" aria-label="Applying coupon" />
                      : 'Apply'}
                  </button>
                </div>
              )}
              {couponMessage && <p className="dtb-cart-coupon__message" role="status">{couponMessage}</p>}
              {couponError && <p className="dtb-cart-coupon__error" role="alert">{couponError}</p>}
            </form>

            <div className="dtb-cart-sheet__summary-lines">
              <div className="dtb-cart-sheet__summary-row">
                <span>Subtotal <small>({totalQuantity} item{totalQuantity !== 1 ? 's' : ''})</small></span>
                <strong>{formatMoney(subtotal, currency)}</strong>
              </div>
              {discount > 0 && (
                <div className="dtb-cart-sheet__summary-row dtb-cart-sheet__summary-row--discount">
                  <span>Discount</span><strong>−{formatMoney(discount, currency)}</strong>
                </div>
              )}
              <div className="dtb-cart-sheet__summary-row">
                <span>Shipping</span>
                <strong className="dtb-cart-sheet__summary-pending">Calculated at checkout</strong>
              </div>
              <div className="dtb-cart-sheet__summary-row">
                <span>Estimated tax</span>
                {estimatedTax !== null
                  ? <strong>{formatMoney(estimatedTax, currency)}</strong>
                  : <strong className="dtb-cart-sheet__summary-pending">Calculated at checkout</strong>}
              </div>
            </div>

            <div className="dtb-cart-sheet__summary-total">
              <span>Estimated total</span><strong>{formatMoney(estimatedTotal, currency)}</strong>
            </div>
            <p className="dtb-cart-sheet__estimate-note">Shipping and final tax are confirmed at checkout.</p>

            <a
              {...checkoutButton.rootProps}
              href={getWooCheckoutUrl()}
              onClick={handleCheckout}
              aria-disabled={checkoutDisabled ? 'true' : undefined}
              aria-busy={checkoutPending ? 'true' : undefined}
              style={{ width: checkoutWidth }}
              className="dtb-cart-sheet__checkout"
            >
              <Lock size={15} aria-hidden="true" strokeWidth={2.5} />
              <span>{checkoutPending ? 'Preparing checkout…' : isMutating ? 'Updating cart…' : 'Proceed to checkout'}</span>
              {checkoutPending
                ? <Loader2 size={16} className="dtb-cart-spin" aria-hidden="true" />
                : <ArrowRight size={16} aria-hidden="true" strokeWidth={2.5} />}
            </a>
            {hasUnavailableItems && (
              <p className="dtb-cart-sheet__checkout-note">
                Review unavailable items before continuing to checkout.
              </p>
            )}
          </aside>
        </div>
      </Container>
    </div>
  );
}
