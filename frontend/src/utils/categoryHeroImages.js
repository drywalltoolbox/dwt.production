/**
 * Category hero artwork resolver for `/category/:slug` pages.
 *
 * Runtime delivery is owned by the live WordPress uploads directory:
 *   /wp-content/uploads/2026/categories/heroes/
 *
 * New hero files should use the exact WooCommerce category slug as the
 * filename: <category-slug>.webp. That convention allows the frontend to
 * resolve hero media without bundling binary assets or maintaining one import
 * per category.
 *
 * `products/launch/media/categories/heroes/` is a local authoring/reference
 * workspace only. Files placed there are not assumed to exist on the live
 * server until they are uploaded/deployed to the uploads directory above.
 *
 * WordPress category hero metadata remains a fallback because it provides a
 * confirmed media URL plus srcset when a category is managed through WP-Admin.
 */

const CATEGORY_HERO_UPLOAD_BASE = '/wp-content/uploads/2026/categories/heroes';

const CATEGORY_HERO_FILENAME_ALIASES = {
  // Legacy live filenames. New files must use the exact category slug.
  'compound-applicators': 'compound-applicator',
  'corner-applicators-angle-boxes': 'corner-applicators',
  'angle-boxes-corner-applicators': 'corner-applicators',
  'loading-compound-pumps': 'loading-pumps',
  'handles': 'handles-extensions',
};

function getHeroFilename(slug) {
  if (!slug) return '';
  return CATEGORY_HERO_FILENAME_ALIASES[slug] || slug;
}

function getLiveHeroUrl(slug) {
  const filename = getHeroFilename(slug);
  return filename ? `${CATEGORY_HERO_UPLOAD_BASE}/${encodeURIComponent(filename)}.webp` : '';
}

export function resolveCategoryHeroImage(category) {
  const slug = category?.slug || '';
  const liveHero = getLiveHeroUrl(slug);

  return {
    src: liveHero || category?.heroImage || '',
    srcSet: liveHero ? '' : (category?.heroImageSrcset || ''),
    fallbackSrc: liveHero && category?.heroImage ? category.heroImage : '',
    fallbackSrcSet: liveHero && category?.heroImage ? (category.heroImageSrcset || '') : '',
  };
}
