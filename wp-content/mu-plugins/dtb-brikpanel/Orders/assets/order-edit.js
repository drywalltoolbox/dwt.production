(function () {
	'use strict';

	const enabledClass = 'dtb-order-edit-workspace-enabled';
	const mountedClass = 'dtb-order-edit-workspace-mounted';
	let observer;
	let attempts = 0;

	function mount() {
		if (!document.body.classList.contains(enabledClass)) return true;
		if (document.body.classList.contains(mountedClass)) return true;

		const tabs = document.querySelector('.bp-otabs');
		const orderData = document.getElementById('woocommerce-order-data');
		const notesBox = document.getElementById('woocommerce-order-notes');
		const sidebar = document.getElementById('side-sortables');
		const form = document.getElementById('order') || document.getElementById('post');
		const mainColumn = document.getElementById('postbox-container-2');
		if (!tabs || !orderData || !form || !mainColumn) return false;
		if (![tabs, orderData].every((node) => form.contains(node))) return true;
		if (!mainColumn.contains(tabs)) return true;

		const customerPanel = orderData.closest('[role="tabpanel"]');
		const workspace = document.createElement('section');
		workspace.className = 'dtb-order-edit-workspace';
		workspace.setAttribute('aria-label', 'Order details and workspace');
		const detailsRegion = document.createElement('div');
		detailsRegion.className = 'dtb-order-edit-workspace__details';
		const contentRegion = document.createElement('div');
		contentRegion.className = 'dtb-order-edit-workspace__content';
		const orderDataPosition = document.createComment(' DTB order details position ');
		const tabsPosition = document.createComment(' DTB order tabs position ');
		const notesPosition = notesBox && notesBox.parentNode
			? document.createComment(' DTB order notes position ')
			: null;
		orderData.parentNode.insertBefore(orderDataPosition, orderData);
		tabs.parentNode.insertBefore(tabsPosition, tabs);
		if (notesPosition) notesBox.parentNode.insertBefore(notesPosition, notesBox);

		try {
			workspace.append(detailsRegion, contentRegion);
			mainColumn.insertBefore(workspace, mainColumn.firstChild);
			detailsRegion.appendChild(orderData);
			contentRegion.appendChild(tabs);
			if (notesBox && sidebar && notesPosition && form.contains(notesBox)) {
				const actions = document.getElementById('woocommerce-order-actions');
				if (actions && actions.parentNode === sidebar) {
					actions.insertAdjacentElement('afterend', notesBox);
				} else {
					sidebar.appendChild(notesBox);
				}
			}
		} catch (error) {
			if (orderDataPosition.parentNode) orderDataPosition.parentNode.replaceChild(orderData, orderDataPosition);
			if (tabsPosition.parentNode) tabsPosition.parentNode.replaceChild(tabs, tabsPosition);
			if (notesPosition && notesPosition.parentNode) notesPosition.parentNode.replaceChild(notesBox, notesPosition);
			workspace.remove();
			return true;
		}

		if (customerPanel && !customerPanel.querySelector('.postbox')) {
			const customerTab = document.querySelector('.bp-otabs__tab[aria-controls="' + customerPanel.id + '"]');
			customerPanel.hidden = true;
			if (customerTab) customerTab.hidden = true;
		}
		const notesPanel = document.getElementById('bp-otabpanel-notes');
		const notesTab = document.querySelector('.bp-otabs__tab[aria-controls="bp-otabpanel-notes"]');
		if (notesPanel && !notesPanel.querySelector('.postbox') && notesTab && sidebar && notesBox && sidebar.contains(notesBox)) {
			notesPanel.hidden = true;
			notesTab.hidden = true;
			const count = notesTab.querySelector('.bp-otabs__count');
			if (count) count.textContent = '';
		}
		const selectedTab = document.querySelector('.bp-otabs__tab[aria-selected="true"]');
		if (selectedTab && selectedTab.hidden) {
			const nextTab = document.querySelector('.bp-otabs__tab:not([hidden])');
			if (nextTab) nextTab.click();
		}
		document.body.classList.add(mountedClass);
		return true;
	}

	function start() {
		if (mount()) return;
		observer = new MutationObserver(() => {
			attempts += 1;
			if (mount() || attempts > 80) {
				observer.disconnect();
				observer = null;
			}
		});
		observer.observe(document.body, { childList: true, subtree: true });
		window.setTimeout(() => {
			if (observer) observer.disconnect();
		}, 8000);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start, { once: true });
	} else {
		start();
	}
})();
