/**
 * frontend/webpack.config.cjs  —  Drywall Toolbox React SPA
 *
 * Production-grade build configuration.
 * Run from the frontend/ directory: npm run build
 *
 * Key behaviours:
 *  – Production build outputs to ../dist/ (repo-root dist/) for clean separation.
 *  – All import.meta.env.* and process.env.* references are statically replaced
 *    via DefinePlugin at compile time.
 *  – public/ folder contents are copied verbatim into dist/.
 *  – CSS goes through PostCSS (Tailwind v4 + autoprefixer), extracted to a
 *    separate file in production, injected via style-loader in dev.
 *  – Deterministic chunk IDs + content hashes guarantee safe long-term caching.
 *  – Tree-shaking and minification via TerserPlugin + CssMinimizerPlugin.
 *  – Bundle analysis available via ANALYZE=true npm run build.
 */

'use strict';

const path                  = require('path');
const fs                    = require('fs');
const webpack               = require('webpack');
const HtmlWebpackPlugin     = require('html-webpack-plugin');
const CopyWebpackPlugin     = require('copy-webpack-plugin');
const MiniCssExtractPlugin  = require('mini-css-extract-plugin');
const CssMinimizerPlugin    = require('css-minimizer-webpack-plugin');
const TerserPlugin          = require('terser-webpack-plugin');
const { BundleAnalyzerPlugin } = require('webpack-bundle-analyzer');
// ─── Load environment-specific .env files ──────────────────────────────────
// This MUST happen inside module.exports so we can read argv/env flags passed
// by webpack CLI (for example: --env appEnv=test).
//
// Resolution order:
//   1. CLI --env appEnv=... (explicit build target)
//   2. APP_ENV / REACT_APP_APP_ENV / REACT_APP_ENV from shell/CI
//   3. Fallback from webpack mode (production/development)
//
// dotenv.config() does not override pre-set env vars by default, so CI secrets
// and workflow-level env still win over file values.

// ─── Helpers ─────────────────────────────────────────────────────────────────

/** Read an env var, trim whitespace, return empty string if absent/empty. */
const env = (key) => (process.env[key] || '').trim();
const SERVER_ERROR_CODES = [
  400, 401, 402, 403, 404, 405, 406, 408, 409, 410, 411, 412, 413, 414, 415, 416, 417,
  421, 422, 423, 424, 426, 428, 429, 431, 451,
  500, 501, 502, 503, 504, 505, 507, 508, 511,
];

// ─── Config factory ──────────────────────────────────────────────────────────

module.exports = (envFlags, argv) => {
  // Determine build mode from webpack argv FIRST
  const mode   = (argv && argv.mode) ? argv.mode : (process.env.NODE_ENV || 'development');

  // Resolve application environment (production/staging/development/test).
  const cliAppEnv = (envFlags && envFlags.appEnv) ? String(envFlags.appEnv).trim() : '';
  const inferredAppEnv = mode === 'production' ? 'production' : 'development';
  const appEnv = (
    cliAppEnv ||
    process.env.APP_ENV ||
    process.env.REACT_APP_APP_ENV ||
    process.env.REACT_APP_ENV ||
    inferredAppEnv
  ).trim().toLowerCase();

  const supportedAppEnvironments = new Set(['development', 'staging', 'production', 'test']);
  if (!supportedAppEnvironments.has(appEnv)) {
    throw new Error(`Unsupported APP_ENV "${appEnv}". Expected development, staging, production, or test.`);
  }

  const envFileByAppEnv = {
    development: '.env.development',
    staging: '.env.staging',
    production: '.env.production',
    test: '.env.test',
  };

  const envFiles = [];
  if (envFileByAppEnv[appEnv]) {
    envFiles.push(envFileByAppEnv[appEnv]);
  }
  envFiles.push(mode === 'production' ? '.env.production' : '.env.development');
  envFiles.push('.env');

  const seen = new Set();
  for (const envFile of envFiles) {
    if (!envFile || seen.has(envFile)) {
      continue;
    }
    seen.add(envFile);
    if (fs.existsSync(envFile)) {
      require('dotenv').config({ path: envFile, quiet: true });
    }
  }

  // Now that env vars are loaded, continue with the rest of config
  const isDev  = mode !== 'production';
  const analyze = process.env.ANALYZE === 'true';
  const deployTarget = env('DTB_DEPLOY_TARGET') || 'siteground';
  if (deployTarget !== 'siteground') {
    throw new Error(`Unsupported DTB_DEPLOY_TARGET "${deployTarget}". Expected siteground.`);
  }
  // Production builds are frequent and deterministic, so keep Webpack's
  // dependency-aware cache enabled by default. Set DTB_WEBPACK_FS_CACHE=0 only
  // for diagnosis; `npm run clean:build-cache` remains the explicit reset path.
  const useFilesystemCache = env('DTB_WEBPACK_FS_CACHE') !== '0';
  const emitSourceMaps = isDev || env('DTB_SOURCE_MAPS') === '1';

  // Production is root-mounted; staging is served from /staging/.
  // Development: serve from / (webpack-dev-server).
  const PUBLIC_URL = env('PUBLIC_URL').replace(/\/+$/, '');
  if (!isDev && appEnv === 'production' && PUBLIC_URL !== '') {
    throw new Error('Production is root-mounted; PUBLIC_URL must be / (or empty).');
  }
  if (!isDev && appEnv === 'staging' && PUBLIC_URL !== '/staging') {
    throw new Error('Staging must be mounted at PUBLIC_URL=/staging.');
  }
  const publicPath = isDev ? '/' : (PUBLIC_URL ? `${PUBLIC_URL}/` : '/');

  // Production and staging writes are isolated so a staging build cannot
  // overwrite the root-deployment payload.
  const outputPath = isDev
    ? path.resolve(__dirname, 'dist')
    : path.resolve(__dirname, '..', appEnv === 'staging' ? 'dist-staging' : 'dist');

  const DEV_PROXY_TARGET = env('REACT_APP_API_BASE_URL') || 'https://drywalltoolbox.com';
  const cacheName = `${mode}-${appEnv}-${deployTarget}-${PUBLIC_URL || 'root'}`.replace(/[^a-z0-9_.-]+/gi, '-');
  const siteUrl = (env('REACT_APP_SITE_URL') || 'https://drywalltoolbox.com').replace(/\/+$/, '');
  const searchIndexingEnabled = env('REACT_APP_SEARCH_INDEXING') !== '0' && appEnv === 'production';
  // Staging remains noindex at the HTML and HTTP-header layers, but robots.txt
  // permits crawling so authorized tools such as Semrush can audit it.
  const robotsRule = searchIndexingEnabled || appEnv === 'staging' ? 'Allow: /' : 'Disallow: /';
  const sitemapRule = searchIndexingEnabled ? `Sitemap: ${siteUrl}/sitemap.xml` : '';

  // ─── DefinePlugin values ────────────────────────────────────────────────
  const defines = {
    // Node / CRA conventions
    'process.env.NODE_ENV':              JSON.stringify(isDev ? 'development' : 'production'),
    'process.env.PUBLIC_URL':            JSON.stringify(publicPath),

    // import.meta.env shims — Webpack DefinePlugin replaces these at compile time.
    // All variables follow the REACT_APP_* convention (process.env.REACT_APP_*).
    'import.meta.env.BASE_URL':                         JSON.stringify(publicPath),
    'import.meta.env.MODE':                             JSON.stringify(mode),
    'import.meta.env.DEV':                              JSON.stringify(isDev),
    'import.meta.env.PROD':                             JSON.stringify(!isDev),

    // Headless WP + WooCommerce architecture
    'process.env.REACT_APP_WP_BASE_URL':                JSON.stringify(env('REACT_APP_WP_BASE_URL')),
    'process.env.REACT_APP_SITE_URL':                   JSON.stringify(env('REACT_APP_SITE_URL')),
    'process.env.REACT_APP_APP_ENV':                    JSON.stringify(env('REACT_APP_APP_ENV') || appEnv),

    // ─── Headless WooCommerce proxy (drywall/v1) ──────────────────────────────
    // Read from the environment-specific .env file loaded above.
    'process.env.REACT_APP_API_BASE_URL':               JSON.stringify(env('REACT_APP_API_BASE_URL')),
    'process.env.REACT_APP_STORE_API_BASE':             JSON.stringify(env('REACT_APP_STORE_API_BASE')),
    'process.env.REACT_APP_ENV':                        JSON.stringify(env('REACT_APP_ENV') || appEnv),
    'process.env.REACT_APP_REWARDS_ENABLED':            JSON.stringify(env('REACT_APP_REWARDS_ENABLED')),
    'process.env.REACT_APP_CATALOG_SNAPSHOTS_ENABLED':  JSON.stringify(env('REACT_APP_CATALOG_SNAPSHOTS_ENABLED')),
    'process.env.REACT_APP_DTB_CATALOG_PLATFORM':       JSON.stringify(env('REACT_APP_DTB_CATALOG_PLATFORM')),
    'process.env.REACT_APP_SEARCH_INDEXING':            JSON.stringify(env('REACT_APP_SEARCH_INDEXING')),

    // Build timestamp — set once at config evaluation time (not per-module).
    'process.env.BUILD_TIMESTAMP':                      JSON.stringify(new Date().toISOString()),

  };

  // ─── Inline asset manifest plugin ───────────────────────────────────────
  // Writes dist/asset-manifest.json so WordPress PHP can resolve hashed filenames.
  const EmitAssetManifestPlugin = {
    apply(compiler) {
      compiler.hooks.thisCompilation.tap('EmitAssetManifestPlugin', (compilation) => {
        compilation.hooks.processAssets.tap(
          { name: 'EmitAssetManifestPlugin', stage: webpack.Compilation.PROCESS_ASSETS_STAGE_SUMMARIZE },
          (assets) => {
            try {
              const files = {};
              for (const [filename] of Object.entries(assets)) {
                if (filename === 'asset-manifest.json') continue;
                const basename = path.basename(filename);
                // Preserve stable logical keys for PHP consumers while the
                // manifest value retains the content-hashed deploy URL.
                // Handles entry assets (main.<hash>.js) and lazy chunks
                // (917.<hash>.chunk.js) without collapsing their identities.
                const key = basename.replace(
                  /\.[a-f0-9]{8}(?=\.(?:chunk\.)?(?:js|css)$)/i,
                  '',
                );
                files[key] = publicPath + filename;
              }
              const entrypoints = Array.from(compilation.entrypoints.values())
                .flatMap((entrypoint) => entrypoint.getFiles())
                .filter((filename) => /\.(?:js|css)$/i.test(filename))
                .map((filename) => publicPath + filename);
              const manifest = { files, entrypoints };
              compilation.emitAsset('asset-manifest.json', new webpack.sources.RawSource(JSON.stringify(manifest, null, 2)));
            } catch (err) {
              compilation.errors.push(err);
            }
          },
        );
      });
    },
  };

  // Static 4xx/5xx error pages are an Apache/.htaccess static-hosting concern —
  // webpack-dev-server never serves them (it handles routing via
  // historyApiFallback), so this plugin is scoped to production builds only.
  // Previously it ran on every dev rebuild too, re-reading the template from
  // disk and re-emitting 35 unused assets on every HMR pass.
  //
  // The template is also read once here (outside the compilation hook)
  // instead of on every compilation/rebuild, since its content only depends
  // on PUBLIC_URL, which is fixed for the lifetime of this config instance.
  const errorPageTemplate = isDev
    ? null
    : fs.readFileSync(path.resolve(__dirname, 'error-page.html'), 'utf8')
        .replaceAll('__PUBLIC_URL__', PUBLIC_URL);

  const htmlMinifyOptions = isDev ? false : {
    removeComments:                true,
    collapseWhitespace:            true,
    removeRedundantAttributes:     true,
    useShortDoctype:               true,
    removeEmptyAttributes:         true,
    removeStyleLinkTypeAttributes: true,
    keepClosingSlash:              true,
    minifyJS:                      true,
    minifyCSS:                     true,
    minifyURLs:                    true,
  };

  const htmlPluginOptions = {
    template: './index.html',
    publicPath,
    siteUrl,
    inject: 'body',
    minify: htmlMinifyOptions,
  };

  // The homepage LCP image is selected by a React <picture>, which means the
  // browser cannot discover it until the entry bundle has parsed and rendered.
  // Injecting responsive preload hints from the final emitted asset names lets
  // the HTML parser begin the correct request immediately while retaining
  // content-hashed URLs and their immutable-cache safety.
  const HeroImagePreloadPlugin = {
    apply(compiler) {
      compiler.hooks.compilation.tap('HeroImagePreloadPlugin', (compilation) => {
        HtmlWebpackPlugin.getHooks(compilation).alterAssetTagGroups.tap(
          'HeroImagePreloadPlugin',
          (htmlPluginData) => {
            const assetNames = new Set(compilation.getAssets().map((asset) => asset.name));
            const heroAssets = [
              { match: /(?:^|\/)home-hero-mobile-640\.[a-f0-9]{8}\.webp$/i, media: '(max-width: 640px)' },
              { match: /(?:^|\/)home-hero-desktop-1600\.[a-f0-9]{8}\.webp$/i, media: '(min-width: 641px)' },
            ];

            for (const { match, media } of heroAssets) {
              const assetName = Array.from(assetNames).find((name) => match.test(name));
              if (!assetName) continue;
              htmlPluginData.headTags.push({
                tagName: 'link',
                voidTag: true,
                attributes: {
                  rel: 'preload',
                  as: 'image',
                  href: `${publicPath}${assetName}`,
                  type: 'image/webp',
                  media,
                  fetchpriority: 'high',
                },
              });
            }

            return htmlPluginData;
          },
        );
      });
    },
  };

  const EmitServerErrorPagesPlugin = {
    apply(compiler) {
      compiler.hooks.thisCompilation.tap('EmitServerErrorPagesPlugin', (compilation) => {
        compilation.hooks.processAssets.tap(
          { name: 'EmitServerErrorPagesPlugin', stage: webpack.Compilation.PROCESS_ASSETS_STAGE_ADDITIONAL },
          () => {
            for (const code of SERVER_ERROR_CODES) {
              compilation.emitAsset(
                `errors/${code}.html`,
                new webpack.sources.RawSource(errorPageTemplate.replaceAll('__ERROR_CODE__', String(code))),
              );
            }
          },
        );
      });
    },
  };

  return {
    mode,
    bail: !isDev,
    cache: useFilesystemCache
      ? {
          type: 'filesystem',
          name: cacheName,
          buildDependencies: {
            config: [
              __filename,
              path.resolve(__dirname, 'babel.config.json'),
              path.resolve(__dirname, 'postcss.config.js'),
              path.resolve(__dirname, 'package-lock.json'),
            ],
          },
        }
      : false,

    // ─── Entry ─────────────────────────────────────────────────────────────
    entry: './src/main.jsx',

    // ─── Output ────────────────────────────────────────────────────────────
    output: {
      path:      outputPath,
      publicPath,
      // Production assets are content-addressed. SiteGround/CDN caches JS for
      // long periods, so reusing a route-chunk URL can otherwise leave one
      // page running old React code after a successful deployment.
      filename: isDev ? 'assets/js/[name].js' : 'assets/js/[name].[contenthash:8].js',
      chunkFilename: isDev
        ? 'assets/js/[name].chunk.js'
        : 'assets/js/[name].[contenthash:8].chunk.js',
      assetModuleFilename: 'assets/media/[name].[hash:8][ext]',
      clean: true,
    },

    // ─── Resolution ────────────────────────────────────────────────────────
    resolve: {
      extensions: ['.js', '.jsx', '.json'],
      // Resolve absolute imports from frontend/public/, root public/, and project root.
      // Brands and schematics live in the root public/brands/ (not duplicated in frontend/).
      roots: [
        path.resolve(__dirname, 'public'),
        path.resolve(__dirname, '..', 'public'),  // root public/ for brands/schematics
        path.resolve(__dirname),
      ],
      alias: {
        '@':           path.resolve(__dirname, 'src'),
        '@api':        path.resolve(__dirname, 'src/api'),
        '@assets':     path.resolve(__dirname, 'src/assets'),
        '@components': path.resolve(__dirname, 'src/components'),
        '@hooks':      path.resolve(__dirname, 'src/hooks'),
        '@pages':      path.resolve(__dirname, 'src/pages'),
        '@styles':     path.resolve(__dirname, 'src/styles'),
        '@context':    path.resolve(__dirname, 'src/context'),
      },
    },

    // ─── Loaders ───────────────────────────────────────────────────────────
    module: {
      rules: [
        {
          test:    /\.(js|jsx)$/,
          exclude: [ /node_modules/, /\.generated\.js$/ ],
          use: {
            loader: 'babel-loader',
            options: {
              cacheDirectory: isDev
                ? path.resolve(__dirname, 'node_modules', '.cache', 'babel-loader')
                : false,
              cacheCompression: isDev ? false : undefined,
            },
          },
        },
        {
          test: /\.css$/,
          use: [
            isDev ? 'style-loader' : MiniCssExtractPlugin.loader,
            {
              loader: 'css-loader',
              options: { importLoaders: 1, sourceMap: isDev },
            },
            {
              loader: 'postcss-loader',
              options: { sourceMap: isDev },
            },
          ],
        },
        {
          test: /\.(png|jpe?g|gif|ico|webp)$/i,
          type: 'asset',
          parser: { dataUrlCondition: { maxSize: 4 * 1024 } },
          generator: { 
            filename: 'assets/images/[name].[hash:8][ext]',
            // Don't inline large images; always reference them as separate files
          },
          // NOTE: Schematic PNGs (4+ MiB) should be CDN-served, not bundled
        },
        {
          test: /\.svg$/i,
          type: 'asset/resource',
          generator: { filename: 'assets/images/[name].[hash:8][ext]' },
        },
        {
          test: /\.(woff2?|eot|ttf|otf)$/i,
          type: 'asset/resource',
          generator: { filename: 'assets/fonts/[name].[hash:8][ext]' },
        },
      ],
    },

    // ─── Plugins ───────────────────────────────────────────────────────────
    plugins: [
      new webpack.DefinePlugin(defines),

      new HtmlWebpackPlugin(htmlPluginOptions),

      HeroImagePreloadPlugin,

      // During pre-launch, production-root index.html remains the public
      // coming-soon document. Customer-owned routes can use this equivalent
      // React shell without launching or replacing the homepage.
      ...(!isDev && appEnv === 'production'
        ? [new HtmlWebpackPlugin({ ...htmlPluginOptions, filename: 'storefront.html' })]
        : []),

      new CopyWebpackPlugin({
        patterns: [
          {
            from: 'public',
            to:   '.',
            globOptions: {
              dot: true,
              ignore: [
                // HTML handled by HtmlWebpackPlugin
                '**/index.html',
                '**/.htaccess',
                '**/robots.txt',
                // CSV data files — served via WooCommerce REST API, not bundled
                '**/*.csv',
                '**/*.bak',
                // Source/download archives must never inflate deploy payloads.
                '**/*.zip',
                '**/products_catalog.csv',
                '**/products_catalog_*.csv',
                // Dev/build scripts — never ship to dist
                '**/scripts/**',
                // Scraped product data — source files only, not for dist
                '**/scraped_results/**',
                // Shared runtime assets are deployed once at public_html/brands.
                // Imported brand logos are still emitted as hashed module assets.
                '**/brands/**',
              ],
            },
          },
          {
            from: 'public/robots.txt',
            to: 'robots.txt',
            toType: 'file',
            transform(content) {
              return content
                .toString()
                .replaceAll('__DTB_ROBOTS_RULE__', robotsRule)
                .replaceAll('__DTB_SITEMAP_RULE__', sitemapRule);
            },
          },
          {
            from: path.resolve(
              __dirname,
              '..',
              'drywalltoolbox',
              appEnv === 'staging'
                ? path.join('staging', '.htaccess')
                : '.htaccess',
            ),
            to: '.htaccess',
            toType: 'file',
          },
        ],
      }),

      // Production-only: static error pages for Apache/.htaccess hosting.
      // Skipped in dev — webpack-dev-server never serves these, and the
      // template is now read once above instead of on every rebuild.
      ...(!isDev ? [EmitServerErrorPagesPlugin] : []),

      ...(!isDev ? [
        new MiniCssExtractPlugin({
          filename:      'assets/css/[name].[contenthash:8].css',
          chunkFilename: 'assets/css/[name].[contenthash:8].chunk.css',
          // Different entry points/async chunks can require the same CSS
          // module graph in different orders (for example after code-splitting
          // a component like ProductDetail into its own chunk). Webpack cannot
          // always prove a single global order is safe and warns "Conflicting
          // order" even when the files involved have zero overlapping
          // selectors. Verified case-by-case that our CSS files are scoped to
          // disjoint class namespaces (BEM-style, component-scoped) with no
          // cross-file cascade dependency, so a non-deterministic chunk-load
          // order across pages cannot change which rule wins.
          ignoreOrder: true,
        }),

      ] : []),

      EmitAssetManifestPlugin,

      ...(analyze ? [new BundleAnalyzerPlugin({ analyzerMode: 'static', openAnalyzer: true })] : []),
    ],

    // ─── Optimisation ──────────────────────────────────────────────────────
    optimization: {
      minimize: !isDev,
      minimizer: [
        new TerserPlugin({
          parallel: true,
          terserOptions: {
            compress: {
              drop_console: true,
              drop_debugger: true,
              pure_funcs: ['console.log', 'console.info', 'console.debug'],
            },
            output: { comments: false },
          },
          extractComments: false,
        }),
        new CssMinimizerPlugin(),
      ],

      splitChunks: {
        chunks: 'all',
        maxInitialRequests: 6,
        maxAsyncRequests:   8,
        minSize: 20_000,
        minRemainingSize: 0,
        cacheGroups: {
          // Disable Webpack's implicit default groups. They emit numeric shared
          // async chunks (for example 770.chunk.js) that are easy to miss during
          // selective file transfers, leaving lazy checkout routes unable to
          // load even when their route-specific chunk exists.
          default: false,
          defaultVendors: false,
          // React core — must be in the initial bundle for every route.
          reactVendor: {
            test:     /[\\/]node_modules[\\/](react|react-dom|react-router(-dom)?)[\\/]/,
            name:     'vendor-react',
            chunks:   'initial',
            priority: 50,
            reuseExistingChunk: true,
          },
          // framer-motion: the app renders framer-motion's lightweight `m`
          // component everywhere (never the full `motion` component), and a
          // single <LazyMotion features={loadMotionFeatures}> provider in
          // App.jsx loads the actual animation engine (domMax) via a dynamic
          // import() (see src/motion/asyncFeatures.js). Deliberately no
          // dedicated cache group here: giving this a fixed `name` would
          // force every framer-motion module — including the heavy engine
          // only reachable through the async import() — into one physical
          // chunk, and since `LazyMotion` itself is imported synchronously,
          // that merged chunk would be classified as part of the initial
          // entrypoint again (this is what caused the ~110 KB
          // vendor-framer-motion chunk to load eagerly on every page even
          // after the LazyMotion migration — verified by inspecting the
          // production build output). Leaving this ungrouped lets webpack's
          // default async chunking put the dynamically-imported engine in
          // its own on-demand chunk, while the small sync-only LazyMotion
          // import falls into the general `vendor` group below.

          // lucide-react is tree-shaken per icon, but its shared runtime is
          // used by both the initial shell (Header/Footer) and async pages.
          // 'all' canonicalises it into one chunk.
          lucide: {
            test:     /[\\/]node_modules[\\/]lucide-react[\\/]/,
            name:     'vendor-lucide',
            chunks:   'all',
            priority: 45,
            reuseExistingChunk: true,
          },
          // Remaining node_modules share one initial vendor chunk.
          vendor: {
            test:     /[\\/]node_modules[\\/]/,
            name:     'vendor',
            chunks:   'initial',
            priority: 20,
            reuseExistingChunk: true,
          },
          // App code shared across ≥2 async chunks (e.g. api/client, utils).
          common: {
            name:               'common',
            minChunks:          2,
            chunks:             'all',
            priority:           10,
            reuseExistingChunk: true,
            enforce:            true,
          },
        },
      },

      runtimeChunk: 'single',
      moduleIds: 'deterministic',
      chunkIds:  'deterministic',
    },

    // ─── Dev server ────────────────────────────────────────────────────────
    devServer: {
      port:               5173,
      historyApiFallback: true,
      hot:                true,
      open:               true,
      compress:           true,
      static: [
        {
          directory:  path.join(__dirname, 'public'),
          publicPath,
        },
        {
          // Also serve root public/ for brands/ and schematics/ directories.
          // These are large assets not duplicated in frontend/public/.
          directory:  path.join(__dirname, '..', 'public'),
          publicPath,
        },
      ],
      proxy: [
        {
          // Proxy all WP/WC REST and DTB API paths to the live backend.
          // This lets the dev server add the correct Origin and rewrite
          // CORS headers so the browser never sees a cross-origin request.
          context: [
            '/wp-json',       // canonical WP REST alias
            '/wp/wp-json',    // WP in /wp subdir
            '/wp-admin',      // WP admin-ajax etc.
          ],
          target: DEV_PROXY_TARGET,
          changeOrigin: true,
          secure: true,
        },
        {
          // WordPress media library (category thumbnails, product photos,
          // etc). These are referenced throughout the app as root-relative
          // URLs (e.g. utils/categoryThumbnailImages.js's
          // `/wp-content/uploads/...`) that resolve correctly once the
          // production build is deployed to the same origin as WordPress —
          // but in local dev, localhost:5173 has no such path, so every
          // WP-media <img> (ShopByToolType's tool-type tiles, the desktop
          // mega menu's category thumbnails, etc.) 404s and shows the
          // browser's broken-image glyph. Proxying it the same way as the
          // REST endpoints above fixes it for every consumer, not just one
          // component — confirmed the underlying live URLs are valid
          // (e.g. https://drywalltoolbox.com/wp-content/uploads/2026/categories/thumbnails/automatic-tapers.webp
          // returns real WebP image data), so this is purely a missing
          // dev-proxy route, not a wrong asset path.
          context: ['/wp-content'],
          target: DEV_PROXY_TARGET,
          changeOrigin: true,
          secure: true,
        },
      ],
      client: {
        overlay: { errors: true, warnings: false },
        progress: true,
      },
    },

    // Production maps are opt-in. Hidden maps are useful only when an
    // error-monitoring upload consumes them; otherwise they add deploy weight.
    devtool: isDev
      ? 'eval-cheap-module-source-map'
      : (emitSourceMaps ? 'hidden-source-map' : false),

    performance: {
      hints:             isDev ? false : 'warning',
      // Measured against a real production build (`npx webpack --mode
      // production`) after removing framer-motion from the initial
      // entrypoint (see the `m`/<LazyMotion> migration and the
      // splitChunks comment above): runtime + vendor-react + vendor-lucide
      // + vendor + main.css + main.js totalled ~1.16 MiB (1,213,547 B).
      // Previously this budget was raised to 1,300,000/1,000,000 to match
      // an inflated bundle that shipped the full framer-motion engine
      // (~110 KB) eagerly on every route — that is the anti-pattern this
      // budget exists to catch, not something to hide by raising the
      // ceiling again. maxEntrypointSize below has ~3% headroom over the
      // measured baseline so routine dependency bumps don't flap, while
      // still failing if a change re-inflates the initial bundle.
      // maxAssetSize covers the largest legitimate single asset today
      // (the shared `common` async route chunk, ~561 KB, loaded on demand
      // by multiple lazy pages — not part of the initial entrypoint) with
      // similar headroom.
      maxEntrypointSize: 1_250_000,
      maxAssetSize:      600_000,
      // Only warn on JS and CSS — images/fonts/media are expected to be large
      assetFilter: (assetFilename) =>
        /\.(js|css)$/.test(assetFilename) && !assetFilename.endsWith('.map'),
    },

    // Both branches previously resolved to the same value ('errors-warnings');
    // simplified to remove the no-op conditional.
    stats: 'errors-warnings',
  };
};
