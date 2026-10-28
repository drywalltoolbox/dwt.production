import { lazy } from 'react';

const moduleLoaders = {
  products: () => import('../pages/Products.jsx'),
  parts: () => import('../pages/Parts.jsx'),
  productLegacy: () => import('../pages/Product.jsx'),
  productDetail: () => import('../pages/ProductDetailPage.jsx'),
  category: () => import('../pages/CategoryLandingPage.jsx'),
  schematics: () => import('../pages/SchematicsPage.jsx'),
  repairs: () => import('../pages/RepairLanding.jsx'),
  repairStart: () => import('../pages/RepairStart.jsx'),
  repairPackages: () => import('../pages/RepairPackages.jsx'),
  repairTrack: () => import('../pages/RepairTrack.jsx'),
  repairStatus: () => import('../pages/RepairStatus.jsx'),
  returnStatus: () => import('../pages/ReturnStatus.jsx'),
  supportStatus: () => import('../pages/SupportStatus.jsx'),
  cart: () => import('../pages/Cart.jsx'),
  checkout: () => import('../pages/WooNativeCheckout.jsx'),
  checkoutReturn: () => import('../pages/CheckoutReturn.jsx'),
  orderConfirmation: () => import('../pages/OrderConfirmation.jsx'),
  orderTracking: () => import('../pages/OrderTracking.jsx'),
  contact: () => import('../pages/Contact.jsx'),
  wooCommerceSettings: () => import('../pages/WooCommerceSettings.jsx'),
  login: () => import('../pages/Login.jsx'),
  register: () => import('../pages/Register.jsx'),
  forgotPassword: () => import('../pages/ForgotPassword.jsx'),
  resetPassword: () => import('../pages/ResetPassword.jsx'),
  dashboard: () => import('../pages/Dashboard.jsx'),
  calculators: () => import('../pages/Calculators.jsx'),
  help: () => import('../pages/Help.jsx'),
  shippingPolicy: () => import('../pages/ShippingPolicy.jsx'),
  returnPortal: () => import('../pages/ReturnPortal.jsx'),
  storePolicies: () => import('../pages/StorePolicies.jsx'),
  returnPolicy: () => import('../pages/ReturnPolicy.jsx'),
  termsOfService: () => import('../pages/legal/TermsOfService.jsx'),
  privacyPolicy: () => import('../pages/legal/PrivacyPolicy.jsx'),
  technicalSpecificationsPreview: () => import('../pages/TechnicalSpecificationsPreview.jsx'),
};

const modulePromises = new Map();

function loadModule(key) {
  const loader = moduleLoaders[key];
  if (!loader) return Promise.resolve(null);

  if (!modulePromises.has(key)) {
    const promise = loader().catch((error) => {
      modulePromises.delete(key);
      throw error;
    });
    modulePromises.set(key, promise);
  }

  return modulePromises.get(key);
}

function normalizePathname(input) {
  if (!input) return '/';

  try {
    const url = new URL(input, typeof window !== 'undefined' ? window.location.origin : 'https://drywalltoolbox.com');
    let pathname = url.pathname || '/';
    const basename = String(process.env.PUBLIC_URL || '').replace(/\/+$/, '');

    if (basename && basename !== '/' && pathname.startsWith(basename)) {
      pathname = pathname.slice(basename.length) || '/';
    }

    return pathname.startsWith('/') ? pathname : `/${pathname}`;
  } catch {
    const pathname = String(input).split(/[?#]/, 1)[0] || '/';
    return pathname.startsWith('/') ? pathname : `/${pathname}`;
  }
}

export function resolveRouteModuleKey(input) {
  const pathname = normalizePathname(input);

  if (pathname === '/' || pathname === '/all-products' || pathname === '/orders' || pathname === '/rewards' || pathname === '/account-settings' || pathname === '/addresses' || pathname === '/notifications') return null;
  if (pathname === '/products' || pathname.startsWith('/products/brands')) return 'products';
  if (/^\/products\/[^/]+(?:\/variations\/[^/]+)?$/.test(pathname)) return 'productDetail';
  if (pathname === '/parts') return 'parts';
  if (pathname.startsWith('/product/')) return 'productLegacy';
  if (pathname.startsWith('/category/')) return 'category';
  if (pathname === '/schematics') return 'schematics';
  if (pathname === '/repairs') return 'repairs';
  if (pathname === '/repairs/start') return 'repairStart';
  if (pathname === '/repairs/packages') return 'repairPackages';
  if (pathname === '/repairs/track') return 'repairTrack';
  if (pathname.startsWith('/repairs/status/') || pathname.startsWith('/dashboard/repairs/')) return 'repairStatus';
  if (pathname.startsWith('/returns/status/')) return 'returnStatus';
  if (pathname.startsWith('/support/status/')) return 'supportStatus';
  if (pathname === '/returns') return 'returnPortal';
  if (pathname === '/return-policy') return 'returnPolicy';
  if (pathname === '/policies') return 'storePolicies';
  if (pathname === '/terms-of-service' || pathname === '/terms-of-service/') return 'termsOfService';
  if (pathname === '/privacy-policy' || pathname === '/privacy-policy/') return 'privacyPolicy';
  if (pathname === '/cart') return 'cart';
  if (pathname === '/checkout') return 'checkout';
  if (pathname.startsWith('/checkout/')) return 'checkoutReturn';
  if (pathname.startsWith('/order-tracking/')) return 'orderTracking';
  if (pathname.startsWith('/order/')) return 'orderConfirmation';
  if (pathname === '/contact') return 'contact';
  if (pathname === '/settings/woocommerce') return 'wooCommerceSettings';
  if (pathname === '/login') return 'login';
  if (pathname === '/register') return 'register';
  if (pathname === '/forgot-password') return 'forgotPassword';
  if (pathname === '/reset-password') return 'resetPassword';
  if (pathname === '/dashboard') return 'dashboard';
  if (pathname === '/calculators') return 'calculators';
  if (pathname === '/help' || pathname === '/faq') return 'help';
  if (pathname === '/shipping-policy') return 'shippingPolicy';
  if (pathname === '/preview/technical-specifications') return 'technicalSpecificationsPreview';

  return null;
}

// Speculative imports are bounded; actual React.lazy navigation bypasses this
// queue via loadModule() so an activated route never waits behind hover work.
const MAX_SPECULATIVE_IMPORTS = 2;
const speculativeQueue = [];
const speculativeRequests = new Map();
let activeSpeculativeImports = 0;

function drainSpeculativeQueue() {
  while (activeSpeculativeImports < MAX_SPECULATIVE_IMPORTS && speculativeQueue.length) {
    const task = speculativeQueue.shift();
    activeSpeculativeImports += 1;
    loadModule(task.key)
      .then(task.resolve, () => task.resolve(null))
      .finally(() => {
        activeSpeculativeImports -= 1;
        speculativeRequests.delete(task.key);
        drainSpeculativeQueue();
      });
  }
}

export function preloadRoute(input) {
  const key = resolveRouteModuleKey(input);
  if (!key || key === 'checkout' || key === 'checkoutReturn') return Promise.resolve(null);

  // Speculation is optional. Save-Data must never block an actual route load.
  if (typeof navigator !== 'undefined') {
    const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    if (connection?.saveData) return Promise.resolve(null);
  }

  if (modulePromises.has(key)) return modulePromises.get(key).catch(() => null);
  if (speculativeRequests.has(key)) return speculativeRequests.get(key);

  const promise = new Promise((resolve) => {
    speculativeQueue.push({ key, resolve });
  });
  speculativeRequests.set(key, promise);
  drainSpeculativeQueue();
  return promise;
}

function withChunkReload(key) {
  return loadModule(key).catch((error) => {
    const message = String(error?.message || '');
    const isChunkLoadFailure =
      /ChunkLoadError/i.test(message) ||
      /Loading chunk [\w-]+ failed/i.test(message) ||
      /Failed to fetch dynamically imported module/i.test(message);

    if (isChunkLoadFailure && typeof window !== 'undefined') {
      const retryKey = `dtb:lazy-retry:${window.location.pathname}`;
      const hasRetried = window.sessionStorage.getItem(retryKey) === '1';

      if (!hasRetried) {
        window.sessionStorage.setItem(retryKey, '1');
        window.location.reload();
        return new Promise(() => {});
      }

      window.sessionStorage.removeItem(retryKey);
    }

    throw error;
  });
}

export function createLazyRoute(key) {
  if (!moduleLoaders[key]) {
    throw new Error(`Unknown DTB route module: ${key}`);
  }

  return lazy(() => withChunkReload(key));
}
