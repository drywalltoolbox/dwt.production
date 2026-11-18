/* global DTBQuickBooksAdmin, DTBVeeqoAdmin, wp */
(() => {
	'use strict';

	const qboRoot = document.getElementById('dtb-qbo-admin-root');
	const veeqoRoot = document.getElementById('dtb-veeqo-admin-root');
	const root = qboRoot || veeqoRoot;
	if (!root) return;

	const provider = qboRoot ? 'QuickBooks' : 'Veeqo';
	const config = qboRoot ? window.DTBQuickBooksAdmin : window.DTBVeeqoAdmin;
	if (!config) return;

	const intervalMs = 15000;
	let timer = null;
	let requestInFlight = false;
	let failures = 0;

	const status = document.createElement('div');
	status.className = 'dtb-integration-live-status';
	status.setAttribute('role', 'status');
	status.setAttribute('aria-live', 'polite');
	status.innerHTML = '<span class="dtb-integration-live-status__state"><span class="dtb-integration-live-status__dot" aria-hidden="true"></span><span data-dtb-live-label>Checking live integration health…</span></span><span class="dtb-integration-live-status__meta" data-dtb-live-meta>Queue-backed synchronization remains authoritative.</span>';

	const header = root.querySelector('.dtb-qbo-hero, .dtb-veeqo-header');
	if (header && header.parentNode) {
		header.insertAdjacentElement('afterend', status);
	}

	const label = status.querySelector('[data-dtb-live-label]');
	const meta = status.querySelector('[data-dtb-live-meta]');

	const timestamp = () => new Intl.DateTimeFormat(undefined, {
		hour: 'numeric',
		minute: '2-digit',
		second: '2-digit',
	}).format(new Date());

	const setState = (state, message) => {
		status.classList.toggle('is-live', state === 'live');
		status.classList.toggle('is-error', state === 'error');
		if (label) label.textContent = message;
		if (meta) meta.textContent = `${state === 'error' ? 'Last attempt' : 'Updated'} ${timestamp()} · ${provider} data is projected through durable queues and webhooks.`;
	};

	const qboEndpoint = () => {
		const rootUrl = String(config.restRoot || '').replace(/\/?$/, '/');
		const basePath = String(config.basePath || '').replace(/^\//, '').replace(/\/$/, '');
		return `${rootUrl}${basePath}/dashboard`;
	};

	const requestQbo = async () => {
		const response = await fetch(qboEndpoint(), {
			credentials: 'same-origin',
			cache: 'no-store',
			headers: {
				Accept: 'application/json',
				'X-WP-Nonce': config.nonce,
			},
		});
		if (!response.ok) throw new Error(`HTTP ${response.status}`);
		return response.json();
	};

	const requestVeeqo = async () => {
		if (!window.wp || !window.wp.apiFetch) throw new Error('WordPress REST client unavailable');
		const base = String(config.basePath || '/dtb/v1/veeqo/admin/control-center').replace(/\/$/, '');
		return window.wp.apiFetch({ path: `${base}/overview` });
	};

	const applyQboHealth = (data) => {
		const ready = Boolean(data?.readiness?.ready);
		const connected = Boolean(data?.status?.connected);
		setState(ready ? 'live' : 'warning', ready ? 'QuickBooks accounting projection is ready.' : connected ? 'QuickBooks is connected; configuration requires attention.' : 'QuickBooks is not connected.');
	};

	const applyVeeqoHealth = (data) => {
		const readiness = data?.readiness || {};
		const ready = Boolean(readiness.order_projection_ready && readiness.inventory_ready);
		setState(ready ? 'live' : 'warning', ready ? 'Veeqo order and inventory synchronization is ready.' : 'Veeqo configuration or synchronization requires attention.');
	};

	const refresh = async () => {
		if (requestInFlight || document.visibilityState !== 'visible') return;
		requestInFlight = true;
		try {
			const data = qboRoot ? await requestQbo() : await requestVeeqo();
			failures = 0;
			if (qboRoot) applyQboHealth(data);
			else applyVeeqoHealth(data);
			root.dispatchEvent(new CustomEvent('dtb:integration-health', { detail: { provider, data } }));
		} catch (error) {
			failures += 1;
			setState('error', `${provider} live health could not be refreshed.`);
		} finally {
			requestInFlight = false;
			schedule();
		}
	};

	const schedule = () => {
		window.clearTimeout(timer);
		const backoff = Math.min(intervalMs * Math.max(1, 2 ** failures), 120000);
		timer = window.setTimeout(refresh, backoff);
	};

	document.addEventListener('visibilitychange', () => {
		if (document.visibilityState === 'visible') refresh();
		else window.clearTimeout(timer);
	});

	window.addEventListener('beforeunload', () => window.clearTimeout(timer), { once: true });
	refresh();
})();
