import { useEffect, useRef } from 'react';
import { useCart } from '../../context/CartContext';
import { useAuthContext } from '../../auth/AuthContext.js';
import StorefrontCartSheet from '../storefront/StorefrontCartSheet';
import { beginCheckoutHandoff, isCheckoutHandoffTarget } from '../../utils/checkoutHandoff.js';

const CART_DEBOUNCE_DRAIN_MS = 350;

export default function CartSidebar({ isOpen, onClose }) {
  const { cart, cartItems, removeFromCart, updateQuantity, applyCoupon, removeCoupon, clearCart, refreshCart, getCartTotal, isMutating } = useCart();
  const { isAuthenticated, ensureNativeCheckoutReady } = useAuthContext();
  const isMutatingRef = useRef(isMutating);
  const checkoutPendingRef = useRef(false);

  useEffect(() => {
    isMutatingRef.current = isMutating;
  }, [isMutating]);

  useEffect(() => {
    if (!isOpen) return undefined;

    const interceptCheckout = async (event) => {
      const anchor = event.target instanceof Element ? event.target.closest('a[href]') : null;
      if (!anchor || !anchor.closest('.storefront-cart-sheet')) return;
      if (!isCheckoutHandoffTarget(anchor.href)) return;

      event.preventDefault();
      event.stopPropagation();

      if (checkoutPendingRef.current) return;
      checkoutPendingRef.current = true;
      anchor.setAttribute('aria-busy', 'true');
      anchor.setAttribute('aria-disabled', 'true');

      try {
        await beginCheckoutHandoff({
          isAuthenticated,
          ensureNativeCheckoutReady,
          isCartMutating: () => isMutatingRef.current,
          settleDelayMs: CART_DEBOUNCE_DRAIN_MS,
          onSessionReconciled: async () => {
            await refreshCart();
          },
          onBeforeNavigate: () => {
            // This capture-phase handler prevents the cart sheet's link handler
            // from running, so it must release focus before closing the sheet.
            // Otherwise React applies aria-hidden/inert while the checkout link
            // is still focused and Chromium blocks the accessibility-tree change.
            if (anchor.contains(document.activeElement)) {
              document.activeElement?.blur?.();
            }
            onClose?.();
          },
        });
      } catch (error) {
        window.alert(error?.message || 'We could not prepare your checkout session. Please try again.');
        anchor.focus?.();
      } finally {
        checkoutPendingRef.current = false;
        anchor.removeAttribute('aria-busy');
        anchor.removeAttribute('aria-disabled');
      }
    };

    document.addEventListener('click', interceptCheckout, true);
    return () => document.removeEventListener('click', interceptCheckout, true);
  }, [ensureNativeCheckoutReady, isAuthenticated, isOpen, onClose, refreshCart]);

  return (
    <StorefrontCartSheet
      isOpen={isOpen}
      onClose={onClose}
      cart={cart}
      cartItems={cartItems}
      applyCoupon={applyCoupon}
      removeCoupon={removeCoupon}
      removeFromCart={removeFromCart}
      updateQuantity={updateQuantity}
      clearCart={clearCart}
      getCartTotal={getCartTotal}
      isMutating={isMutating}
    />
  );
}
