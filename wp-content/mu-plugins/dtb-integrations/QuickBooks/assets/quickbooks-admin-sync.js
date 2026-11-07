(() => {
	'use strict';

	const config = window.DTBQuickBooksAdmin;
	const root = document.getElementById('dtb-qbo-admin-root');
	if (!config || !root) {
		return;
	}

	const endpoint = (path) => {
		const restRoot = String(config.restRoot || '').replace(/\/?$/, '/');
		return `${restRoot}dtb/v1/admin/qbo${path}`;
	};

	const api = async (path, options = {}) => {
		const response = await fetch(endpoint(path), {
			method: options.method || 'GET',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body: options.body ? JSON.stringify(options.body) : undefined,
		});

		const payload = await response.json().catch(() => null);
		if (!response.ok) {
			const error = new Error(payload?.message || `QuickBooks sync request failed with HTTP ${response.status}.`);
			error.payload = payload;
			throw error;
		}
		return payload;
	};

	const formatDate = (value) => {
		if (!value) {
			return 'Never';
		}
		const date = new Date(value);
		return Number.isNaN(date.getTime())
			? 'Never'
			: new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
	};

	const createMetric = (label, value, attribute) => {
		const metric = document.createElement('div');
		metric.className = 'dtb-qbo-sync-metric';
		const name = document.createElement('span');
		name.textContent = label;
		const data = document.createElement('strong');
		data.textContent = value;
		if (attribute) {
			data.dataset[attribute] = '';
		}
		metric.append(name, data);
		return metric;
	};

	const buildCard = () => {
		const workflow = root.querySelector('[data-qbo-panel="workflow"]');
		if (!workflow || workflow.querySelector('[data-qbo-sync-card]')) {
			return workflow?.querySelector('[data-qbo-sync-card]') || null;
		}

		const card = document.createElement('section');
		card.className = 'dtb-qbo-sync-card';
		card.dataset.qboSyncCard = '';

		const header = document.createElement('div');
		header.className = 'dtb-qbo-sync-card__header';
		const copy = document.createElement('div');
		const title = document.createElement('h3');
		title.textContent = 'Synchronization operations';
		const description = document.createElement('p');
		description.textContent = 'Queue eligible captured-payment WooCommerce orders through the canonical dtb-orders Action Scheduler pipeline. No accounting write runs in this browser request.';
		copy.append(title, description);

		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'button button-primary';
		button.dataset.qboSyncQueue = '';
		button.textContent = 'Queue eligible orders';
		button.disabled = true;
		header.append(copy, button);

		const grid = document.createElement('div');
		grid.className = 'dtb-qbo-sync-grid';
		grid.append(
			createMetric('Execution', 'Queued', 'qboSyncExecution'),
			createMetric('Queue group', 'dtb-orders', 'qboSyncGroup'),
			createMetric('Last queued', 'Never', 'qboSyncLastAt'),
			createMetric('Last batch', '0 orders', 'qboSyncLastCount')
		);

		const blockers = document.createElement('ul');
		blockers.className = 'dtb-qbo-sync-blockers';
		blockers.dataset.qboSyncBlockers = '';
		blockers.hidden = true;

		card.append(header, grid, blockers);
		workflow.append(card);
		return card;
	};

	const render = (status) => {
		const card = buildCard();
		if (!card) {
			return;
		}
		const button = card.querySelector('[data-qbo-sync-queue]');
		if (button) {
			button.disabled = !status.ready;
		}
		const lastAt = card.querySelector('[data-qbo-sync-last-at]');
		const lastCount = card.querySelector('[data-qbo-sync-last-count]');
		if (lastAt) {
			lastAt.textContent = formatDate(status.last_queued_at);
		}
		if (lastCount) {
			lastCount.textContent = `${Number(status.last_queued_count || 0)} orders`;
		}

		const blockers = card.querySelector('[data-qbo-sync-blockers]');
		if (blockers) {
			blockers.replaceChildren();
			(status.blockers || []).forEach((message) => {
				const item = document.createElement('li');
				item.textContent = message;
				blockers.append(item);
			});
			blockers.hidden = !(status.blockers || []).length;
		}
	};

	const load = async () => {
		const status = await api('/sync/status');
		render(status);
		return status;
	};

	root.addEventListener('click', async (event) => {
		const button = event.target.closest('[data-qbo-sync-queue]');
		if (!button || button.disabled) {
			return;
		}

		button.disabled = true;
		const original = button.textContent;
		button.textContent = 'Queuing…';
		try {
			const result = await api('/sync/queue', { method: 'POST', body: { limit: 25 } });
			button.textContent = `Queued ${Number(result.queued || 0)}`;
			render(result.status || {});
		} catch (error) {
			button.textContent = 'Queue failed';
			const alert = root.querySelector('[data-qbo-alert]');
			if (alert) {
				alert.hidden = false;
				alert.className = 'dtb-qbo-alert is-error';
				alert.textContent = error.message;
			}
		} finally {
			window.setTimeout(() => {
				button.textContent = original;
				load().catch(() => {});
			}, 1400);
		}
	});

	buildCard();
	load().catch(() => {});
})();
