import type { ProductFeatureProposal, ProductSpecificationProposal } from './aiService';

export const CATALOG_CONTENT_FIELDS = {
  description: 'Description',
  shortDescription: 'Short description',
  specifications: 'Meta: _dtb_specs_json',
  features: 'Meta: _dtb_features_json',
  contentVersion: 'Meta: _dtb_content_schema_version'
} as const;

/** Serialize only the importer-approved v1 feature document shape. */
export function serializeFeatures(features: ProductFeatureProposal[]): string {
  const cleaned = features
    .map(({ label, description }) => ({ label: label.trim(), description: description.trim() }))
    .filter(feature => feature.label && feature.description);
  return JSON.stringify({ schema_version: 1, features: cleaned });
}

/** Serialize only canonical customer-facing specification keys. */
export function serializeSpecifications(specifications: ProductSpecificationProposal[]): string {
  const cleaned = specifications.flatMap(({ label, value, items }) => {
    const cleanLabel = label.trim();
    const cleanValue = String(value || '').trim();
    const cleanItems = (items || [])
      .map(({ name, sku }) => ({ name: name.trim(), ...(sku?.trim() ? { sku: sku.trim() } : {}) }))
      .filter(item => item.name);
    if (!cleanLabel || (!cleanValue && !cleanItems.length)) return [];
    return [{ label: cleanLabel, ...(cleanValue ? { value: cleanValue } : {}), ...(cleanItems.length ? { items: cleanItems } : {}) }];
  });
  return JSON.stringify(cleaned);
}
