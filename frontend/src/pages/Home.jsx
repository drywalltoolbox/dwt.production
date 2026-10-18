import TrendingProducts from '../components/catalog/TrendingProducts';
import { useMemo } from 'react';
import HomeHero from '../components/home/HomeHero';
import SEOHead from '../components/shared/SEOHead';
import { buildOrganizationSchema, buildSiteLinksSearchBoxSchema } from '../utils/schema';
import StorefrontSection from '../components/storefront/StorefrontSection';
import StorefrontRail from '../components/storefront/StorefrontRail';
import StorefrontBrandTile from '../components/storefront/StorefrontBrandTile';
import StorefrontProductRail from '../components/storefront/StorefrontProductRail';
import { useCatalogFacets } from '../hooks/useCatalogFacets.js';
import { getBrandLogo } from '../utils/brandAssets.js';
import columbiaHeroLogo from '/brands/Columbia/columbia_taping_tools_logo.svg';
import platinumHeroLogo from '/brands/Platinum/platinum_logo.svg';
import { mapCatalogBrands } from '../utils/catalogFacets.js';

const MAX_HOME_BRANDS = 6;
const HOME_BRAND_ORDER = ['TapeTech', 'Columbia Tools', 'Platinum Drywall Tools', 'Level5', 'SurPro', 'DuraStilts'];

function normalizeHomeBrandName(value = '') {
  return String(value).toLowerCase().replace(/[^a-z0-9]/g, '');
}

function getHomeBrandOrderIndex(value = '') {
  const normalized = normalizeHomeBrandName(value);
  if (normalized.includes('tapetech')) return 0;
  if (normalized.includes('columbia')) return 1;
  if (normalized.includes('platinum')) return 2;
  if (normalized.includes('level5')) return 3;
  if (normalized.includes('surpro')) return 4;
  if (normalized.includes('durastilts')) return 5;
  return Number.POSITIVE_INFINITY;
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

export default function Home() {
  const { facets } = useCatalogFacets();
  const brands = useMemo(() => {
    const mapped = mapCatalogBrands(facets?.brands);
    return mapped
      .filter((brand) => Number.isFinite(getHomeBrandOrderIndex(brand.name)))
      .sort((a, b) => getHomeBrandOrderIndex(a.name) - getHomeBrandOrderIndex(b.name))
      .slice(0, MAX_HOME_BRANDS)
      .map((brand) => {
        const name = getHomeBrandDisplayName(brand.name);
        const logo = getBrandLogo(brand.name) || getBrandLogo(name);
        return {
          name,
          logo,
          to: `/products/brands/${brand.slug}`,
        };
      });
  }, [facets]);
  const heroBrands = useMemo(() => brands.map((brand) => ({
    name: brand.name,
    src: /columbia/i.test(brand.name)
      ? columbiaHeroLogo
      : (/platinum/i.test(brand.name) ? platinumHeroLogo : brand.logo),
    to: brand.to,
  })), [brands]);

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
        <HomeHero brands={heroBrands} />

        <div className="dtb-home-storefront">
          <div className="dtb-container dtb-container--fluid pb-4">

            {/* ── Trending / Featured Products (brand-balanced) ── */}
            <TrendingProducts />

            {/* ── New Arrivals ── */}
            <StorefrontSection
              eyebrow="Just In"
              revealHeading
              title="New Arrivals"
              viewAllHref="/products?sort=newest"
            >
              <StorefrontProductRail sort="newest" maxItems={10} label="New arrivals" />
            </StorefrontSection>

            {/* ── Shop by Brand ── */}
            <StorefrontSection
              eyebrow="Brands"
              revealHeading
              title="Shop by Brand"
              viewAllHref="/products/brands"
              viewAllLabel="All brands"
            >
              <StorefrontRail label="Brands" className="storefront-rail--brand">
                {brands.map((brand) => (
                  <StorefrontBrandTile key={brand.name} {...brand} />
                ))}
              </StorefrontRail>
            </StorefrontSection>

          </div>
        </div>
      </div>
    </>
  );
}
