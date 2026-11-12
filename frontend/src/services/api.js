/**
 * src/services/api.js
 *
 * Product normalization and compatibility helpers for the storefront.
 * Reads are routed through the same-origin DTB WooCommerce proxy; browser code
 * never receives WooCommerce REST credentials.
 */

import { apiClient } from '../api/client.js';
import { CATEGORY_MAP } from '../utils/parseProductCsv.js';
import { PLACEHOLDER_IMAGE } from '../constants/images.js';
import {
  buildSpecificationsFromApiProduct,
  buildSpecsMetaFromRows,
  mergeSpecMeta,
} from '../utils/csvSpecificationMapping.js';
import { buildIncludesMetaFromContent } from '../utils/includesExtraction.js';
import { decodeHtmlEntities } from '../utils/string.js';
import { filterSpecificationMetaData } from '../utils/productSpecificationPolicy.js';
import { toLegacyProductCardDTO, toLegacyVariationDTO } from '../utils/catalogDtoAdapters.js';
import {
  fetchCatalogProducts,
  fetchCatalogProductVariations,
} from './catalogPlatformCache.js';

function hasStructuredIncludesMeta(metaItems = []) {
  return metaItems.some(({ key }) => /^_includes_\d+_(name|sku)$/.test(String(key || '')));
}


// ─── Active and legacy storefront brand labels ───────────────────────────────
const KNOWN_BRANDS = [
  'TapeTech',
  'Columbia Tools',
  'Asgard',
  'LEVEL5',
  'SurPro',
  'Platinum Drywall Tools',
  'Dura-Stilts',
  'USG Sheetrock Tools',
];

// Maps common brand name variations to the canonical brand name used throughout the UI.
const BRAND_ALIASES = {
  'platinum':                  'Platinum Drywall Tools',
  'platinum tools':            'Platinum Drywall Tools',
  'platinum drywall':          'Platinum Drywall Tools',
  'platinum drywall tools':    'Platinum Drywall Tools',
  'dura stilts':               'Dura-Stilts',
  'dura_stilts':               'Dura-Stilts',
  'columbia':                  'Columbia Tools',
  'columbia taping':           'Columbia Tools',
  'columbia taping tools':     'Columbia Tools',
  'columbia-tools':            'Columbia Tools',
  'level 5':                   'LEVEL5',
  'level5':                    'LEVEL5',
  'level5 tools':              'LEVEL5',
  'tapetech':                  'TapeTech',
  'tape tech':                 'TapeTech',
  'surpro':                    'SurPro',
  'sur pro':                   'SurPro',
  'asgard':                    'Asgard',
  'usg sheetrock tools':       'USG Sheetrock Tools',
  'usg-sheetrock-tools':       'USG Sheetrock Tools',
  'usg sheetrock tools®':      'USG Sheetrock Tools',
  'usg':                       'USG Sheetrock Tools',
};

// ─── Normalizer ──────────────────────────────────────────────────────────────
//
// Maps the WooCommerce product shape to the internal shape expected by the
// existing UI components (ProductDetail, ProductCard, TrendingProducts, etc.).
// This avoids touching every component when the data source changes.

// Parts category name stems (decoded, lowercase) — production catalog only.
// The WooCommerce production catalog uses a single canonical parts leaf: "Parts".
const PARTS_LEAF_NAMES = [
  'parts',
];

// Parts slug prefixes — production catalog only.
// Keep a light prefix fallback because WooCommerce may de-duplicate identical
// leaf slugs across brands (for example "parts-2").
const PARTS_SLUG_PREFIXES = [
  'parts',
];

// Generic catch-all category labels that should be treated as lowest priority
// when a more specific mapped category is also present on the same product.
// NOTE: names are lowercase because category-name normalization lowercases first.
const GENERIC_MAPPED_CATEGORY_NAMES = new Set([
  'tools',
]);

/** Decode common HTML entities that WooCommerce embeds in category names via the REST API. */

/**
 * Map a WooCommerce REST API categories array to our internal category key.
 *
 * This is intentionally separate from the exported `mapCategory` in
 * parseProductCsv.js: that function parses a CSV cell string such as
 * "Drywall Finishing Tools > TapeTech > Parts & Accessories", whereas the
 * WooCommerce REST API delivers categories as an array of objects
 * ({id, name, slug}).  Both functions use the same CATEGORY_MAP.
 *
 * @param {Array<{id:number, name:string, slug:string}>} wcCategories
 * @returns {string}
 */
function mapApiCategory(wcCategories) {
  if (!wcCategories || !wcCategories.length) return '';

  const mapped = wcCategories
    .map((cat) => {
      const normalizedName = decodeHtmlEntities(cat.name).toLowerCase();
      const key = CATEGORY_MAP[normalizedName];
      return key ? { key, normalizedName } : null;
    })
    .filter(Boolean);

  if (mapped.length > 0) {
    const nonGenericMapped = mapped.filter(({ normalizedName }) => !GENERIC_MAPPED_CATEGORY_NAMES.has(normalizedName));
    const preferred = nonGenericMapped.length > 0 ? nonGenericMapped : mapped;
    // WooCommerce category arrays are typically ordered broad → specific.
    // Use the last preferred mapped category so generic ancestors like "Tools"
    // don't override specific leaves like "Tool Sets & Bundles" or parts leaves.
    return preferred[preferred.length - 1].key;
  }

  return decodeHtmlEntities(wcCategories[0].name).toLowerCase();
}

/**
 * Return true when the product belongs to the canonical production-catalog
 * replacement-parts category.
 *
 *   • Name check: decodes HTML entities ("&amp;" → "&") then matches "parts".
 *   • Slug check: prefix match catches WooCommerce slug de-duplication such as
 *     "parts-2" while remaining aligned to the single canonical leaf.
 *
 * @param {Array<{id:number, name:string, slug:string}>} wcCategories
 * @returns {boolean}
 */
function isPartsFromApi(wcCategories) {
  if (!wcCategories || !wcCategories.length) return false;
  return wcCategories.some(cat => {
    const name = decodeHtmlEntities(cat.name).toLowerCase();
    const slug = (cat.slug || '').toLowerCase();
    return (
      PARTS_LEAF_NAMES.includes(name) ||
      PARTS_SLUG_PREFIXES.some(prefix => slug.startsWith(prefix))
    );
  });
}

/**
 * Return the human-readable leaf WC category name for use in category cards.
 * WooCommerce REST API returns categories from most-generic to most-specific
 * (e.g. ["Drywall Finishing Tools", "TapeTech", "Finishing Boxes"]).
 * We iterate the array and return the name of the first category whose name
 * maps to a CATEGORY_MAP key, which is typically the most-specific leaf.
 *
 * @param {Array<{id:number, name:string, slug:string}>} wcCategories
 * @returns {string}  e.g. "Finishing Boxes", "Automatic Tapers"
 */
function extractDisplayCategory(wcCategories) {
  if (!wcCategories || !wcCategories.length) return '';

  const mapped = wcCategories
    .map((cat) => {
      const name = decodeHtmlEntities(cat.name);
      const normalizedName = name.toLowerCase();
      return CATEGORY_MAP[normalizedName] ? { name, normalizedName } : null;
    })
    .filter(Boolean);

  if (mapped.length > 0) {
    const nonGenericMapped = mapped.filter(({ normalizedName }) => !GENERIC_MAPPED_CATEGORY_NAMES.has(normalizedName));
    const preferred = nonGenericMapped.length > 0 ? nonGenericMapped : mapped;
    // Keep the human-readable mapped leaf category for category-card UI grouping.
    return preferred[preferred.length - 1].name;
  }

  return '';
}

/**
 * Extract the UPC/GTIN from WooCommerce product meta_data.
 * WooCommerce CSV importer stores UPC in meta_data under key 'upc'.
 *
 * @param {Array<{key:string, value:any}>} metaData
 * @returns {string}
 */
function extractUpc(metaData) {
  if (!metaData || !metaData.length) return '';
  const upcMeta = metaData.find(m =>
    m.key === 'upc' ||
    m.key === '_upc' ||
    m.key === 'gtin' ||
    m.key === 'Meta: upc'
  );
  return upcMeta ? String(upcMeta.value || '').trim() : '';
}

/**
 * Extract a brand name from a WooCommerce product.
 * Priority:
 *   1. Product attribute named "Brand"
 *   2. Any category whose name matches a known brand
 *   3. First product tag (legacy fallback)
 *
 * @param {Object} wcProduct
 * @returns {string}
 */
function extractBrand(wcProduct) {
  // 0. Product meta_data: _dtb_brand (stored by CSV importer via dtb-woocommerce.php)
  //    This is the most reliable source — set explicitly from the "Brands" CSV column.
  if (Array.isArray(wcProduct.meta_data) && wcProduct.meta_data.length) {
    const dtbEntry = wcProduct.meta_data.find(
      (m) => m.key === '_dtb_brand' || m.key === 'dtb_brand'
    );
    if (dtbEntry && dtbEntry.value) {
      const raw = String(dtbEntry.value).trim();
      return BRAND_ALIASES[raw.toLowerCase()] || raw;
    }
  }

  // 1. Explicit "Brand" attribute (set by the WooCommerce CSV importer)
  if (wcProduct.attributes && wcProduct.attributes.length) {
    const brandAttr = wcProduct.attributes.find(
      (a) => a.name && a.name.toLowerCase() === 'brand'
    );
    if (brandAttr && brandAttr.options && brandAttr.options.length) {
      const raw = brandAttr.options[0];
      // Normalize alias → canonical brand name (e.g. "Platinum" → "Platinum Drywall Tools")
      return BRAND_ALIASES[raw.toLowerCase()] || raw;
    }
  }

  // 2. Category whose name is one of our known brands
  //    (WooCommerce assigns products to every level of the hierarchy when
  //    importing via CSV, so "TapeTech" appears as a category object.)
  if (wcProduct.categories && wcProduct.categories.length) {
    for (const cat of wcProduct.categories) {
      const catName = decodeHtmlEntities(cat.name);
      // Try exact canonical match first, then alias normalization
      const match = KNOWN_BRANDS.find(b => b.toLowerCase() === catName.toLowerCase());
      if (match) return match;
      const alias = BRAND_ALIASES[catName.toLowerCase()];
      if (alias) return alias;
    }
  }

  // 3. Tags: only return a tag that matches a known brand name.
  //    Do NOT return the first arbitrary tag — tags frequently contain MPNs,
  //    SKUs, and other non-brand terms (e.g. "03TT", "4-760").
  if (wcProduct.tags && wcProduct.tags.length) {
    for (const tag of wcProduct.tags) {
      const tagName = decodeHtmlEntities(tag.name || '');
      const match = KNOWN_BRANDS.find(b => b.toLowerCase() === tagName.toLowerCase());
      if (match) return match;
      const alias = BRAND_ALIASES[tagName.toLowerCase()];
      if (alias) return alias;
    }
  }

  return '';
}

/**
 * Resolve the primary `variation_attribute` for a WooCommerce variation product.
 *
 * Prefers the first entry from the already-normalised `variation_attribute_values`
 * list (which strips empty names/options and normalises whitespace).  Falls back
 * to the first raw attribute object from the API when that list is empty.
 *
 * @param {string} productType
 * @param {Array<{name:string, option:string}>|null} variation_attribute_values
 * @param {Array<Object>|undefined} rawAttributes
 * @returns {{name:string, option:string}|null}
 */
function resolveVariationAttribute(productType, variation_attribute_values, rawAttributes) {
  if (productType !== 'variation') return null;
  if (variation_attribute_values && variation_attribute_values.length > 0) {
    return variation_attribute_values[0];
  }
  if (rawAttributes && rawAttributes.length > 0) {
    return rawAttributes[0];
  }
  return null;
}

function normalizeVariationAttributeOptions(options) {
  if (!options) return [];
  if (Array.isArray(options)) {
    return options.flatMap((value) => {
      if (typeof value !== 'string') return [];
      return value.split('|').map((item) => item.trim()).filter(Boolean);
    });
  }
  if (typeof options === 'string') {
    return options.split('|').map((item) => item.trim()).filter(Boolean);
  }
  return [];
}

function isVariationAttribute(attr) {
  if (!attr) return false;
  const name = `${attr.name || ''}`.trim().toLowerCase();
  if (!name || name === 'brand') return false;
  const options = normalizeVariationAttributeOptions(attr.options);
  return Boolean(attr.variation) || options.length > 1;
}

/**
 * Normalise a WooCommerce product object to the internal product shape used
 * throughout the UI.
 *
 * @param {Object} wcProduct  Raw product from the WooCommerce REST API
 * @returns {Object}          Normalised product
 */
export function normalizeProduct(wcProduct) {
  if (!wcProduct) return null;

  const rawImages = Array.isArray(wcProduct.images) ? wcProduct.images : [];
  const primaryRawImage = rawImages[0] ?? null;
  const image_srcset = typeof primaryRawImage === 'string'
    ? (wcProduct.image_srcset || '')
    : (primaryRawImage?.srcset || wcProduct.image_srcset || '');
  const image_sizes = typeof primaryRawImage === 'string'
    ? (wcProduct.image_sizes || '')
    : (primaryRawImage?.sizes || wcProduct.image_sizes || '');
  const image_thumbnail = typeof primaryRawImage === 'string'
    ? (wcProduct.image_thumbnail || '')
    : (primaryRawImage?.thumbnail || wcProduct.image_thumbnail || '');

  // Images: prefer array of src strings; keep single `image` for legacy compat.
  // Handle both WooCommerce image objects ({ src, id, ... }) and plain string URLs
  // so that this function is idempotent even if called on an already-normalised product.
  const images = rawImages
    .map((img) => (typeof img === 'string' ? img : (img?.src ?? '')))
    .filter(Boolean);
  if (images.length === 0) images.push(PLACEHOLDER_IMAGE);
  const image = images[0];

  const productType = wcProduct.type || 'simple';
  const isVariable  = productType === 'variable';

  // Price: prefer numeric parse; keep string fallback
  const priceRaw = wcProduct.price ?? wcProduct.regular_price ?? '';
  const priceNum = parseFloat(priceRaw);
  let price    = Number.isFinite(priceNum)
    ? priceNum
    : 0;

  // Category: map to internal key (e.g. "finishing") using the same CATEGORY_MAP
  // the CSV parser uses, so filtering works identically regardless of data source.
  const category = mapApiCategory(wcProduct.categories);

  // is_parts: true when any assigned category is a replacement-parts category
  const is_parts = isPartsFromApi(wcProduct.categories);

  // UPC: extract from meta_data (WC CSV importer stores it under key 'upc')
  const upc = extractUpc(wcProduct.meta_data);

  // ── Variable-product support ───────────────────────────────────────────────
  // WooCommerce REST API returns type: 'variable' for variable products and
  // type: 'variation' for individual variation records.
  // Variation-attribute options (only populated for variable products).

  // Variation-attribute options (only populated for variable products).
  // Each entry: { name: 'Size', options: ['7"', '10"', '12"'], id: 1 }
  const variation_attributes = isVariable
    ? (wcProduct.attributes || [])
        .filter(isVariationAttribute)
        .map((a) => ({
          id: a.id || 0,
          name: a.name || '',
          options: normalizeVariationAttributeOptions(a.options),
        }))
    : [];

  // For variable products, WC returns price_html with min/max range.
  // Use min_price / max_price when available, fall back to price.
  const min_price = wcProduct.min_price
    ? (parseFloat(wcProduct.min_price) || 0)
    : (isVariable ? (parseFloat(wcProduct.price) || 0) : null);
  if (isVariable && price === 0 && Number.isFinite(min_price) && min_price > 0) {
    price = min_price;
  }
  const max_price = wcProduct.max_price
    ? (parseFloat(wcProduct.max_price) || 0)
    : null;

  // Human-readable leaf category name for category-card grouping (e.g. "Finishing Boxes").
  const display_category = extractDisplayCategory(wcProduct.categories);
  const brand = extractBrand(wcProduct);

  // Synthesize spec metadata from truthful live product fields so the UI can
  // render a consistent specifications table even when curated _specs_* meta
  // hasn't been written into WooCommerce yet.
  const apiSpecRows = buildSpecificationsFromApiProduct(wcProduct, { brand });
  const apiSpecsMeta = buildSpecsMetaFromRows(apiSpecRows);
  const existingMeta = filterSpecificationMetaData(
    Array.isArray(wcProduct.meta_data) ? wcProduct.meta_data : []
  );
  const inferredIncludes = buildIncludesMetaFromContent(wcProduct.description || '', {
    sku: wcProduct.sku,
  });
  const mergedSpecMeta = mergeSpecMeta(
    mergeSpecMeta(existingMeta, apiSpecsMeta),
    inferredIncludes.specsMeta
  );
  const nonSpecMeta = existingMeta.filter(({ key }) => {
    const normalizedKey = String(key || '');
    return !/^_specs_\d+_(label|value)$/.test(normalizedKey)
      && !/^_includes_\d+_(name|sku)$/.test(normalizedKey);
  });
  const includeMeta = hasStructuredIncludesMeta(existingMeta)
    ? existingMeta.filter(({ key }) => /^_includes_\d+_(name|sku)$/.test(String(key || '')))
    : inferredIncludes.includesMeta;
  const meta_data = [...nonSpecMeta, ...mergedSpecMeta, ...includeMeta];

  // For individual variations: the parent product ID and selected attribute.
  const parent_id = wcProduct.parent_id || null;
  const variation_attribute_values = productType === 'variation'
    ? (wcProduct.attributes || [])
        .map((attr) => ({ name: (attr?.name || '').trim(), option: (attr?.option || '').trim() }))
        .filter((attr) => attr.name && attr.option)
    : null;
  const variation_attribute = resolveVariationAttribute(
    productType, variation_attribute_values, wcProduct.attributes
  );

  return {
    // Identity
    id:           wcProduct.id,
    part_number:  wcProduct.sku || String(wcProduct.id),
    sku:          wcProduct.sku || '',
    upc,
    slug:         wcProduct.slug || '',

    // Display
    name:         decodeHtmlEntities(wcProduct.name || wcProduct.sku || String(wcProduct.id)),
    brand,
    category,
    display_category,
    is_parts,
    categories:   wcProduct.categories || [],

    // Media
    image,
    images,
    image_srcset,
    image_sizes,
    image_thumbnail,

    // Pricing & inventory
    price,
    regular_price: wcProduct.regular_price || '',
    sale_price:    wcProduct.sale_price    || '',
    on_sale:       wcProduct.on_sale       || false,
    stock_status:  wcProduct.stock_status  || 'instock',
    manage_stock:  wcProduct.manage_stock  || false,
    stock_quantity: wcProduct.stock_quantity || null,

    // Descriptions
    short_description: wcProduct.short_description || '',
    description_full:  wcProduct.description       || '',

    // Attributes / meta (preserved for schematic / parts lookups)
    attributes: wcProduct.attributes || [],
    meta_data,

    // Variable-product fields
    type:                productType,
    is_variable:         isVariable,
    variation_attributes,
    variations:          wcProduct.variations || [],   // array of variation IDs
    min_price,
    max_price,
    parent_id,
    variation_attribute,  // selected attribute for a variation record
    variation_attribute_values,

    // Rating placeholder (WooCommerce provides this via reviews endpoint)
    rating:  Number(wcProduct.average_rating) || 0,
    reviews: Number(wcProduct.rating_count)   || 0,

    // Source tag — matches the '_source' field set by parseProductCsv.js
    _source: 'api',
  };
}

// ─── Products ────────────────────────────────────────────────────────────────

/**
 * Full-text search across products.
 * @param {string} searchTerm
 * @param {{signal?: AbortSignal}} options
 * @returns {Promise<Array>}
 */
export const searchProducts = (searchTerm, options = {}) =>
  fetchCatalogProducts(
    { search: String(searchTerm || '').trim(), page: 1, perPage: 50 },
    options,
  )
    .then((payload) => (Array.isArray(payload?.items) ? payload.items.map((item) => toLegacyProductCardDTO(item)) : []));

/**
 * Fetch all variations for a variable product.
 * Returns each variation through the canonical catalog DTO adapter.
 *
 * @param {number|string} parentId  WooCommerce parent product ID
 * @returns {Promise<Array>}        Array of normalised variation objects
 */
export const getProductVariations = (parentId) =>
  fetchCatalogProductVariations(parentId)
    .then((payload) => (Array.isArray(payload?.variations) ? payload.variations.map((item) => toLegacyVariationDTO(item)) : []));
