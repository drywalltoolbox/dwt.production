(() => {
	'use strict';

	const panel = document.getElementById('dtb-hotspot-optimizer');
	if (!panel) return;

	const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	const applyMessage = 'Run the one-time hotspot optimizer now? This synchronizes current schematic hotspot datasets and applies deterministic exact product resolutions. It does not rewrite WooCommerce SKU, MPN, or brand identifiers.';

	panel.addEventListener('submit', (event) => {
		const form = event.target.closest('.dtb-hotspot-optimizer__form');
		if (!form) return;

		if (form.dataset.optimizerMode === 'apply' && !window.confirm(applyMessage)) {
			event.preventDefault();
			return;
		}

		const button = form.querySelector('button[type="submit"]');
		panel.dataset.busy = 'true';
		panel.setAttribute('aria-busy', 'true');
		panel.querySelectorAll('button[type="submit"]').forEach((candidate) => {
			candidate.disabled = true;
		});
		if (button) {
			button.textContent = form.dataset.optimizerMode === 'apply' ? 'Running optimizer…' : 'Building preview…';
		}

		if (!reducedMotion) {
			panel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
		}
	});
})();
