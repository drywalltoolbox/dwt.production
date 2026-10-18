import { BrowserRouter as Router, Routes, Route, useLocation, useNavigationType, Navigate, useNavigate } from 'react-router-dom';
import { useState, useEffect, useLayoutEffect, Suspense, useCallback, useRef } from 'react';
import { LazyMotion } from 'framer-motion';
import loadMotionFeatures from './motion/asyncFeatures.js';
import PageTransition from './components/routing/PageTransition';
import RouteIntentPreloader from './components/routing/RouteIntentPreloader.jsx';
import StorefrontScrollExperience from './components/routing/StorefrontScrollExperience.jsx';
import RouteLoadingSurface from './components/routing/RouteLoadingSurface.jsx';
import { createLazyRoute } from './routing/routeModules.js';
import { CartProvider } from './context/CartContext';
import { WooCommerceProvider } from './context/WooCommerceContext';
import { WorkflowTransitionProvider } from './context/WorkflowTransitionContext.jsx';
import { AuthProvider, useAuthContext } from './auth/AuthContext.js';
import { DesignConfigProvider } from './context/DesignConfigContext.jsx';
import { usePreviewRouteSync } from './designer/usePreviewRouteSync.js';
import AppErrorBoundary from './components/system/AppErrorBoundary.jsx';
import CustomerErrorPage from './components/errors/CustomerErrorPage.jsx';
import Header from './components/shell/Header';
import Footer from './components/shell/Footer';
import CartSidebar from './components/shell/CartSidebar';
import ProtectedRoute from './components/routing/ProtectedRoute';
import HomepageSignupCTA from './components/cta/HomepageSignupCTA.jsx';
import SmartBackButton from './components/navigation/SmartBackButton.jsx';
import { isRewardsEnabled } from './utils/featureFlags.js';
import { initializeWebpackPublicPath } from './setWebpackPublicPath.js';
import Home from './pages/Home.jsx';

const HOMEPAGE_SIGNUP_CTA_SEEN_KEY = 'dtb:homepage-signup-cta-seen:v1';
const HOMEPAGE_SIGNUP_CTA_DELAY_MS = 900;

initializeWebpackPublicPath();

function getLocalStorageFlag(key) {
  if (typeof window === 'undefined') return false;

  try {
    return window.localStorage.getItem(key) === '1';
  } catch {
    return false;
  }
}

function setLocalStorageFlag(key) {
  if (typeof window === 'undefined') return;

  try {
    window.localStorage.setItem(key, '1');
  } catch {
    // Local storage can be unavailable in private browsing or strict modes.
  }
}

// The homepage contains the LCP element. It is intentionally part of the
// entry route rather than a lazy boundary: a fallback-to-home swap moves the
// shared footer by the full page height and was the direct source of the
// measured 0.217 CLS regression on mobile Lighthouse.
const Products = createLazyRoute('products');
const Parts = createLazyRoute('parts');
const Product = createLazyRoute('productLegacy');
const ProductDetailPage = createLazyRoute('productDetail');
const CategoryLandingPage = createLazyRoute('category');
const Schematics = createLazyRoute('schematics');
const Repairs = createLazyRoute('repairs');
const RepairStart = createLazyRoute('repairStart');
const RepairPackages = createLazyRoute('repairPackages');
const RepairTrack = createLazyRoute('repairTrack');
const RepairStatus = createLazyRoute('repairStatus');
const ReturnStatus = createLazyRoute('returnStatus');
const SupportStatus = createLazyRoute('supportStatus');
const Cart = createLazyRoute('cart');
const Checkout = createLazyRoute('checkout');
const CheckoutReturn = createLazyRoute('checkoutReturn');
const OrderConfirmation = createLazyRoute('orderConfirmation');
const OrderTracking = createLazyRoute('orderTracking');
const Contact = createLazyRoute('contact');
const WooCommerceSettings = createLazyRoute('wooCommerceSettings');
const Login = createLazyRoute('login');
const Register = createLazyRoute('register');
const ForgotPassword = createLazyRoute('forgotPassword');
const ResetPassword = createLazyRoute('resetPassword');
const Dashboard = createLazyRoute('dashboard');
const Calculators = createLazyRoute('calculators');
const FAQ = createLazyRoute('faq');
const ShippingPolicy = createLazyRoute('shippingPolicy');
const ReturnPortal = createLazyRoute('returnPortal');
const StorePolicies = createLazyRoute('storePolicies');
const ReturnPolicy = createLazyRoute('returnPolicy');
const TermsOfService = createLazyRoute('termsOfService');
const PrivacyPolicy = createLazyRoute('privacyPolicy');
const TechnicalSpecificationsPreview = createLazyRoute('technicalSpecificationsPreview');

function ScrollToTop() {
  const location = useLocation();
  const navigationType = useNavigationType();
  const scrollPositionsRef = useRef(new Map());
  const lastKnownScrollRef = useRef(
    typeof window === 'undefined'
      ? { top: 0, left: 0 }
      : { top: window.scrollY, left: window.scrollX },
  );
  const previousLocationRef = useRef({
    key: location.key,
    routeKey: `${location.pathname}${location.hash}`,
  });

  useEffect(() => {
    if (typeof window === 'undefined' || !('scrollRestoration' in window.history)) {
      return undefined;
    }

    const previousScrollRestoration = window.history.scrollRestoration;
    window.history.scrollRestoration = 'manual';

    return () => {
      window.history.scrollRestoration = previousScrollRestoration;
    };
  }, []);

  useEffect(() => {
    if (typeof window === 'undefined') return undefined;

    const captureScroll = () => {
      lastKnownScrollRef.current = {
        top: window.scrollY,
        left: window.scrollX,
      };
    };

    captureScroll();
    window.addEventListener('scroll', captureScroll, { passive: true });
    return () => window.removeEventListener('scroll', captureScroll);
  }, []);

  useLayoutEffect(() => {
    if (typeof window === 'undefined') return undefined;

    // Query parameters represent in-page state on routes such as repair
    // package tabs and catalog filters. They intentionally do not define a
    // new viewport position. Path/hash changes do.
    const routeKey = `${location.pathname}${location.hash}`;
    const previousLocation = previousLocationRef.current;

    if (previousLocation.key !== location.key) {
      scrollPositionsRef.current.set(previousLocation.key, lastKnownScrollRef.current);

      // History-key positions are session-only UI state. Bound the map so a
      // very long SPA session cannot grow it without limit.
      if (scrollPositionsRef.current.size > 100) {
        const oldestKey = scrollPositionsRef.current.keys().next().value;
        scrollPositionsRef.current.delete(oldestKey);
      }
    }

    previousLocationRef.current = { key: location.key, routeKey };

    if (previousLocation.routeKey === routeKey) {
      return undefined;
    }

    // Reset only route-owned scroll regions. Generic overflow utilities also
    // occur inside drawers, rails and nested widgets; resetting all of them
    // interrupts native momentum and causes unexpected jumps on mobile.
    const scrollNestedContainers = () => {
      document.querySelectorAll('[data-route-scroll-container]').forEach((element) => {
        if (!(element instanceof HTMLElement)) return;
        const style = window.getComputedStyle(element);
        if (!/(auto|scroll)/.test(`${style.overflowY} ${style.overflow}`)) return;
        element.scrollTop = 0;
      });
    };

    const savedPosition = navigationType === 'POP'
      ? scrollPositionsRef.current.get(location.key)
      : null;

    if (savedPosition) {
      window.scrollTo({
        top: savedPosition.top,
        left: savedPosition.left,
        behavior: 'auto',
      });
      lastKnownScrollRef.current = savedPosition;
      return undefined;
    }

    if (location.hash) {
      let targetId = location.hash.slice(1);
      try {
        targetId = decodeURIComponent(targetId);
      } catch {
        // A malformed encoded hash should not break route rendering.
      }

      const target = document.getElementById(targetId);
      if (target) {
        target.scrollIntoView({ block: 'start', inline: 'nearest', behavior: 'auto' });
        lastKnownScrollRef.current = {
          top: window.scrollY,
          left: window.scrollX,
        };
        return undefined;
      }
    }

    window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
    document.documentElement.scrollTop = 0;
    document.body.scrollTop = 0;
    lastKnownScrollRef.current = { top: 0, left: 0 };
    scrollNestedContainers();

    // All writes remain in the layout phase. Delayed scroll corrections make
    // lazy content and images visibly snap after navigation.
    return undefined;
  }, [location.hash, location.key, location.pathname, navigationType]);

  return null;
}

function RedirectToProducts() {
  const { search } = useLocation();
  return <Navigate to={`/products${search || ''}`} replace />;
}

function getRouteBackConfig(pathname) {
  if (pathname.startsWith('/repairs/status/')) return { fallbackTo: '/dashboard?tab=repairs', label: 'Back to repairs' };
  if (pathname.startsWith('/order-tracking/')) return null;
  if (pathname.startsWith('/order/')) {
    return {
      fallbackTo: '/dashboard?tab=orders',
      label: 'Back to orders',
      scope: 'orders',
    };
  }
  return null;
}

function RouteBackBar() {
  const location = useLocation();
  const config = getRouteBackConfig(location.pathname);
  if (!config) return null;

  return (
    <div className={`dtb-route-back-bar${config.scope ? ` dtb-route-back-bar--${config.scope}` : ''}`}>
      <SmartBackButton
        fallbackTo={config.fallbackTo}
        label={config.label}
        className={config.scope ? `dtb-route-back-button dtb-route-back-button--${config.scope}` : 'dtb-route-back-button'}
      />
    </div>
  );
}

function AppRoutes() {
  const location = useLocation();
  const rewardsEnabled = isRewardsEnabled();
  const productSelectorElement = <Products title="Products" isPartsFilter={0} />;
  const routes = (
    <Suspense fallback={<RouteLoadingSurface pathname={location.pathname} />}>
      <Routes>
        <Route path="/" element={<Home />} />
        <Route path="/products" element={<Products forceProductGrid title="Products" isPartsFilter={0} />} />
        <Route path="/products/brands" element={productSelectorElement} />
        <Route path="/products/brands/:brandSlug" element={productSelectorElement} />
        <Route path="/products/brands/:brandSlug/categories/:categorySlug" element={productSelectorElement} />
        <Route path="/products/:slug/variations/:variationId" element={<ProductDetailPage />} />
        <Route path="/products/:slug" element={<ProductDetailPage />} />
        <Route path="/all-products" element={<RedirectToProducts />} />
        <Route path="/parts" element={<Parts />} />
        <Route path="/product/:partNumber" element={<Product />} />
        <Route path="/category/taping-finishing-tools" element={<RedirectToProducts />} />
        <Route path="/category/:categoryPathSlug" element={<CategoryLandingPage />} />
        <Route path="/schematics" element={<Schematics />} />
        <Route path="/repairs" element={<Repairs />} />
        <Route path="/repairs/start" element={<RepairStart />} />
        <Route path="/repairs/packages" element={<RepairPackages />} />
        <Route path="/repairs/track" element={<RepairTrack />} />
        <Route path="/repairs/status/:id" element={<RepairStatus />} />
        <Route path="/faq" element={<FAQ />} />
        <Route path="/calculators" element={<Calculators />} />
        <Route path="/shipping-policy" element={<ShippingPolicy />} />
        <Route path="/returns" element={<ReturnPortal />} />
        <Route path="/returns/status/:id" element={<ReturnStatus />} />
        <Route path="/return-policy" element={<ReturnPolicy />} />
        <Route path="/policies" element={<StorePolicies />} />
        <Route path="/terms-of-service" element={<TermsOfService />} />
        <Route path="/privacy-policy" element={<PrivacyPolicy />} />
        <Route path="/preview/technical-specifications" element={<TechnicalSpecificationsPreview />} />
        <Route path="/cart" element={<Cart />} />
        <Route path="/checkout" element={<Checkout />} />
        <Route path="/checkout/complete" element={<CheckoutReturn fallbackState="complete" />} />
        <Route path="/checkout/payment-failed" element={<CheckoutReturn fallbackState="failed" />} />
        <Route path="/checkout/payment-cancelled" element={<CheckoutReturn fallbackState="cancelled" />} />
        <Route path="/checkout/order-received/:id" element={<CheckoutReturn fallbackState="complete" />} />
        <Route path="/order/:id" element={<OrderConfirmation />} />
        <Route path="/order-tracking/:id" element={<OrderTracking />} />
        <Route path="/contact" element={<Contact />} />
        <Route path="/support/status/:id" element={<SupportStatus />} />
        <Route path="/settings/woocommerce" element={<ProtectedRoute><WooCommerceSettings /></ProtectedRoute>} />
        <Route path="/login" element={<Login />} />
        <Route path="/register" element={<Register />} />
        <Route path="/forgot-password" element={<ForgotPassword />} />
        <Route path="/reset-password" element={<ResetPassword />} />
        <Route path="/dashboard" element={<ProtectedRoute><Dashboard /></ProtectedRoute>} />
        <Route path="/dashboard/repairs/:id" element={<ProtectedRoute><RepairStatus /></ProtectedRoute>} />
        <Route path="/orders" element={<Navigate to="/dashboard?tab=orders" replace />} />
        <Route path="/rewards" element={<Navigate to={rewardsEnabled ? "/dashboard?tab=rewards" : "/dashboard"} replace />} />
        <Route path="/account-settings" element={<Navigate to="/dashboard?tab=settings" replace />} />
        <Route path="/addresses" element={<Navigate to="/dashboard?tab=addresses" replace />} />
        <Route path="/notifications" element={<Navigate to="/dashboard?tab=settings" replace />} />
        <Route path="/error/:code" element={<CustomerErrorPage />} />
        <Route path="*" element={<CustomerErrorPage code={404} />} />
      </Routes>
    </Suspense>
  );

  if (location.pathname === '/checkout') return routes;

  return (
    <PageTransition>
      {routes}
    </PageTransition>
  );
}

function App() {
  const [cartOpen, setCartOpen] = useState(false);
  const toggleCart = useCallback(() => setCartOpen(prev => !prev), []);
  const closeCart = useCallback(() => setCartOpen(false), []);
  const basename = (process.env.PUBLIC_URL || '').replace(/\/+$/, '') || '/';

  return (
    <AppErrorBoundary>
      <AuthProvider>
        <DesignConfigProvider>
          <WooCommerceProvider>
            <CartProvider>
              <LazyMotion features={loadMotionFeatures} strict>
                <WorkflowTransitionProvider>
                  <Router basename={basename}>
                    <ScrollToTop />
                    <RouteIntentPreloader />
                    <StorefrontScrollExperience />
                    <AppShell cartOpen={cartOpen} toggleCart={toggleCart} closeCart={closeCart} />
                  </Router>
                </WorkflowTransitionProvider>
              </LazyMotion>
            </CartProvider>
          </WooCommerceProvider>
        </DesignConfigProvider>
      </AuthProvider>
    </AppErrorBoundary>
  );
}

function AppShell({ cartOpen, toggleCart, closeCart }) {
  const location = useLocation();
  const { user } = useAuthContext();
  const routeKey = `${location.pathname}${location.search}${location.hash}`;
  const previousRouteKeyRef = useRef(routeKey);
  usePreviewRouteSync();

  const isHome = location.pathname === '/';
  const minimalChrome = location.pathname === '/checkout';
  const schematicViewer = location.pathname === '/schematics' && Boolean(new URLSearchParams(location.search).get('schematic'));

  // The provider owns conversation state; suppress its launcher only while
  // high-priority storefront surfaces are active.
  useEffect(() => {
    const root = document.documentElement;
    root.dataset.dtbAiCartOpen = String(cartOpen);
    root.dataset.dtbAiCheckout = String(minimalChrome);
    root.dataset.dtbAiProduct = String(/^\/product(?:s)?\//.test(location.pathname));
    return () => {
      delete root.dataset.dtbAiCartOpen;
      delete root.dataset.dtbAiCheckout;
      delete root.dataset.dtbAiProduct;
    };
  }, [cartOpen, minimalChrome, location.pathname]);

  useEffect(() => {
    if (previousRouteKeyRef.current === routeKey) return;
    previousRouteKeyRef.current = routeKey;
    if (cartOpen) closeCart();
  }, [cartOpen, closeCart, routeKey]);

  return (
    <>
      {!minimalChrome && <Header onCartToggle={toggleCart} onMobileMenuOpen={closeCart} />}
      <main className={schematicViewer ? 'main-content main-content--schematic-viewer' : !isHome && !minimalChrome ? 'main-content' : minimalChrome ? '' : 'main-content home-main'}>
        <RouteBackBar />
        <AppRoutes />
      </main>
      {!minimalChrome && !schematicViewer && <Footer />}
      {!minimalChrome && <CartSidebar isOpen={cartOpen} onClose={closeCart} />}
      {!minimalChrome && isHome && !user && <HomepageSignupCtaController />}
    </>
  );
}

function HomepageSignupCtaController() {
  const navigate = useNavigate();
  const [showSignupCta, setShowSignupCta] = useState(false);

  useEffect(() => {
    if (getLocalStorageFlag(HOMEPAGE_SIGNUP_CTA_SEEN_KEY)) {
      return undefined;
    }

    const timer = setTimeout(() => {
      setShowSignupCta(true);
    }, HOMEPAGE_SIGNUP_CTA_DELAY_MS);

    return () => clearTimeout(timer);
  }, []);

  const handleClose = useCallback(() => {
    setLocalStorageFlag(HOMEPAGE_SIGNUP_CTA_SEEN_KEY);
    setShowSignupCta(false);
  }, []);

  const handleRegister = useCallback(() => {
    setLocalStorageFlag(HOMEPAGE_SIGNUP_CTA_SEEN_KEY);
    setShowSignupCta(false);
    navigate('/register', { state: { returnTo: '/' } });
  }, [navigate]);

  return (
    <HomepageSignupCTA
      isVisible={showSignupCta}
      onClose={handleClose}
      onRegister={handleRegister}
    />
  );
}

export default App;
