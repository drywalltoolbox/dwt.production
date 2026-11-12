/**
 * Storefront SKU lookup adapter over the canonical DTB catalog read API.
 * WooCommerce remains the runtime identity and product-data authority.
 */

import { resolveCatalogProductSku } from '../services/catalogPlatformCache.js';
import { toLegacyProductCardDTO, toLegacyVariationDTO } from '../utils/catalogDtoAdapters.js';

/**
 * Resolve an exact published WooCommerce SKU for a schematic hotspot.
 * A confirmed miss returns null; upstream/runtime failures are allowed to
 * propagate so the caller can distinguish unavailable from not found.
 */
export async function getHotspotProductBySku(sku, options = {}) {
  const result = await resolveCatalogProductSku(sku, options);
  if (!result?.product) return null;

  const parent = toLegacyProductCardDTO(result.product);
  if (result.type !== 'variation' || !result.variation) return parent;

  const variation = toLegacyVariationDTO(result.variation, result.product);
  const productUrl = parent.slug
    ? `/products/${parent.slug}?variant=${encodeURIComponent(String(variation.id))}`
    : '';

  return {
    ...variation,
    type: 'variation',
    is_variable: false,
    parent_slug: parent.slug,
    product_url: productUrl,
    permalink: productUrl,
  };
}
