import TrendingProducts from '../components/catalog/TrendingProducts';
import HomeHero from '../components/home/HomeHero';
import HomeHeroBrands from '../components/home/HomeHeroBrands';
import SEOHead from '../components/shared/SEOHead';
import { buildOrganizationSchema, buildSiteLinksSearchBoxSchema } from '../utils/schema';
import StorefrontSection from '../components/storefront/StorefrontSection';
import StorefrontProductRail from '../components/storefront/StorefrontProductRail';
import { getBrandLogo } from '../utils/brandAssets.js';
import { brandToSlug } from '../utils/catalogUrlState.js';

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
  'Asgard',
];

const HOME_TRUST_BRANDS = HOME_TRUST_BRAND_NAMES.map((brandName) => {
  const name = getHomeBrandDisplayName(brandName);
  return {
    name,
    src: getBrandLogo(brandName),
    to: `/products/brands/${brandToSlug(brandName)}`,
  };
});

const HOME_BRAND_PRODUCT_SECTIONS = HOME_BRAND_ORDER.map((brandName) => {
  const name = getHomeBrandDisplayName(brandName);
  return {
    name,
    brand: brandName,
    logo: getBrandLogo(brandName),
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
                    height="48"
                    loading="lazy"
                    decoding="async"
                  />
                )}
                subtitle={`Explore the ${brand.name} lineup.`}
                className="dtb-home-product-section dtb-home-brand-section"
                viewAllHref={brand.to}
                viewAllLabel="View All"
              >
                <StorefrontProductRail
                  brand={brand.brand}
                  sort="popular"
                  maxItems={8}
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
