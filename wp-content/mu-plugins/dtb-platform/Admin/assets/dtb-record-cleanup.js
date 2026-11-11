(function () {
	'use strict';

	function initRecordCleanup() {
		var form = document.querySelector('[data-dtb-record-cleanup-form]');
		if (!form || form.dataset.dtbReady === '1') return;
		form.dataset.dtbReady = '1';

		var countNode = form.querySelector('[data-dtb-selected-count]');
		var confirmation = form.querySelector('[data-dtb-delete-confirmation]');
		var submit = form.querySelector('[data-dtb-delete-submit]');

		function groupBoxes(group) {
			return Array.prototype.slice.call(
				form.querySelectorAll('[data-dtb-record-group="' + group + '"]')
			);
		}

		function updateState() {
			var selected = form.querySelectorAll('[data-dtb-record-group]:checked').length;
			if (countNode) countNode.textContent = String(selected);

			form.querySelectorAll('[data-dtb-select-group]').forEach(function (master) {
				var boxes = groupBoxes(master.dataset.dtbSelectGroup);
				var checked = boxes.filter(function (box) { return box.checked; }).length;
				master.checked = boxes.length > 0 && checked === boxes.length;
				master.indeterminate = checked > 0 && checked < boxes.length;
			});

			if (submit) {
				submit.disabled = selected === 0 || !confirmation || confirmation.value.trim() !== 'DELETE';
			}
		}

		form.addEventListener('change', function (event) {
			var master = event.target.closest('[data-dtb-select-group]');
			if (master) {
				groupBoxes(master.dataset.dtbSelectGroup).forEach(function (box) {
					box.checked = master.checked;
				});
			}
			updateState();
		});

		form.addEventListener('input', updateState);

		form.addEventListener('click', function (event) {
			var row = event.target.closest('[data-dtb-cleanup-row]');
			if (!row || event.target.closest('input, button, a, label')) return;
			var checkbox = row.querySelector('[data-dtb-record-group]');
			if (!checkbox) return;
			checkbox.checked = !checkbox.checked;
			checkbox.dispatchEvent(new Event('change', { bubbles: true }));
		});

		form.addEventListener('submit', function (event) {
			var selected = form.querySelectorAll('[data-dtb-record-group]:checked').length;
			if (selected === 0 || !confirmation || confirmation.value.trim() !== 'DELETE') {
				event.preventDefault();
				updateState();
			}
		});

		updateState();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initRecordCleanup);
	} else {
		initRecordCleanup();
	}
})();
