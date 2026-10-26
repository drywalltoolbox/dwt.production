const CATEGORY_THUMBNAIL_ROOT = 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/categories/thumbnails';

// Existing media filenames are retained as assets. Canonical taxonomy slugs
// resolve to the closest current image without making media filenames a
// classification authority.
const CATEGORY_THUMBNAIL_SLUGS = new Set([
  'angle-heads',
  'automatic-tapers',
  'automatic-taping-tool-cases',
  'automatic-taping-tool-sets',
  'automatic-taping-tools',
  'angle-boxes',
  'box-fillers',
  'compound-applicators',
  'compound-tubes',
  'corner-boxes',
  'corner-flushers',
  'corner-finishers',
  'corner-rollers',
  'corner-tool-handles',
  'extendable-handles',
  'fixed-handles',
  'flat-box-handles',
  'flat-boxes',
  'goosenecks',
  'loading-pumps',
  'nail-spotters',
  'taping-tool-accessories',
  'tool-cases',
  'semi-automatic-accessories',
  'semi-automatic-tapers',
  'semi-automatic-taping-tool-sets',
  'semi-automatic-taping-tools',
  'semi-automatic-tools',
  'semi-automatic-tool-cases',
  'stilts',
]);

const CATEGORY_THUMBNAIL_FILE_BY_SLUG = {
  // Curated replacement art retains its supplied filename and intrinsic
  // dimensions. This map keeps taxonomy identity (the slug) separate from
  // media identity, including the supplied `corner-finshers` filename.
  'automatic-tapers': 'automatic-tapers',
  'powered-compound-applicators': 'powered-compound-applicators',
  'compound-applicators': 'compound-applicators',
  'compound-tubes': 'compound-tubes',
  'corner-finishers': 'corner-finshers',
  'finishing-boxes': 'finishing-box',
  'flat-boxes': 'finishing-box',
  'corner-applicators-angle-boxes': 'corner-applicators',
  'applicator-heads': 'compound-applicators',
  'corner-flushers': 'corner-flushers',
  'corner-rollers': 'corner-rollers',
  'loading-compound-pumps': 'loading-pumps',
  'loading-pumps': 'loading-pumps',
  'automatic-goosenecks-box-fillers': 'goosenecks-box-fillers-adapters',
  'goosenecks-box-fillers-adapters': 'goosenecks-box-fillers-adapters',
  'handles-extensions': 'handles-extensions',
  'handles': 'handles-extensions',
  'tool-sets-kits': 'automatic-taping-tool-sets',
  'automatic-tool-sets-kits': 'automatic-taping-tool-sets',
  'semi-automatic-tool-sets-kits': 'semi-automatic-taping-tool-sets',
  'tool-storage-cases': 'automatic-taping-tool-cases',
  'semi-automatic-tapers-banjos': 'semi-automatic-tapers',
  'drywall-stilts': 'stilts',

  // Historical URL/term compatibility.
  'angle-boxes': 'corner-boxes',
  'angle-boxes-corner-applicators': 'corner-applicators',
  'angle-heads-corner-finishers': 'angle-heads',
  'automatic-tool-sets': 'automatic-taping-tool-sets',
  'corner-tool-handles': 'fixed-handles',
  'goosenecks-box-fillers': 'box-fillers',
  'semi-automatic-taping-tool-accessories': 'semi-automatic-accessories',
  'semi-automatic-tool-sets': 'semi-automatic-taping-tool-sets',
  'semi-automatic-tools': 'semi-automatic-tapers',
  'semi-automatic-taping-tools': 'semi-automatic-tapers',
  'taping-tool-accessories': 'semi-automatic-accessories',
  'tool-cases': 'automatic-taping-tool-cases',
  'tool-sets': 'automatic-taping-tool-sets',
  'tool-sets-automatic-taping-tools': 'automatic-taping-tool-sets',
};

export function resolveCategoryThumbnail(category) {
  const slug = String(category?.slug || category?.key || '')
    .trim()
    .toLowerCase()
    .replace(/_/g, '-');

  const configuredThumbnail = CATEGORY_THUMBNAIL_FILE_BY_SLUG[slug];
  const thumbnailSlug = configuredThumbnail || slug;
  if (configuredThumbnail || CATEGORY_THUMBNAIL_SLUGS.has(thumbnailSlug)) {
    return `${CATEGORY_THUMBNAIL_ROOT}/${thumbnailSlug}.webp`;
  }

  return category?.image || '';
}
