// React storefront logos are imported from the local brand asset library so
// Webpack emits fingerprinted, cacheable assets with the storefront bundle.
import tapeTechLogo from '/brands/TapeTech/tapetech_logo.svg';
import columbiaLogo from '/brands/Columbia/columbia_taping_tools_logo.svg';
import columbiaWhiteLogo from '/brands/Columbia/columbia_logo_white.svg';
import surproLogo from '/brands/SurPro/surpro_logo.svg';
import surproWhiteLogo from '/brands/SurPro/surpro_logo_white.svg';
import asgardLogo from '/brands/Asgard/asgard_logo.svg';
import asgardSelectorLogo from '/brands/Asgard/asgard_logo2.svg';
import gracoLogo from '/brands/Graco/graco_logo.svg';
import platinumLogo from '/brands/Platinum/platinum_logo.svg';
import duraStiltsLogo from '/brands/Dura-Stilts/dura-stilts-logo.svg';
import level5Logo from '/brands/Level5/Level5.svg';
import level5WhiteLogo from '/brands/Level5/Level5-white.svg';
import usgSheetrockToolsLogo from '/brands/USG-Sheetrock-Tools/usg-sheetrock-tools.svg';
import usgSheetrockToolsTransparentLogo from '/brands/USG-Sheetrock-Tools/usg-sheetrock-tools-transparent.svg';

export const BRAND_LOGO_FILES = {
  tapeTech: tapeTechLogo,
  columbia: columbiaLogo,
  surpro: surproLogo,
  asgard: asgardLogo,
  graco: gracoLogo,
  platinum: platinumLogo,
  duraStilts: duraStiltsLogo,
  level5: level5Logo,
  usgSheetrockTools: usgSheetrockToolsLogo,
};

export const FEATURED_BRAND_LOGO_FILES = {
  asgard: asgardSelectorLogo,
  columbia: columbiaWhiteLogo,
  level5: level5WhiteLogo,
  surpro: surproWhiteLogo,
  usgSheetrockTools: usgSheetrockToolsTransparentLogo,
};
