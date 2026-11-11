import { useMemo } from 'react';
import TrendingProducts from '../components/catalog/TrendingProducts';
import HomeHero from '../components/home/HomeHero';
import HomeHeroBrands from '../components/home/HomeHeroBrands';
import SEOHead from '../components/shared/SEOHead';
import { buildOrganizationSchema, buildSiteLinksSearchBoxSchema } from '../utils/schema';
import StorefrontSection from '../components/storefront/StorefrontSection';
import StorefrontProductRail from '../components/storefront/StorefrontProductRail';
import { getBrandLogo } from '../utils/brandAssets.js';
import { brandToSlug } from '../utils/catalogUrlState.js';
import ShopByToolType from '../components/catalog/ShopByToolType.jsx';
import useCatalogFacets from '../hooks/useCatalogFacets.js';
import { flattenCatalogNavigationGroups } from '../utils/catalogFacets.js';
import usgSheetrockToolsLogo from '/brands/USG-Sheetrock-Tools/usg-sheetrock-tools.svg';
import '../styles/home-commerce-discovery.css';

const HOME_BRAND_ORDER = ['TapeTech', 'Columbia Tools', 'Platinum Drywall Tools', 'LEVEL5', 'SurPro', 'Dura-Stilts', 'USG Sheetrock Tools'];

function normalizeHomeBrandName(value = '') {
  return String(value).toLowerCase().replace(/[^a-z0-9]/g, '');
}

function getHomeBrandDisplayName(value = '') {
  const normalized = normalizeHomeBrandName(value);
  if (normalized.includes('tapetech')) return 'TapeTech';
  if (normalized.includes('columbia')) return 'Columbia Tools';
  if (normalized.includes('platinum')) return 'Platinum Drywall Tools';
  if (normalized.includes('level5')) return 'LEVEL5';
  if (normalized.includes('surpro')) return 'SurPro';
  if (normalized.includes('durastilts')) return 'Dura-Stilts';
  return value;
}

const HOME_TRUST_BRAND_NAMES = HOME_BRAND_ORDER;

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
  LEVEL5: [2048, 470],
  SurPro: [1640, 430],
  'Dura-Stilts': [2048, 414],
  'USG Sheetrock Tools': [2171, 400],
};

const HOME_BRAND_PRODUCT_SECTIONS = HOME_BRAND_ORDER.map((brandName) => {
  const name = getHomeBrandDisplayName(brandName);
  return {
    name,
    brand: brandName,
    // Keep the USG rail's build asset explicit: this SVG wraps a large embedded
    // raster and should not depend on a lazy request after the rail is reached.
    logo: brandName === 'USG Sheetrock Tools' ? usgSheetrockToolsLogo : getBrandLogo(brandName),
    logoWidth: HOME_BRAND_LOGO_DIMENSIONS[brandName][0],
    logoHeight: HOME_BRAND_LOGO_DIMENSIONS[brandName][1],
    to: `/products/brands/${brandToSlug(brandName)}`,
  };
});

function HomeToolTypeCategories() {
  const { facets } = useCatalogFacets();
  const categories = useMemo(() => flattenCatalogNavigationGroups(facets?.navigationGroups)
    .map((category) => ({ ...category, name: category.label }))
    .filter((category) => category.name && category.slug && category.count > 0), [facets?.navigationGroups]);

  return <ShopByToolType categories={categories} viewAllHref="/products" homeVariant />;
}

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
          <HomeToolTypeCategories />
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
              subtitle="Explore the latest additions to our tool lineup."
              viewAllHref="/products?sort=newest"
            >
              <StorefrontProductRail sort="newest" maxItems={6} label="New arrivals" />
            </StorefrontSection>
          </div>

          <HomeHeroBrands brands={HOME_TRUST_BRANDS} />

          <div className="dtb-container dtb-container--fluid pb-4">
            <StorefrontSection
              className="dtb-home-product-section dtb-home-brand-directory-heading"
              title="Shop by Brand"
              subtitle="Shop professional tools and parts from leading brands."
            />

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
                    loading={brand.brand === 'USG Sheetrock Tools' ? 'eager' : 'lazy'}
                    decoding="async"
                  />
                )}
                className={`dtb-home-product-section dtb-home-brand-section dtb-home-brand-section--${brandToSlug(brand.brand)}`}
                subtitle={`Explore tools and parts from ${brand.name}.`}
                viewAllHref={brand.to}
                viewAllLabel="View all"
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
