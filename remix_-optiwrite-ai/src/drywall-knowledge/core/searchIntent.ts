export const SEARCH_INTENT_RULES = [
  'Build search language from exact brand, product type, documented size, model/series, and part identity.',
  'Prefer natural trade queries over keyword repetition.',
  'Use contextual synonyms only when they are technically valid for the domain and do not alter product identity.',
  'For replacement parts, exact SKU/MPN and compatible tool-family intent may be more valuable than broad category language.',
  'For tool sets, search intent should describe verified major tool types or workflow coverage rather than unsupported complete-system claims.',
  'Never introduce an unsupported size, compatibility term, model family, or trademark merely because it is a common search query.'
] as const;
