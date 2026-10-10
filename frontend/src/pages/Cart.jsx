// Cart checkout remains WooCommerce-authoritative.
import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { m as Motion, AnimatePresence, useReducedMotion } from 'framer-motion';
import { dtbTransition } from '../motion/dtbMotion.js';
import { ShoppingBag, Trash2, Plus, Minus, ArrowRight, ArrowLeft, Lock, ShoppingCart, ShieldAlert } from 'lucide-react';

import SEOHead from '../components/shared/SEOHead';
import BackButton from '../components/shared/BackButton';
import ExpressCheckoutMethods from '../components/checkout/ExpressCheckoutMethods.jsx';
import { Container } from '../components/layout';
import { useCart } from '../context/CartContext';
import { useAuthContext } from '../auth/AuthContext.js';
import { getWooCheckoutUrl } from '../utils/checkoutUrl.js';
import { beginCheckoutHandoff } from '../utils/checkoutHandoff.js';
import { useEditableComponent } from '../designer/useEditableComponent.js';
import useCheckoutReadiness from '../hooks/useCheckoutReadiness.js';
import '../styles/cart-page.css';

function parseStoreMoney(value, minorUnit) {
  const raw = Number(value);
  const unit = Number(minorUnit);
  if (!Number.isFinite(raw)) return null;
  return Number.isFinite(unit) && unit >= 0 ? raw / (10 ** unit) : raw;
}

function formatMoney(value) {
  return (Number(value) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export default function Cart() {
  const reduceMotion = useReducedMotion();
  const navigate = useNavigate();
  const { cart, cartItems, updateQuantity, removeFromCart, refreshCart, isMutating } = useCart();
  const { isAuthenticated, ensureNativeCheckoutReady } = useAuthContext();
  const [checkoutPending, setCheckoutPending] = useState(false);
  const [checkoutNotice, setCheckoutNotice] = useState('');
  const checkoutReadiness = useCheckoutReadiness();

  const itemsList = useEditableComponent('cart', 'cart-items-list');
  const orderSummary = useEditableComponent('cart', 'order-summary');
  const checkoutButton = useEditableComponent('cart', 'proceed-to-checkout');
  const checkoutWidth = checkoutButton.getValue('width', 'full') === 'auto' ? 'auto' : '100%';

  const localSubtotal = cartItems.reduce((sum, item) => sum + (Number(item?.price) || 0) * (Number(item?.quantity) || 1), 0);
  const serverSubtotal = parseStoreMoney(cart?.totals?.total_items, cart?.totals?.currency_minor_unit);
  const subtotal = serverSubtotal ?? localSubtotal;
  const totalQuantity = cartItems.reduce((sum, item) => sum + (Number(item?.quantity) || 1), 0);
  // WooCommerce's Store API returns the store's actual configured tax (nexus,
  // product tax class, rate table) here — this is the same engine checkout
  // uses, so it's authoritative rather than a client-guessed rate.
  const estimatedTax = parseStoreMoney(cart?.totals?.total_tax, cart?.totals?.currency_minor_unit);
  const estimatedTotal = subtotal + (estimatedTax || 0);
  const checkoutDisabled = isMutating || checkoutPending;

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
        onSessionReconciled: async () => {
          await refreshCart();
        },
      });
    } catch (error) {
      const message = error?.message || 'We could not prepare your checkout session. Please try again.';
      if (error?.code === 'checkout_identity_reconciled') setCheckoutNotice(message);
      else window.alert(message);
    } finally {
      setCheckoutPending(false);
    }
  };

  if (cartItems.length === 0) {
    return (
      <div className="dtb-cart-empty">
        <SEOHead noindex title="Shopping Cart" />
        <div className="dtb-cart-empty__card">
          <div className="dtb-cart-empty__icon"><ShoppingBag aria-hidden="true" strokeWidth={1.5} /></div>
          <h1>Your cart is empty</h1>
          <p>Discover professional drywall tools and equipment for every job.</p>
          <Link to="/products" className="dtb-cart-empty__action"><ShoppingBag size={16} aria-hidden="true" />Browse products</Link>
        </div>
      </div>
    );
  }

  return (
    <div className="page-wrapper dtb-cart-page">
      <SEOHead noindex title="Shopping Cart" />
      <Container width="wide" className="dtb-cart-page__container">
        <header className="dtb-listing-heading dtb-listing-heading--standard">
          <button type="button" onClick={() => navigate('/products')} className="dtb-listing-heading__back-pill sm:hidden">
            <ArrowLeft size={14} aria-hidden="true" />
            <span>Products</span>
          </button>
          <div className="dtb-listing-heading__title-row hidden sm:flex">
            <div className="dtb-listing-heading__nav-col">
              <BackButton onClick={() => navigate('/products')} label="Products" className="dtb-product-nav-back" />
            </div>
            <div className="dtb-listing-heading__title-group">
              <h1 className="dtb-listing-heading__title">Shopping cart</h1>
              <p className="dtb-listing-heading__meta">{cartItems.length} item{cartItems.length !== 1 ? 's' : ''}</p>
            </div>
          </div>
        </header>

        {checkoutNotice && (
          <div role="alert" className="dtb-cart-page__notice">
            <ShieldAlert aria-hidden="true" />
            <div><p><strong>Checkout session refreshed</strong></p><p>{checkoutNotice}</p></div>
          </div>
        )}

        <div className="dtb-cart-sheet">
          <div {...itemsList.rootProps} className="dtb-cart-sheet__items">
            <AnimatePresence mode="popLayout" initial={false}>
              {cartItems.map((item) => {
                const itemKey = item.cartKey || item.id;
                const quantity = Number(item.quantity) || 1;
                const unitPrice = Number(item.price) || 0;
                const optionText = Array.isArray(item.variation_attribute_values) ? item.variation_attribute_values.map((attribute) => attribute.option).filter(Boolean).join(' / ') : '';
                return (
                  <Motion.article key={itemKey} layout={!reduceMotion} initial={false} animate={{ opacity: 1, y: 0 }} exit={reduceMotion ? { opacity: 1, transition: { duration: 0 } } : { opacity: 0, x: -28, scale: 0.97, transition: dtbTransition.exit }} transition={reduceMotion ? { duration: 0 } : dtbTransition.standard} className="dtb-cart-row">
                    <div className="dtb-cart-row__image">{item.image ? <img src={item.image} alt={item.name} loading="lazy" decoding="async" /> : <div className="dtb-cart-row__placeholder"><ShoppingCart size={24} aria-hidden="true" strokeWidth={1.5} /></div>}</div>
                    <div className="dtb-cart-row__content">
                      <div className="dtb-cart-row__heading"><div>{item.brand && <p className="dtb-cart-row__brand">{item.brand}</p>}<h2>{item.name}</h2>{item.sku && <p className="dtb-cart-row__sku">SKU: {item.sku}</p>}{optionText && <p className="dtb-cart-row__option">{optionText}</p>}</div><button type="button" onClick={() => removeFromCart(itemKey)} disabled={isMutating} className="dtb-cart-row__remove" aria-label={`Remove ${item.name}`}><Trash2 size={14} aria-hidden="true" /></button></div>
                      <div className="dtb-cart-row__footer"><div className="dtb-cart-row__quantity" role="group" aria-label={`Quantity for ${item.name}`}><button type="button" onClick={() => updateQuantity(itemKey, quantity - 1)} disabled={isMutating} aria-label="Decrease quantity"><Minus size={12} aria-hidden="true" strokeWidth={2.5} /></button><span>{quantity}</span><button type="button" onClick={() => updateQuantity(itemKey, quantity + 1)} disabled={isMutating} aria-label="Increase quantity"><Plus size={12} aria-hidden="true" strokeWidth={2.5} /></button></div><div className="dtb-cart-row__price"><strong>${formatMoney(unitPrice * quantity)}</strong></div></div>
                    </div>
                  </Motion.article>
                );
              })}
            </AnimatePresence>
          </div>

          <div {...orderSummary.rootProps} className="dtb-cart-sheet__summary">
            <h2>Order summary</h2>

            <div className="dtb-cart-sheet__summary-lines">
              <div className="dtb-cart-sheet__summary-row">
                <span>Subtotal <small>({totalQuantity} item{totalQuantity !== 1 ? 's' : ''})</small></span>
                <strong>${formatMoney(subtotal)}</strong>
              </div>
              <div className="dtb-cart-sheet__summary-row">
                <span>Shipping</span>
                <strong className="dtb-cart-sheet__summary-pending">Calculated at checkout</strong>
              </div>
              <div className="dtb-cart-sheet__summary-row">
                <span>Tax</span>
                {estimatedTax !== null
                  ? <strong>${formatMoney(estimatedTax)}</strong>
                  : <strong className="dtb-cart-sheet__summary-pending">Calculated at checkout</strong>}
              </div>
            </div>

            <div className="dtb-cart-sheet__summary-total">
              <span>Estimated total</span>
              <strong>${formatMoney(estimatedTotal)}</strong>
            </div>

            <a {...checkoutButton.rootProps} href={getWooCheckoutUrl()} onClick={handleCheckout} aria-disabled={checkoutDisabled ? 'true' : undefined} aria-busy={checkoutPending ? 'true' : undefined} style={{ width: checkoutWidth }} className="dtb-cart-sheet__checkout"><Lock size={14} aria-hidden="true" strokeWidth={2.5} />{checkoutPending ? 'Preparing checkout…' : isMutating ? 'Updating cart…' : checkoutNotice ? 'Confirm refreshed cart and checkout' : 'Continue to secure checkout'}<ArrowRight size={14} aria-hidden="true" strokeWidth={2.5} /></a>

            <ExpressCheckoutMethods
              paymentMethods={checkoutReadiness.paymentMethods}
              className="dtb-cart-sheet__express-checkout"
            />
          </div>
        </div>
      </Container>
    </div>
  );
}
