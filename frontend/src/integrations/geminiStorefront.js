/**
 * Static-SPA bridge for Google's installed Gemini Enterprise for CX plugin.
 * The same-origin WordPress endpoint supplies Google's public storefront
 * configuration. Google continues to own session/auth, widget UI and cart API.
 * Do not load this integration in an unrelated preview origin.
 */
let bootPromise;

function loadScript(src) {
  return new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = src;
    script.async = false;
    script.addEventListener('load', resolve, { once: true });
    script.addEventListener('error', () => reject(new Error('Gemini script unavailable')), { once: true });
    document.body.appendChild(script);
  });
}

export function initializeGeminiStorefront() {
  if (typeof window === 'undefined' || !/^drywalltoolbox\.com$/i.test(window.location.hostname)) {
    return Promise.resolve(false);
  }
  if (bootPromise) return bootPromise;

  bootPromise = (async () => {
    const response = await fetch('/wp-json/dtb/v1/gemini-storefront', {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' },
    });
    if (!response.ok) throw new Error('Gemini storefront settings unavailable');
    const data = await response.json();
    if (!data.enabled) return false;
    if (!data.agentName || !data.storefrontUrl || !data.widgetUrl || !data.config) {
      throw new Error('Gemini storefront configuration incomplete');
    }

    // No secrets or browser-auth tokens are stored in this bootstrap.
    window.gecxStorefrontConfig = data.config;

    const style = document.createElement('link');
    style.rel = 'stylesheet';
    style.href = data.styleUrl;
    document.head.appendChild(style);

    const container = document.createElement('div');
    container.id = 'dtb-gemini-storefront';
    const launcher = document.createElement('div');
    launcher.className = 'gecx-floating-button-container gecx-floating-button-container--bottom-right';
    launcher.style.cssText = 'position:fixed;bottom:calc(20px + env(safe-area-inset-bottom, 0px));right:20px;z-index:99999';
    // Generated and escaped by Google's own public get_agent_button_html().
    launcher.innerHTML = data.config.markup.button;
    container.appendChild(launcher);

    const widget = document.createElement('gecx-woocommerce-chat-widget');
    widget.setAttribute('agent-name', data.agentName);
    widget.setAttribute('store-url', data.storeUrl);
    widget.setAttribute('rest-url', data.restUrl);
    widget.setAttribute('token-broker', data.tokenBroker || '');
    widget.setAttribute('environment', 'prod');
    widget.setAttribute('hide-launcher', '');
    container.appendChild(widget);
    document.body.appendChild(container);

    // Google's storefront runtime owns launcher behavior, nonce renewal and
    // gecx:cart-updated. Its own config controls deferred widget loading.
    await loadScript(data.storefrontUrl);
    if (data.config.shouldLoadWidget) await loadScript(data.widgetUrl);
    return true;
  })().catch((error) => {
    console.warn('[DTB] Gemini unavailable:', error.message);
    return false;
  });
  return bootPromise;
}
