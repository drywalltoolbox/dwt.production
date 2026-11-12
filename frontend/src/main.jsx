import './bootstrapRuntimeAssetBase.js';
import { StrictMode, useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import { HelmetProvider } from 'react-helmet-async';
import '@fontsource-variable/geist/wght.css';
import '@fontsource-variable/geist/wght-italic.css';

/* Canonical global order: utilities -> tokens -> layout foundation. */
import './index.css';
import './styles/storefront-tokens.css';
import './styles/responsive-foundation.css';

/* Shared feature and component authorities. */
import './styles/machined-design.css';
import './styles/hero-section.css';
import './styles/trusted-brands.css';
import './styles/home-hero.css';
import './styles/home-hero-desktop.css';
import './styles/storefront-shell.css';
import './styles/storefront-sections.css';
import './styles/storefront-product-card.css';
import './styles/storefront-drawer.css';
import './styles/storefront-cart-sheet.css';
import './styles/storefront-search-product-cards.css';
import './styles/storefront-visibility.css';
import './styles/account-hub.css';
import './styles/account-hub-motion.css';
import './styles/account-hub-cta.css';
import './styles/global-loading.css';
import './styles/cart-interaction-feedback.css';
import './styles/add-to-cart-button.css';
import './styles/loading-transitions.css';
import './styles/global-typography.css';

/* Shared timing/easing authority loads after feature appearance styles so
 * component geometry remains local while transition behavior stays global. */
import './styles/storefront-motion.css';

/* Shared cross-route responsive defaults; route CSS stays feature-scoped. */
import './styles/responsive-shared.css';
import './styles/side-sheet.css';

import { initializeGeminiStorefront } from './integrations/geminiStorefront.js';
import App from './App.jsx';
import ErrorBoundary from './components/system/AppErrorBoundary.jsx';
import GlobalMotionProvider from './components/motion/GlobalMotionProvider.jsx';
import { installRepairPackageSelectionRuntime } from './utils/repairPackageSelectionRuntime.js';
import { prewarmCatalog } from './services/catalog.js';

// Note: the legacy schematicPageLabelRuntime / mobileSchematicNavRuntime
// global DOM/history runtimes were schematics-only compatibility shims for
// the old, now-removed frontend/src/pages/Schematics.jsx implementation.
// The /schematics route (frontend/src/pages/SchematicsPage.jsx) owns page
// labels and navigation directly through React state and React Router.
installRepairPackageSelectionRuntime();
void initializeGeminiStorefront();

if (typeof window !== 'undefined') {
  const pathname = window.location.pathname.replace(/^\/drywall-toolbox(?=\/|$)/, '') || '/';
  const isCatalogRoute = pathname.startsWith('/products') || pathname.startsWith('/parts');
  const isHomePage = pathname === '/';
  const CATALOG_PREWARM_TIMEOUT_MS = 5000;

  // Home owns its first catalog reads through its visible rails. A duplicate
  // startup prewarm competes with the LCP image and repeats that work before
  // the user has requested catalog navigation. Keep the prewarm for other
  // non-catalog routes, after their initial rendering window.
  if (!isCatalogRoute && !isHomePage) {
    const scheduleCatalogPrewarm = () => prewarmCatalog();
    window.setTimeout(scheduleCatalogPrewarm, CATALOG_PREWARM_TIMEOUT_MS);
  }
}

function markAppMounted() {
  if (typeof document === 'undefined') return;

  document.documentElement.setAttribute('data-dtb-app-mounted', 'true');

  // Tell the entry-bundle watchdog that React mounted successfully, then clear
  // any retry marker left by a prior failed entry bundle.
  try {
    window.sessionStorage.removeItem('dtb:boot-retry');
  } catch {
    // Session storage can be unavailable in strict/private browsing modes.
  }
}

function AppMountedMarker() {
  useEffect(() => {
    markAppMounted();
    return undefined;
  }, []);

  return null;
}

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <HelmetProvider>
      <GlobalMotionProvider>
        <ErrorBoundary>
          <AppMountedMarker />
          <App />
        </ErrorBoundary>
      </GlobalMotionProvider>
    </HelmetProvider>
  </StrictMode>,
);
