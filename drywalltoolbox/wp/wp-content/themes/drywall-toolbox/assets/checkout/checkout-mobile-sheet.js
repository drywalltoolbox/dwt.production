/**
 * Mobile native order summary presentation only.
 * No WooCommerce field, cart, totals, payment or order state is modified.
 * Summary stays in its original React-managed tree.
 */
(() => {
 'use strict';
 const media = window.matchMedia('(max-width: 767px)');
 const trigger = document.querySelector('.dtb-mobile-sheet-trigger');
 const backdrop = document.querySelector('.dtb-mobile-sheet-backdrop');
 if (!trigger || !backdrop) return;
 const candidates = '.wc-block-checkout__sidebar, .wc-block-components-sidebar-layout__sidebar';
 let sidebar = null;
 let open = false;
 const close = (restoreFocus = false) => {
   open = false;
   document.body.classList.remove('dtb-mobile-sheet-open');
   if (sidebar && media.matches) sidebar.inert = true;
   trigger.setAttribute('aria-expanded', 'false');
   trigger.querySelector('.dtb-mobile-sheet-trigger__label').textContent = 'View order summary';
   if (restoreFocus) trigger.focus({ preventScroll: true });
 };
 const setup = () => {
   if (!media.matches) {
     close();
     document.body.classList.remove('dtb-mobile-sheet-ready');
     trigger.hidden = true;
     if (sidebar) { sidebar.inert = false; sidebar.classList.remove('dtb-mobile-summary-host'); sidebar.removeAttribute('id'); }
     sidebar = null;
     return;
   }
   // Use the actual native summary, not an empty responsive sidebar placeholder.
   const summary = document.querySelector('.wc-block-components-checkout-order-summary, .wp-block-woocommerce-checkout-order-summary-block');
   const found = summary && (summary.closest(candidates) || summary);
   if (!found) {
     if (sidebar) { sidebar.inert = false; sidebar.classList.remove('dtb-mobile-summary-host'); sidebar.removeAttribute('id'); }
     sidebar = null;
     document.body.classList.remove('dtb-mobile-sheet-ready', 'dtb-mobile-sheet-open');
     trigger.hidden = true;
     return;
   }
   if (sidebar && sidebar !== found) { sidebar.inert = false; sidebar.classList.remove('dtb-mobile-summary-host'); sidebar.removeAttribute('id'); }
   sidebar = found;
   sidebar.classList.add('dtb-mobile-summary-host');
   sidebar.id = 'dtb-mobile-summary';
   sidebar.inert = !open;
   trigger.hidden = false;
   document.body.classList.add('dtb-mobile-sheet-ready');
 };
 trigger.addEventListener('click', () => {
   if (!sidebar || !media.matches) return;
   if (open) { close(true); return; }
   open = true;
   sidebar.inert = false;
   document.body.classList.add('dtb-mobile-sheet-open');
   trigger.setAttribute('aria-expanded', 'true');
   trigger.querySelector('.dtb-mobile-sheet-trigger__label').textContent = 'Close order summary';
 });
 backdrop.addEventListener('click', () => close(true));
 document.addEventListener('keydown', event => {
   if (event.key === 'Escape' && open) { event.preventDefault(); close(true); }
 });
 media.addEventListener('change', setup);
 // WooCommerce renders Checkout Blocks asynchronously. Detect once and then
 // observe only the outer checkout region for a replaced summary container.
 let attempts = 0;
 const timer = window.setInterval(() => {
   setup();
   attempts++;
   if (document.body.classList.contains('dtb-mobile-sheet-ready') || attempts >= 30) {
     window.clearInterval(timer);
   }
 }, 300);
 document.addEventListener('visibilitychange', () => { if (!document.hidden) setup(); });
})();
