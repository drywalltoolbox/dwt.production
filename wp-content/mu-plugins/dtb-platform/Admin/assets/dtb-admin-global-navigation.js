/* global DTBAdminGlobalNavigation */
(() => {
	'use strict';

	const config = window.DTBAdminGlobalNavigation || {};
	const items = Array.isArray(config.items) ? config.items : [];
	if (!items.length || document.getElementById('dtb-global-quick-nav')) return;

	const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
	const current = String(config.currentUrl || window.location.href);
	const isActive = (item) => item.match && current.includes(String(item.match));

	const nav = document.createElement('nav');
	nav.id = 'dtb-global-quick-nav';
	nav.className = 'dtb-global-quick-nav';
	nav.setAttribute('aria-label', config.labels?.navigation || 'Drywall Toolbox quick navigation');

	const primary = items.slice(0, 6);
	const overflow = items.slice(6);

	nav.innerHTML = `
		<div class="dtb-global-quick-nav__links">
			${primary.map((item) => `<a class="dtb-global-quick-nav__link${isActive(item) ? ' is-active' : ''}" href="${esc(item.url)}"${isActive(item) ? ' aria-current="page"' : ''}>${esc(item.label)}</a>`).join('')}
		</div>
		${overflow.length ? `<details class="dtb-global-quick-nav__more"><summary>${esc(config.labels?.more || 'More')}</summary><div class="dtb-global-quick-nav__menu">${overflow.map((item) => `<a class="dtb-global-quick-nav__menu-link${isActive(item) ? ' is-active' : ''}" href="${esc(item.url)}"${isActive(item) ? ' aria-current="page"' : ''}>${esc(item.label)}</a>`).join('')}</div></details>` : ''}
	`;

	const candidates = [
		document.querySelector('.brikpanel-topbar'),
		document.querySelector('[class*="brikpanel"][class*="topbar"]'),
		document.querySelector('.woocommerce-layout__header'),
		document.querySelector('#wpadminbar'),
	].filter(Boolean);

	const host = candidates[0];
	if (host) {
		host.classList.add('has-dtb-global-quick-nav');

		// Insert inside the topbar's own inner flex row (between the brand and
		// the icon cluster) so this nav is a real item of the existing single
		// row, not a second row appended after it. Appending directly to the
		// outer topbar element made this render below the bar's fixed height,
		// overlapping the sidebar and content area (both offset to match a
		// single-row bar).
		const inner = host.querySelector(':scope > .brikpanel-topbar-inner') || host;
		const trailingCluster = inner.querySelector(':scope > .brikpanel-topbar-right');
		if (trailingCluster) {
			inner.insertBefore(nav, trailingCluster);
		} else {
			inner.append(nav);
		}
		return;
	}

	const body = document.getElementById('wpbody-content');
	if (body) body.prepend(nav);
})();
