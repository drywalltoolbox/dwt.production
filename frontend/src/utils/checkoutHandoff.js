import { getCart } from '../api/cart.js';
import { getWooCheckoutUrl } from './checkoutUrl.js';
import { navigateDocument } from './documentNavigation.js';

const DEFAULT_SETTLE_DELAY_MS = 350;
const DEFAULT_MUTATION_WAIT_MS = 8000;
const POLL_INTERVAL_MS = 75;

let activeHandoffPromise = null;

export class CheckoutHandoffError extends Error {
  constructor(code, message, cause = null, context = null) {
    super(message);
    this.name = 'CheckoutHandoffError';
    this.code = code;
    this.cause = cause;
    this.context = context;
  }
}

function sleep(milliseconds) {
  return new Promise((resolve) => window.setTimeout(resolve, milliseconds));
}

function normalizeBooleanReader(reader) {
  return typeof reader === 'function' ? reader : () => Boolean(reader);
}

async function waitForCartMutations({ isCartMutating, settleDelayMs, mutationWaitMs }) {
  const readMutating = normalizeBooleanReader(isCartMutating);

  if (settleDelayMs > 0) await sleep(settleDelayMs);

  const startedAt = Date.now();
  while (readMutating()) {
    if (Date.now() - startedAt >= mutationWaitMs) {
      throw new CheckoutHandoffError(
        'cart_mutation_timeout',
        'Your cart is still updating. Please wait a moment and try checkout again.'
      );
    }
    await sleep(POLL_INTERVAL_MS);
  }
}

function validateAuthoritativeCart(cart) {
  if (!Array.isArray(cart?.items) || cart.items.length === 0) {
    throw new CheckoutHandoffError(
      'cart_not_confirmed',
      'Your checkout cart could not be confirmed. Please refresh your cart and try again.'
    );
  }

  if (Array.isArray(cart?.errors) && cart.errors.length > 0) {
    const firstMessage = String(cart.errors[0]?.message || '').trim();
    throw new CheckoutHandoffError(
      'cart_validation_failed',
      firstMessage || 'Your cart needs attention before checkout. Please review it and try again.'
    );
  }

  return cart;
}

async function executeCheckoutHandoff({
  isAuthenticated = false,
  ensureNativeCheckoutReady,
  isCartMutating = false,
  settleDelayMs = DEFAULT_SETTLE_DELAY_MS,
  mutationWaitMs = DEFAULT_MUTATION_WAIT_MS,
  onSessionReconciled,
  onBeforeNavigate,
} = {}) {
  if (typeof window === 'undefined') {
    throw new CheckoutHandoffError('browser_required', 'Checkout is only available in the browser.');
  }

  await waitForCartMutations({
    isCartMutating,
    settleDelayMs: Math.max(0, Number(settleDelayMs) || 0),
    mutationWaitMs: Math.max(1000, Number(mutationWaitMs) || DEFAULT_MUTATION_WAIT_MS),
  });

  if (isAuthenticated) {
    if (typeof ensureNativeCheckoutReady !== 'function') {
      throw new CheckoutHandoffError(
        'identity_bridge_unavailable',
        'We could not prepare your signed-in checkout session. Please try again.'
      );
    }

    try {
      await ensureNativeCheckoutReady();
    } catch (error) {
      if (error?.code === 'checkout_identity_reconciled') {
        const refreshedCart = await getCart();
        if (typeof onSessionReconciled === 'function') {
          await onSessionReconciled({ cart: refreshedCart, checkoutSession: error.checkoutSession || null });
        }
        throw new CheckoutHandoffError(
          'checkout_identity_reconciled',
          error.message,
          error,
          { cart: refreshedCart, checkoutSession: error.checkoutSession || null }
        );
      }
      throw error;
    }
  }

  const authoritativeCart = validateAuthoritativeCart(await getCart());
  const checkoutUrl = getWooCheckoutUrl();

  if (typeof onBeforeNavigate === 'function') {
    await onBeforeNavigate({ cart: authoritativeCart, checkoutUrl });
  }

  navigateDocument(checkoutUrl, { transition: 'checkout' });
  return { cart: authoritativeCart, checkoutUrl };
}

/**
 * Runs the one supported React -> native WooCommerce checkout transfer.
 * Concurrent calls share one in-flight promise so rapid taps cannot start
 * competing identity/cart checks or document navigations.
 */
export function beginCheckoutHandoff(options = {}) {
  if (activeHandoffPromise) return activeHandoffPromise;

  activeHandoffPromise = executeCheckoutHandoff(options)
    .catch((error) => {
      if (error instanceof CheckoutHandoffError) throw error;
      throw new CheckoutHandoffError(
        error?.code || 'checkout_handoff_failed',
        error?.message || 'We could not prepare your checkout session. Please try again.',
        error
      );
    })
    .finally(() => {
      activeHandoffPromise = null;
    });

  return activeHandoffPromise;
}

export function isCheckoutHandoffTarget(value) {
  if (!value || typeof window === 'undefined') return false;

  try {
    const url = new URL(value, window.location.origin);
    return /\/checkout\/?$/i.test(url.pathname);
  } catch {
    return false;
  }
}
