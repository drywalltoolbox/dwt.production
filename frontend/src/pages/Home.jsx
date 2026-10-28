import TrendingProducts from '../components/catalog/TrendingProducts';
import HomeHero from '../components/home/HomeHero';
import HomeHeroBrands from '../components/home/HomeHeroBrands';
import SEOHead from '../components/shared/SEOHead';
import { buildOrganizationSchema, buildSiteLinksSearchBoxSchema } from '../utils/schema';
import StorefrontSection from '../components/storefront/StorefrontSection';
import StorefrontProductRail from '../components/storefront/StorefrontProductRail';
import { getBrandLogo } from '../utils/brandAssets.js';
import { brandToSlug } from '../utils/catalogUrlState.js';
import { ToolTypeTile } from '../components/catalog/ShopByToolType.jsx';
import StorefrontRail from '../components/storefront/StorefrontRail.jsx';
import '../styles/home-commerce-discovery.css';

const HOME_CATEGORIES = [
  ['Automatic Tapers', 'automatic-tapers'], ['Finishing Boxes', 'flat-boxes'],
  ['Loading Pumps', 'loading-pumps'], ['Corner Finishers', 'corner-finishers'],
  ['Corner Rollers', 'corner-rollers'], ['Corner Applicators', 'corner-applicators-angle-boxes'],
  ['Nail Spotters', 'nail-spotters'], ['Handles', 'flat-box-handles'],
];

const HOME_BRAND_ORDER = ['TapeTech', 'Columbia Tools', 'Platinum Drywall Tools', 'Level 5', 'SurPro', 'Dura-Stilts'];

function normalizeHomeBrandName(value = '') {
  return String(value).toLowerCase().replace(/[^a-z0-9]/g, '');
}

function getHomeBrandDisplayName(value = '') {
  const normalized = normalizeHomeBrandName(value);
  if (normalized.includes('tapetech')) return 'TapeTech';
  if (normalized.includes('columbia')) return 'Columbia Tools';
  if (normalized.includes('platinum')) return 'Platinum Drywall Tools';
  if (normalized.includes('level5')) return 'Level5';
  if (normalized.includes('surpro')) return 'SurPro';
  if (normalized.includes('durastilts')) return 'DuraStilts';
  return value;
}

const HOME_TRUST_BRAND_NAMES = [
  ...HOME_BRAND_ORDER,
  'USG Sheetrock Tools',
];

const HOME_TRUST_BRANDS = HOME_TRUST_BRAND_NAMES.map((brandName) => {
  const name = getHomeBrandDisplayName(brandName);
  return {
    name,
    src: getBrandLogo(brandName),
    to: `/products/brands/${brandToSlug(brandName)}`,
  };
});

const HOME_BRAND_LOGO_DIMENSIONS = {
  TapeTech: [2048, 500],
  'Columbia Tools': [2048, 760],
  'Platinum Drywall Tools': [2048, 613],
  'Level 5': [2048, 470],
  SurPro: [1640, 430],
  'Dura-Stilts': [2048, 414],
};

const HOME_BRAND_PRODUCT_SECTIONS = HOME_BRAND_ORDER.map((brandName) => {
  const name = getHomeBrandDisplayName(brandName);
  return {
    name,
    brand: brandName,
    logo: getBrandLogo(brandName),
    logoWidth: HOME_BRAND_LOGO_DIMENSIONS[brandName][0],
    logoHeight: HOME_BRAND_LOGO_DIMENSIONS[brandName][1],
    to: `/products/brands/${brandToSlug(brandName)}`,
  };
});

export default function Home() {
  return (
    <>
      <SEOHead
        title="Drywall Toolbox"
        noSuffix
        description="Top trusted one-stop shop for professional drywall tools. Get production-grade tools and parts at unbeatable prices with lightning-fast shipping."
        canonical="/"
        schema={[buildOrganizationSchema(), buildSiteLinksSearchBoxSchema()]}
      />

      <div className="page-wrapper dtb-home-page storefront-shell">
        <HomeHero />

        <section className="home-commerce-categories dtb-container dtb-container--fluid" aria-label="Shop popular tool categories">
          <div className="storefront-section__head">
            <h2 className="storefront-section__title">Shop by Tool Type</h2>
          </div>
          <StorefrontRail label="Shop popular tool categories" className="dtb-tool-type-rail">
            {HOME_CATEGORIES.map(([name, slug]) => (
              <ToolTypeTile key={slug} category={{ name, slug }} showSelectorChevron />
            ))}
          </StorefrontRail>
        </section>

        <div className="dtb-home-storefront">
          <div className="dtb-container dtb-container--fluid pb-4">

            {/* ── Featured Products ── */}
            <TrendingProducts />

            {/* ── New Arrivals ── */}
            <StorefrontSection
              className="dtb-home-product-section"
              revealHeading
              title="New Arrivals"
              viewAllHref="/products?sort=newest"
            >
              <StorefrontProductRail sort="newest" maxItems={10} label="New arrivals" />
            </StorefrontSection>
          </div>

          <HomeHeroBrands brands={HOME_TRUST_BRANDS} />

          <div className="dtb-container dtb-container--fluid pb-4">
            {/* ── Brand product sections ── */}
            {HOME_BRAND_PRODUCT_SECTIONS.map((brand) => (
              <StorefrontSection
                key={brand.name}
                title={(
                  <img
                    className="dtb-home-brand-section__logo"
                    src={brand.logo}
                    alt={brand.name}
                    width={brand.logoWidth}
                    height={brand.logoHeight}
                    loading="lazy"
                    decoding="async"
                  />
                )}
                subtitle={`Explore the ${brand.name} lineup.`}
                className={`dtb-home-product-section dtb-home-brand-section dtb-home-brand-section--${brandToSlug(brand.brand)}`}
                viewAllHref={brand.to}
                viewAllLabel="View All"
              >
                <StorefrontProductRail
                  brand={brand.brand}
                  sort="popular"
                  maxItems={10}
                  label={`${brand.name} products`}
                />
              </StorefrontSection>
            ))}

          </div>
        </div>
      </div>
    </>
  );
}
