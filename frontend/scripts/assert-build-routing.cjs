'use strict';

const fs = require('fs');
const path = require('path');

const frontendRoot = path.resolve(__dirname, '..');
const repositoryRoot = path.resolve(frontendRoot, '..');
const appEnv = String(process.env.APP_ENV || '').trim().toLowerCase();
const publicUrl = String(process.env.PUBLIC_URL || '').trim().replace(/\/+$/, '');
const deployTarget = String(process.env.DTB_DEPLOY_TARGET || 'siteground').trim().toLowerCase();

if (!['production', 'staging'].includes(appEnv)) {
  throw new Error('APP_ENV must be production or staging for routing validation.');
}

if (appEnv === 'production' && publicUrl !== '') {
  throw new Error('Production routing must be root-mounted with PUBLIC_URL=/ (normalized to empty).');
}
if (appEnv === 'staging' && publicUrl !== '/staging') {
  throw new Error('Staging routing must use PUBLIC_URL=/staging.');
}

const outputRoot = path.join(repositoryRoot, appEnv === 'staging' ? 'dist-staging' : 'dist');
const emittedPath = path.join(outputRoot, '.htaccess');
const routingFilename = appEnv === 'staging'
  ? path.join('staging', '.htaccess')
  : '.htaccess';
const sourcePath = path.join(repositoryRoot, 'drywalltoolbox', routingFilename);
const manifestPath = path.join(outputRoot, 'asset-manifest.json');
const robotsPath = path.join(outputRoot, 'robots.txt');
const storefrontShellPath = path.join(outputRoot, 'storefront.html');
const schematicSourceRoot = path.join(frontendRoot, 'public', 'brands');
const sharedBrandsOutputRoot = path.join(outputRoot, 'brands');

function collectSchematicDatasets(root) {
  const datasets = new Map();
  const pending = [root];

  while (pending.length > 0) {
    const directory = pending.pop();
    if (!fs.existsSync(directory)) continue;

    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
      const absolutePath = path.join(directory, entry.name);
      if (entry.isDirectory()) {
        pending.push(absolutePath);
      } else if (/^schematic_data[^/]*\.json$/i.test(entry.name)) {
        const relativePath = path.relative(root, absolutePath).split(path.sep).join('/');
        datasets.set(relativePath, fs.readFileSync(absolutePath));
      }
    }
  }

  return datasets;
}

const expected = fs.readFileSync(sourcePath, 'utf8');
const emitted = fs.readFileSync(emittedPath, 'utf8');

const sourceSchematicDatasets = collectSchematicDatasets(schematicSourceRoot);
if (sourceSchematicDatasets.size === 0) {
  throw new Error('frontend/public/brands contains no schematic_data*.json source datasets.');
}
for (const [relativePath, sourceContent] of sourceSchematicDatasets) {
  try {
    JSON.parse(sourceContent.toString('utf8'));
  } catch (error) {
    throw new Error(`Invalid schematic hotspot JSON at frontend/public/brands/${relativePath}: ${error.message}`);
  }
}
if (fs.existsSync(sharedBrandsOutputRoot)) {
  throw new Error(
    'The build artifact contains brands/, but shared brand and schematic assets must remain at public_html/brands.'
  );
}

if (emitted !== expected) {
  throw new Error(
    `${path.relative(repositoryRoot, emittedPath)} does not match its canonical ` +
    `routing source ${path.relative(repositoryRoot, sourcePath)}.`
  );
}

if (appEnv === 'production' && /^\s*RewriteRule\s+\^cart/m.test(emitted)) {
  throw new Error('The emitted .htaccess incorrectly routes the React /cart page to WordPress.');
}

if (appEnv === 'production' && !/^\s*RewriteRule\s+\^checkout\/\?\$\s+wp\/index\.php\?pagename=checkout\s+\[QSA,L\]$/m.test(emitted)) {
  throw new Error('The emitted .htaccess does not route native /checkout/ to WordPress.');
}

if (appEnv === 'production') {
  if (!fs.existsSync(storefrontShellPath)) {
    throw new Error('The production build is missing the pre-launch storefront.html React shell.');
  }
  if (!emitted.includes('RewriteRule ^order-tracking/[0-9]+/?$ storefront.html [QSA,L]')) {
    throw new Error('Production order-tracking routes must use storefront.html while index.html remains pre-launch.');
  }
}

const stripeReturnCondition = 'RewriteCond %{QUERY_STRING} (^|&)_stripe_payment_method=stripe_[^&]+(&|$) [NC]';
const stripeReturnTarget = appEnv === 'staging'
  ? 'RewriteRule ^$ /wp/index.php [QSA,L]'
  : 'RewriteRule ^$ wp/index.php [QSA,L]';
const homepageRule = 'RewriteRule ^$ index.html [L]';
const stripeReturnPosition = emitted.indexOf(stripeReturnCondition);
const stripeReturnTargetPosition = emitted.indexOf(stripeReturnTarget, stripeReturnPosition);
const homepagePosition = emitted.indexOf(homepageRule);

if (
  stripeReturnPosition < 0 ||
  stripeReturnTargetPosition < stripeReturnPosition ||
  homepagePosition < 0 ||
  stripeReturnTargetPosition > homepagePosition
) {
  throw new Error('Stripe query-string payment returns must reach WordPress before the SPA homepage rule.');
}

const orderReceivedCondition = 'RewriteCond %{QUERY_STRING} (^|&)order-received=[0-9]+(&|$) [NC]';
const orderReceivedPosition = emitted.indexOf(orderReceivedCondition);
const orderReceivedTargetPosition = emitted.indexOf(stripeReturnTarget, orderReceivedPosition);

if (
  orderReceivedPosition < 0 ||
  orderReceivedTargetPosition < orderReceivedPosition ||
  orderReceivedTargetPosition > homepagePosition
) {
  throw new Error('Plain-permalink WooCommerce order-received returns must reach WordPress before the SPA homepage rule.');
}

if (appEnv === 'staging') {
  if (!/^\s*RewriteRule\s+\^\s+index\.html\s+\[QSA,L\]$/m.test(emitted)) {
    throw new Error('The staging .htaccess must provide the SiteGround subdirectory SPA fallback.');
  }
  if (!/^\s*Header\s+always\s+set\s+X-Robots-Tag\s+"noindex, nofollow"\s*$/m.test(emitted)) {
    throw new Error('The staging .htaccess must enforce X-Robots-Tag: noindex, nofollow.');
  }
  if (!emitted.includes('RewriteRule ^wp-json/(.*)$ /wp/index.php?rest_route=/$1 [QSA,L]')) {
    throw new Error('Staging REST aliases must route to the shared root WordPress runtime.');
  }
  if (!emitted.includes('RewriteRule ^checkout/?$ /checkout/ [R=302,L,NE]')) {
    throw new Error('Staging checkout must redirect to the canonical native WooCommerce checkout URL.');
  }
  if (emitted.includes('RewriteRule ^checkout/?$ /wp/index.php?pagename=checkout [QSA,L]')) {
    throw new Error('Staging must not execute a second checkout URL inside WordPress.');
  }
  const checkoutUrlSource = fs.readFileSync(path.join(frontendRoot, 'src/utils/checkoutUrl.js'), 'utf8');
  if (!/return buildCheckoutUrl\('\/checkout\/'\)/.test(checkoutUrlSource) || checkoutUrlSource.includes('getStorefrontMount')) {
    throw new Error('Staging checkout CTA must hand off to root /checkout/, independent of PUBLIC_URL.');
  }
  if (!emitted.includes('RewriteRule ^checkout/order-pay/([0-9]+)/?$ /wp/index.php?pagename=checkout&order-pay=$1 [QSA,L]')) {
    throw new Error('Staging order-pay routes must stay on the staging mount and execute through shared WordPress.');
  }
  if (!emitted.includes('RewriteRule ^checkout/order-received/([0-9]+)/?$ /wp/index.php?pagename=checkout&order-received=$1 [QSA,L]')) {
    throw new Error('Staging order-received routes must stay on the staging mount and execute through shared WordPress.');
  }
  if (/RewriteRule[^\r\n]+\s+wp\/index\.php/.test(emitted)) {
    throw new Error('Staging routing must not target a nonexistent local staging wp/index.php.');
  }
} else {
  const sitemapIndexRule = 'RewriteRule ^sitemap\\.xml$ wp/index.php?dtb_sitemap=index [QSA,L]';
  const sitemapChildRule = 'RewriteRule ^sitemaps/([a-z0-9-]+)-([1-9][0-9]*)\\.xml$ wp/index.php?dtb_sitemap=$1&dtb_sitemap_page=$2 [QSA,L]';
  const xmlGuardMarker = 'RewriteCond %{REQUEST_URI} \\.(?:css|js|mjs|map|json|webmanifest';
  const sitemapIndexPosition = emitted.indexOf(sitemapIndexRule);
  const sitemapChildPosition = emitted.indexOf(sitemapChildRule);
  const xmlGuardPosition = emitted.indexOf(xmlGuardMarker);

  if (sitemapIndexPosition < 0 || sitemapChildPosition < 0) {
    throw new Error('The emitted .htaccess must route DTB sitemap index and child XML requests to WordPress.');
  }
  if (xmlGuardPosition < 0 || sitemapIndexPosition > xmlGuardPosition || sitemapChildPosition > xmlGuardPosition) {
    throw new Error('DTB sitemap rewrites must run before the generic missing-static-asset XML 404 guard.');
  }
}

const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
for (const logicalName of ['main.js', 'main.css', 'runtime.js']) {
  const assetPath = manifest.files?.[logicalName];
  if (!assetPath || !/\.[a-f0-9]{8}\.(?:js|css)$/i.test(assetPath)) {
    throw new Error(`${logicalName} must resolve to a content-hashed production asset.`);
  }
  if (appEnv === 'staging' && !assetPath.startsWith('/staging/')) {
    throw new Error(`${logicalName} must be emitted below /staging/.`);
  }
}

// The responsive homepage art is an LCP contract: both independently sized
// sources must be emitted with a content hash. Do not accept a generic
// home-hero match here; that would allow one viewport to silently regress.
const requiredHomeHeroAssets = [
  'home-hero-mobile-640',
  'home-hero-desktop-1600',
];
const emittedHomeHeroAssets = requiredHomeHeroAssets.map((logicalName) => {
  const expectedAsset = new RegExp(`/assets/images/${logicalName}\\.[a-f0-9]{8}\\.webp$`, 'i');
  const assetPath = Object.values(manifest.files || {}).find((candidate) => expectedAsset.test(candidate));
  if (!assetPath) {
    throw new Error(`The asset manifest is missing the content-hashed ${logicalName} image.`);
  }
  return assetPath;
});
if (appEnv === 'staging' && emittedHomeHeroAssets.some((p) => !p.startsWith('/staging/'))) {
  throw new Error('The staging home hero asset must be emitted below /staging/.');
}

const lazyAssets = Object.values(manifest.files || {}).filter((assetPath) => /\.chunk\.(?:js|css)$/i.test(assetPath));
if (lazyAssets.length === 0 || lazyAssets.some((assetPath) => !/\.[a-f0-9]{8}\.chunk\.(?:js|css)$/i.test(assetPath))) {
  throw new Error('Every lazy JavaScript/CSS chunk must use a content-hashed production URL.');
}

if (appEnv === 'staging') {
  if (!fs.existsSync(robotsPath)) {
    throw new Error('The staging artifact is missing robots.txt.');
  }

  const robots = fs.readFileSync(robotsPath, 'utf8');
  if (!/^Allow:\s*\/$/m.test(robots)) {
    throw new Error('The staging robots.txt must allow crawling for authorized SEO audits.');
  }
  if (/^Disallow:\s*\/$/m.test(robots) || /^Disallow:\s*\/staging\/$/m.test(robots)) {
    throw new Error('The staging robots policy must not block the staging audit surface.');
  }
  if (/^Sitemap:/mi.test(robots)) {
    throw new Error('The staging robots.txt must not advertise a staging sitemap.');
  }

  const javascriptAssets = Object.values(manifest.files || {})
    .filter((assetPath) => /\.js$/i.test(assetPath))
    .map((assetPath) => path.join(outputRoot, assetPath.replace(/^\/staging\//, '')));
  const compiledJavascript = javascriptAssets
    .filter((assetPath) => fs.existsSync(assetPath))
    .map((assetPath) => fs.readFileSync(assetPath, 'utf8'))
    .join('\n');

  if (!compiledJavascript.includes('REACT_APP_API_BASE_URL:"https://drywalltoolbox.com"')) {
    throw new Error('The staging bundle must target the shared root WordPress API origin.');
  }
  if (compiledJavascript.includes('https://drywalltoolbox.com/staging/wp-json')) {
    throw new Error('The staging bundle contains the obsolete staging-prefixed REST authority.');
  }
  if (compiledJavascript.includes('https://drywalltoolbox.com/staging/wp/wp-content')) {
    throw new Error('The staging bundle contains the obsolete staging WordPress media path.');
  }
}

process.stdout.write(
  `Routing contract verified: ${path.relative(repositoryRoot, emittedPath)} ` +
  `matches ${path.relative(repositoryRoot, sourcePath)}; ` +
  `${sourceSchematicDatasets.size} source schematic hotspot datasets are valid and brands/ was excluded.\n`
);
