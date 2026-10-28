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
  'Level 5': level5Logo,
  Level5: level5Logo,
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

  const raw = String(value).trim();
  if (BRAND_LOGOS_BY_LABEL[raw]) return BRAND_LOGOS_BY_LABEL[raw];
  if (BRAND_LOGOS_BY_SLUG[raw]) return BRAND_LOGOS_BY_SLUG[raw];

  const canonical = canonicalBrandLabel(raw);
  if (BRAND_LOGOS_BY_LABEL[canonical]) return BRAND_LOGOS_BY_LABEL[canonical];

  const slug = brandToSlug(raw);
  return BRAND_LOGOS_BY_SLUG[slug] || '';
}

export default getBrandLogo;
