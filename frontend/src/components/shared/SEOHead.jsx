/**
 * frontend/src/components/shared/SEOHead.jsx
 *
 * Reusable document-head manager powered by react-helmet-async.
 * Drop this component anywhere in a page tree to control that page's
 * <title>, meta tags, Open Graph, Twitter Card, JSON-LD structured data,
 * and resource hints.
 *
 * Props:
 *   title       {string}          — page title; non-product pages receive "| Drywall Toolbox"
 *   description {string}          — meta description (truncated to 160 chars)
 *   canonical   {string}          — canonical URL override
 *   og          {object}          — Open Graph overrides: { type, image, imageAlt }
 *   schema      {object|object[]} — JSON-LD schema block(s) to inject
 *   noSuffix    {boolean}         — skip "| Drywall Toolbox" suffix (use for product pages with full custom titles)
 *   noindex     {boolean}         — emit noindex, nofollow when true
 *   links       {object[]}        — extra <link> tag objects: [{ rel, href, as, type, crossOrigin }]
 */
import { Helmet } from 'react-helmet-async';
import { absoluteSiteUrl, canonicalSiteUrl } from '../../utils/siteUrl.js';
import { DTB_LOGO_BLACK_URL } from '../../utils/dtbLogoAssets.js';

const SITE_NAME      = 'Drywall Toolbox';
const DEFAULT_OG_IMG = DTB_LOGO_BLACK_URL;
const MAX_DESC_LEN   = 160;
const SEARCH_INDEXING_ENABLED =
  process.env.REACT_APP_ENV === 'production' && process.env.REACT_APP_SEARCH_INDEXING !== '0';

const STATIC_ROUTE_TITLES = {
  '/': '',
  '/products': 'Products',
  '/products/brands': 'Brands',
  '/parts': 'Parts',
  '/schematics': 'Schematics',
  '/repairs': 'Repairs',
  '/repairs/start': 'Start a Repair',
  '/repairs/packages': 'Repair Packages',
  '/repairs/track': 'Track a Repair',
  '/help': 'Help Center',
  '/calculators': 'Calculators',
  '/shipping-policy': 'Shipping Policy',
  '/returns': 'Returns',
  '/return-policy': 'Return Policy',
  '/policies': 'Store Policies',
  '/terms-of-service': 'Terms of Service',
  '/privacy-policy': 'Privacy Policy',
  '/toolset-builder': 'Toolset Builder',
  '/cart': 'Cart',
  '/checkout': 'Checkout',
  '/checkout/complete': 'Order Complete',
  '/checkout/payment-failed': 'Payment Failed',
  '/checkout/payment-cancelled': 'Payment Cancelled',
  '/contact': 'Contact',
  '/login': 'Sign In',
  '/register': 'Create Account',
  '/forgot-password': 'Forgot Password',
  '/reset-password': 'Reset Password',
  '/dashboard': 'Account Dashboard',
  '/account-settings': 'Account Settings',
  '/addresses': 'Addresses',
  '/notifications': 'Notifications',
};

function normalizedRoutePath(pathname) {
  return String(pathname || '').replace(/\/+$/, '') || '/';
}

function currentPathname() {
  if (typeof window === 'undefined') return '';
  return normalizedRoutePath(window.location.pathname);
}

function routeTitleFor(pathname) {
  if (!pathname) return '';
  return STATIC_ROUTE_TITLES[pathname] || '';
}

function stripSiteName(title) {
  return String(title || '')
    .replace(new RegExp(`\\s*(?:\\||-|—)\\s*${SITE_NAME}\\s*$`, 'i'), '')
    .trim();
}

function normalizeBrowserTitle(title, pathname, noSuffix) {
  const routeTitle = noSuffix ? '' : routeTitleFor(pathname);
  const cleanTitle = stripSiteName(title);
  return routeTitle || cleanTitle;
}

export default function SEOHead({
  title       = '',
  description = '',
  canonical   = '',
  og          = {},
  schema      = null,
  noSuffix    = false,
  noindex     = false,
  links       = [],
}) {
  const pathname = currentPathname();
  const browserTitle = normalizeBrowserTitle(title, pathname, noSuffix);
  const fullTitle = browserTitle
    ? (noSuffix ? browserTitle : `${browserTitle} | ${SITE_NAME}`)
    : SITE_NAME;

  // Truncate description
  const safeDesc = description.length > MAX_DESC_LEN
    ? `${description.slice(0, MAX_DESC_LEN - 1)}…`
    : description;

  // Canonical URL
  const canonicalPath = canonical || (typeof window !== 'undefined' ? window.location.pathname : '/');
  const canonicalUrl = canonicalSiteUrl(canonicalPath);

  // Open Graph
  const ogType     = og.type     || 'website';
  const ogImage    = og.image ? absoluteSiteUrl(og.image) : DEFAULT_OG_IMG;
  const ogImageAlt = og.imageAlt || SITE_NAME;

  // Normalise schema to an array so we can render multiple blocks
  const schemas = schema
    ? (Array.isArray(schema) ? schema : [schema]).filter(Boolean)
    : [];

  return (
    <Helmet>
      <title>{fullTitle}</title>
      <meta name="description" content={safeDesc} />

      {/* Robots */}
      {noindex || !SEARCH_INDEXING_ENABLED
        ? <meta name="robots" content="noindex, nofollow" />
        : <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1" />
      }

      {/* Canonical */}
      <link rel="canonical" href={canonicalUrl} />

      {/* Open Graph */}
      <meta property="og:type"        content={ogType} />
      <meta property="og:title"       content={fullTitle} />
      <meta property="og:description" content={safeDesc} />
      <meta property="og:url"         content={canonicalUrl} />
      <meta property="og:site_name"   content={SITE_NAME} />
      <meta property="og:image"       content={ogImage} />
      <meta property="og:image:alt"   content={ogImageAlt} />

      {/* Twitter Card */}
      <meta name="twitter:card"        content="summary_large_image" />
      <meta name="twitter:title"       content={fullTitle} />
      <meta name="twitter:description" content={safeDesc} />
      <meta name="twitter:image"       content={ogImage} />
      <meta name="twitter:image:alt"   content={ogImageAlt} />

      {/* Extra link tags (preload, preconnect, etc.) */}
      {links.map((linkProps, i) => {
        const { rel, href, as: asAttr, type: typeAttr, crossOrigin, ...rest } = linkProps;
        return (
          <link
            key={i}
            rel={rel}
            href={href}
            {...(asAttr      ? { as: asAttr }              : {})}
            {...(typeAttr    ? { type: typeAttr }           : {})}
            {...(crossOrigin ? { crossOrigin: crossOrigin } : {})}
            {...rest}
          />
        );
      })}

      {/* JSON-LD structured data */}
      {schemas.map((s, i) => (
        <script
          key={i}
          type="application/ld+json"
          dangerouslySetInnerHTML={{ __html: JSON.stringify(s) }}
        />
      ))}
    </Helmet>
  );
}
