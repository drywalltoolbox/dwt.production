import TrustedBrands from '../ui/TrustedBrands.jsx';
import { HOME_STRIP_BRAND_LOGO_FILES } from '../../utils/brandLogoFiles.js';

export default function HomeHeroBrands({ brands = [] }) {
  return (
    <TrustedBrands
      brands={brands.map((brand) => ({
        ...brand,
        src: HOME_STRIP_BRAND_LOGO_FILES[brand.name] || brand.src,
      }))}
      title={"SHOP BY BRANDS.\nTRUSTED BY PROFESSIONALS."}
      speed={34}
      className="dtb-ui-trusted-brands--home"
    />
  );
}
