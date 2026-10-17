/**
 * Native Checkout Block mobile summary presentation.
 * Never move, clone or replace WooCommerce controls. Fail open to inline summary.
 */
(() => {
 'use strict';
 const mobile = window.matchMedia('(max-width: 767px)');
 const body = document.body;
 let host = null;
 let observer = null;
 let retryTimer = 0;
 let attempts = 0;
 const reset = () => {
   if (host) host.classList.remove('dtb-native-summary-sheet');
   host = null;
   body.classList.remove('dtb-native-summary-ready');
 };
 const sync = () => {
   if (!mobile.matches) { reset(); return; }
   const found = document.querySelector(
     '.wc-block-components-checkout-order-summary, .wp-block-woocommerce-checkout-order-summary-block'
   );
   if (!found || !found.isConnected) { reset(); return; }
   if (host !== found) {
     reset();
     host = found;
     host.classList.add('dtb-native-summary-sheet');
   }
   body.classList.add('dtb-native-summary-ready');
 };
 const start = () => {
   sync();
   // Checkout Blocks can hydrate after DOMContentLoaded or replace the summary.
   // Watch only the checkout root; disconnect after a bounded interval.
   const root = document.querySelector('.wp-block-woocommerce-checkout, #primary');
   if (root && !observer) {
     observer = new MutationObserver(() => {
       if (!host || !host.isConnected) sync();
     });
     observer.observe(root, { childList: true, subtree: true });
   }
   window.clearInterval(retryTimer);
   attempts = 0;
   retryTimer = window.setInterval(() => {
     sync();
     if (++attempts >= 120) {
       window.clearInterval(retryTimer);
       if (observer) { observer.disconnect(); observer = null; }
     }
   }, 250);
 };
 mobile.addEventListener('change', () => { reset(); start(); });
 if (document.readyState === 'loading') {
   document.addEventListener('DOMContentLoaded', start, { once: true });
 } else start();
})();
