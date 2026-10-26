const TOOL_SET_CATEGORY_KEYS = new Set([
  'automatic-tool-sets',
  'automatic-tool-sets-kits',
  'semi-automatic-tool-sets-kits',
  'toolsets',
  'tool-sets-kits',
  'tool-sets-and-kits',
]);

function normalizeCategoryKey(value = '') {
  return String(value || '').trim().toLowerCase().replace(/[_\s]+/g, '-');
}

function productMetaItems(product = {}) {
  if (Array.isArray(product?.metaData)) return product.metaData;
  if (Array.isArray(product?.meta_data)) return product.meta_data;
  return [];
}

export function isToolSetProduct(product = {}) {
  const candidates = [
    product?.display_category,
    product?.displayCategory?.slug,
    product?.displayCategory?.key,
    product?.category,
    product?.category?.slug,
    product?.category?.key,
  ].filter(Boolean).map(normalizeCategoryKey);

  return candidates.some((candidate) => TOOL_SET_CATEGORY_KEYS.has(candidate));
}

export function getStructuredIncludedItems(product = {}) {
  const byIndex = new Map();

  productMetaItems(product).forEach((entry) => {
    const key = String(entry?.key || '').trim();
    const match = key.match(/^_includes_(\d+)_(name|sku)$/);
    if (!match) return;

    const index = Number.parseInt(match[1], 10);
    if (!Number.isInteger(index)) return;

    const field = match[2];
    const value = String(entry?.value ?? '').trim();
    if (!value) return;

    const current = byIndex.get(index) || { index, name: '', sku: '' };
    current[field] = value;
    byIndex.set(index, current);
  });

  return [...byIndex.values()]
    .filter((item) => item.name)
    .sort((a, b) => a.index - b.index)
    .map(({ name, sku }) => ({ name, sku: sku || '' }));
}

export function getToolSetContentsSummary(product = {}, maxVisible = 3) {
  if (!isToolSetProduct(product)) return null;

  const items = getStructuredIncludedItems(product);
  if (!items.length) return null;

  const boundedMax = Math.max(1, Math.min(Number(maxVisible) || 3, 5));
  return {
    count: items.length,
    items,
    visibleItems: items.slice(0, boundedMax),
    hiddenCount: Math.max(0, items.length - boundedMax),
  };
}
