(function () {
	'use strict';

	var root = document.getElementById('dtb-email-studio');
	if (!root) return;

	var cfg = window.dtbAdminConfig || {};
	var state = { manifest: null, emailId: '', orderId: 0, device: 'desktop', fullscreen: false, rendering: false, renderSeq: 0, result: null, error: '' };

	function viewportLabel() {
		return state.device === 'mobile' ? '390px · Mobile' : state.device === 'tablet' ? '768px · Tablet' : 'Fluid · Desktop';
	}

	function isAccountFixture() {
		return state.emailId === 'customer_new_account' || state.emailId === 'customer_reset_password';
	}

	function restUrl(path) {
		return (cfg.restUrl || '/wp-json/').replace(/\/$/, '') + path;
	}

	function api(method, path, body) {
		return fetch(restUrl(path), {
			method: method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
			body: body ? JSON.stringify(body) : undefined,
		}).then(function (res) {
			return res.json().then(function (json) {
				if (!res.ok) throw new Error(json.message || 'Request failed');
				return json;
			});
		});
	}

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) {
			if (key === 'class') node.className = attrs[key];
			else if (key.indexOf('on') === 0) node.addEventListener(key.slice(2), attrs[key]);
			else if (attrs[key] !== null && attrs[key] !== undefined) node.setAttribute(key, attrs[key]);
		});
		(children || []).forEach(function (child) {
			node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
		});
		return node;
	}

	function loadManifest() {
		api('GET', '/dtb/v1/design/email-studio').then(function (manifest) {
			state.manifest = manifest;
			state.emailId = Object.keys(manifest.emails || {})[0] || '';
			state.orderId = 0;
			render();
			if (state.emailId) renderEmail();
		}).catch(function (error) {
			state.error = error.message;
			render();
		});
	}

	function renderEmail() {
		if (!state.emailId) return;
		var requestId = ++state.renderSeq;
		var emailId = state.emailId;
		state.rendering = true;
		state.error = '';
		state.result = null;
		render();
		api('POST', '/dtb/v1/design/email-studio/render', { emailId: emailId, orderId: 0 })
			.then(function (result) {
				if (requestId !== state.renderSeq || emailId !== state.emailId) return;
				state.result = result;
				state.rendering = false;
				render();
			})
			.catch(function (error) {
				if (requestId !== state.renderSeq || emailId !== state.emailId) return;
				state.error = error.message;
				state.rendering = false;
				render();
			});
	}

	function selectOptions(map, selected) {
		return Object.keys(map || {}).map(function (value) {
			return el('option', { value: value, selected: value === String(selected) ? 'selected' : null }, [map[value]]);
		});
	}

	function render() {
		root.classList.toggle('dtb-es--fullscreen', state.fullscreen);
		root.innerHTML = '';
		if (!state.manifest && !state.error) {
			root.appendChild(el('div', { class: 'dtb-vd__loading' }, [el('div', { class: 'dtb-vd__spinner' }, []), el('p', {}, ['Loading Email Studio…'])]));
			return;
		}

		var emailOptions = selectOptions(state.manifest ? state.manifest.emails : {}, state.emailId);

		var toolbar = el('div', { class: 'dtb-es__toolbar' }, [
			el('div', { class: 'dtb-es__brand' }, [el('span', { class: 'dtb-es__eyebrow' }, ['TRANSACTIONAL EMAILS']), el('h1', {}, ['Email Studio']), el('p', {}, ['Preview customer and operator email templates using safe simulated data. No messages are sent.'])]),
			el('label', {}, [el('span', {}, ['Email']), el('select', { onchange: function (event) { state.emailId = event.target.value; renderEmail(); } }, emailOptions)]),
			el('div', { class: 'dtb-es__fixture-label', role: 'status' }, ['SYNTHETIC DATA · NO REAL ORDERS OR CUSTOMERS']),
			el('div', { class: 'dtb-es__devices', role: 'group', 'aria-label': 'Email viewport width' },
				['desktop', 'tablet', 'mobile'].map(function (device) {
					return el('button', { type: 'button', class: state.device === device ? 'is-active' : '', 'aria-pressed': state.device === device ? 'true' : 'false', onclick: function () { state.device = device; render(); } }, [device.charAt(0).toUpperCase() + device.slice(1)]);
				})
			),
			el('button', { type: 'button', class: 'dtb-es__fullscreen', 'aria-pressed': state.fullscreen ? 'true' : 'false', onclick: function () { state.fullscreen = !state.fullscreen; render(); } }, [state.fullscreen ? 'Exit fullscreen' : 'Fullscreen']),
			el('button', { type: 'button', class: 'dtb-es__refresh', onclick: renderEmail, disabled: state.rendering ? 'disabled' : null }, [state.rendering ? 'Rendering…' : 'Refresh preview']),
		]);

		var meta = state.result ? el('div', { class: 'dtb-es__meta' }, [
			el('span', {}, ['Subject: ' + state.result.subject]),
			el('span', {}, ['Heading: ' + state.result.heading]),
			el('span', { class: 'dtb-es__viewport-label' }, [viewportLabel()]),
			el('span', {}, [state.result.sourceFidelity === 'visual-only-fulfillment' ? 'Visual-only shipment simulation · fulfillment hooks not exercised' : 'Production template · simulated data']),
		]) : el('div', { class: 'dtb-es__meta' }, [state.manifest ? state.manifest.notice : '']);

		var frameContent;
		if (state.error) {
			frameContent = el('div', { class: 'dtb-es__empty dtb-es__empty--error' }, [el('strong', {}, ['Preview unavailable']), el('p', {}, [state.error])]);
		} else if (!state.result) {
			frameContent = el('div', { class: 'dtb-es__empty' }, [el('strong', {}, ['Choose an email to preview']), el('p', {}, ['The Studio generates a synthetic preview automatically.'])]);
		} else {
			frameContent = el('iframe', { title: 'Rendered WooCommerce email', sandbox: '', srcdoc: state.result.html }, []);
		}

		root.appendChild(toolbar);
		root.appendChild(meta);
		root.appendChild(el('section', { class: 'dtb-es__stage', 'aria-label': viewportLabel() + ' email preview' }, [el('div', { class: 'dtb-es__canvas dtb-es__canvas--' + state.device }, [frameContent])]));
	}

	document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && state.fullscreen) { state.fullscreen = false; render(); } });
	loadManifest();
}());
