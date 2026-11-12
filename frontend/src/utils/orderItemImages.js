import { resolveCatalogProductSku } from '../services/catalogPlatformCache.js';

export function getOrderItemKey(item = {}) {
  if (!item || typeof item !== 'object') return '';

  return String(
    item.id ||
    item.cartKey ||
    item.key ||
    item.variation_id ||
    item.product_id ||
    item.sku ||
    item.slug ||
    item.name ||
    ''
  );
}

export function resolveOrderItemImage(item = {}) {
  if (!item || typeof item !== 'object') return '';

  if (typeof item.image === 'string' && item.image.trim()) return item.image.trim();
  if (item.image && typeof item.image === 'object') {
    return item.image.src || item.image.url || item.image.thumbnail || '';
  }
  if (Array.isArray(item.images) && item.images.length > 0) {
    const first = item.images[0];
    if (typeof first === 'string' && first.trim()) return first.trim();
    if (first && typeof first === 'object') return first.src || first.url || first.thumbnail || '';
  }
  return item.image_url || item.image_thumb || item.thumbnail || item.thumbnail_url || '';
}

export function resolveCatalogProductImage(product = {}) {
  if (!product || typeof product !== 'object') return '';

  const media = product.media && typeof product.media === 'object' ? product.media : {};
  if (typeof media.image === 'string' && media.image.trim()) return media.image.trim();
  if (Array.isArray(media.images) && media.images.length > 0) {
    const first = media.images[0];
    if (typeof first === 'string' && first.trim()) return first.trim();
    if (first && typeof first === 'object') return first.src || first.url || first.thumbnail || '';
  }

  if (typeof product.image === 'string' && product.image.trim()) return product.image.trim();
  if (product.image && typeof product.image === 'object') {
    return product.image.src || product.image.url || product.image.thumbnail || '';
  }
  if (Array.isArray(product.images) && product.images.length > 0) {
    const first = product.images[0];
    if (typeof first === 'string' && first.trim()) return first.trim();
    if (first && typeof first === 'object') return first.src || first.url || first.thumbnail || '';
  }
  return product.image_url || product.image_thumb || product.thumbnail || product.thumbnail_url || '';
}

export function getOrderPreviewItem(order = {}) {
  const items = Array.isArray(order.line_items)
    ? order.line_items
    : Array.isArray(order.items)
      ? order.items
      : [];
  return items[0] || null;
}

export async function resolveMissingOrderItemImages(items = [], { signal } = {}) {
  const missingItems = items.filter((item) => getOrderItemKey(item) && !resolveOrderItemImage(item));
  if (missingItems.length === 0) return {};

  const resolved = {};
  const bySku = new Map();
  for (const item of missingItems) {
    const sku = String(item?.sku || '').trim();
    if (sku && !bySku.has(sku)) bySku.set(sku, []);
    if (sku) bySku.get(sku).push(item);
  }

  const entries = Array.from(bySku.entries());
  let cursor = 0;
  const workerCount = Math.min(4, entries.length);
  await Promise.all(Array.from({ length: workerCount }, async () => {
    while (cursor < entries.length && !signal?.aborted) {
      const [sku, skuItems] = entries[cursor++];
      try {
        const result = await resolveCatalogProductSku(sku, { signal });
        const product = result?.type === 'variation' ? result.variation : result?.product;
        const image = resolveCatalogProductImage(product);
        if (!image) continue;
        for (const item of skuItems) resolved[getOrderItemKey(item)] = image;
      } catch {
        // Image fallback is non-critical; a failed catalog read must not turn
        // into a guessed match or interfere with order rendering.
      }
    }
  }));

  return resolved;
}
