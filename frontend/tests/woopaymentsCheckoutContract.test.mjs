import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');

test('WooPayments is the public checkout capability contract', () => {
  const api = read('../src/api/checkoutCapabilities.js');
  const backend = read('../../drywalltoolbox/wp/wp-content/mu-plugins/dtb-commerce/Payment/WooPaymentsNativeCheckout.php');
  assert.match(api, /capabilities\?\.provider === 'woopayments'/);
  assert.match(api, /testEnvironmentIsolated/);
  assert.match(backend, /DTB_WOOPAYMENTS_TEST_CHECKOUT_ENABLED/);
  assert.match(backend, /WP_ENVIRONMENT_TYPE/);
  assert.match(backend, /DTB_PRODUCTION_DB_NAME/);
  assert.match(backend, /DB_NAME/);
  assert.match(backend, /\$stage_db\s*!==\s*\$prod_db/);
  assert.match(backend, /'woocommerce_payments'/);
});

test('guest checkout telemetry does not require a WordPress or custom nonce', () => {
  const backend = read('../../drywalltoolbox/wp/wp-content/mu-plugins/dtb-commerce/Payment/CheckoutPerformance.php');
  const runtime = read('../../drywalltoolbox/wp/wp-content/mu-plugins/dtb-commerce/assets/woo-native-checkout-performance.js');
  assert.match(backend, /request_origin_is_same_site/);
  assert.match(backend, /DTB_RateLimiter::check/);
  assert.doesNotMatch(backend, /wp_verify_nonce|TELEMETRY_NONCE_ACTION|telemetryNonce|wpRestNonce|wp_create_nonce/);
  assert.doesNotMatch(runtime, /wpRestNonce|telemetryNonce|X-WP-Nonce|X-DTB-Checkout-Telemetry/);
});

test('integration health reports WooPayments as the active checkout plugin', () => {
  const health = read('../../drywalltoolbox/wp/wp-content/mu-plugins/dtb-platform/SystemManager/IntegrationHealthService.php');
  assert.match(health, /'name'\s*=>\s*'WooPayments'/);
  assert.match(health, /class_exists\( 'WC_Payments' \)/);
  assert.match(health, /WCPAY_VERSION_NUMBER/);
  assert.doesNotMatch(health, /WC_Stripe|WC_STRIPE_VERSION/);
});

test('customer product and cart surfaces do not render provider express controls', () => {
  const panel = read('../src/components/product/ProductPurchasePanel.jsx');
  const cart = read('../src/pages/Cart.jsx');
  assert.doesNotMatch(panel, /EXPRESS_CHECKOUT_METHODS|applePay|googlePay|klarna|affirm|paypal|afterpay/i);
  assert.doesNotMatch(cart, /ExpressCheckoutMethods|paymentMethods/);
  assert.equal(existsSync(new URL('../src/components/checkout/ExpressCheckoutMethods.jsx', import.meta.url)), false);
});

test('administrator-only staging wallet probe has been removed', () => {
  assert.equal(existsSync(new URL('../../drywalltoolbox/wp/wp-content/mu-plugins/dtb-staging-wallet-probe.php', import.meta.url)), false);
  assert.equal(existsSync(new URL('../../drywalltoolbox/wp/wp-content/mu-plugins/dtb-staging-native-wallet.php', import.meta.url)), false);
  const stagingRules = read('../../drywalltoolbox/staging/.htaccess');
  assert.doesNotMatch(stagingRules, /__native-wallet|__wallet-probe/);
});
