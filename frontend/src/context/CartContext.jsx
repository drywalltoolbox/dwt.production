import { createContext, useContext, useState, useEffect, useCallback, useMemo, useRef } from 'react';
import {
  initCart,
  getCart,
  addToCart as storeAddToCart,
  updateCartItem,
  removeCartItem,
  applyCoupon as applyStoreCoupon,
  removeCoupon as removeStoreCoupon,
  clearStoreCart,
} from '../api/cart.js';
import { trackAddToCart, trackRemoveFromCart } from '../analytics/ecommerceEvents.js';
import { decodeHtmlEntities } from '../utils/string.js';
import CartInteractionFeedback from '../components/cart/CartInteractionFeedback.jsx';

const CART_SNAPSHOT_KEY = 'drywall-cart-snapshot';
const CartContext = createContext();

export function useCart() {
  const context = useContext(CartContext);
  if (!context) {
    throw new Error('useCart must be used within a CartProvider');
  }
  return context;
}

function parsePriceFromStoreApi(value, minorUnit = null) {
  const rawString = String(value ?? '').trim();
  const raw = typeof value === 'number' ? value : parseFloat(rawString || '0');
  if (!Number.isFinite(raw)) return 0;

  const parsedMinor = Number(minorUnit);
  const hasMinorUnit = Number.isFinite(parsedMinor) && parsedMinor >= 0;
  const hasDecimalPoint = rawString.includes('.');

  // Woo Store API price fields are typically integer minor units with a
  // companion currency_minor_unit. Example: "100" + minor_unit=2 => $1.00.
  if (hasMinorUnit && Number.isInteger(raw) && !hasDecimalPoint) {
    return raw / (10 ** parsedMinor);
  }

  // Fallback for payloads that omit minor-unit metadata.
  return raw > 999 ? raw / 100 : raw;
}

function getStoreItemImage(item) {
  const image = Array.isArray(item?.images) ? item.images[0] : null;
  return image?.thumbnail || image?.src || item?.image || '';
}

function normalizeAttributeName(value) {
  const decoded = decodeHtmlEntities(value || '').trim();
  return decoded
    .replace(/^attribute_/i, '')
    .replace(/^pa[_-]/i, '')
    .replace(/[_-]+/g, ' ')
    .replace(/\s+/g, ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase());
}

function normalizeAttributeOption(value) {
  return decodeHtmlEntities(value || '')
    .replace(/\s+/g, ' ')
    .trim();
}

function normalizeVariationAttributes(attributes) {
  if (!Array.isArray(attributes)) return [];

  return attributes
    .map((attr) => {
      const name = normalizeAttributeName(attr?.name || attr?.attribute || attr?.slug || '');
      const option = normalizeAttributeOption(attr?.option || attr?.value || '');
      if (!option) return null;
      return { ...attr, name, option };
    })
    .filter(Boolean);
}

function extractVariationOptionSuffix(attributes) {
  const normalized = normalizeVariationAttributes(attributes);
  if (normalized.length === 0) return '';
  return normalized.map((attr) => attr.option).filter(Boolean).join(' / ');
}

function buildVariationDetailText(attributes) {
  const normalized = normalizeVariationAttributes(attributes);
  if (normalized.length === 0) return '';
  return normalized
    .map((attr) => (attr.name ? `${attr.name}: ${attr.option}` : attr.option))
    .join(' / ');
}

function cartNameAlreadyIncludesVariation(name, suffix) {
  if (!name || !suffix) return false;
  const normalizedName = String(name).toLowerCase().replace(/[\s–—-]+/g, ' ').trim();
  const normalizedSuffix = String(suffix).toLowerCase().replace(/[\s–—-]+/g, ' ').trim();
  return normalizedName.endsWith(normalizedSuffix) || normalizedName.includes(` ${normalizedSuffix}`);
}

function buildDisplayNameWithVariation(baseName, attributes) {
  const name = decodeHtmlEntities(baseName || '').trim();
  const suffix = extractVariationOptionSuffix(attributes);
  if (!name || !suffix || cartNameAlreadyIncludesVariation(name, suffix)) return name;
  return `${name} - ${suffix}`;
}

function normalizeCartSnapshotItem(item) {
  if (!item || typeof item !== 'object') return item;
  const attrs = normalizeVariationAttributes(item.variation_attribute_values || item.variation || []);
  const name = buildDisplayNameWithVariation(item.name || item.product_name || '', attrs);
  return {
    ...item,
    name,
    variation_name: item.variation_name || buildVariationDetailText(attrs),
    variation_attribute_values: attrs.length ? attrs : item.variation_attribute_values,
  };
}

function normalizeStoreCartItem(item) {
  const variationValues = normalizeVariationAttributes(item?.variation || []);
  const parentName = decodeHtmlEntities(item.name || '');
  const displayName = buildDisplayNameWithVariation(parentName, variationValues);
  const decodedSku = decodeHtmlEntities(item.sku || '');
  const hasVariation = variationValues.length > 0 || Number(item?.variation_id || 0) > 0;

  return {
    cartKey: item.key,
    id: item.id,
    key: item.key,
    name: displayName,
    brand: item.brand || '',
    price: parsePriceFromStoreApi(item?.prices?.price ?? item?.price, item?.prices?.currency_minor_unit),
    image: getStoreItemImage(item),
    part_number: decodedSku || String(item.id || ''),
    sku: decodedSku,
    parent_id: item.parent_id || null,
    variation_id: item.variation_id || (hasVariation ? item.id : null),
    variation_name: buildVariationDetailText(variationValues),
    variation_attribute_values: variationValues.length ? variationValues : null,
    quantity: item.quantity || 1,
    raw: item,
  };
}

function normalizeStoreCart(cart) {
  return Array.isArray(cart?.items) ? cart.items.map(normalizeStoreCartItem) : [];
}

function asCartItems(value) {
  return Array.isArray(value) ? value : [];
}

function buildStoreApiVariation(variationAttributeValues) {
  if (!Array.isArray(variationAttributeValues)) return [];
  return variationAttributeValues
    .filter((attr) => attr?.name && attr?.option)
    .map((attr) => ({
      attribute: attr.slug || attr.name,
      value: attr.option,
    }));
}

function buildStoreApiExtensions(product) {
  const metadata = Array.isArray(product?.metadata) ? product.metadata : [];
  if (!product?.extensions && metadata.length === 0) return {};
  if (product?.extensions && metadata.length === 0) return product.extensions;

  return {
    ...(product?.extensions || {}),
    dtb: {
      ...((product?.extensions && product.extensions.dtb) || {}),
      metadata,
    },
  };
}

function getProductPrice(product) {
  const price = product?.price;
  const candidates = [
    typeof price === 'object' ? price?.value : price,
    product?.price_value,
    product?.sale_price,
    product?.regular_price,
    product?.min_price,
  ];

  for (const candidate of candidates) {
    const parsed = Number.parseFloat(String(candidate ?? ''));
    if (Number.isFinite(parsed)) return parsed;
  }
  return 0;
}

function getProductImage(product) {
  if (typeof product?.image === 'string') return product.image;
  return product?.image?.src
    || product?.image_thumbnail
    || product?.images?.[0]?.thumbnail
    || product?.images?.[0]?.src
    || '';
}

function getProductIdentity(product) {
  const attributes = normalizeVariationAttributes(product?.variation_attribute_values || product?.variation || []);
  const variationKey = attributes
    .map((attribute) => `${attribute.name}:${attribute.option}`.toLowerCase())
    .sort()
    .join('|');
  return `${product?.id || ''}:${product?.variation_id || ''}:${variationKey}`;
}

function addOptimisticCartItem(items, product, quantity, mutationId) {
  const safeQuantity = Math.max(1, Number.parseInt(quantity, 10) || 1);
  const productIdentity = getProductIdentity(product);
  const existingIndex = items.findIndex((item) => getProductIdentity(item) === productIdentity);

  if (existingIndex >= 0) {
    return items.map((item, index) => index === existingIndex
      ? { ...item, quantity: Number(item.quantity || 0) + safeQuantity, isPending: true }
      : item);
  }

  const attributes = normalizeVariationAttributes(product?.variation_attribute_values || product?.variation || []);
  return [
    ...items,
    normalizeCartSnapshotItem({
      ...product,
      cartKey: `optimistic:${mutationId}`,
      key: `optimistic:${mutationId}`,
      image: getProductImage(product),
      price: getProductPrice(product),
      quantity: safeQuantity,
      variation_attribute_values: attributes,
      isPending: true,
    }),
  ];
}

function findMatchingServerItem(serverCart, target) {
  const serverItems = normalizeStoreCart(serverCart);
  const targetIdentity = getProductIdentity(target);
  return serverItems.find((item) => getProductIdentity(item) === targetIdentity)
    || serverItems.find((item) => String(item.id) === String(target?.id))
    || null;
}

function readSnapshot() {
  try {
    const saved = localStorage.getItem(CART_SNAPSHOT_KEY);
    const parsed = saved ? JSON.parse(saved) : [];
    return Array.isArray(parsed) ? parsed.map(normalizeCartSnapshotItem) : [];
  } catch {
    return [];
  }
}

function writeSnapshot(items) {
  try {
    localStorage.setItem(CART_SNAPSHOT_KEY, JSON.stringify(asCartItems(items).map(normalizeCartSnapshotItem)));
  } catch {
    // Snapshot persistence is non-critical.
  }
}

export function CartProvider({ children }) {
  const [cart, setCart] = useState(null);
  const [cartItems, setCartItems] = useState(readSnapshot);
  const [isLoading, setIsLoading] = useState(true);
  const [isMutating, setIsMutating] = useState(false);
  const [error, setError] = useState(null);
  const [lastSyncedAt, setLastSyncedAt] = useState(null);
  const cartItemsRef = useRef(cartItems);
  const serverCartRef = useRef(null);
  const lastMutationIdRef = useRef(0);
  const pendingMutationCountRef = useRef(0);
  const mutationQueueRef = useRef(Promise.resolve());

  useEffect(() => {
    cartItemsRef.current = cartItems;
  }, [cartItems]);

  const beginMutation = useCallback(() => {
    lastMutationIdRef.current += 1;
    pendingMutationCountRef.current += 1;
    setIsMutating(true);
    return lastMutationIdRef.current;
  }, []);

  const finishMutation = useCallback(() => {
    pendingMutationCountRef.current = Math.max(0, pendingMutationCountRef.current - 1);
    setIsMutating(pendingMutationCountRef.current > 0);
  }, []);

  const enqueueMutation = useCallback((task) => {
    const queued = mutationQueueRef.current
      .catch(() => undefined)
      .then(task);
    mutationQueueRef.current = queued.catch(() => undefined);
    return queued;
  }, []);

  const isLatestMutation = useCallback(
    (mutationId) => mutationId === lastMutationIdRef.current,
    []
  );

  const applyServerCart = useCallback((nextCart, options = {}) => {
    const mutationId = options?.mutationId;
    if (!Array.isArray(nextCart?.items)) {
      return null;
    }
    serverCartRef.current = nextCart;
    if (Number.isFinite(mutationId) && mutationId !== lastMutationIdRef.current) {
      return null;
    }
    const normalizedItems = normalizeStoreCart(nextCart);
    setCart(nextCart || null);
    setCartItems(normalizedItems);
    cartItemsRef.current = normalizedItems;
    setLastSyncedAt(Date.now());
    writeSnapshot(normalizedItems);
    return normalizedItems;
  }, []);

  const applyOrRefreshServerCart = useCallback(async (nextCart, mutationId) => {
    const appliedItems = applyServerCart(nextCart, { mutationId });
    if (appliedItems) return appliedItems;
    if (Number.isFinite(mutationId) && mutationId !== lastMutationIdRef.current) {
      return cartItemsRef.current;
    }

    const refreshedCart = await getCart();
    return applyServerCart(refreshedCart, { mutationId }) || [];
  }, [applyServerCart]);

  const restoreAfterMutationFailure = useCallback((previousCart, previousItems, mutationId, mutationError = null) => {
    const recoveredItems = applyServerCart(mutationError?.cart || serverCartRef.current, { mutationId });
    if (recoveredItems) return;
    setCart(previousCart);
    setCartItems(previousItems);
    cartItemsRef.current = previousItems;
    writeSnapshot(previousItems);
  }, [applyServerCart]);

  const refreshCart = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      // A fresh Store API cart bootstrap also refreshes the user-bound Nonce after
      // login/logout so subsequent mutations cannot reuse a nonce from the prior
      // identity/session boundary.
      const initialized = await initCart();
      applyServerCart(initialized);
      return initialized;
    } catch (err) {
      setError(err?.message || 'Could not load cart.');
      throw err;
    } finally {
      setIsLoading(false);
    }
  }, [applyServerCart]);

  useEffect(() => {
    let mounted = true;
    initCart()
      .then((serverCart) => {
        if (!mounted) return;
        applyServerCart(serverCart);
      })
      .catch(async (err) => {
        if (!mounted) return;
        try {
          const serverCart = await getCart();
          if (!mounted) return;
          applyServerCart(serverCart);
        } catch {
          if (!mounted) return;
          setError(err?.message || 'Could not initialize cart.');
        }
      })
      .finally(() => {
        if (mounted) setIsLoading(false);
      });
    return () => { mounted = false; };
  }, [applyServerCart]);

  useEffect(() => {
    let active = true;

    const reconcileAuthSession = () => {
      // Serialize identity-bound cart reconciliation behind any in-flight cart
      // mutation. Later mutations also wait for this refresh, preventing a stale
      // pre-login/pre-logout Store API nonce or cart snapshot from winning a race.
      const queued = mutationQueueRef.current
        .catch(() => undefined)
        .then(async () => {
          if (!active) return null;
          return refreshCart();
        });
      mutationQueueRef.current = queued.catch(() => undefined);
      void queued.catch(() => undefined);
    };

    window.addEventListener('dtb:auth-changed', reconcileAuthSession);
    return () => {
      active = false;
      window.removeEventListener('dtb:auth-changed', reconcileAuthSession);
    };
  }, [refreshCart]);

  // The Google WooCommerce agent mutates the same authoritative Store API cart.
  // Refresh React's projection after the plugin announces a completed update.
  useEffect(() => {
    const onGeminiCartUpdated = () => { void refreshCart(); };
    document.body.addEventListener('gecx:cart-updated', onGeminiCartUpdated);
    return () => document.body.removeEventListener('gecx:cart-updated', onGeminiCartUpdated);
  }, [refreshCart]);

  const addToCart = useCallback(async (product, quantity = 1) => {
    if (!product?.id) return null;
    const mutationId = beginMutation();
    setError(null);
    const previousItems = cartItemsRef.current;
    const previousCart = cart;
    const optimisticItems = addOptimisticCartItem(previousItems, product, quantity, mutationId);
    setCart(null);
    setCartItems(optimisticItems);
    cartItemsRef.current = optimisticItems;
    try {
      const variation = buildStoreApiVariation(product.variation_attribute_values);
      const extensions = buildStoreApiExtensions(product);
      const nextCart = await enqueueMutation(
        () => storeAddToCart(product.id, quantity, variation, extensions)
      );
      const normalizedItems = await applyOrRefreshServerCart(nextCart, mutationId);
      const addedItem = normalizedItems.find((item) => String(item.id) === String(product.id)) || normalizeCartSnapshotItem({ ...product, quantity });
      trackAddToCart({ ...addedItem, quantity });
      if (typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent('dtb:cart-add-success', {
          detail: { productId: String(product.id) },
        }));
      }
      return nextCart;
    } catch (err) {
      if (isLatestMutation(mutationId)) {
        restoreAfterMutationFailure(previousCart, previousItems, mutationId, err);
        setError(err?.message || 'Could not add item to cart.');
      }
      if (typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent('dtb:cart-add-failure', {
          detail: { productId: String(product.id) },
        }));
      }
      throw err;
    } finally {
      finishMutation();
    }
  }, [applyOrRefreshServerCart, beginMutation, cart, enqueueMutation, finishMutation, isLatestMutation, restoreAfterMutationFailure]);

  const removeFromCart = useCallback(async (productIdOrKey) => {
    const key = String(productIdOrKey || '');
    if (!key) return null;
    const target = cartItemsRef.current.find((item) => String(item.cartKey || item.key || item.id) === key || String(item.id) === key);
    if (!target?.cartKey && !target?.key) return null;
    const mutationId = beginMutation();
    setError(null);
    const previousItems = cartItemsRef.current;
    const previousCart = cart;
    const optimisticItems = previousItems.filter(
      (item) => String(item.cartKey || item.key || item.id) !== key && String(item.id) !== key
    );
    setCartItems(optimisticItems);
    cartItemsRef.current = optimisticItems;
    setCart(null);
    writeSnapshot(optimisticItems);
    try {
      const nextCart = await enqueueMutation(() => {
        const serverTarget = String(target.cartKey || target.key).startsWith('optimistic:')
          ? findMatchingServerItem(serverCartRef.current, target)
          : target;
        const serverKey = serverTarget?.cartKey || serverTarget?.key;
        if (!serverKey || String(serverKey).startsWith('optimistic:')) {
          throw new Error('This cart item is still syncing. Please try again.');
        }
        return removeCartItem(serverKey);
      });
      await applyOrRefreshServerCart(nextCart, mutationId);
      trackRemoveFromCart(target);
      return nextCart;
    } catch (err) {
      if (isLatestMutation(mutationId)) {
        restoreAfterMutationFailure(previousCart, previousItems, mutationId, err);
        setError(err?.message || 'Could not remove item from cart.');
      }
      throw err;
    } finally {
      finishMutation();
    }
  }, [applyOrRefreshServerCart, beginMutation, cart, enqueueMutation, finishMutation, isLatestMutation, restoreAfterMutationFailure]);

  const updateQuantity = useCallback(async (productIdOrKey, newQuantity) => {
    const normalizedQuantity = Number(newQuantity);
    if (!Number.isFinite(normalizedQuantity)) return null;
    if (normalizedQuantity < 1) {
      return removeFromCart(productIdOrKey);
    }

    const key = String(productIdOrKey || '');
    const target = cartItemsRef.current.find((item) => String(item.cartKey || item.key || item.id) === key || String(item.id) === key);
    if (!target?.cartKey && !target?.key) return null;

    const mutationId = beginMutation();
    setError(null);
    const previousItems = cartItemsRef.current;
    const previousCart = cart;
    const optimisticItems = previousItems.map((item) => {
      const itemKey = String(item.cartKey || item.key || item.id);
      return itemKey === key || String(item.id) === key
        ? { ...item, quantity: normalizedQuantity }
        : item;
    });
    setCartItems(optimisticItems);
    cartItemsRef.current = optimisticItems;
    setCart(null);
    writeSnapshot(optimisticItems);
    try {
      const nextCart = await enqueueMutation(() => {
        const serverTarget = String(target.cartKey || target.key).startsWith('optimistic:')
          ? findMatchingServerItem(serverCartRef.current, target)
          : target;
        const serverKey = serverTarget?.cartKey || serverTarget?.key;
        if (!serverKey || String(serverKey).startsWith('optimistic:')) {
          throw new Error('This cart item is still syncing. Please try again.');
        }
        return updateCartItem(serverKey, normalizedQuantity);
      });
      const normalizedItems = await applyOrRefreshServerCart(nextCart, mutationId);
      if (previousItems.length > 0 && normalizedItems.length === 0) {
        throw new Error('Cart sync returned an empty cart unexpectedly.');
      }
      return nextCart;
    } catch (err) {
      if (isLatestMutation(mutationId)) {
        restoreAfterMutationFailure(previousCart, previousItems, mutationId, err);
        setError(err?.message || 'Could not update cart quantity.');
      }
      throw err;
    } finally {
      finishMutation();
    }
  }, [applyOrRefreshServerCart, beginMutation, cart, enqueueMutation, finishMutation, isLatestMutation, removeFromCart, restoreAfterMutationFailure]);

  const applyCartCoupon = useCallback(async (code) => {
    const normalizedCode = String(code || '').trim();
    if (!normalizedCode) return null;

    const mutationId = beginMutation();
    setError(null);
    const previousItems = cartItemsRef.current;
    const previousCart = cart;
    try {
      const nextCart = await enqueueMutation(() => applyStoreCoupon(normalizedCode));
      await applyOrRefreshServerCart(nextCart, mutationId);
      return nextCart;
    } catch (err) {
      if (isLatestMutation(mutationId)) {
        restoreAfterMutationFailure(previousCart, previousItems, mutationId, err);
        setError(err?.message || 'Could not apply coupon.');
      }
      throw err;
    } finally {
      finishMutation();
    }
  }, [applyOrRefreshServerCart, beginMutation, cart, enqueueMutation, finishMutation, isLatestMutation, restoreAfterMutationFailure]);

  const removeCartCoupon = useCallback(async (code) => {
    const normalizedCode = String(code || '').trim();
    if (!normalizedCode) return null;

    const mutationId = beginMutation();
    setError(null);
    const previousItems = cartItemsRef.current;
    const previousCart = cart;
    try {
      const nextCart = await enqueueMutation(() => removeStoreCoupon(normalizedCode));
      await applyOrRefreshServerCart(nextCart, mutationId);
      return nextCart;
    } catch (err) {
      if (isLatestMutation(mutationId)) {
        restoreAfterMutationFailure(previousCart, previousItems, mutationId, err);
        setError(err?.message || 'Could not remove coupon.');
      }
      throw err;
    } finally {
      finishMutation();
    }
  }, [applyOrRefreshServerCart, beginMutation, cart, enqueueMutation, finishMutation, isLatestMutation, restoreAfterMutationFailure]);

  const clearCart = useCallback(async () => {
    const mutationId = beginMutation();
    setError(null);
    const previousItems = cartItemsRef.current;
    const previousCart = cart;
    setCartItems([]);
    cartItemsRef.current = [];
    setCart(null);
    writeSnapshot([]);
    try {
      const nextCart = await enqueueMutation(clearStoreCart);
      await applyOrRefreshServerCart(nextCart, mutationId);
      return nextCart;
    } catch (err) {
      if (isLatestMutation(mutationId)) {
        restoreAfterMutationFailure(previousCart, previousItems, mutationId, err);
        setError(err?.message || 'Could not clear cart.');
      }
      throw err;
    } finally {
      finishMutation();
    }
  }, [applyOrRefreshServerCart, beginMutation, cart, enqueueMutation, finishMutation, isLatestMutation, restoreAfterMutationFailure]);

  const getCartTotal = useCallback(() => {
    const safeItems = asCartItems(cartItems);
    const totalPrice = cart?.totals?.total_price;
    if (totalPrice != null) return parsePriceFromStoreApi(totalPrice, cart?.totals?.currency_minor_unit);
    return safeItems.reduce((total, item) => total + (item.price * item.quantity), 0);
  }, [cart, cartItems]);

  const getCartCount = useCallback(
    () => asCartItems(cartItems).reduce((count, item) => count + item.quantity, 0),
    [cartItems]
  );

  const value = useMemo(() => ({
    cart,
    cartItems,
    isLoading,
    isMutating,
    error,
    lastSyncedAt,
    addToCart,
    removeFromCart,
    updateQuantity,
    applyCoupon: applyCartCoupon,
    removeCoupon: removeCartCoupon,
    clearCart,
    refreshCart,
    getCartTotal,
    getCartCount,
  }), [
    cart,
    cartItems,
    isLoading,
    isMutating,
    error,
    lastSyncedAt,
    addToCart,
    removeFromCart,
    updateQuantity,
    applyCartCoupon,
    removeCartCoupon,
    clearCart,
    refreshCart,
    getCartTotal,
    getCartCount,
  ]);

  return (
    <CartContext.Provider value={value}>
      {children}
      <CartInteractionFeedback />
    </CartContext.Provider>
  );
}
