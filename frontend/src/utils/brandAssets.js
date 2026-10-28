import { brandToSlug, canonicalBrandLabel } from './catalogUrlState.js';

const BRAND_LOGO_ROOT = 'https://drywalltoolbox.com/logos';
const brandLogo = (filename) => `${BRAND_LOGO_ROOT}/${filename}`;

const tapeTechLogo = brandLogo('tapetech_logo.svg');
const columbiaLogo = brandLogo('columbia_taping_tools_logo.svg');
const surproLogo = brandLogo('surpro_logo.svg');
const asgardLogo = brandLogo('asgard_logo.svg');
const platinumLogo = brandLogo('platinum_logo.svg');
const duraStiltsLogo = brandLogo('dura-stilts-logo.svg');
const gracoLogo = brandLogo('graco_logo.svg');
const level5Logo = brandLogo('Level5.svg');
const usgSheetrockToolsLogo = brandLogo('usg-sheetrock-tools.svg');

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
