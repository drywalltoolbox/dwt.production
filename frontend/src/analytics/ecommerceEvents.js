
/**
 * frontend/src/analytics/ecommerceEvents.js
 *
 * Centralized ecommerce instrumentation layer.
 *
 * Future integrations:
 * - GA4
 * - GTM dataLayer
 * - Meta Pixel
 * - TikTok Pixel
 * - internal BI pipeline
 */

function normalizeItem(item = {}) {
  return {
    item_id: item.sku || item.part_number || String(item.id || ''),
    item_name: item.name || '',
    item_brand: item.brand || '',
    item_category: item.category || item.display_category || '',
    item_variant: Array.isArray(item.variation_attribute_values)
      ? item.variation_attribute_values.map((a) => a.option).join(' / ')
      : '',
    price: Number(item.price || 0),
    quantity: Number(item.quantity || 1),
  };
}

function emit(eventName, payload = {}) {
  if (typeof window === 'undefined') return;

  const event = {
    event: eventName,
    ecommerce: payload,
    timestamp: Date.now(),
    path: window.location.pathname,
  };

  // GTM / GA4 compatible.
  window.dataLayer = window.dataLayer || [];
  window.dataLayer.push(event);

  // Internal debug visibility during rollout.
  if (process.env.NODE_ENV !== 'production') {
    console.debug('[DTB Ecommerce Event]', eventName, event);
  }
}

export function trackViewItem(item) {
  emit('view_item', {
    items: [normalizeItem(item)],
  });
}

export function trackSelectItem(item, context = {}) {
  emit('select_item', {
    item_list_name: context.listName || '',
    items: [normalizeItem(item)],
  });
}

export function trackSelectVariant(product, variation) {
  emit('select_variant', {
    items: [normalizeItem({
      ...product,
      ...variation,
    })],
  });
}

export function trackAddToCart(item) {
  emit('add_to_cart', {
    currency: 'USD',
    items: [normalizeItem(item)],
  });

}

export function trackRemoveFromCart(item) {
  emit('remove_from_cart', {
    currency: 'USD',
    items: [normalizeItem(item)],
  });
}

export function trackBeginCheckout(items = []) {
  emit('begin_checkout', {
    currency: 'USD',
    items: items.map(normalizeItem),
  });
}

export function trackPurchase(order = {}) {
  emit('purchase', {
    transaction_id: order.order_id || order.id || '',
    value: Number(order.total || 0),
    currency: 'USD',
    items: Array.isArray(order.items)
      ? order.items.map(normalizeItem)
      : [],
  });
}
