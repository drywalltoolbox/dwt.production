import tapeTechLogo from '/brands/TapeTech/tapetech_logo.svg';
import columbiaLogo from '/brands/Columbia/columbia_taping_tools_logo.svg';
import surproLogo from '/brands/SurPro/surpro_logo.svg';
import asgardLogo from '/brands/Asgard/asgard_logo.svg';
import platinumLogo from '/brands/Platinum/platinum_logo.svg';
import duraStiltsLogo from '/brands/Dura-Stilts/dura-stilts-logo.svg';
import gracoLogo from '/brands/Graco/graco_logo.svg';
import level5Logo from '/brands/Level5/Level5.svg';
import usgSheetrockToolsLogo from '/brands/USG-Sheetrock-Tools/usg-sheetrock-tools.svg';
import { brandToSlug, canonicalBrandLabel } from './catalogUrlState.js';

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
