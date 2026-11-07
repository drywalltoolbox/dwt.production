(() => {
	'use strict';
	const root = document.querySelector('[data-dtb-pricing-root]');
	if (!root || !window.dtbAdminConfig) return;
	const config = window.dtbAdminConfig;
	const activeTab = root.dataset.activeTab || 'products';
	const message = root.querySelector('[data-pricing-message]');
	const syncStatus = root.querySelector('[data-pricing-sync]');
	const currency = config.currencySymbol || '$';
	let summary = null;
	let refreshActiveView = async () => {};
	let lastFocused = null;
	let lastSyncAt = 0;
	let refreshing = false;

	const escapeHtml = (value) => String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
	const money = (value) => value === null || value === undefined || value === '' || Number.isNaN(Number(value)) ? '—' : `${currency}${Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
	const percent = (value) => value === null || value === undefined || value === '' || Number.isNaN(Number(value)) ? '—' : `${Number(value).toFixed(2)}%`;
	const setMessage = (text = '', type = '') => { if (!message) return; message.textContent = text; message.classList.remove('is-error', 'is-success'); if (type) message.classList.add(`is-${type}`); };
	const restUrl = (path = '') => {
		const routeBase = '/dtb/v1/admin/pricing';
		const separator = path.indexOf('?');
		const routePath = separator === -1 ? path : path.slice(0, separator);
		const query = separator === -1 ? '' : path.slice(separator + 1);
		const configuredBase = String(config.restUrl || '');

		if (configuredBase.includes('rest_route=')) {
			const url = new URL(configuredBase, window.location.origin);
			url.searchParams.set('rest_route', `${routeBase}${routePath}`);
			new URLSearchParams(query).forEach((value, key) => url.searchParams.set(key, value));
			return url.href;
		}

		const base = configuredBase || `${window.location.origin}/wp-json/`;
		return `${base.replace(/\/$/, '')}${routeBase}${routePath}${query ? `?${query}` : ''}`;
	};
	const request = async (path, options = {}) => {
		const response = await fetch(restUrl(path), { credentials: 'same-origin', ...options, headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce, ...(options.headers || {}) } });
		const payload = await response.json().catch(() => ({}));
		if (!response.ok) throw new Error(payload.message || 'The pricing request could not be completed.');
		return payload;
	};
	const loadSummary = async () => { summary = await request('/data'); return summary; };
	const statusBadge = (row) => {
		const tone = { healthy: 'success', below_target: 'warning', below_map: 'danger', below_cogs: 'danger', below_minimum: 'warning', missing_map: 'neutral', missing_cost: 'neutral', missing_price: 'danger', sale_active: 'info' }[row.status] || 'neutral';
		return `<span class="dtb-badge dtb-badge--${tone}">${escapeHtml(row.status_label || row.status)}</span>`;
	};
	const productCell = (row) => {
		const image = row.image_url ? `<img class="dtb-pricing-product__image" src="${escapeHtml(row.image_url)}" alt="">` : '<span class="dtb-pricing-product__placeholder" aria-hidden="true">IMG</span>';
		const meta = [row.sku ? `SKU ${row.sku}` : '', row.brand || '', row.type === 'variation' ? 'Variation' : ''].filter(Boolean);
		return `<div class="dtb-pricing-product">${image}<div><span class="dtb-pricing-product__name">${escapeHtml(row.name)}</span><span class="dtb-pricing-product__meta">${meta.map(escapeHtml).join('<span aria-hidden="true">·</span>')}</span></div></div>`;
	};
	const reasonText = (row) => {
		const labels = {
			REGULAR_BELOW_COGS: 'Regular price is below Cost of Goods.',
			SALE_BELOW_COGS: 'Sale price is below Cost of Goods.',
			EFFECTIVE_BELOW_COGS: 'Effective price is below Cost of Goods.',
			MAP_FLOOR_VIOLATION: 'Price is below official MAP.',
			BELOW_MINIMUM_MARGIN: `Price is below the ${Number(row.minimum_margin || 0).toFixed(1)}% minimum margin floor.`,
			BELOW_TARGET_MARGIN: `Regular margin is below the ${Number(row.target_margin || 0).toFixed(1)}% target.`,
			ACTIVE_SALE: 'Active sale is inside the protected floor but requires review.',
			MAX_CHANGE_EXCEEDED: 'Suggested change exceeds the configured maximum-change guardrail.',
			LARGE_CHANGE_REVIEW: 'Suggested change requires manual review.',
			CHANGE_BELOW_THRESHOLD: 'Suggested change is inside the no-change tolerance.',
			MISSING_COGS: 'Cost of Goods is missing; economic optimization is unavailable.',
			MISSING_PRICE: 'Regular price is missing.',
			MAP_NOT_CONFIGURED: 'MAP is not configured; economic policy still applies when COGS exists.',
		};
		const base = labels[row.reason_code] || row.reason_code || 'No pricing action required.';
		return `${base} Policy: ${row.policy_source_label || row.policy_source || 'global'} (${percent(row.minimum_margin)} min / ${percent(row.target_margin)} target).`;
	};
	const buildPagination = (container, data, onPage) => {
		if (!container) return;
		container.replaceChildren();
		const label = document.createElement('span');
		label.className = 'dtb-pricing-pagination__label';
		if (!data || data.total_pages <= 1) { label.textContent = `${Number(data?.total || 0).toLocaleString()} records`; container.append(label); return; }
		const prev = document.createElement('button'); prev.type = 'button'; prev.className = 'button button-secondary'; prev.textContent = 'Previous'; prev.disabled = data.page <= 1; prev.addEventListener('click', () => onPage(data.page - 1));
		label.textContent = `Page ${data.page} of ${data.total_pages} · ${Number(data.total).toLocaleString()} records`;
		const next = document.createElement('button'); next.type = 'button'; next.className = 'button button-secondary'; next.textContent = 'Next'; next.disabled = data.page >= data.total_pages; next.addEventListener('click', () => onPage(data.page + 1));
		container.append(prev, label, next);
	};
	const renderSummary = () => {
		if (!summary) return;
		const counts = summary.counts || {};
		const review = Number(counts.optimizer_actions || 0) + Number(counts.review_actions || 0) + Number(counts.blocked_actions || 0) + Number(counts.missing_cost || 0) + Number(counts.missing_price || 0);
		[['[data-summary-total]', counts.total], ['[data-summary-cost]', counts.with_cost], ['[data-summary-map]', counts.with_map], ['[data-summary-review]', review]].forEach(([selector, value]) => { const node = root.querySelector(selector); if (node) node.textContent = Number(value || 0).toLocaleString(); });
	};

	const initDrawer = () => {
		const drawer = root.querySelector('[data-pricing-drawer]');
		if (!drawer) return null;
		const form = drawer.querySelector('[data-pricing-drawer-form]');
		const close = drawer.querySelector('[data-drawer-close]');
		const price = drawer.querySelector('[data-drawer-regular-price]');
		const map = drawer.querySelector('[data-drawer-map-price]');
		const mapSource = drawer.querySelector('[data-drawer-map-source]');
		const productId = drawer.querySelector('[data-drawer-product-id]');
		const useTarget = drawer.querySelector('[data-drawer-use-target]');
		let current = null;
		const closeDrawer = () => { drawer.hidden = true; current = null; if (lastFocused?.focus) lastFocused.focus(); };
		const populate = (row) => {
			current = row;
			drawer.querySelector('[data-drawer-title]').textContent = row.name || 'Product pricing';
			drawer.querySelector('[data-drawer-sku]').textContent = row.sku ? `SKU ${row.sku}` : row.type || '';
			drawer.querySelector('[data-drawer-cost]').textContent = money(row.cost);
			drawer.querySelector('[data-drawer-margin]').textContent = percent(row.gross_margin);
			drawer.querySelector('[data-drawer-markup]').textContent = percent(row.markup);
			drawer.querySelector('[data-drawer-target]').textContent = money(row.suggested_price ?? row.optimization_floor);
			productId.value = row.id; price.value = row.regular_price ?? ''; map.value = row.map_price ?? ''; mapSource.value = row.map_source ?? ''; useTarget.disabled = row.suggested_price == null;
			const saleNote = drawer.querySelector('[data-drawer-sale-note]');
			saleNote.hidden = !row.on_sale;
			if (row.on_sale) saleNote.textContent = `Active sale. Sale prices are protected by COGS, the ${Number(row.minimum_margin || 0).toFixed(1)}% minimum-margin floor, and MAP when configured.`;
		};
		close.addEventListener('click', closeDrawer);
		document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !drawer.hidden) closeDrawer(); });
		useTarget.addEventListener('click', () => { if (current?.suggested_price != null) price.value = Number(current.suggested_price).toFixed(2); });
		form.addEventListener('submit', async (event) => {
			event.preventDefault(); const submit = form.querySelector('button[type="submit"]'); submit.disabled = true;
			try { const updated = await request(`/product/${productId.value}`, { method: 'POST', body: JSON.stringify({ regular_price: price.value, map_price: map.value, map_source: mapSource.value }) }); populate(updated); setMessage('Pricing saved to WooCommerce with hard pricing floors enforced.', 'success'); await refreshActiveView(); }
			catch (error) { setMessage(error.message, 'error'); }
			finally { submit.disabled = false; }
		});
		return { open: async (id, trigger) => { lastFocused = trigger || document.activeElement; try { populate(await request(`/product/${id}`)); drawer.hidden = false; close.focus(); } catch (error) { setMessage(error.message, 'error'); } } };
	};
	const drawerApi = initDrawer();

	const initProducts = async () => {
		const tbody = root.querySelector('[data-pricing-products-body]'); const pagination = root.querySelector('[data-pricing-pagination]'); const search = root.querySelector('[data-pricing-search]'); const brand = root.querySelector('[data-pricing-brand]'); const status = root.querySelector('[data-pricing-status]'); let page = 1; let timer = null;
		await loadSummary(); renderSummary();
		(summary.brands || []).forEach((name) => { const option = document.createElement('option'); option.value = name; option.textContent = name; brand.append(option); });
		const load = async (requestedPage = page) => {
			page = requestedPage; tbody.innerHTML = '<tr><td colspan="8" class="dtb-pricing-loading">Loading pricing data…</td></tr>';
			const params = new URLSearchParams({ page: String(page), per_page: '25', search: search.value.trim(), brand: brand.value, status: status.value, sort: 'name', direction: 'asc' });
			try {
				const data = await request(`/products?${params}`);
				tbody.innerHTML = data.items.length ? data.items.map((row) => `<tr><td>${productCell(row)}</td><td>${money(row.cost)}</td><td>${money(row.map_price)}</td><td><div class="dtb-pricing-margin"><strong>${money(row.effective_price)}</strong>${row.on_sale ? `<span>Regular ${money(row.regular_price)}</span>` : ''}</div></td><td><div class="dtb-pricing-margin"><strong>${percent(row.gross_margin)}</strong><span>${money(row.gross_profit)} profit</span></div></td><td><div class="dtb-pricing-margin"><strong>${money(row.optimization_floor)}</strong><span>${escapeHtml(row.policy_source_label || '')}</span></div></td><td>${statusBadge(row)}</td><td class="dtb-pricing-table__actions"><button type="button" class="button button-secondary" data-edit-pricing="${Number(row.id)}">Edit</button></td></tr>`).join('') : '<tr><td colspan="8" class="dtb-pricing-empty">No pricing records match these filters.</td></tr>';
				buildPagination(pagination, data, load);
			} catch (error) { tbody.innerHTML = '<tr><td colspan="8" class="dtb-pricing-empty">Pricing data could not be loaded.</td></tr>'; setMessage(error.message, 'error'); }
		};
		tbody.addEventListener('click', (event) => { const button = event.target.closest('[data-edit-pricing]'); if (button && drawerApi) drawerApi.open(button.dataset.editPricing, button); });
		search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => load(1), 250); }); brand.addEventListener('change', () => load(1)); status.addEventListener('change', () => load(1));
		refreshActiveView = async () => { await loadSummary(); renderSummary(); await load(page); };
		await load();
	};

	const initOptimizer = async () => {
		const tbody = root.querySelector('[data-optimizer-body]'); const pagination = root.querySelector('[data-optimizer-pagination]'); const filter = root.querySelector('[data-optimizer-filter]'); const apply = root.querySelector('[data-optimizer-apply]'); const selectAll = root.querySelector('[data-optimizer-select-all]'); let page = 1;
		await loadSummary();
		const chip = root.querySelector('[data-optimizer-target]'); if (chip) chip.textContent = `${Number(summary.policy?.global_minimum_margin || 0).toFixed(1)}% min · ${Number(summary.policy?.global_target_margin || 0).toFixed(1)}% target`;
		const updateApplyState = () => { apply.disabled = !tbody.querySelector('input[data-optimizer-row]:checked'); };
		const load = async (requestedPage = page) => {
			page = requestedPage; selectAll.checked = false; apply.disabled = true; tbody.innerHTML = '<tr><td colspan="8" class="dtb-pricing-loading">Loading recommendations…</td></tr>';
			const params = new URLSearchParams({ page: String(page), per_page: '25', status: filter.value, map_only: '0', sort: 'margin', direction: 'asc' });
			try {
				const data = await request(`/products?${params}`);
				tbody.innerHTML = data.items.length ? data.items.map((row) => `<tr><td class="dtb-pricing-check"><input type="checkbox" data-optimizer-row data-product-id="${Number(row.id)}" data-regular-price="${escapeHtml(row.regular_price ?? '')}" ${row.recommendation_action !== 'optimize' ? 'disabled' : ''} aria-label="Select ${escapeHtml(row.name)}"></td><td>${productCell(row)}</td><td>${money(row.cost)}</td><td>${money(row.regular_price)}</td><td>${percent(row.regular_gross_margin ?? row.gross_margin)}</td><td><strong>${money(row.suggested_price)}</strong><span class="dtb-pricing-product__meta">${escapeHtml(row.severity || '')}${row.price_change_pct != null ? ` · +${percent(row.price_change_pct)}` : ''}</span></td><td>${percent(row.suggested_gross_margin)}</td><td class="dtb-pricing-muted">${escapeHtml(reasonText(row))}</td></tr>`).join('') : '<tr><td colspan="8" class="dtb-pricing-empty">No pricing recommendations in this group.</td></tr>';
				buildPagination(pagination, data, load);
			} catch (error) { tbody.innerHTML = '<tr><td colspan="8" class="dtb-pricing-empty">Recommendations could not be loaded.</td></tr>'; setMessage(error.message, 'error'); }
		};
		tbody.addEventListener('change', (event) => { if (event.target.matches('[data-optimizer-row]')) updateApplyState(); });
		selectAll.addEventListener('change', () => { tbody.querySelectorAll('[data-optimizer-row]:not(:disabled)').forEach((box) => { box.checked = selectAll.checked; }); updateApplyState(); }); filter.addEventListener('change', () => load(1));
		apply.addEventListener('click', async () => {
			const selected = [...tbody.querySelectorAll('[data-optimizer-row]:checked')]; if (!selected.length) return; apply.disabled = true;
			try { const result = await request('/apply', { method: 'POST', body: JSON.stringify({ items: selected.map((box) => ({ product_id: Number(box.dataset.productId), expected_regular_price: box.dataset.regularPrice })) }) }); const updated = result.updated?.length || 0; const conflicts = result.conflicts?.length || 0; const errors = result.errors?.length || 0; setMessage(`Applied ${updated} price change${updated === 1 ? '' : 's'}${conflicts ? ` · ${conflicts} held/reviewed` : ''}${errors ? ` · ${errors} failed` : ''}.`, errors ? 'error' : 'success'); await loadSummary(); await load(page); }
			catch (error) { setMessage(error.message, 'error'); updateApplyState(); }
		});
		refreshActiveView = async () => load(page);
		await load();
	};

	const initData = async () => {
		const form = root.querySelector('[data-pricing-policy-form]');
		const target = root.querySelector('[data-pricing-target-margin]');
		const minimum = root.querySelector('[data-policy-field="minimum_margin"]');
		const noChange = root.querySelector('[data-policy-field="no_change_threshold_pct"]');
		const reviewChange = root.querySelector('[data-policy-field="review_change_threshold_pct"]');
		const blockChange = root.querySelector('[data-policy-field="block_change_threshold_pct"]');
		const button = form?.querySelector('button[type="submit"]');
		if (!form || !target || !minimum || !noChange || !reviewChange || !blockChange || !button) throw new Error('Pricing policy controls are unavailable.');

		const setCount = (selector, value) => { const node = root.querySelector(selector); if (node) node.textContent = Number(value || 0).toLocaleString(); };
		const render = async () => {
			await loadSummary();
			const counts = summary.counts || {}; const policy = summary.policy || {};
			target.value = Number(policy.global_target_margin || summary.target_margin || 0).toFixed(1);
			minimum.value = Number(policy.global_minimum_margin || 0).toFixed(1);
			noChange.value = Number(policy.no_change_threshold_pct || 0).toFixed(1);
			reviewChange.value = Number(policy.review_change_threshold_pct || 0).toFixed(1);
			blockChange.value = Number(policy.block_change_threshold_pct || 0).toFixed(1);
			const costCoverage = root.querySelector('[data-data-cost-coverage]'); if (costCoverage) costCoverage.textContent = `${Number(counts.with_cost || 0).toLocaleString()} / ${Number(counts.total || 0).toLocaleString()}`;
			const mapCoverage = root.querySelector('[data-data-map-coverage]'); if (mapCoverage) mapCoverage.textContent = `${Number(counts.with_map || 0).toLocaleString()} / ${Number(counts.total || 0).toLocaleString()}`;
			setCount('[data-data-total]', counts.total); setCount('[data-data-missing-cost]', counts.missing_cost); setCount('[data-data-below-cogs]', counts.below_cogs); setCount('[data-data-below-minimum]', counts.below_minimum); setCount('[data-data-below-target]', counts.below_target); setCount('[data-data-below-map]', counts.below_map);
		};
		form.addEventListener('submit', async (event) => {
			event.preventDefault(); button.disabled = true;
			try { await request('/settings', { method: 'POST', body: JSON.stringify({ target_margin: target.value, minimum_margin: minimum.value, no_change_threshold_pct: noChange.value, review_change_threshold_pct: reviewChange.value, block_change_threshold_pct: blockChange.value }) }); setMessage('Pricing policy saved.', 'success'); await render(); }
			catch (error) { setMessage(error.message, 'error'); }
			finally { button.disabled = false; }
		});
		refreshActiveView = render;
		await render();
	};

	const refreshLiveCatalog = async () => {
		if (refreshing || document.visibilityState !== 'visible') return;
		if (activeTab === 'optimizer' && root.querySelector('[data-optimizer-row]:checked')) return;
		refreshing = true;
		try {
			await refreshActiveView();
			lastSyncAt = Date.now();
			if (syncStatus) syncStatus.textContent = `Live WooCommerce catalog · Updated ${new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit', second: '2-digit' }).format(lastSyncAt)}`;
		} catch (error) {
			setMessage(`Live catalog refresh failed: ${error.message}`, 'error');
		} finally {
			refreshing = false;
		}
	};

	(async () => {
		try {
			if (activeTab === 'products') await initProducts(); else if (activeTab === 'optimizer') await initOptimizer(); else await initData();
			lastSyncAt = Date.now();
			if (syncStatus) syncStatus.textContent = `Live WooCommerce catalog · Updated ${new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit', second: '2-digit' }).format(lastSyncAt)}`;
			window.setInterval(refreshLiveCatalog, 30000);
			document.addEventListener('visibilitychange', () => {
				if (document.visibilityState === 'visible' && Date.now() - lastSyncAt >= 30000) refreshLiveCatalog();
			});
			window.addEventListener('focus', () => {
				if (Date.now() - lastSyncAt >= 30000) refreshLiveCatalog();
			});
		}
		catch (error) { setMessage(error.message || 'Catalog Pricing could not be initialized.', 'error'); }
	})();
})();
