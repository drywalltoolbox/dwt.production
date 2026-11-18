(() => {
	'use strict';
	const config = window.DTBQuickBooksAdmin;
	const root = document.getElementById('dtb-qbo-admin-root');
	if (!config || !root) return;
	const state = { view: 'overview', page: 1, busy: false };
	const q = (s, c = root) => c.querySelector(s);
	const qa = (s, c = root) => [...c.querySelectorAll(s)];
	const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
	const money = (v, currency = 'USD') => new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'USD' }).format(Number(v || 0));
	const date = (v) => v ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(v)) : '—';
	const badge = (v) => `<span class="dtb-qbo-badge is-${esc(v || 'unknown')}">${esc(v || 'unknown')}</span>`;
	const endpoint = (path) => `${String(config.restRoot).replace(/\/?$/, '/')}${String(config.basePath).replace(/^\//, '').replace(/\/$/, '')}${path}`;
	const savedViewsKey = `dtb-qbo-saved-views-${config.environment || 'default'}`;
	const savedViews = () => { try { return JSON.parse(window.localStorage.getItem(savedViewsKey) || '{}'); } catch { return {}; } };

	async function api(path, body) {
		const response = await fetch(endpoint(path), {
			method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
			headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: body ? JSON.stringify(body) : undefined,
		});
		const payload = await response.json().catch(() => ({}));
		if (!response.ok) throw new Error(payload.message || `Request failed (${response.status}).`);
		return payload;
	}
	function alert(message, tone = 'info') {
		const node = q('[data-qbo-alert]');
		node.hidden = !message; node.className = `dtb-qbo-alert is-${tone}`; node.textContent = message || '';
	}
	function setBusy(value) {
		state.busy = value; root.setAttribute('aria-busy', value ? 'true' : 'false');
		qa('button').forEach((button) => { button.disabled = value; });
	}
	function table(columns, rows) {
		if (!rows.length) return '<div class="dtb-qbo-empty"><strong>No records</strong><span>This view is clear for the selected range.</span></div>';
		return `<div class="dtb-qbo-table-wrap"><table class="dtb-qbo-table"><thead><tr>${columns.map((c) => `<th>${esc(c.label)}</th>`).join('')}</tr></thead><tbody>${rows.map((row) => `<tr>${columns.map((c) => `<td>${c.render ? c.render(row) : esc(row[c.key])}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
	}
	const ledgerColumns = [
		{ label: 'Date', render: (r) => date(r.txn_date) },
		{ label: 'Source', render: (r) => `<strong>${esc(r.document_number)}</strong><small>${esc(r.source_key)}</small>` },
		{ label: 'Type', render: (r) => esc(r.direction) },
		{ label: 'Expected', render: (r) => money(r.expected_total, r.currency) },
		{ label: 'QBO total', render: (r) => r.qbo_total === null ? '—' : money(r.qbo_total, r.currency) },
		{ label: 'Variance', render: (r) => money(r.variance, r.currency) },
		{ label: 'State', render: (r) => badge(r.state) },
		{ label: 'Trace', render: (r) => `<code>${esc(r.trace_id || '—')}</code>` },
	];
	function kpis(items) {
		return items.map(([label, value, note]) => `<article class="dtb-qbo-kpi"><span>${esc(label)}</span><strong>${esc(value)}</strong>${note ? `<small>${esc(note)}</small>` : ''}</article>`).join('');
	}
	function actions(view) {
		const host = q(`[data-qbo-panel-actions="${view}"]`);
		if (!host) return;
		const map = {
			transactions: '<button class="button" data-qbo-control="dry_run">Dry-run order</button>',
			exceptions: '<button class="button button-primary" data-qbo-control="reconcile">Queue selected reconciliation</button>',
			tax: '<button class="button" data-qbo-control="review_exemption">Review exemption</button>',
			settlement: '<p class="description">Legacy Stripe payout imports are disabled. WooPayments is the active gateway; historical Stripe rows remain read-only.</p>',
			reports: '<button class="button" data-qbo-control="reports">Refresh QBO reports</button><button class="button button-primary" data-qbo-control="close_period">Close period</button>',
			rules: '<button class="button" data-qbo-action="discover">Discover service items</button><button class="button button-primary" data-qbo-control="save_rules">Approve mappings</button>',
			automation: '<button class="button" data-qbo-action="queue">Queue eligible orders</button>',
		};
		host.innerHTML = map[view] || '';
	}
	function render(view, payload) {
		const data = payload.data || {};
		const host = q(`[data-qbo-table="${view}"]`);
		const metricsHost = q(`[data-qbo-panel="${view}"] [data-qbo-kpis]`);
		if (view === 'overview') {
			const m = data.metrics || {};
			metricsHost.hidden = false;
			metricsHost.innerHTML = kpis([
				['Sales', money(m.sales), 'last 30 days'], ['Refunds', money(m.refunds), 'concrete refunds'],
				['Tax liability', money(m.tax_collected), 'net collected'], ['Historical processor fees', money(m.fees), 'legacy Stripe payout records'],
				['Reconciled', m.reconciled_count || 0, 'exact QBO matches'], ['Exceptions', m.exception_count || 0, 'requires review'],
			]);
			const connection = data.connection || {};
			host.innerHTML = `${table(ledgerColumns, data.latest || [])}<div class="dtb-qbo-health-grid dtb-qbo-overview-health"><article><span>QuickBooks connection</span><strong>${connection.status?.connected ? 'Connected' : 'Disconnected'}</strong><small>${esc(connection.company?.name || 'No company verified')}</small></article><article><span>Accounting readiness</span><strong>${connection.readiness?.ready ? 'Ready' : 'Blocked'}</strong><small>${esc(Object.values(connection.readiness?.checks || {}).filter((check)=>!check.complete).map((check)=>check.label).join(', ') || 'All prerequisites verified')}</small></article><article><span>Controls</span><strong>${esc(String(connection.status?.environment || config.environment || '').toUpperCase())}</strong><button class="button" data-qbo-action="test">Test connection</button>${connection.status?.connected ? '<button class="button button-secondary" data-qbo-action="disconnect">Disconnect</button>' : '<button class="button button-primary" data-qbo-action="connect">Connect / reconnect</button>'}</article></div>`;
		} else if (['transactions','exceptions','settlement','audit'].includes(view)) {
			host.innerHTML = table(ledgerColumns, data.rows || []);
			renderPagination(data);
		} else if (view === 'tax') {
			metricsHost.hidden = false;
			metricsHost.innerHTML = kpis([['Collected', money(data.collected)], ['Refund reversals', money(data.reversed)], ['Net liability', money(data.liability)], ['QBO tax tracking', data.taxPreference?.tracked ? 'Detected' : 'Needs review']]);
			host.innerHTML = table([
				{ label: 'Jurisdiction / rate', render: (r) => `<strong>${esc(r.jurisdiction)}</strong><small>Rate ID ${esc(r.rateId)}</small>` },
				{ label: 'Rate', render: (r) => `${Number(r.rate || 0).toFixed(4)}%` },
				{ label: 'Collected', render: (r) => money(r.collected) },
				{ label: 'Reversed', render: (r) => money(r.reversed) },
				{ label: 'Liability', render: (r) => money(Number(r.collected) - Number(r.reversed)) },
			], data.rows || []);
		} else if (view === 'reports') {
			const reports = Object.entries(data.reports || {}).map(([name, report]) => ({ name, ...report }));
			host.innerHTML = table([{label:'Report', key:'name'}, {label:'Status', render:(r)=>badge(r.ok?'ready':'failed')}, {label:'As of', render:(r)=>esc(r.header?.EndPeriod || '—')}, {label:'Snapshot', render:(r)=>(r.summary || []).slice(0,4).map((item)=>`<strong>${esc(item.label)}</strong> ${esc(item.value)}`).join('<br>') || '—'}, {label:'Refreshed', render:(r)=>date(r.refreshedAt)}], reports);
		} else if (view === 'rules') {
			const p = data.policy || {};
			host.innerHTML = `${table([{label:'Role',render:(r)=>`<strong>${esc(r.label)}</strong><small>${esc(r.description)}</small>`},{label:'QBO service item',render:(r)=>`${esc(r.name || r.expected)}<small>ID ${esc(r.id || '—')}</small>`},{label:'Source',key:'source'},{label:'Status',render:(r)=>badge(r.verified?'verified':'attention')}], data.items || [])}<div class="dtb-qbo-rule-grid">
				${['tax_code','deposit_account','clearing_account','fee_account','bank_account'].map((key) => `<label><span>${esc(key.replaceAll('_',' '))}</span><input data-policy="${key}_id" value="${esc(p[`${key}_id`] || '')}" placeholder="QuickBooks ID"><input data-policy="${key}_name" value="${esc(p[`${key}_name`] || '')}" placeholder="Approved name"></label>`).join('')}
				<p class="description">Changes are versioned and stamped with the approving administrator. Remote records are never auto-created.</p></div>`;
		} else if (view === 'automation') {
			host.innerHTML = `<div class="dtb-qbo-health-grid"><article><span>Order queue</span><strong>${data.sync?.ready ? 'Ready' : 'Blocked'}</strong><small>${esc((data.sync?.blockers || []).join(' ') || 'dtb-orders queue is available.')}</small></article><article><span>QBO change capture</span><strong>${esc(data.cdc?.state || 'never run')}</strong><small>${esc(data.cdc?.checkedAt || 'Daily webhook backstop')}</small></article><article><span>Legacy Stripe settlement import</span><strong>Disabled</strong><small>WooPayments is the active gateway. Historical records remain available.</small></article></div>`;
		}
		actions(view);
		q('[data-qbo-last-refresh]').textContent = `Updated ${date(payload.generatedAt || new Date().toISOString())}`;
	}
	function renderPagination(data) {
		const host = q(`[data-qbo-panel="${state.view}"] [data-qbo-pagination]`);
		host.hidden = !data.pages || data.pages <= 1;
		host.innerHTML = host.hidden ? '' : `<button class="button" data-page="${Math.max(1, data.page - 1)}" ${data.page <= 1 ? 'disabled' : ''}>Previous</button><span>${data.page} / ${data.pages}</span><button class="button" data-page="${Math.min(data.pages, data.page + 1)}" ${data.page >= data.pages ? 'disabled' : ''}>Next</button>`;
	}
	async function load() {
		if (state.busy) return;
		setBusy(true); alert('');
		const params = new URLSearchParams({ view: state.view, page: String(state.page), limit: String(config.pageSize || 25) });
		const search = q('[data-qbo-filter="search"]')?.value.trim();
		const from = q('[data-qbo-filter="from"]')?.value;
		const to = q('[data-qbo-filter="to"]')?.value;
		if (search) params.set('search', search); if (from) params.set('date_from', from); if (to) params.set('date_to', to);
		try { render(state.view, await api(`/enterprise?${params.toString()}`)); }
		catch (error) { alert(error.message, 'error'); }
		finally { setBusy(false); }
	}
	function activate(view) {
		state.view = view; state.page = 1;
		qa('[data-qbo-tab]').forEach((tab) => { const active = tab.dataset.qboTab === view; tab.classList.toggle('is-active', active); tab.setAttribute('aria-selected', active ? 'true' : 'false'); tab.tabIndex = active ? 0 : -1; });
		qa('[data-qbo-panel]').forEach((panel) => { const active = panel.dataset.qboPanel === view; panel.hidden = !active; panel.classList.toggle('is-active', active); });
		load();
	}
	async function control(action) {
		let body = { action };
		if (action === 'dry_run') {
			const orderId = window.prompt('WooCommerce order ID to preview:'); if (!orderId) return;
			body.order_id = Number(orderId);
		} else if (action === 'reconcile') {
			const id = window.prompt('Accounting ledger document ID to queue:'); if (!id) return;
			body.document_id = Number(id);
		} else if (action === 'review_exemption') {
			const orderId = window.prompt('WooCommerce order ID:'); if (!orderId) return;
			const status = window.prompt('Decision: approved or rejected'); if (!['approved','rejected'].includes(status)) return;
			body.order_id = Number(orderId); body.status = status;
		} else if (action === 'close_period') {
			const closed = window.prompt('Close accounting through (YYYY-MM-DD):'); if (!closed) return;
			if (!window.confirm(`Close accounting through ${closed}? Open exceptions will block this action.`)) return;
			body.closed_through = closed;
		} else if (action === 'save_rules') {
			body.policy = Object.fromEntries(qa('[data-policy]').map((input) => [input.dataset.policy, input.value]));
			if (!window.confirm('Approve and version these accounting mappings?')) return;
		}
		setBusy(true);
		try {
			const result = await api('/accounting/control', body);
			if (result.preview) window.alert(JSON.stringify(result.preview, null, 2));
			alert(result.queued ? 'Operation queued in dtb-orders.' : 'Accounting control updated.', 'success');
			await load();
		} catch (error) { alert(error.message, 'error'); } finally { setBusy(false); }
	}
	root.addEventListener('click', (event) => {
		const tab = event.target.closest('[data-qbo-tab]'); if (tab) return activate(tab.dataset.qboTab);
		const page = event.target.closest('[data-page]'); if (page && !page.disabled) { state.page = Number(page.dataset.page); return load(); }
		const ctl = event.target.closest('[data-qbo-control]'); if (ctl) return control(ctl.dataset.qboControl);
		const action = event.target.closest('[data-qbo-action]');
		if (action?.dataset.qboAction === 'refresh' || action?.dataset.qboAction === 'apply-filters') return load();
		if (action?.dataset.qboAction === 'save-view') {
			const name = window.prompt('Saved view name:'); if (!name) return;
			const views = savedViews();
			const id = `custom-${Date.now()}`; views[id] = { name: name.slice(0, 60), view: state.view, search: q('[data-qbo-filter="search"]').value, from: q('[data-qbo-filter="from"]').value, to: q('[data-qbo-filter="to"]').value };
			window.localStorage.setItem(savedViewsKey, JSON.stringify(views)); loadSavedViews(); q('[data-qbo-saved-view]').value = id; return alert('View saved in this browser.', 'success');
		}
		if (action?.dataset.qboAction === 'queue') return api('/sync/queue', { limit: 25 }).then(() => alert('Eligible orders queued.', 'success')).catch((error) => alert(error.message, 'error'));
		if (action?.dataset.qboAction === 'discover') return api('/items/discover', {}).then(() => { alert('Service item mappings verified.', 'success'); load(); }).catch((error) => alert(error.message, 'error'));
		if (action?.dataset.qboAction === 'test') return api('/test', {}).then(() => alert('QuickBooks connection verified.', 'success')).catch((error) => alert(error.message, 'error'));
		if (action?.dataset.qboAction === 'connect') return api('/connect', {}).then((result) => { if (result.authorization_url) window.location.assign(result.authorization_url); }).catch((error) => alert(error.message, 'error'));
		if (action?.dataset.qboAction === 'disconnect') {
			if (!window.confirm(config.labels.confirmDisconnect)) return;
			setBusy(true);
			return api('/disconnect', { confirm: true }).then(() => { alert('QuickBooks disconnected. Existing accounting records were retained.', 'success'); setBusy(false); return load(); }).catch((error) => alert(error.message, 'error')).finally(() => setBusy(false));
		}
	});
	q('[data-qbo-saved-view]')?.addEventListener('change', (event) => {
		const value = event.target.value;
		if (value === 'month') {
			const now = new Date(); q('[data-qbo-filter="from"]').value = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`; q('[data-qbo-filter="to"]').value = now.toISOString().slice(0, 10);
		}
		if (value === 'attention') return activate('exceptions');
		if (value.startsWith('custom-')) {
			const view = savedViews()[value];
			if (view) { q('[data-qbo-filter="search"]').value = view.search || ''; q('[data-qbo-filter="from"]').value = view.from || ''; q('[data-qbo-filter="to"]').value = view.to || ''; return activate(view.view || 'overview'); }
		}
		load();
	});
	function loadSavedViews() {
		const select = q('[data-qbo-saved-view]'); if (!select) return;
		qa('option[data-custom]', select).forEach((option) => option.remove());
		const views = savedViews();
		Object.entries(views).forEach(([id, view]) => { const option = document.createElement('option'); option.value = id; option.dataset.custom = '1'; option.textContent = view.name; select.append(option); });
	}
	loadSavedViews();
	activate('overview');
})();
