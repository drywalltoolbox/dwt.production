/**
 * csvSpecificationMapping.js
 *
 * Normalize truthful catalog/product fields into _specs_* metadata consumed by
 * TechnicalSpecifications.jsx. Supports both CSV-row input and live
 * WooCommerce API product objects.
 */

import { isCustomerFacingSpecificationLabel } from './productSpecificationPolicy.js';

function clean(value) {
  return String(value ?? '').trim();
}

function normalizeLabel(label) {
  return clean(label).toLowerCase();
}

function splitOptions(value) {
  return clean(value)
    .split('|')
    .map((part) => clean(part))
    .filter(Boolean);
}

function formatDimension(value, unit) {
  const raw = clean(value);
  if (!raw) return '';
  return `${raw} ${unit}`;
}

function mapProductType(type) {
  const key = normalizeLabel(type);
  if (key === 'simple') return 'Standard product';
  if (key === 'variable') return 'Variable product';
  if (key === 'variation') return 'Product variation';
  return clean(type);
}

function normalizeCondition(value) {
  const raw = clean(value);
  if (!raw) return '';
  if (/newcondition/i.test(raw)) return 'New';
  return raw.replace(/condition$/i, '').trim() || raw;
}

function createRow(label, value) {
  const normalizedLabel = clean(label);
  const normalizedValue = Array.isArray(value)
    ? value.filter(Boolean)
    : clean(value);

  if (!isCustomerFacingSpecificationLabel(normalizedLabel)) return null;
  if (Array.isArray(normalizedValue) && normalizedValue.length === 0) return null;
  if (!Array.isArray(normalizedValue) && !normalizedValue) return null;

  return { label: normalizedLabel, value: normalizedValue };
}

function collectCsvAttributeRows(row, attrIndexes = []) {
  const rows = [];

  for (const n of attrIndexes) {
    const name = clean(row[`Attribute ${n} name`]);
    const value = clean(row[`Attribute ${n} value(s)`]);
    if (!name || !value) continue;

    const normalized = normalizeLabel(name);
    if (normalized === 'brand') continue;

    const options = splitOptions(value);
    rows.push(createRow(name, options.length > 1 ? options : value));
  }

  return rows.filter(Boolean);
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

function extractMetaValue(metaData, keys) {
  if (!Array.isArray(metaData) || metaData.length === 0) return '';
  const keyList = Array.isArray(keys) ? keys : [keys];
  const entry = metaData.find(({ key }) => keyList.includes(key));
  return clean(entry?.value);
}

function collectApiAttributeRows(product) {
  const attributes = Array.isArray(product?.attributes) ? product.attributes : [];

  return attributes
    .map((attr) => {
      const name = clean(attr?.name);
      if (!name || normalizeLabel(name) === 'brand') return null;

      const options = normalizeVariationAttributeOptions(attr?.options);
      if (options.length === 0) {
        const option = clean(attr?.option);
        return createRow(name, option);
      }

      return createRow(name, options.length > 1 ? options : options[0]);
    })
    .filter(Boolean);
}

export function mergeSpecRows(primaryRows = [], fallbackRows = []) {
  const merged = [];
  const seen = new Set();

  const pushRow = (row) => {
    if (!isCustomerFacingSpecificationLabel(row?.label)) return;
    const key = normalizeLabel(row.label);
    if (seen.has(key)) return;
    seen.add(key);
    merged.push(row);
  };

  primaryRows.forEach(pushRow);
  fallbackRows.forEach(pushRow);

  return merged;
}

export function extractSpecRowsFromMeta(metaData = []) {
  const rows = [];
  const byIndex = new Map();

  metaData.forEach(({ key, value }) => {
    const match = String(key || '').match(/^_specs_(\d+)_(label|value)$/);
    if (!match) return;
    const [, index, part] = match;
    const row = byIndex.get(index) || {};
    row[part] = value;
    byIndex.set(index, row);
  });

  for (const [, row] of byIndex) {
    if (!isCustomerFacingSpecificationLabel(row.label) || !row.value) continue;
    rows.push({ label: clean(row.label), value: clean(row.value) });
  }

  return rows;
}

export function buildSpecificationsFromCsvRow(row, attrIndexes = []) {
  const coreRows = [
    createRow('SKU', row['SKU']),
    createRow('Product Family', row['meta:product_family']),
    createRow('Series', row['meta:series']),
    createRow('Product Type', mapProductType(row['Type'])),
    createRow('Weight', formatDimension(row['Weight (lbs)'], 'lbs')),
    createRow('Length', formatDimension(row['Length (in)'], 'in')),
    createRow('Width', formatDimension(row['Width (in)'], 'in')),
    createRow('Height', formatDimension(row['Height (in)'], 'in')),
    createRow('Condition', normalizeCondition(row['meta:schema_condition'])),
  ].filter(Boolean);

  const attributeRows = collectCsvAttributeRows(row, attrIndexes);
  return mergeSpecRows(coreRows, attributeRows);
}

export function buildSpecificationsFromApiProduct(product) {
  const metaData = Array.isArray(product?.meta_data) ? product.meta_data : [];
  const sku = clean(product?.sku);
  const weight = clean(product?.weight);
  const dimensions = product?.dimensions || {};

  const coreRows = [
    createRow('SKU', sku),
    createRow('Product Family', extractMetaValue(metaData, ['meta:product_family', '_product_family'])),
    createRow('Series', extractMetaValue(metaData, ['meta:series', '_series'])),
    createRow('Product Type', mapProductType(product?.type)),
    createRow('Weight', formatDimension(weight, 'lbs')),
    createRow('Length', formatDimension(dimensions.length, 'in')),
    createRow('Width', formatDimension(dimensions.width, 'in')),
    createRow('Height', formatDimension(dimensions.height, 'in')),
    createRow('Condition', normalizeCondition(extractMetaValue(metaData, ['meta:schema_condition', '_condition']))),
  ].filter(Boolean);

  const attributeRows = collectApiAttributeRows(product);
  return mergeSpecRows(coreRows, attributeRows);
}

export function buildSpecsMetaFromRows(rows) {
  return (Array.isArray(rows) ? rows : []).filter((spec) => (
    isCustomerFacingSpecificationLabel(spec?.label)
  )).flatMap((spec, index) => {
    const value = Array.isArray(spec.value) ? spec.value.join(', ') : spec.value;
    return [
      { key: `_specs_${index}_label`, value: spec.label },
      { key: `_specs_${index}_value`, value },
    ];
  });
}

export function mergeSpecMeta(primaryMeta = [], fallbackMeta = []) {
  const primaryRows = extractSpecRowsFromMeta(primaryMeta);
  const fallbackRows = extractSpecRowsFromMeta(fallbackMeta);
  return buildSpecsMetaFromRows(mergeSpecRows(primaryRows, fallbackRows));
}
