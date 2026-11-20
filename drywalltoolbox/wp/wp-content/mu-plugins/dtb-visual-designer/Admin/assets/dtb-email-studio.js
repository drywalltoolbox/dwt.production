(function () {
	'use strict';

	var root = document.getElementById('dtb-email-studio');
	if (!root) return;

	var cfg = window.dtbAdminConfig || {};
	var state = { manifest: null, emailId: '', orderId: 0, device: 'desktop', fullscreen: false, rendering: false, renderSeq: 0, result: null, error: '' };
	var fullscreenLayer = null;
	var fullscreenPlaceholder = null;
	var pageLock = null;

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
		(children || []).filter(Boolean).forEach(function (child) {
			node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
		});
		return node;
	}

	function saveInlineStyle(style, property) {
		return { value: style.getPropertyValue(property), priority: style.getPropertyPriority(property) };
	}

	function lockPageScroll(layer) {
		var html = document.documentElement;
		var body = document.body;
		var properties = ['position', 'top', 'left', 'right', 'width', 'overflow'];
		var bodyStyles = {};
		properties.forEach(function (property) { bodyStyles[property] = saveInlineStyle(body.style, property); });
		pageLock = {
			scrollY: window.scrollY || window.pageYOffset || 0,
			htmlOverflow: saveInlineStyle(html.style, 'overflow'),
			bodyStyles: bodyStyles,
			inertSiblings: [],
		};
		Array.prototype.slice.call(body.children).forEach(function (child) {
			if (child === layer) return;
			pageLock.inertSiblings.push({ node: child, inert: child.inert });
			child.inert = true;
		});
		html.style.setProperty('overflow', 'hidden');
		body.style.setProperty('position', 'fixed');
		body.style.setProperty('top', '-' + pageLock.scrollY + 'px');
		body.style.setProperty('left', '0');
		body.style.setProperty('right', '0');
		body.style.setProperty('width', '100%');
		body.style.setProperty('overflow', 'hidden');
	}

	function restoreInlineStyle(style, property, saved) {
		if (saved.value) style.setProperty(property, saved.value, saved.priority);
		else style.removeProperty(property);
	}

	function unlockPageScroll() {
		if (!pageLock) return;
		var html = document.documentElement;
		var body = document.body;
		var scrollY = pageLock.scrollY;
		restoreInlineStyle(html.style, 'overflow', pageLock.htmlOverflow);
		Object.keys(pageLock.bodyStyles).forEach(function (property) {
			restoreInlineStyle(body.style, property, pageLock.bodyStyles[property]);
		});
		pageLock.inertSiblings.forEach(function (entry) { entry.node.inert = entry.inert; });
		pageLock = null;
		window.scrollTo(0, scrollY);
	}

	function enterFullscreen() {
		if (state.fullscreen) return;
		state.fullscreen = true;
		fullscreenPlaceholder = document.createComment('Email Studio fullscreen return point');
		root.parentNode.insertBefore(fullscreenPlaceholder, root);
		fullscreenLayer = el('div', {
			class: 'dtb-es__fullscreen-layer',
			role: 'dialog',
			'aria-modal': 'true',
			'aria-label': 'Email preview fullscreen',
		});
		document.body.appendChild(fullscreenLayer);
		fullscreenLayer.appendChild(root);
		lockPageScroll(fullscreenLayer);
		render();
		window.requestAnimationFrame(function () {
			var exitButton = root.querySelector('.dtb-es__fullscreen');
			if (exitButton) exitButton.focus();
		});
	}

	function exitFullscreen() {
		if (!state.fullscreen) return;
		state.fullscreen = false;
		render();
		if (fullscreenPlaceholder && fullscreenPlaceholder.parentNode) {
			fullscreenPlaceholder.parentNode.insertBefore(root, fullscreenPlaceholder);
			fullscreenPlaceholder.parentNode.removeChild(fullscreenPlaceholder);
		}
		if (fullscreenLayer && fullscreenLayer.parentNode) fullscreenLayer.parentNode.removeChild(fullscreenLayer);
		fullscreenPlaceholder = null;
		fullscreenLayer = null;
		unlockPageScroll();
		window.requestAnimationFrame(function () {
			var fullscreenButton = root.querySelector('.dtb-es__fullscreen');
			if (fullscreenButton) fullscreenButton.focus();
		});
	}

	function fitEmailFrame(iframe) {
		function updateHeight() {
			try {
				var doc = iframe.contentDocument;
				if (!doc || !doc.documentElement) return;
				var body = doc.body;
				var height = Math.max(
					doc.documentElement.scrollHeight,
					doc.documentElement.offsetHeight,
					body ? body.scrollHeight : 0,
					body ? body.offsetHeight : 0,
					320
				);
				var pixels = Math.ceil(height) + 'px';
				if (iframe.style.height !== pixels) iframe.style.height = pixels;
			} catch (error) {
				// Keep the iframe's native scrolling fallback if the client blocks measurement.
			}
		}

		iframe.addEventListener('load', function () {
			updateHeight();
			window.requestAnimationFrame(updateHeight);
			window.setTimeout(updateHeight, 150);
			try {
				var doc = iframe.contentDocument;
				var view = iframe.contentWindow;
				if (view) view.addEventListener('resize', updateHeight);
				if (window.ResizeObserver && doc && doc.documentElement) {
					var observer = new ResizeObserver(updateHeight);
					observer.observe(doc.documentElement);
					if (doc.body) observer.observe(doc.body);
				}
				if (doc && doc.images) {
					Array.prototype.forEach.call(doc.images, function (image) {
						if (!image.complete) image.addEventListener('load', updateHeight, { once: true });
					});
				}
				if (doc) doc.addEventListener('keydown', function (event) {
					if (event.key === 'Escape' && state.fullscreen) {
						event.preventDefault();
						exitFullscreen();
					}
				});
			} catch (error) {
				// The preview remains readable if a browser denies iframe document access.
			}
		});
	}

	function mailIcon(name) {
		return el('span', { class: 'dashicons dashicons-' + name, 'aria-hidden': 'true' }, []);
	}

	function mailActions() {
		return el('div', { class: 'dtb-es__mail-actions', 'aria-hidden': 'true' }, [
			mailIcon('arrow-left-alt2'), mailIcon('archive'), mailIcon('trash'), mailIcon('email-alt2'), mailIcon('ellipsis'),
		]);
	}

	function buildMailClient(frameContent) {
		var subject = state.result && state.result.subject ? String(state.result.subject) : (state.result ? String(state.result.heading || 'Email preview') : 'Email preview');
		subject = subject.replace(/^\[Synthetic preview\]\s*/i, '');
		var subjectHeader = el('header', { class: 'dtb-es__mail-message-head' }, [
			el('h2', {}, [subject]),
			el('div', { class: 'dtb-es__mail-sender' }, [
				el('span', { class: 'dtb-es__mail-avatar', 'aria-hidden': 'true' }, ['DT']),
				el('span', { class: 'dtb-es__mail-sender-copy' }, [
					el('strong', {}, ['Drywall Toolbox']),
					el('span', {}, ['To you · Synthetic preview']),
				]),
				el('span', { class: 'dtb-es__mail-time' }, ['Preview']),
			]),
		]);
		var replyActions = el('div', { class: 'dtb-es__mail-reply', 'aria-hidden': 'true' }, [
			el('span', {}, ['↶ Reply']),
			el('span', {}, ['↪ Forward']),
		]);
		var children = [];
		if (state.device === 'mobile') {
			children.push(el('div', { class: 'dtb-es__mail-status', 'aria-hidden': 'true' }, [
				el('strong', {}, ['9:41']),
				el('span', { class: 'dtb-es__mail-status-icons' }, ['▮▮▮　5G　▰']),
			]));
		} else {
			children.push(el('div', { class: 'dtb-es__mail-topbar', 'aria-hidden': 'true' }, [
				el('span', { class: 'dtb-es__mail-wordmark' }, [mailIcon('email-alt'), 'Mail']),
				el('span', { class: 'dtb-es__mail-search' }, [mailIcon('search'), 'Search mail']),
				el('span', { class: 'dtb-es__mail-avatar dtb-es__mail-avatar--small' }, ['DT']),
			]));
		}
		children.push(el('div', { class: 'dtb-es__mail-toolbar', 'aria-hidden': 'true' }, [
			state.device === 'mobile' ? mailActions() : el('span', { class: 'dtb-es__mail-inbox' }, ['Inbox']),
			state.device === 'mobile' ? null : mailActions(),
		]));
		children.push(el('div', { class: 'dtb-es__mail-scroll' }, [subjectHeader, el('div', { class: 'dtb-es__mail-content' }, [frameContent]), replyActions]));
		return el('div', { class: 'dtb-es__mail-client dtb-es__mail-client--' + state.device }, children.filter(Boolean));
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
			el('button', { type: 'button', class: 'dtb-es__fullscreen', 'aria-pressed': state.fullscreen ? 'true' : 'false', onclick: function () { state.fullscreen ? exitFullscreen() : enterFullscreen(); } }, [state.fullscreen ? 'Exit fullscreen' : 'Fullscreen']),
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
			frameContent = el('iframe', { title: 'Rendered WooCommerce email', sandbox: 'allow-same-origin', srcdoc: state.result.html }, []);
			fitEmailFrame(frameContent);
		}

		root.appendChild(toolbar);
		if (!state.fullscreen) root.appendChild(meta);
		var canvas = el('div', { class: 'dtb-es__canvas dtb-es__canvas--' + state.device }, [state.fullscreen ? buildMailClient(frameContent) : frameContent]);
		root.appendChild(el('section', { class: 'dtb-es__stage', 'aria-label': viewportLabel() + ' email preview' }, [canvas]));
	}

	document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && state.fullscreen) { event.preventDefault(); exitFullscreen(); } });
	loadManifest();
}());
