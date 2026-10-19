(function () {
	'use strict';

	const modalSelector = '.wc-backbone-modal.wc-order-preview';
	const observer = new MutationObserver((records) => {
		records.forEach((record) => {
			record.addedNodes.forEach(inspectNode);
		});
	});

	function inspectNode(node) {
		if (!node || node.nodeType !== 1) return;
		const modal = node.matches(modalSelector) ? node : node.closest(modalSelector);
		if (modal) transform(modal);
		node.querySelectorAll(modalSelector).forEach(transform);
	}

	function transform(modal) {
		if (modal.dataset.dtbQvLayout || !document.body.classList.contains('dtb-order-quick-view-v2')) return;

		const article = modal.querySelector('article');
		if (!article) return;

		const addresses = article.querySelector('.wc-order-preview-addresses');
		const tableWrapper = article.querySelector('.wc-order-preview-table-wrapper');
		const payload = tableWrapper && tableWrapper.querySelector('.dtb-qv-payload');
		if (!addresses || !tableWrapper || !payload) return;

		const addressCards = addresses.querySelectorAll('.wc-order-preview-address');
		const orderContext = payload.querySelector('.dtb-qv__order-meta');
		const itemCard = payload.querySelector('.dtb-qv-card--items');
		const summaryCard = payload.querySelector('.dtb-qv-card--summary');
		const customerCard = payload.querySelector('.dtb-qv-card--customer');
		const itemTable = itemCard && itemCard.querySelector('.wc-order-preview-table');
		if (itemTable) {
			itemTable.setAttribute('role', 'table');
			itemTable.setAttribute('aria-labelledby', 'dtb-qv-items-title');
			const head = itemTable.querySelector('thead');
			const body = itemTable.querySelector('tbody');
			if (head) head.setAttribute('role', 'rowgroup');
			if (body) body.setAttribute('role', 'rowgroup');
			const headers = Array.from(itemTable.querySelectorAll('thead th')).map((header) => header.textContent.trim());
			itemTable.querySelectorAll('thead tr').forEach((row) => {
				row.setAttribute('role', 'row');
				row.querySelectorAll('th').forEach((cell) => cell.setAttribute('role', 'columnheader'));
			});
			itemTable.querySelectorAll('tbody tr').forEach((row) => {
				row.setAttribute('role', 'row');
				Array.from(row.cells).forEach((cell, index) => {
					cell.setAttribute('role', 'cell');
					if (headers[index]) cell.dataset.label = headers[index];
				});
			});
		}
		if (addressCards.length < 2 || !orderContext || !itemCard || !itemTable || !summaryCard || !customerCard) {
			modal.dataset.dtbQvLayout = 'unsupported';
			return;
		}

		const layout = document.createElement('div');
		layout.className = 'dtb-qv-layout';
		layout.setAttribute('aria-label', 'Order preview details');
		const primary = document.createElement('div');
		primary.className = 'dtb-qv-layout__primary';
		const rail = document.createElement('aside');
		rail.className = 'dtb-qv-layout__rail';
		rail.setAttribute('aria-label', 'Order summary and customer');
		const tableScroll = document.createElement('div');
		tableScroll.className = 'dtb-qv-table-scroll';
		tableScroll.setAttribute('role', 'region');
		tableScroll.setAttribute('aria-label', 'Scrollable order items');
		itemTable.parentNode.insertBefore(tableScroll, itemTable);
		tableScroll.setAttribute('tabindex', '0');
		tableScroll.appendChild(itemTable);

		const unhandled = Array.from(article.children).filter((child) => child !== addresses && child !== tableWrapper);
		const modalHeader = modal.querySelector('.wc-backbone-modal-main > header, .wc-backbone-modal-main header, .wc-backbone-modal-header');
		if (modalHeader) {
			orderContext.classList.add('dtb-qv__header-meta');
			modalHeader.appendChild(orderContext);
		} else {
			primary.appendChild(orderContext);
		}
		primary.append(addresses, itemCard);
		rail.append(summaryCard, customerCard);
		layout.append(primary, rail);
		article.prepend(layout);
		unhandled.forEach((child) => primary.appendChild(child));

		if (!payload.querySelector('*') && !payload.textContent.trim()) payload.remove();
		if (!tableWrapper.querySelector('*') && !tableWrapper.textContent.trim()) tableWrapper.remove();

		modal.dataset.dtbQvLayout = '2';
		modal.classList.add('dtb-qv-mounted');
	}

	function start() {
		if (!document.body.classList.contains('dtb-order-quick-view-v2')) return;
		document.querySelectorAll(modalSelector).forEach(transform);
		observer.observe(document.body, { childList: true, subtree: true });
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start, { once: true });
	} else {
		start();
	}
})();

