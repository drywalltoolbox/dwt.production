(() => {
	'use strict';

	const app = document.getElementById('dtb-hotspot-resolver-app');
	const config = window.dtbHotspotResolver || {};
	if (!app || !config.ajaxUrl) return;

	const confirmMessage = 'Apply this resolver action? This changes only the schematic-to-product relationship, not the product identifiers.';

	async function submitForm(form) {
		if (form.dataset.confirm === '1' && !window.confirm(confirmMessage)) return;

		const button = form.querySelector('button[type="submit"]');
		const originalLabel = button ? button.textContent : '';
		const payload = new FormData(form);
		payload.set('action', 'dtb_schematic_hotspot_resolver_action');

		try {
			app.setAttribute('aria-busy', 'true');
			if (button) {
				button.disabled = true;
				button.textContent = config.workingLabel || 'Applying…';
			}

			const response = await fetch(config.ajaxUrl, {
				method: 'POST',
				body: payload,
				credentials: 'same-origin',
				headers: { 'X-Requested-With': 'XMLHttpRequest' },
			});
			const json = await response.json();
			if (!response.ok || !json?.success || typeof json?.data?.html !== 'string') {
				throw new Error(json?.data?.message || config.errorLabel || 'Resolver action failed.');
			}

			app.innerHTML = json.data.html;
			app.removeAttribute('aria-busy');
			const notice = app.querySelector('.notice');
			if (notice) {
				notice.scrollIntoView({ block: 'nearest', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
			}
		} catch (error) {
			app.removeAttribute('aria-busy');
			if (button) {
				button.disabled = false;
				button.textContent = originalLabel;
			}
			window.alert(error?.message || config.errorLabel || 'Resolver action failed.');
		}
	}

	app.addEventListener('submit', (event) => {
		const form = event.target.closest('.dtb-hotspot-resolver__action-form');
		if (!form) return;
		event.preventDefault();
		submitForm(form);
	});
})();
