/**
 * Checkout order summary: reuse the native WooCommerce disclosure.
 * Never synthesize a second trigger, alter Woo state or move React-managed DOM.
 * If the native title is unavailable, the summary remains inline (fail open).
 */
(() => {
 'use strict';
 const mq = window.matchMedia('(max-width: 767px)');
 const legacyTrigger = document.querySelector('.dtb-mobile-sheet-trigger');
 const legacyBackdrop = document.querySelector('.dtb-mobile-sheet-backdrop');
 if (legacyTrigger) legacyTrigger.hidden = true;
 if (legacyBackdrop) legacyBackdrop.hidden = true;
 document.body.classList.remove('dtb-mobile-sheet-ready', 'dtb-mobile-sheet-open');
 let host = null;
 const clear = () => {
   if (host) { host.classList.remove('dtb-native-summary-sheet'); host.inert = false; }
   host = null;
   document.body.classList.remove('dtb-native-summary-ready');
 };
 const setup = () => {
   if (!mq.matches) { clear(); return; }
   // Native Woo title is the sole disclosure authority.
   const titles = Array.from(document.querySelectorAll('.wc-block-components-checkout-order-summary__title'));
   const title = titles.find(node => node.closest('.wc-block-components-checkout-order-summary'));
   const target = title && title.closest('.wc-block-components-checkout-order-summary');
   if (!target) { clear(); return; }
   if (host !== target) { clear(); host = target; }
   host.classList.add('dtb-native-summary-sheet');
   document.body.classList.add('dtb-native-summary-ready');
 };
 mq.addEventListener('change', setup);
 document.addEventListener('DOMContentLoaded', setup, { once: true });
 // Checkout Blocks hydrate after document ready; bounded retry, no global mutation observer.
 let count = 0;
 const id = window.setInterval(() => {
   setup();
   count++;
   if (host || count >= 40) window.clearInterval(id);
 }, 250);
})();
