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
  assert.match(backend, /'woocommerce_payments'/);
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
