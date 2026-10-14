import { apiClient } from './client.js';

const CACHE_TTL_MS = 60 * 1000;
let cachedReadiness = null;
let cacheExpiresAt = 0;
let inFlightRequest = null;

function asBoolean(value) {
  return typeof value === 'boolean' ? value : null;
}

function unavailableReasons(checks) {
  const labels = {
    nativeCheckout: 'Native WooCommerce checkout is not ready.',
    woopaymentsProvider: 'WooPayments is not the configured payment authority.',
    gatewayEnabled: 'WooPayments is not enabled for checkout.',
    checkoutBlockReady: 'The WooCommerce Checkout Block is not configured.',
    https: 'HTTPS is required for secure checkout.',
    testEnvironmentIsolated: 'Test payments require an isolated staging environment.',
  };
  return Object.entries(checks)
    .filter(([, value]) => value === false)
    .map(([key]) => labels[key])
    .filter(Boolean);
}

export function normalizeProductBuyNowReadiness(capabilities) {
  const readiness = capabilities?.readiness && typeof capabilities.readiness === 'object'
    ? capabilities.readiness
    : {};
  const gateways = Array.isArray(capabilities?.gateways) ? capabilities.gateways : [];
  const gateway = gateways.find((item) => item?.provider === 'woopayments' && item?.id === 'woocommerce_payments') || null;
  const checks = {
    nativeCheckout: capabilities?.checkout === 'woo_native_checkout_block',
    woopaymentsProvider: capabilities?.provider === 'woopayments',
    gatewayEnabled: gateway ? asBoolean(gateway.enabled) : asBoolean(readiness.gateway_enabled),
    checkoutBlockReady: asBoolean(readiness.checkout_block),
    https: asBoolean(readiness.https),
    testEnvironmentIsolated: readiness.provider_test_mode === true
      ? asBoolean(readiness.isolated_test_environment)
      : true,
  };
  const required = Object.values(checks);
  const unavailable = required.some((value) => value === false);
  const ready = required.every((value) => value === true);

  return {
    state: unavailable ? 'unavailable' : (ready ? 'ready' : 'unknown'),
    provider: 'WooPayments',
    checks,
    reasons: unavailableReasons(checks),
  };
}

export async function getProductBuyNowReadiness({ force = false } = {}) {
  if (inFlightRequest) return inFlightRequest;
  if (!force && cachedReadiness && cacheExpiresAt > Date.now()) return cachedReadiness;

  inFlightRequest = apiClient('/wp-json/dtb/v1/checkout/capabilities', {
    method: 'GET',
    cache: 'no-store',
    headers: { Accept: 'application/json' },
  })
    .then(normalizeProductBuyNowReadiness)
    .catch(() => ({ state: 'unknown', provider: 'WooPayments', checks: {}, reasons: [] }))
    .then((result) => {
      cachedReadiness = result;
      cacheExpiresAt = Date.now() + CACHE_TTL_MS;
      return result;
    })
    .finally(() => { inFlightRequest = null; });
  return inFlightRequest;
}
