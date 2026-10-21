import { normalizeBrandAssetKey, resolveBrandLogo } from './brandLogoAssets.js';
import asgardSelectorLogo from '/brands/Asgard/asgard_logo2.svg';

const sitegroundLogoAsset = (filename) => `/logos/${filename}`;

export const FEATURED_BRAND_PRESENTATION = {
  asgard: {
    logo: asgardSelectorLogo,
    className: 'products-brand-selector__card--asgard',
    cardStyle: {
      backgroundColor: 'rgba(0, 116, 191, 1)',
      padding: 0,
    },
    logoStyle: {
      width: '72%',
      height: 'auto',
      maxWidth: '72%',
      maxHeight: '54%',
    },
  },
  tapetech: {
    logo: resolveBrandLogo('TapeTech'),
    className: 'products-brand-selector__card--tapetech',
    cardStyle: {
      backgroundColor: 'rgb(236, 170, 31)',
      padding: 0,
    },
    logoStyle: {
      width: '76%',
      height: 'auto',
      maxWidth: '76%',
      maxHeight: '46%',
    },
  },
  columbia: {
    logo: sitegroundLogoAsset('columbia_logo_white.svg'),
    className: 'products-brand-selector__card--columbia',
    cardStyle: {
      background: '#080808',
      padding: 0,
    },
    logoStyle: {
      width: '72%',
      height: 'auto',
      maxWidth: '72%',
      maxHeight: '48%',
    },
  },
  durastilts: {
    logo: resolveBrandLogo('Dura-Stilts'),
    className: 'products-brand-selector__card--dura-stilts',
    cardStyle: {
      backgroundColor: 'rgba(17, 0, 87)',
      padding: 0,
    },
    logoStyle: {
      width: '78%',
      height: 'auto',
      maxWidth: '78%',
      maxHeight: '48%',
    },
  },
  level5: {
    logo: sitegroundLogoAsset('Level5-white.svg'),
    className: 'products-brand-selector__card--level5',
    cardStyle: {
      backgroundColor: '#b5121b',
      backgroundImage: `url("${sitegroundLogoAsset('level5-background.webp')}")`,
      backgroundPosition: 'center',
      backgroundRepeat: 'no-repeat',
      backgroundSize: 'cover',
      padding: 0,
    },
    logoStyle: {
      width: '74%',
      height: 'auto',
      maxWidth: '74%',
      maxHeight: '48%',
    },
  },
  platinum: {
    logo: resolveBrandLogo('Platinum'),
    className: 'products-brand-selector__card--platinum',
    cardStyle: {
      backgroundColor: '#eef2f6',
      padding: 0,
    },
    logoStyle: {
      width: '76%',
      height: 'auto',
      maxWidth: '76%',
      maxHeight: '44%',
    },
  },
  surpro: {
    logo: sitegroundLogoAsset('surpro_logo_white.svg'),
    className: 'products-brand-selector__card--surpro',
    cardStyle: {
      backgroundColor: 'rgb(255, 102, 0)',
      padding: 0,
    },
    logoStyle: {
      width: '74%',
      height: 'auto',
      maxWidth: '74%',
      maxHeight: '48%',
    },
  },
  usgsheetrocktools: {
    // Selector artwork is intentionally separate from the canonical USG logo
    // used by headers and other storefront surfaces.
    logo: sitegroundLogoAsset('usg-sheetrock-tools-transparent.svg'),
    className: 'products-brand-selector__card--usg',
    cardStyle: {
      backgroundColor: '#00843d',
      backgroundImage: `url("${sitegroundLogoAsset('USG-background.webp')}")`,
      backgroundPosition: 'center',
      backgroundRepeat: 'no-repeat',
      backgroundSize: 'cover',
      padding: 0,
    },
    logoStyle: {
      width: '88%',
      height: 'auto',
      maxWidth: '88%',
      maxHeight: '56%',
    },
  },
};

export function resolveFeaturedBrandPresentation(brand = {}, { includeSchematicOnly = false } = {}) {
  const candidates = [brand.slug, brand.key, brand.label, brand.name, brand.id];

  for (const candidate of candidates) {
    const normalized = normalizeBrandAssetKey(candidate);
    if (!normalized) continue;

    if (includeSchematicOnly && normalized === 'asgard') return FEATURED_BRAND_PRESENTATION.asgard;
    if (normalized.includes('columbia')) return FEATURED_BRAND_PRESENTATION.columbia;
    if (normalized === 'durastilts' || normalized === 'durastilt') return FEATURED_BRAND_PRESENTATION.durastilts;
    if (normalized === 'tapetech' || normalized === 'tapetechtools') return FEATURED_BRAND_PRESENTATION.tapetech;
    if (normalized === 'level5') return FEATURED_BRAND_PRESENTATION.level5;
    if (normalized.includes('platinum')) return FEATURED_BRAND_PRESENTATION.platinum;
    if (normalized === 'surpro' || normalized === 'sur') return FEATURED_BRAND_PRESENTATION.surpro;
    if (normalized === 'usg' || normalized.includes('usgsheetrock')) {
      return FEATURED_BRAND_PRESENTATION.usgsheetrocktools;
    }
  }

  return null;
}
