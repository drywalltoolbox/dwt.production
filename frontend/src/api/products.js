/**
 * frontend/src/api/products.js
 *
 * Product API helpers via the drywall/v1 server-side proxy.
 * Proxy namespace: /wp-json/drywall/v1/
 *
 * Also exports backward-compatible helpers used by existing services
 * (getProductBySku, getProductCategories, etc.).
 */

import { apiClient } from './client.js';
import { normalizeProduct } from '../services/api.js';
import { getProductById as getCatalogProductById } from '../services/catalog.js';

// ─── drywall/v1 proxy helpers ─────────────────────────────────────────────────

/**
 * Fetch a paginated list of products.
 *
 * @param {Object} params  Supported: page, per_page, category, search,
 *                         orderby, order, min_price, max_price, stock_status
 * @returns {Promise<any>}
 */
export async function fetchProducts( params = {}, options = {} ) {
  const qs = new URLSearchParams( params ).toString();
  return apiClient( `/wp-json/drywall/v1/products${ qs ? `?${ qs }` : '' }`, options );
}

/**
 * Fetch a single product by its WooCommerce ID.
 *
 * @param {number|string} id
 * @returns {Promise<any>}
 */
export async function fetchProduct( id ) {
  return apiClient( `/wp-json/drywall/v1/products/${ encodeURIComponent( id ) }` );
}

/**
 * Fetch a single product by its slug.
 *
 * @param {string} slug
 * @returns {Promise<any>}
 */
export async function fetchProductBySlug( slug ) {
  return apiClient( `/wp-json/drywall/v1/products/slug/${ encodeURIComponent( slug ) }` );
}

/**
 * Fetch product categories.
 *
 * @param {Object} params  Supported: page, per_page, parent
 * @returns {Promise<any>}
 */
export async function fetchCategories( params = {} ) {
  const qs = new URLSearchParams( params ).toString();
  return apiClient( `/wp-json/drywall/v1/categories${ qs ? `?${ qs }` : '' }` );
}

/**
 * Fetch all product attributes.
 *
 * @returns {Promise<any>}
 */
export async function fetchAttributes() {
  return apiClient( '/wp-json/drywall/v1/attributes' );
}

/**
 * Search products by keyword.
 *
 * @param {string} query
 * @param {Object} params  Additional params (page, per_page)
 * @returns {Promise<any>}
 */
export async function searchProducts( query, params = {} ) {
  const merged = { q: query, ...params };
  const qs = new URLSearchParams( merged ).toString();
  return apiClient( `/wp-json/drywall/v1/search?${ qs }` );
}

/**
 * Search parent products by partial variation SKU token.
 *
 * Example: q=PAHC should return the parent variable product even when
 * parent product text fields do not include the variation SKU token.
 *
 * @param {string} query
 * @param {Object} params  Additional params (limit)
 * @returns {Promise<any>}
 */
export async function searchProductsByVariationSku( query, params = {} ) {
  const merged = { q: query, ...params };
  const qs = new URLSearchParams( merged ).toString();
  return apiClient( `/wp-json/drywall/v1/search/variation-sku?${ qs }` );
}

// ─── Backward-compatible helpers (used by Schematics.jsx / services/) ─────────

/**
 * Fetch a paginated list of products (legacy alias via wcClient).
 *
 * @param {Object} params
 * @returns {Promise<Array>}
 */
export async function getProducts( params = {} ) {
  return apiClient( `/wp-json/drywall/v1/products?${ new URLSearchParams({ per_page: 20, ...params }).toString() }` );
}

/**
 * Fetch a single product by WooCommerce ID (legacy alias via wcClient).
 *
 * @param {number|string} id
 * @returns {Promise<Object>}
 */
export async function getProduct( id ) {
  return apiClient( `/wp-json/drywall/v1/products/${ encodeURIComponent( id ) }` );
}

/**
 * Fetch a single product by WooCommerce ID (alias for getProduct).
 */
export const getProductById = getProduct;

/**
 * Fetch products belonging to a specific category (legacy, via wcClient).
 *
 * @param {number|string} categoryId
 * @param {Object}        params
 * @returns {Promise<Array>}
 */
export async function getProductsByCategory( categoryId, params = {} ) {
  return apiClient( `/wp-json/drywall/v1/products?${ new URLSearchParams({ category: categoryId, per_page: 20, ...params }).toString() }` );
}

/**
 * Fetch all product categories (legacy alias via wcClient).
 *
 * @param {Object} params
 * @returns {Promise<Array>}
 */
export async function getProductCategories( params = {} ) {
  return apiClient( `/wp-json/drywall/v1/categories?${ new URLSearchParams({ per_page: 100, ...params }).toString() }` );
}

/**
 * Fetch all variations for a variable product.
 *
 * @param {number|string} parentId  WooCommerce parent product ID
 * @param {Object}        params    Optional params (e.g. per_page, page)
 * @returns {Promise<any>}
 */
export async function fetchProductVariations( parentId, params = {} ) {
  const qs = new URLSearchParams( { per_page: 24, ...params } ).toString();
  return apiClient( `/wp-json/drywall/v1/products/${ encodeURIComponent( parentId ) }/variations?${ qs }` );
}

/**
 * Fetch a single product by SKU (used by Schematics.jsx hotspot lookup).
 *
 * Routes through the drywall/v1 server-side proxy so no client-side WC
 * credentials are required and CORS is handled server-side.
 *
 * @param {string} sku
 * @returns {Promise<Object|null>}
 */
export async function getProductBySku( sku ) {
  if ( ! sku ) return null;
  try {
    const result = await fetchProducts( { sku, per_page: 1 } );
    const products = Array.isArray( result ) ? result : result?.products ?? [];
    if ( products.length > 0 ) {
      // Normalize the raw WC/proxy response to the internal product shape so
      // callers (e.g. the schematic hotspot modal) can reliably access
      // .stock_status, .images, .price, .name, .sku, etc.
      return normalizeProduct( products[ 0 ] );
    }
    // WC proxy returned an empty list — fall through to catalog lookup below.
  } catch {
    // Network error or proxy unavailable (e.g. GitHub Pages) — fall through to
    // catalog lookup below.
  }

  // WC proxy returned nothing (e.g. GitHub Pages, offline, product not yet in WC).
  // catalog.js getProductById accepts both numeric IDs and SKU strings — it
  // searches the in-memory catalog by id, slug, sku, and part_number in order.
  // Falls back to the catalog cache populated from the live WC REST API.
  try {
    return await getCatalogProductById( sku ) ?? null;
  } catch {
    // Catalog load also failed — nothing more we can do.
    return null;
  }
}

/**
 * Fetch a single variation by its parent product ID and variation ID.
 *
 * Backed by `GET /wp-json/drywall/v1/products/{parent_id}/variations/{id}`
 * (see `ProxyRoutes.php` `dtb_proxy_product_variation_by_id` /
 * `DTB_VARIATION_FIELDS`) — returns id, sku, slug, name, type, status,
 * price, regular_price, sale_price, on_sale, purchasable, stock_status,
 * manage_stock, stock_quantity, backorders_allowed, backordered, images,
 * attributes, parent_id. No `permalink` field — build the SPA URL from the
 * parent slug (`/products/{parentSlug}?variant={id}`) instead.
 *
 * @param {number|string} parentId
 * @param {number|string} variationId
 * @returns {Promise<Object|null>}
 */
export async function fetchProductVariationById( parentId, variationId ) {
  if ( ! parentId || ! variationId ) return null;
  return apiClient( `/wp-json/drywall/v1/products/${ encodeURIComponent( parentId ) }/variations/${ encodeURIComponent( variationId ) }` );
}

/**
 * Resolve a hotspot part's SKU to a product-shaped object, covering both
 * top-level/simple products AND WooCommerce variation SKUs (e.g. size
 * variants like "AH7-2.5" that only exist nested under a parent variable
 * product and are never returned by the top-level product listing search).
 *
 * Resolution order:
 *   1. `getProductBySku` — fast path; covers simple/parent products.
 *   2. `resolveProductBySku` — if that resolves as `type: 'variation'`,
 *      fetch the variation itself (own image/price/stock_status, not the
 *      parent's) and attach a `product_url`/`permalink` deep link
 *      (`/products/{parentSlug}?variant={variationId}`) rather than the
 *      parent's bare product page. If it resolves as `type: 'simple'`,
 *      fetch that product directly (covers the rare case where the
 *      top-level SKU search in step 1 didn't already find it).
 *   3. Nothing resolves — return null so the caller can fall back to the
 *      static schematic payload's own resolution_state/product_url.
 *
 * @param {string} sku
 * @returns {Promise<Object|null>} A product-shaped object with at least
 *   id, name, sku, images (array of src strings), price, stock_status, and
 *   product_url/permalink — or null if the SKU can't be resolved at all.
 */
export async function getHotspotProductBySku( sku ) {
  if ( ! sku ) return null;

  const simple = await getProductBySku( sku );
  if ( simple ) return simple;

  const resolved = await resolveProductBySku( sku );
  if ( ! resolved ) return null;

  try {
    if ( resolved.type === 'variation' && resolved.parentId && resolved.id ) {
      const variation = await fetchProductVariationById( resolved.parentId, resolved.id );
      if ( ! variation ) return null;

      const images = ( Array.isArray( variation.images ) ? variation.images : [] )
        .map( ( img ) => ( typeof img === 'string' ? img : img?.src ?? '' ) )
        .filter( Boolean );

      let parentSlug = resolved.parentSlug;
      if ( ! parentSlug ) {
        try {
          const parent = await getProduct( resolved.parentId );
          parentSlug = parent?.slug || '';
        } catch {
          parentSlug = '';
        }
      }

      const productUrl = parentSlug ? `/products/${ parentSlug }?variant=${ variation.id }` : '';

      return {
        id: variation.id,
        name: variation.name || '',
        sku: variation.sku || sku,
        images,
        price: variation.sale_price || variation.price || variation.regular_price || '',
        stock_status: variation.stock_status || 'unknown',
        product_url: productUrl,
        permalink: productUrl,
      };
    }

    if ( resolved.type === 'simple' && resolved.id ) {
      const product = await getProduct( resolved.id );
      return product ? normalizeProduct( product ) : null;
    }
  } catch {
    return null;
  }

  return null;
}

/**
 * Resolve a SKU (parent or variation) to its canonical product URL components.
 *
 * For simple products: returns { type: 'simple', id, slug }
 * For variation SKUs:  returns { type: 'variation', id, parentId, parentSlug }
 *
 * Used by catalog discovery flows to map a variation SKU to its canonical
 * /products/{parentSlug}?variant={id} URL.
 *
 * @param {string} sku
 * @returns {Promise<{ type: string, id: number, slug?: string, parentId?: number, parentSlug?: string } | null>}
 */
export async function resolveProductBySku( sku ) {
  if ( ! sku ) return null;
  try {
    return await apiClient( `/wp-json/drywall/v1/products/resolve-sku/${ encodeURIComponent( sku ) }` );
  } catch {
    return null;
  }
}

