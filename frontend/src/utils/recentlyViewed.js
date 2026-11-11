/**
 * frontend/src/utils/recentlyViewed.js
 *
 * localStorage utility for tracking recently viewed products.
 *
 * Storage key: dtb_recently_viewed
 * Schema: Array of { id, slug, name, image, price, viewedAt }
 * Max items: 10 (oldest item dropped when limit exceeded)
 */

const STORAGE_KEY = 'dtb_recently_viewed';
const MAX_ITEMS   = 10;

/**
 * Read the current recently-viewed list from localStorage.
 * Returns an empty array on parse failure.
 *
 * @returns {{ id: number|string, slug: string, name: string, image: string, price: string, viewedAt: number }[]}
 */
export function getRecentlyViewed() {
  try {
    const raw = localStorage.getItem( STORAGE_KEY );
    if ( ! raw ) return [];
    const parsed = JSON.parse( raw );
    return Array.isArray( parsed ) ? parsed : [];
  } catch {
    return [];
  }
}

/**
 * Record a product view.  Moves existing entries to the top (most recent first)
 * and trims the list to MAX_ITEMS.
 *
 * @param {{ id: number|string, slug: string, name: string, image?: string, price?: string }} product
 */
export function addRecentlyViewed( product ) {
  if ( ! product?.id || ! product?.slug ) return;
  try {
    const entry = {
      id:       product.id,
      slug:     product.slug,
      name:     product.name || '',
      image:    product.image || '',
      price:    product.price || '',
      viewedAt: Date.now(),
    };
    const current = getRecentlyViewed().filter( ( p ) => String( p.id ) !== String( product.id ) );
    const updated = [ entry, ...current ].slice( 0, MAX_ITEMS );
    localStorage.setItem( STORAGE_KEY, JSON.stringify( updated ) );
    if ( typeof window !== 'undefined' ) {
      window.dispatchEvent( new CustomEvent( 'dtb:recently-viewed-change' ) );
    }
  } catch { /* noop — storage unavailable */ }
}

/** Record the common catalog and WooCommerce product shapes in the same
 * local history consumed by the account hub. */
export function recordRecentlyViewedProduct( product ) {
  if ( ! product?.id || ! product?.slug ) return;

  const imageCandidates = [
    product?.media?.images?.[0],
    product?.media?.image,
    product?.images?.[0],
    product?.image,
  ];
  const image = imageCandidates
    .map( ( candidate ) => typeof candidate === 'string' ? candidate : candidate?.src || candidate?.url || '' )
    .find( Boolean ) || '';
  const rawPrice = typeof product.price === 'object'
    ? product.price?.value ?? product.price?.min
    : product.price;
  const numericPrice = Number( String( rawPrice || '' ).replace( /[^\d.]/g, '' ) );

  addRecentlyViewed( {
    id: product.id,
    slug: product.slug,
    name: product.name || '',
    image,
    price: Number.isFinite( numericPrice ) && numericPrice > 0 ? `$${ numericPrice.toFixed( 2 ) }` : '',
  } );
}
