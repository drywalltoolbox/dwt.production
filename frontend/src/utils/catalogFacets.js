import { brandToSlug, canonicalBrandLabel, canonicalDisplayCategorySlug, sortBrandsBy } from './catalogUrlState.js';

const LEGACY_CATEGORY_SLUG_ALIASES = {
  'stilts-accessories': 'drywall-stilts',
  'stilts': 'drywall-stilts',
  'bead-tools': 'bead-hoppers',
  'tool-storage-cases': 'tool-cases',
  'drywall-sealants': 'sealants',
  'automatic-taping-tool-sets': 'tool-sets-kits',
  'automatic-tool-sets': 'tool-sets-kits',
  'tool-sets': 'tool-sets-kits',
  'tool-sets-automatic-taping-tools': 'tool-sets-kits',
  'semi-automatic-tools': 'semi-automatic-tapers-banjos',
  'semi-automatic-taping-tools': 'semi-automatic-tapers-banjos',
  'semi-automatic-taping-tool-sets': 'tool-sets-kits',
  'semi-automatic-tool-sets': 'tool-sets-kits',
  'angle-heads': 'corner-finishers',
  'corner-applicators-angle-boxes': 'angle-boxes-corner-applicators',
  'loading-compound-pumps': 'loading-pumps',
  'goosenecks-box-fillers': 'goosenecks-box-fillers-adapters',
  'automatic-handles-extensions': 'handles',
  'handles-extensions': 'handles',
};

export function canonicalCatalogCategorySlug(value) {
  const slug = String(value || '')
    .trim()
    .toLowerCase()
    .replace(/_/g, '-');
  return LEGACY_CATEGORY_SLUG_ALIASES[slug] || slug;
}

export function normalizeDisplayCategorySlug(value) {
  return String(value || '')
    .toLowerCase()
    .replace(/[^\w]+/g, '_')
    .replace(/_+/g, '_')
    .replace(/^_+|_+$/g, '');
}

export function buildDisplayCategoryUrl(slug) {
  return `/products?display_category=${encodeURIComponent(slug)}`;
}

export function buildCatalogCategoryUrl(slug) {
  return `/products?category=${encodeURIComponent(canonicalCatalogCategorySlug(slug))}`;
}

export function buildCategoryPageUrl(slug) {
  return `/category/${encodeURIComponent(canonicalCatalogCategorySlug(slug))}`;
}

export function normalizeCatalogBrandEntry(rawBrand = {}) {
  const label = canonicalBrandLabel(rawBrand.label || rawBrand.name || rawBrand.key || rawBrand.slug || '');
  if (!label) return null;
  const slug = brandToSlug(label);
  if (!slug) return null;
  const productCount = Number(rawBrand.productCount || rawBrand.count || 0);
  return {
    ...rawBrand,
    key: slug,
    label,
    name: label,
    slug,
    productCount,
    count: productCount,
  };
}

export function dedupeCatalogBrandEntries(rawBrands = []) {
  const bySlug = new Map();
  (Array.isArray(rawBrands) ? rawBrands : []).forEach((rawBrand) => {
    const brand = normalizeCatalogBrandEntry(rawBrand);
    if (!brand) return;
    const existing = bySlug.get(brand.slug);
    if (!existing) {
      bySlug.set(brand.slug, brand);
      return;
    }
    const productCount = Math.max(existing.productCount || 0, brand.productCount || 0);
    bySlug.set(brand.slug, {
      ...existing,
      logo: existing.logo || brand.logo,
      image: existing.image || brand.image,
      imageUrl: existing.imageUrl || brand.imageUrl,
      productCount,
      count: productCount,
    });
  });
  return sortBrandsBy(Array.from(bySlug.values()), 'label');
}

export function toCatalogBrand(rawBrand = {}) {
  const brand = normalizeCatalogBrandEntry(rawBrand);
  if (!brand) return null;
  return { name: brand.name, slug: brand.slug, count: brand.productCount };
}

export function mapCatalogBrands(rawBrands = []) {
  return dedupeCatalogBrandEntries(rawBrands)
    .map((brand) => ({ name: brand.name, slug: brand.slug, count: brand.productCount }));
}

export function mergeCatalogDisplayCategories(displayCategoriesByBrand = {}) {
  const merged = new Map();
  Object.values(displayCategoriesByBrand || {}).forEach((items) => {
    if (!Array.isArray(items)) return;
    items.forEach((item) => {
      const slug = canonicalDisplayCategorySlug(item?.slug || item?.key);
      if (!slug) return;
      const count = Number(item?.productCount || item?.count || 0);
      const existing = merged.get(slug);
      merged.set(slug, {
        slug,
        label: item?.label || item?.name || item?.key || slug,
        count: Math.max(existing?.count || 0, count),
      });
    });
  });
  return Array.from(merged.values())
    .filter((item) => item.count > 0)
    .sort((a, b) => (b.count - a.count) || a.label.localeCompare(b.label));
}

export function normalizeCatalogNavigationGroups(rawGroups = []) {
  const seenGroups = new Set();
  const seenChildren = new Set();

  return (Array.isArray(rawGroups) ? rawGroups : [])
    .map((rawGroup) => {
      const label = rawGroup?.label || rawGroup?.name || '';
      const slug = canonicalCatalogCategorySlug(rawGroup?.slug || rawGroup?.key || '');
      if (!label || !slug || seenGroups.has(slug)) return null;
      seenGroups.add(slug);

      const children = (Array.isArray(rawGroup?.children) ? rawGroup.children : [])
        .map((rawChild) => {
          const childLabel = rawChild?.label || rawChild?.name || '';
          const childSlug = canonicalCatalogCategorySlug(rawChild?.slug || rawChild?.key || '');
          if (!childLabel || !childSlug || seenChildren.has(childSlug)) return null;
          seenChildren.add(childSlug);
          return {
            ...rawChild,
            label: childLabel,
            slug: childSlug,
            count: Number(rawChild?.productCount || rawChild?.count || 0),
            to: buildCategoryPageUrl(childSlug),
          };
        })
        .filter(Boolean);
      return {
        ...rawGroup,
        label,
        slug,
        count: Number(rawGroup?.productCount || rawGroup?.count || 0),
        to: buildCategoryPageUrl(slug),
        children,
      };
    })
    .filter(Boolean);
}

export function flattenCatalogNavigationGroups(rawGroups = []) {
  return normalizeCatalogNavigationGroups(rawGroups).flatMap((group) => {
    const children = Array.isArray(group.children) ? group.children : [];
    return children.length > 0 ? children : [group];
  });
}

export function normalizeCatalogCategoryEntry(category) {
  if (typeof category === 'string') {
    return {
      label: category,
      slug: canonicalCatalogCategorySlug(normalizeDisplayCategorySlug(category)),
    };
  }
  const label = category?.label || category?.name || '';
  const slug = canonicalCatalogCategorySlug(category?.slug || category?.key || normalizeDisplayCategorySlug(label));
  return { label, slug };
}
