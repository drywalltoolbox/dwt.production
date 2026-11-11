const EXCLUDED_CUSTOMER_SPEC_LABELS = new Set([
  'manufacturer sku',
  'official product name',
  'display category',
  'display category key',
]);

export function normalizeSpecificationLabel(label) {
  return String(label ?? '')
    .replace(/<[^>]*>/g, ' ')
    .replace(/&nbsp;|&#0*160;|&#x0*a0;/gi, ' ')
    .replace(/&amp;/gi, ' and ')
    .normalize('NFKC')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, ' ')
    .trim();
}

export function isCustomerFacingSpecificationLabel(label) {
  const normalized = normalizeSpecificationLabel(label);
  return Boolean(normalized) && !EXCLUDED_CUSTOMER_SPEC_LABELS.has(normalized);
}

function filterCanonicalSpecificationRows(value) {
  let rows;
  const wasString = typeof value === 'string';

  try {
    rows = wasString ? JSON.parse(value) : value;
  } catch {
    return null;
  }

  if (!Array.isArray(rows)) return null;

  const safeRows = rows.filter((spec) => (
    !spec || typeof spec !== 'object' || Array.isArray(spec)
      || !spec.label || isCustomerFacingSpecificationLabel(spec.label)
  ));

  return wasString ? JSON.stringify(safeRows) : safeRows;
}

/** Remove prohibited specification rows without changing product identity metadata. */
export function filterSpecificationMetaData(metaData = []) {
  if (!Array.isArray(metaData)) return [];

  const excludedIndexes = new Set();
  metaData.forEach((entry) => {
    const key = String(entry?.key || '').trim();
    const match = key.match(/^_specs_(\d+)_label$/);
    if (match && !isCustomerFacingSpecificationLabel(entry?.value)) {
      excludedIndexes.add(match[1]);
    }
  });

  return metaData.flatMap((entry) => {
    const key = String(entry?.key || '').trim();
    if (!key) return [];

    if (key === '_dtb_specs_json' || key === 'dtb_specs_json') {
      const value = filterCanonicalSpecificationRows(entry?.value);
      return value === null ? [] : [{ ...entry, value }];
    }

    const indexed = key.match(/^_specs_(\d+)_(label|value)$/);
    if (indexed && excludedIndexes.has(indexed[1])) return [];
    if (indexed && indexed[2] === 'label' && !isCustomerFacingSpecificationLabel(entry?.value)) return [];

    return [entry];
  });
}
