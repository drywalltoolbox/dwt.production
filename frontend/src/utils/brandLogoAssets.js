import tapeTechLogo from '/brands/TapeTech/tapetech_logo.svg';
import columbiaLogo from '/brands/Columbia/columbia_taping_tools_logo.svg';
import surproLogo from '/brands/SurPro/surpro_logo.svg';
import asgardLogo from '/brands/Asgard/asgard_logo.svg';
import gracoLogo from '/brands/Graco/graco_logo.svg';
import platinumLogo from '/brands/Platinum/platinum_logo.svg';
import duraStiltsLogo from '/brands/Dura-Stilts/dura-stilts-logo.svg';
import level5Logo from '/brands/Level5/Level5.svg';
const usgSheetrockToolsLogo = 'https://drywalltoolbox.com/staging/assets/images/usg-sheetrock-tools.2ee7ec43.svg';

/*
 * Product brand discovery intentionally preserves the exact asset mapping that
 * existed before selector presentation was centralized. Products and
 * schematics share selector-card presentation, not preview-image ownership.
 */
const PRODUCT_BRAND_LOGOS = {
  TapeTech: tapeTechLogo,
  'Columbia Taping Tools': columbiaLogo,
  'Columbia Tools': columbiaLogo,
  Columbia: columbiaLogo,
  SurPro: surproLogo,
  Asgard: asgardLogo,
  Graco: gracoLogo,
  'Platinum Drywall Tools': platinumLogo,
  Platinum: platinumLogo,
  'Dura-Stilts': duraStiltsLogo,
  Level5: level5Logo,
  'Level 5': level5Logo,
  'USG Sheetrock® Tools': usgSheetrockToolsLogo,
  'USG Sheetrock Tools': usgSheetrockToolsLogo,
  USG: usgSheetrockToolsLogo,
  'usg-sheetrock-tools': usgSheetrockToolsLogo,
};

const BRAND_LOGO_MATCHERS = [
  { test: /durastilts?/, logo: duraStiltsLogo },
  { test: /tapetech/, logo: tapeTechLogo },
  { test: /columbia/, logo: columbiaLogo },
  { test: /surpro/, logo: surproLogo },
  { test: /asgard/, logo: asgardLogo },
  { test: /graco/, logo: gracoLogo },
  { test: /platinum/, logo: platinumLogo },
  { test: /level5/, logo: level5Logo },
  { test: /usgsheetrocktools|usgsheetrock|^usg$/, logo: usgSheetrockToolsLogo },
];

export function normalizeBrandAssetKey(value = '') {
  return String(value || '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]/g, '');
}

/**
 * Exact resolver for the product Brands selector. This intentionally mirrors
 * the pre-centralization Product selector contract so its preview asset URLs
 * cannot drift to Schematics' fuzzy brand-name mapping.
 */
export function resolveProductBrandLogo(brand = {}) {
  if (!brand || typeof brand !== 'object') return '';
  return PRODUCT_BRAND_LOGOS[brand.label]
    || PRODUCT_BRAND_LOGOS[brand.key]
    || brand.logo
    || '';
}

/**
 * Flexible resolver retained for schematics and other non-product consumers,
 * where REST brand names can vary in punctuation and casing.
 */
export function resolveBrandLogo(brandOrName) {
  const candidates = brandOrName && typeof brandOrName === 'object'
    ? [brandOrName.label, brandOrName.name, brandOrName.key, brandOrName.slug, brandOrName.id]
    : [brandOrName];

  for (const candidate of candidates) {
    const normalized = normalizeBrandAssetKey(candidate);
    if (!normalized) continue;
    const match = BRAND_LOGO_MATCHERS.find(({ test }) => test.test(normalized));
    if (match) return match.logo;
  }

  if (brandOrName && typeof brandOrName === 'object' && brandOrName.logo) {
    return brandOrName.logo;
  }

  return '';
}
