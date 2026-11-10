import TrustedBrands from '../ui/TrustedBrands.jsx';

// Use display-sized derivatives here; the source logo SVGs embed oversized
// raster artwork that is unnecessary at ticker scale on mobile.
const HOME_STRIP_LOGOS = {
  TapeTech: '/brands/home-strip/tapetech.webp',
  'Columbia Tools': '/brands/home-strip/columbia.webp',
  'Platinum Drywall Tools': '/brands/home-strip/platinum.webp',
  LEVEL5: '/brands/home-strip/level5.webp',
  SurPro: '/brands/home-strip/surpro.webp',
  'Dura-Stilts': '/brands/home-strip/dura-stilts.webp',
  'USG Sheetrock Tools': '/brands/home-strip/usg-sheetrock-tools.webp',
};

export default function HomeHeroBrands({ brands = [] }) {
  return (
    <TrustedBrands
      brands={brands.map((brand) => ({
        ...brand,
        src: HOME_STRIP_LOGOS[brand.name] || brand.src,
      }))}
      title={"SHOP BY BRANDS.\nTRUSTED BY PROFESSIONALS."}
      speed={34}
      imageLoading="lazy"
      className="dtb-ui-trusted-brands--home"
    />
  );
}
