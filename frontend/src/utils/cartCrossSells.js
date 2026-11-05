export const MAX_CART_CROSS_SELLS = 4;
export const MAX_CART_COMPATIBILITY_SKUS = 20;
export const MAX_CART_COMPATIBLE_PARTS = 100;

export function getCartCompatibilitySkus(cartItems = []) {
  const seen = new Set();
  return (Array.isArray(cartItems) ? cartItems : [])
    .map((item) => String(item?.sku ?? ''))
    .filter((sku) => {
      if (!/^[A-Za-z0-9._-]+$/.test(sku) || seen.has(sku)) return false;
      seen.add(sku);
      return true;
    })
    .slice(0, MAX_CART_COMPATIBILITY_SKUS);
}

export function storePrice(value, minorUnit) {
  const rawString = String(value ?? '').trim();
  const raw = typeof value === 'number' ? value : Number.parseFloat(rawString || '');
  if (!Number.isFinite(raw)) return null;
  const unit = Number(minorUnit);
  if (Number.isFinite(unit) && unit >= 0 && Number.isInteger(raw) && !rawString.includes('.')) {
    return raw / (10 ** unit);
  }
  return raw;
}

export function getVisibleCrossSells(cart, cartItems = [], limit = MAX_CART_CROSS_SELLS) {
  const existingIds = new Set();
  (Array.isArray(cartItems) ? cartItems : []).forEach((item) => {
    [item?.id, item?.parent_id, item?.variation_id].forEach((id) => {
      const value = Number(id);
      if (Number.isInteger(value) && value > 0) existingIds.add(value);
    });
  });

  const seen = new Set();
  const boundedLimit = Math.floor(Math.max(0, Math.min(MAX_CART_CROSS_SELLS, Number(limit) || 0)));
  return (Array.isArray(cart?.cross_sells) ? cart.cross_sells : [])
    .filter((product) => {
      const id = Number(product?.id);
      if (!Number.isInteger(id) || id <= 0 || seen.has(id) || existingIds.has(id)) return false;
      if (product?.is_in_stock === false || product?.is_purchasable === false) return false;
      seen.add(id);
      return true;
    })
    .slice(0, boundedLimit);
}

export function getVisibleCartRecommendations(cart, cartItems = [], compatibleParts = [], limit = MAX_CART_CROSS_SELLS) {
  const boundedLimit = Math.floor(Math.max(0, Math.min(MAX_CART_CROSS_SELLS, Number(limit) || 0)));
  if (boundedLimit === 0) return [];

  const existingIds = new Set();
  const cartSkus = new Set();
  (Array.isArray(cartItems) ? cartItems : []).forEach((item) => {
    [item?.id, item?.parent_id, item?.variation_id].forEach((id) => {
      const value = Number(id);
      if (Number.isInteger(value) && value > 0) existingIds.add(value);
    });
    if (typeof item?.sku === 'string' && item.sku) cartSkus.add(item.sku);
  });

  const recommendations = [];
  const seen = new Set();
  getVisibleCrossSells(cart, cartItems, boundedLimit).forEach((product) => {
    const id = Number(product?.id);
    seen.add(id);
    recommendations.push({ type: 'cross_sell', product });
  });

  (Array.isArray(compatibleParts) ? compatibleParts : []).some((product) => {
    if (recommendations.length >= boundedLimit) return true;
    const id = Number(product?.id);
    const matchedVariation = (Array.isArray(product?.compatible_part_variations) ? product.compatible_part_variations : [])
      .find((variation) => (Array.isArray(variation?.compatible_with_skus) ? variation.compatible_with_skus : []).some((sku) => cartSkus.has(sku)));
    const compatibleWithSku = (Array.isArray(matchedVariation?.compatible_with_skus) ? matchedVariation.compatible_with_skus : [])
      .find((sku) => cartSkus.has(sku))
      || (Array.isArray(product?.compatible_with_skus) ? product.compatible_with_skus : [])
      .find((sku) => cartSkus.has(sku));
    const availability = matchedVariation || product;
    if (!Number.isInteger(id) || id <= 0 || seen.has(id) || existingIds.has(id)) return false;
    if (product?.type === 'variable' && !matchedVariation) return false;
    if (!compatibleWithSku || availability?.purchasable === false || availability?.is_in_stock === false || availability?.stock_status === 'outofstock') return false;
    seen.add(id);
    recommendations.push({ type: 'compatible_part', product, compatibleWithSku, matchedVariation: matchedVariation || null });
    return false;
  });

  return recommendations.slice(0, boundedLimit);
}
