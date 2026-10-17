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

    // Scope overrides to the launcher, never the provider-owned conversation
    // widget. A suspended launcher must not reset the active Gemini session.
    const launcherStyle = document.createElement('style');
    launcherStyle.id = 'dtb-gemini-launcher-layout';
    launcherStyle.textContent = `
      #dtb-gemini-storefront > .gecx-floating-button-container {
        /* Keep the provider's actual button at least 44px: never scale the hit target. */
        transition: opacity 160ms ease, visibility 160ms ease;
        -webkit-tap-highlight-color: transparent;
      }
      #dtb-gemini-storefront > .gecx-floating-button-container :focus-visible {
        outline: 2px solid #2255ee;
        outline-offset: 4px;
      }
      @media (max-width: 640px) {
        #dtb-gemini-storefront > .gecx-floating-button-container {
          bottom: calc(10px + env(safe-area-inset-bottom, 0px)) !important;
          right: calc(10px + env(safe-area-inset-right, 0px)) !important;
        }
        /* Limit the custom-element box without touching Google's internal
           conversation layout or constraining a closed shadow tree. */
        #dtb-gemini-storefront > gecx-woocommerce-chat-widget {
          max-inline-size: 100vw;
          max-block-size: 100dvh;
        }
      }
      html[data-dtb-ai-cart-open="true"] #dtb-gemini-storefront > .gecx-floating-button-container,
      html[data-dtb-ai-navigation-open="true"] #dtb-gemini-storefront > .gecx-floating-button-container,
      html[data-dtb-ai-checkout="true"] #dtb-gemini-storefront > .gecx-floating-button-container {
        opacity: 0 !important;
        visibility: hidden !important;
        pointer-events: none !important;
      }
      @media (prefers-reduced-motion: reduce) {
        #dtb-gemini-storefront > .gecx-floating-button-container { transition: none; }
      }
    `;
    document.head.appendChild(launcherStyle);

    const container = document.createElement('div');
    container.id = 'dtb-gemini-storefront';
    const launcher = document.createElement('div');
    launcher.className = 'gecx-floating-button-container gecx-floating-button-container--bottom-right';
    launcher.style.cssText = 'position:fixed;bottom:calc(12px + env(safe-area-inset-bottom, 0px));right:12px;z-index:9999';
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
    // Match Google's wp_enqueue_scripts order: widget bundle first when
    // eager, then the plugin storefront adapter. In deferred mode the plugin
    // adapter owns gecxLoadWidget() and loads the hosted bundle on demand.
    if (data.config.shouldLoadWidget) await loadScript(data.widgetUrl);
    await loadScript(data.storefrontUrl);
    return true;
  })().catch((error) => {
    console.warn('[DTB] Gemini unavailable:', error.message);
    return false;
  });
  return bootPromise;
}
