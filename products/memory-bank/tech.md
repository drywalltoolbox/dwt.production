# Drywall Toolbox — Technology Stack

## Frontend

### Core
- **React 19** with JSX (`.jsx` files for components/pages, `.js` for utilities/hooks)
- **React Router DOM v7** — SPA routing with `BrowserRouter`, lazy routes
- **Webpack 5** — build system (not Vite); config in `frontend/webpack.config.cjs`
- **Babel** — transpilation via `@babel/preset-env` + `@babel/preset-react`

### Styling
- **Tailwind CSS v4** via PostCSS (`@tailwindcss/postcss`)
- **Scoped CSS files** in `src/styles/` — one file per component/feature, no CSS modules
- **CSS custom properties** for design tokens (managed by Visual Designer plugin)
- **Framer Motion v11** for page transitions and animations

### Key Libraries
| Library | Use |
|---|---|
| `axios` | HTTP client for all API calls |
| `framer-motion` | Animations and page transitions |
| `lucide-react` | Icon library (tree-shaken) |
| `react-helmet-async` | Document head management |
| `@base-ui/react` | Accessible UI primitives |
| `@fontsource-variable/geist` | Self-hosted Geist variable font |
| `socket.io-client` | Real-time order/repair event streams |
| `dompurify` | HTML sanitization |
| `match-sorter` | Fuzzy search/sort |

### Build System Details
- Entry: `frontend/src/main.jsx`
- Production output: `dist/` at repo root (for SiteGround deployment)
- Dev output: `frontend/dist/`
- Dev server: port 5173, proxies `/wp-json` and `/wp-admin` to live backend
- Chunk strategy: named vendor chunks (react, framer-motion, lucide, stripe, vendor, common)
- CSS: `MiniCssExtractPlugin` in production, `style-loader` in dev
- Asset manifest: `dist/asset-manifest.json` for WordPress PHP filename resolution
- Source maps: dev only by default; production opt-in via `DTB_SOURCE_MAPS=1`
- Bundle analysis: `ANALYZE=true npm run build`

### Path Aliases
```
@           → src/
@api        → src/api/
@components → src/components/
@hooks      → src/hooks/
@pages      → src/pages/
@styles     → src/styles/
@context    → src/context/
```

### Dev Commands
```bash
npm run dev              # Webpack dev server (port 5173, HMR)
npm run build            # Production build → repo-root dist/
npm run lint             # ESLint on src/
npm run reviews-server   # Local mock reviews server
npm run clean:build-cache
```

### Environment Variables
All prefixed `REACT_APP_*` (CRA convention, replaced at compile time via DefinePlugin):
- `REACT_APP_WP_API_BASE` — WordPress REST base URL
- `REACT_APP_WC_API_BASE` — WooCommerce REST base URL
- `REACT_APP_DTB_API_BASE` — Custom DTB REST namespace base
- `REACT_APP_JWT_ENDPOINT` — JWT auth endpoint
- `REACT_APP_SITE_URL` — Canonical site URL
- `REACT_APP_APP_ENV` — `production` | `development` | `test`
- `REACT_APP_DTB_CATALOG_PLATFORM` — Feature flag for catalog platform
- `REACT_APP_CATALOG_SNAPSHOTS_ENABLED` — Catalog snapshot caching flag

---

## Backend (WordPress / WooCommerce)

### Platform
- **WordPress** (latest) in `/wp/` subdirectory
- **WooCommerce** — ecommerce engine, order management, product catalog base
- **PHP** (version from `DTB_Diagnostics::snapshot()` — reads `PHP_VERSION`)
- **SiteGround** hosting with SiteGround Optimizer for caching

### Custom PHP Plugins (mu-plugins)
All plugins are must-use (auto-loaded), no activation required. See `structure.md` for full list.

### PHP Conventions
- Namespace: class-per-file, `DTB_` prefix for standalone classes
- All files guard with `defined('ABSPATH') || exit;`
- Class-exists guard for singleton-style classes: `if (class_exists('DTB_X')) return;`
- `final` keyword on utility/singleton classes
- PHPDoc `@return array<string,mixed>` type annotations

### REST API
- WP REST API namespace: `drywall/v1`
- All custom controllers extend `AbstractRestController`
- Responses via `RestResponseFactory`
- JWT Bearer token authentication for protected endpoints

### Database
- Custom tables installed via schema installer classes (`*SchemaInstaller.php`)
- Event sourcing tables for orders, repairs, support tickets
- WooCommerce order meta for toolset line items

---

## DevOps & Tooling

### Deployment
- **SiteGround Git deployment** via webhook (`scripts/deployment/siteground-git-release.sh`)
- GitHub Actions workflows: checkout UI contract tests, release to SiteGround
- Protected paths policy enforced during deployment

### Scripts
- **PowerShell** (`.ps1`) — catalog normalization, media sync, inventory rebuild
- **Python** — catalog image processing, schematic filename normalization, launch readiness suite
- **SQL** — production product reset script

### Launch Readiness Suite (`scripts/launch-readiness/`)
- Python + Selenium browser automation
- Tests: routing contract, guest checkout, customer checkout, website crawl
- Integrations: WooCommerce, Veeqo, QuickBooks API checks
- HTML/JSON report output

### Integrations
- **Veeqo** — inventory management and fulfillment
- **QuickBooks Online** — accounting (OAuth 2.0)
- **Amazon SP-API** — marketplace orders
- **eBay** — marketplace orders
- **Stripe** — payment processing
- **Socket.IO** — real-time event streaming (order/repair status)
