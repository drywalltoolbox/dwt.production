/**
 * frontend/src/data/catalogues.js
 *
 * Catalogue metadata for the native in-shell flipbook viewer
 * (/catalogues, /catalogues/:slug).
 *
 * Page counts and contents are grounded in the catalogue sources, not
 * invented:
 * - Columbia: 28 pages rendered from
 *   workspace/user/files/customercatalogue2024_compressed_0_egsq.pdf
 *   (Columbia Tools Product Catalogue 2024). Contents page numbers come
 *   from the catalogue's printed Tool Index (page 27 of the PDF); a
 *   printed page number is one less than the PDF page, so the PDF page
 *   is printed + 1. Compound Tubes (PDF 19), Corner Flushers (PDF 21)
 *   and Phantom Sander (PDF 22) are the headings on those pages.
 * - TapeTech: 110 pages, the exact page count in the flipbook-data JSON
 *   of TapeTech-Product-Catalog_ENG_web_compressed-flipbook_1_6cbg.html
 *   (110 distinct full-page images). That source has an empty outline
 *   and no links, so TapeTech has no verified contents index; the
 *   viewer offers thumbnails and page jump instead of invented sections.
 *
 * Brand shop links use the verified WooCommerce brand keys from the
 * official catalogue export: columbia-taping-tools and tapetech.
 * Never link to /products/brands/columbia — that slug does not exist.
 */

const PUBLIC_BASE = (process.env.PUBLIC_URL || '').replace(/\/+$/, '');

function pageUrl(slug, page) {
  return `${PUBLIC_BASE}/catalogues/${slug}/page-${String(page).padStart(2, '0')}.jpg`;
}

export const CATALOGUES = [
  {
    slug: 'columbia',
    title: 'Columbia Tools Product Catalogue 2024',
    brand: 'Columbia Tools',
    description:
      'The Columbia Tools Product Catalogue 2024 — tool sets, automatic tapers, corner rollers, angle heads, throttle boxes, flat boxes, nail spotters, mud pumps, Tomahawk smoothing blades, handles, compound tubes, corner flushers, sanders, tool cases and maintenance kits.',
    pageCount: 28,
    orientation: 'landscape',
    shopLink: '/products/brands/columbia-taping-tools',
    shopLabel: 'Shop Columbia Tools',
    contents: [
      { label: 'Sets', page: 4 },
      { label: 'Automatic Tapers', page: 6 },
      { label: 'Corner Rollers', page: 8 },
      { label: 'Angle Heads', page: 10 },
      { label: 'Throttle Boxes', page: 11 },
      { label: 'Flat Boxes', page: 12 },
      { label: 'Nail Spotters', page: 14 },
      { label: 'Mud Pumps', page: 15 },
      { label: 'Tomahawk Smoothing Blades', page: 16 },
      { label: 'Columbia One', page: 17 },
      { label: 'Flat Box Handles', page: 18 },
      { label: 'Compound Tubes', page: 19 },
      { label: 'Corner Flushers', page: 21 },
      { label: 'Phantom Sander', page: 22 },
      { label: 'Tool Cases', page: 23 },
      { label: 'Maintenance Kits', page: 24 },
      { label: 'Tool Index', page: 27 },
    ],
  },
  {
    slug: 'tapetech',
    title: 'TapeTech Product Catalog',
    brand: 'TapeTech',
    description:
      'The TapeTech Product Catalog — automatic taping and finishing tools, tool sets, boxes, handles and accessories. Browse all 110 pages in the flipbook, then shop the TapeTech range.',
    pageCount: 110,
    orientation: 'portrait',
    shopLink: '/products/brands/tapetech',
    shopLabel: 'Shop TapeTech',
    // No verified contents index exists for this catalogue (see header).
    contents: [],
  },
];

export function getCatalogue(slug) {
  return CATALOGUES.find((catalogue) => catalogue.slug === slug) || null;
}

export function cataloguePageUrl(catalogue, page) {
  return pageUrl(catalogue.slug, page);
}
