// React storefront logos are imported from the local brand asset library so
// Webpack emits fingerprinted, cacheable assets with the storefront bundle.
import tapeTechLogo from '/brands/TapeTech/tapetech_logo.svg';
import columbiaLogo from '/brands/Columbia/columbia-logo.svg';
import columbiaWhiteLogo from '/brands/Columbia/columbia_logo_white.svg';
import surproLogo from '/brands/SurPro/surpro_logo.svg';
import surproSelectorLogo from '/brands/SurPro/surpro_logo_icon.svg';
import asgardLogo from '/brands/Asgard/asgard_logo.svg';
import asgardSelectorLogo from '/brands/Asgard/asgard_logo2.svg';
import platinumLogo from '/brands/Platinum/platinum_logo.svg';
import duraStiltsLogo from '/brands/Dura-Stilts/dura-stilts-logo.svg';
import level5Logo from '/brands/Level5/Level5.svg';
import level5WhiteLogo from '/brands/Level5/Level5-white.svg';
import usgSheetrockToolsLogo from '/brands/USG-Sheetrock-Tools/usg-sheetrock-tools.svg';
import usgSheetrockToolsTransparentLogo from '/brands/USG-Sheetrock-Tools/usg-sheetrock-tools-transparent.svg';
import tapeTechHomeStripLogo from '/brands/home-strip/tapetech.webp';
import columbiaHomeStripLogo from '/brands/home-strip/columbia.webp';
import platinumHomeStripLogo from '/brands/home-strip/platinum.webp';
import level5HomeStripLogo from '/brands/home-strip/level5.webp';
import surproHomeStripLogo from '/brands/home-strip/surpro.webp';
import duraStiltsHomeStripLogo from '/brands/home-strip/dura-stilts.webp';
import usgSheetrockToolsHomeStripLogo from '/brands/home-strip/usg-sheetrock-tools.webp';

export const BRAND_LOGO_FILES = {
  tapeTech: tapeTechLogo,
  columbia: columbiaLogo,
  surpro: surproLogo,
  asgard: asgardLogo,
  platinum: platinumLogo,
  duraStilts: duraStiltsLogo,
  level5: level5Logo,
  usgSheetrockTools: usgSheetrockToolsLogo,
};

export const FEATURED_BRAND_LOGO_FILES = {
  asgard: asgardSelectorLogo,
  columbia: columbiaWhiteLogo,
  level5: level5WhiteLogo,
  surpro: surproSelectorLogo,
  usgSheetrockTools: usgSheetrockToolsTransparentLogo,
};

// Compact, display-sized variants used only by the continuously moving home
// ticker. Keep them imported so Webpack emits fingerprinted production assets.
export const HOME_STRIP_BRAND_LOGO_FILES = {
  TapeTech: tapeTechHomeStripLogo,
  'Columbia Tools': columbiaHomeStripLogo,
  'Platinum Drywall Tools': platinumHomeStripLogo,
  LEVEL5: level5HomeStripLogo,
  SurPro: surproHomeStripLogo,
  'Dura-Stilts': duraStiltsHomeStripLogo,
  'USG Sheetrock Tools': usgSheetrockToolsHomeStripLogo,
};
