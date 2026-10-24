import TrustedBrands from '../ui/TrustedBrands.jsx';

export default function HomeHeroBrands({ brands = [] }) {
  return (
    <TrustedBrands
      brands={brands}
      title={"SHOP BY BRAND.\nTRUSTED BY PROFESSIONALS."}
      speed={34}
      className="dtb-ui-trusted-brands--home"
    />
  );
}
