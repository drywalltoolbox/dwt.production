(() => {
	'use strict';
	const root = document.querySelector('[data-dtb-pricing-root][data-active-tab="optimizer"]');
	if (!root || !window.dtbAdminConfig) return;
	const config = window.dtbAdminConfig;
	const toolbar = root.querySelector('.dtb-pricing-toolbar__actions');
	if (!toolbar) return;

	const money = (value) => `${config.currencySymbol || '$'}${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
	const restUrl = (path = '') => {
		const route = `/dtb/v1/admin/pricing${path}`;
		const configuredBase = String(config.restUrl || '');
		if (configuredBase.includes('rest_route=')) {
			const url = new URL(configuredBase, window.location.origin);
			url.searchParams.set('rest_route', route);
			return url.href;
		}
		const base = configuredBase || `${window.location.origin}/wp-json/`;
		return `${base.replace(/\/$/, '')}${route}`;
	};
	const request = async (path, body = {}) => {
		const response = await fetch(restUrl(path), {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: JSON.stringify(body),
		});
		const payload = await response.json().catch(() => ({}));
		if (!response.ok) throw new Error(payload.message || 'Pricing optimization could not be completed.');
		return payload;
	};

	const button = document.createElement('button');
	button.type = 'button'; button.className = 'button button-primary dtb-pricing-optimize-all';
	button.textContent = 'Optimize All Eligible Products'; toolbar.prepend(button);

	const dialog = document.createElement('dialog');
	dialog.className = 'dtb-pricing-bulk-dialog';
	dialog.innerHTML = `<form method="dialog" class="dtb-pricing-bulk-dialog__panel">
		<div class="dtb-pricing-bulk-dialog__header"><div><span class="dtb-pricing-bulk-dialog__eyebrow">Catalog Pricing</span><h2>Pricing Optimization Preview</h2></div><button type="submit" class="button-link" aria-label="Close pricing optimization preview">Close</button></div>
		<div class="dtb-pricing-bulk-dialog__body" data-bulk-body><p>Calculating current catalog recommendations…</p></div>
		<div class="dtb-pricing-bulk-dialog__footer"><button type="submit" class="button button-secondary">Cancel</button><button type="button" class="button button-primary" data-bulk-apply disabled>Apply Price Updates</button></div>
	</form>`;
	document.body.append(dialog);
	const body = dialog.querySelector('[data-bulk-body]'); const apply = dialog.querySelector('[data-bulk-apply]');
	let runToken = ''; let preview = null;

	const renderPreview = (summary) => {
		const policy = summary.policy || {};
		const rows = [
			['Total price-owning products', summary.total],
			['With Cost of Goods', summary.with_cost],
			['MAP configured', summary.with_map],
			['Prices will increase', summary.will_update],
			['Below COGS — critical', summary.below_cogs],
			['Below minimum margin', summary.below_minimum],
			['MAP violations corrected', summary.map_violations],
			['Already optimal — unchanged', summary.already_optimal],
			['Review required — unchanged', summary.review],
			['Blocked by guardrail — unchanged', summary.blocked],
			['Missing COGS', summary.missing_cost],
			['MAP not configured', summary.missing_map],
		];
		body.innerHTML = `<div class="dtb-pricing-bulk-summary">${rows.map(([label, value]) => `<div><span>${label}</span><strong>${Number(value || 0).toLocaleString()}</strong></div>`).join('')}</div>
			<div class="dtb-pricing-bulk-impact"><span>Estimated aggregate regular-price increase</span><strong>${money(summary.estimated_regular_increase)}</strong></div>
			<p class="description">Global fallback: minimum ${Number(policy.global_minimum_margin || 0).toFixed(1)}% · target ${Number(policy.global_target_margin || 0).toFixed(1)}%. Category policies take priority when supported by evidence, then brand, then global. COGS/minimum-margin/MAP violations are hard constraints. Every product is recalculated again immediately before WooCommerce is updated.</p>`;
		apply.textContent = `Apply ${Number(summary.will_update || 0).toLocaleString()} Price Updates`;
		apply.disabled = Number(summary.will_update || 0) < 1;
	};

	button.addEventListener('click', async () => {
		button.disabled = true; runToken = ''; preview = null; body.innerHTML = '<p>Calculating current catalog recommendations…</p>'; apply.disabled = true; dialog.showModal();
		try { preview = await request('/optimize-all/preview'); runToken = preview.run_token; renderPreview(preview.summary || {}); }
		catch (error) { body.innerHTML = '<p class="dtb-pricing-bulk-error"></p>'; body.querySelector('p').textContent = error.message; }
		finally { button.disabled = false; }
	});

	apply.addEventListener('click', async () => {
		if (!runToken) return;
		apply.disabled = true; const total = Number(preview?.summary?.will_update || 0); let response = null;
		try {
			do {
				response = await request('/optimize-all/apply', { run_token: runToken });
				body.innerHTML = `<div class="dtb-pricing-bulk-progress"><strong>Applying optimized prices…</strong><span>${Number(response.processed || 0).toLocaleString()} of ${Number(response.total || total).toLocaleString()}</span><progress max="${Math.max(1, Number(response.total || total))}" value="${Number(response.processed || 0)}"></progress></div>`;
			} while (!response.complete);
			const result = response.result || {};
			body.innerHTML = `<div class="dtb-pricing-bulk-complete"><h3>Pricing optimization complete</h3><div class="dtb-pricing-bulk-summary">
				<div><span>Prices updated</span><strong>${Number(result.updated || 0).toLocaleString()}</strong></div>
				<div><span>Changed during run / held</span><strong>${Number(result.conflicts || 0).toLocaleString()}</strong></div>
				<div><span>Errors</span><strong>${Number(result.errors || 0).toLocaleString()}</strong></div>
			</div><p class="description">WooCommerce is authoritative for the applied prices. Per-product and whole-run pricing audit events preserve the policy and reason context.</p></div>`;
			apply.hidden = true; window.setTimeout(() => window.location.reload(), 1200);
		} catch (error) { body.innerHTML = '<p class="dtb-pricing-bulk-error"></p>'; body.querySelector('p').textContent = error.message; apply.disabled = false; }
	});
	dialog.addEventListener('close', () => { apply.hidden = false; apply.disabled = true; runToken = ''; preview = null; });
})();
