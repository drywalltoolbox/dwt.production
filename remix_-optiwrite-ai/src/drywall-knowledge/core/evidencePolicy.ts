export const EVIDENCE_POLICY = [
  'Canonical SKU-level catalog evidence is authoritative for the exact product.',
  'Verified official manufacturer evidence may supplement catalog evidence when research is enabled and provenance is retained.',
  'Domain knowledge explains trade meaning and buyer relevance; it never proves that a specific SKU has a feature, material, dimension, compatibility, capacity, inclusion, or performance characteristic.',
  'Existing product copy is reference material, not proof of unsupported claims.',
  'Generic workflow relationships may be explained, but exact cross-brand or cross-model fitment requires explicit product evidence.',
  'Package contents require structured inclusion evidence or authoritative manufacturer evidence; mentions, upsells, cross-sells, and prose SKU references do not establish inclusion.',
  'When evidence cannot answer a buyer question, omit the answer rather than infer it.',
  'A sparse evidence packet may still produce precise concise copy; do not pad it with generic trade knowledge.'
] as const;

export const FACTS_REQUIRING_PRODUCT_EVIDENCE = [
  'dimensions and weight', 'materials and coatings', 'capacity', 'compatible SKUs or models', 'included items',
  'adjustment ranges', 'blade or wheel construction', 'warranty terms', 'certifications', 'country of origin',
  'service intervals', 'productivity or durability claims', 'manufacturer-specific mechanism names'
] as const;
