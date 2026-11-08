import { brandToSlug, canonicalBrandLabel } from './catalogUrlState.js';
import { BRAND_LOGO_FILES } from './brandLogoFiles.js';

const {
  tapeTech: tapeTechLogo,
  columbia: columbiaLogo,
  surpro: surproLogo,
  asgard: asgardLogo,
  platinum: platinumLogo,
  duraStilts: duraStiltsLogo,
  graco: gracoLogo,
  level5: level5Logo,
  usgSheetrockTools: usgSheetrockToolsLogo,
} = BRAND_LOGO_FILES;

export const BRAND_LOGOS_BY_LABEL = {
  TapeTech: tapeTechLogo,
  'Columbia Tools': columbiaLogo,
  'Columbia Taping Tools': columbiaLogo,
  Columbia: columbiaLogo,
  SurPro: surproLogo,
  Asgard: asgardLogo,
  Graco: gracoLogo,
  'Platinum Drywall Tools': platinumLogo,
  Platinum: platinumLogo,
  'Dura-Stilts': duraStiltsLogo,
  'Dura Stilts': duraStiltsLogo,
  LEVEL5: level5Logo,
  Level5: level5Logo,
  'Level 5': level5Logo,
  'USG Sheetrock® Tools': usgSheetrockToolsLogo,
  'USG Sheetrock Tools': usgSheetrockToolsLogo,
  USG: usgSheetrockToolsLogo,
};

export const BRAND_LOGOS_BY_SLUG = {
  tapetech: tapeTechLogo,
  'columbia-taping-tools': columbiaLogo,
  'columbia-tools': columbiaLogo,
  columbia: columbiaLogo,
  surpro: surproLogo,
  asgard: asgardLogo,
  graco: gracoLogo,
  platinum: platinumLogo,
  'platinum-drywall-tools': platinumLogo,
  'dura-stilts': duraStiltsLogo,
  level5: level5Logo,
  'level-5': level5Logo,
  'usg-sheetrock-tools': usgSheetrockToolsLogo,
  usg: usgSheetrockToolsLogo,
};

export function getBrandLogo(value) {
  if (!value) return '';

  const candidates = typeof value === 'object'
    ? [value.label, value.name, value.key, value.slug, value.id]
    : [value];

  for (const candidate of candidates) {
    if (candidate == null) continue;

    const raw = String(candidate).trim();
    if (!raw) continue;
    if (BRAND_LOGOS_BY_LABEL[raw]) return BRAND_LOGOS_BY_LABEL[raw];
    if (BRAND_LOGOS_BY_SLUG[raw]) return BRAND_LOGOS_BY_SLUG[raw];

    const canonical = canonicalBrandLabel(raw);
    if (BRAND_LOGOS_BY_LABEL[canonical]) return BRAND_LOGOS_BY_LABEL[canonical];

    const slug = brandToSlug(raw);
    const logo = BRAND_LOGOS_BY_SLUG[slug];
    if (logo) return logo;
  }

  return '';
}

export default getBrandLogo;
