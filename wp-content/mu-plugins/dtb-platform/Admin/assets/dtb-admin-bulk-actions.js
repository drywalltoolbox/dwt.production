( function () {
	'use strict';

	var DtbAdmin = window.DtbAdmin || {};

	function getRecordCheckboxes( scope ) {
		return Array.prototype.slice.call(
			( scope || document ).querySelectorAll( '[data-dtb-bulk-id], .dtb-support-row__checkbox[data-dtb-ticket-id]' )
		).filter( function ( checkbox ) {
			return checkbox.type === 'checkbox';
		} );
	}

	function getRecordId( checkbox ) {
		return checkbox.getAttribute( 'data-dtb-bulk-id' ) || checkbox.getAttribute( 'data-dtb-ticket-id' ) || '';
	}

	function getRecordType( checkbox ) {
		return checkbox.getAttribute( 'data-dtb-bulk-record' ) || ( checkbox.hasAttribute( 'data-dtb-ticket-id' ) ? 'support' : '' );
	}

	function findToolbar( checkbox ) {
		var type = getRecordType( checkbox );
		return document.querySelector( '[data-dtb-bulk-toolbar][data-dtb-bulk-record="' + type + '"]' )
			|| document.querySelector( '[data-dtb-bulk-toolbar]' );
	}

	function getRowsForToolbar( toolbar ) {
		var type = toolbar ? toolbar.getAttribute( 'data-dtb-bulk-record' ) : '';
		var selector = type ? '[data-dtb-bulk-record="' + type + '"]' : '[data-dtb-bulk-id]';
		var checkboxes = getRecordCheckboxes( document ).filter( function ( checkbox ) {
			return ! type || getRecordType( checkbox ) === type;
		} );
		if ( ! checkboxes.length && 'support' === type ) {
			checkboxes = getRecordCheckboxes( document ).filter( function ( checkbox ) {
				return checkbox.matches( '.dtb-support-row__checkbox[data-dtb-ticket-id]' );
			} );
		}
		return checkboxes.filter( function ( checkbox ) {
			return selector ? !! checkbox : true;
		} );
	}

	function getSelected( toolbar ) {
		return getRowsForToolbar( toolbar ).filter( function ( checkbox ) {
			return checkbox.checked;
		} );
	}

	function updateToolbar( toolbar ) {
		if ( ! toolbar ) {
			return;
		}
		var selected = getSelected( toolbar );
		var count = toolbar.querySelector( '[data-dtb-bulk-count]' );
		var selectAll = document.querySelector( '[data-dtb-bulk-select-all][data-dtb-bulk-record="' + ( toolbar.getAttribute( 'data-dtb-bulk-record' ) || '' ) + '"]' );

		if ( count ) {
			count.textContent = String( selected.length );
		}
		toolbar.hidden = selected.length === 0;

		if ( selectAll ) {
			var all = getRowsForToolbar( toolbar );
			selectAll.checked = all.length > 0 && selected.length === all.length;
			selectAll.indeterminate = selected.length > 0 && selected.length < all.length;
		}
	}

	function updateRowState( checkbox ) {
		var row = checkbox.closest( 'tr, .dtb-support-row' );
		if ( row ) {
			row.classList.toggle( 'is-selected', checkbox.checked );
		}
	}

	function showToast( message, type ) {
		if ( DtbAdmin && typeof DtbAdmin.showToast === 'function' ) {
			DtbAdmin.showToast( message, type || 'info' );
			return;
		}
		if ( DtbAdmin && typeof DtbAdmin.toast === 'function' ) {
			DtbAdmin.toast( message, 'error' === type ? 'danger' : ( type || 'info' ) );
			return;
		}
		window.alert( message );
	}

	function apiFetch( endpoint, body ) {
		if ( DtbAdmin && typeof DtbAdmin.apiFetch === 'function' ) {
			return DtbAdmin.apiFetch( endpoint, {
				method: 'POST',
				body: JSON.stringify( body || {} ),
			} );
		}
		var cfg = window.dtbAdminConfig || {};
		var base = ( cfg.restUrl || '/wp-json' ).replace( /\/$/, '' );
		return fetch( base + '/' + endpoint.replace( /^\//, '' ), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce || '',
			},
			body: JSON.stringify( body || {} ),
		} ).then( function ( response ) {
			return response.json().then( function ( payload ) {
				if ( ! response.ok ) {
					throw payload;
				}
				return payload;
			} );
		} );
	}

	function refreshAfterBulk( toolbar, selected ) {
		selected.forEach( function ( checkbox ) {
			var row = checkbox.closest( 'tr, .dtb-support-row' );
			if ( row ) {
				row.remove();
			}
		} );

		var refreshTarget = toolbar.getAttribute( 'data-dtb-bulk-refresh' );
		if ( refreshTarget && DtbAdmin && typeof DtbAdmin.refreshLiveRegion === 'function' ) {
			DtbAdmin.refreshLiveRegion( refreshTarget );
		} else if ( refreshTarget ) {
			var refreshButton = document.querySelector( '[data-dtb-live-refresh="' + refreshTarget + '"]' );
			if ( refreshButton ) {
				refreshButton.click();
			}
		}
		updateToolbar( toolbar );
	}

	function bindSelectAll() {
		document.addEventListener( 'change', function ( event ) {
			var selectAll = event.target.closest ? event.target.closest( '[data-dtb-bulk-select-all]' ) : null;
			if ( ! selectAll ) {
				return;
			}
			var type = selectAll.getAttribute( 'data-dtb-bulk-record' ) || '';
			var toolbar = document.querySelector( '[data-dtb-bulk-toolbar][data-dtb-bulk-record="' + type + '"]' );
			getRowsForToolbar( toolbar ).forEach( function ( checkbox ) {
				checkbox.checked = selectAll.checked;
				updateRowState( checkbox );
			} );
			updateToolbar( toolbar );
		} );
	}

	function bindRowCheckboxes() {
		document.addEventListener( 'change', function ( event ) {
			var checkbox = event.target.closest ? event.target.closest( '[data-dtb-bulk-id], .dtb-support-row__checkbox[data-dtb-ticket-id]' ) : null;
			if ( ! checkbox || checkbox.hasAttribute( 'data-dtb-bulk-select-all' ) || 'dtb-support-select-all' === checkbox.id ) {
				return;
			}
			updateRowState( checkbox );
			updateToolbar( findToolbar( checkbox ) );
		} );
	}

	function bindDelete() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest ? event.target.closest( '[data-dtb-bulk-delete]' ) : null;
			if ( ! button ) {
				return;
			}
			var toolbar = button.closest( '[data-dtb-bulk-toolbar]' );
			if ( ! toolbar || button.disabled ) {
				return;
			}

			var selected = getSelected( toolbar );
			var ids = selected.map( getRecordId ).filter( Boolean );
			if ( ! ids.length ) {
				updateToolbar( toolbar );
				return;
			}

			var label = toolbar.getAttribute( 'data-dtb-bulk-label' ) || 'records';
			var prompt = 'Move ' + ids.length + ' selected ' + label + ' to trash?';
			if ( ! window.confirm( prompt ) ) {
				return;
			}

			button.disabled = true;
			apiFetch( toolbar.getAttribute( 'data-dtb-bulk-endpoint' ) || '', {
				action: 'delete',
				ids: ids,
			} ).then( function ( payload ) {
				var failed = Array.isArray( payload.errors ) ? payload.errors.length : 0;
				refreshAfterBulk( toolbar, selected );
				showToast( failed ? 'Some selected records could not be removed.' : 'Selected records moved to trash.', failed ? 'warning' : 'success' );
			} ).catch( function ( error ) {
				var message = error && error.message ? error.message : 'Selected records could not be removed.';
				showToast( message, 'error' );
			} ).finally( function () {
				button.disabled = false;
			} );
		} );
	}

	function enhanceSupportSelectAll() {
		var legacy = document.getElementById( 'dtb-support-select-all' );
		if ( ! legacy || legacy.hasAttribute( 'data-dtb-bulk-select-all' ) ) {
			return;
		}
		legacy.setAttribute( 'data-dtb-bulk-select-all', '1' );
		legacy.setAttribute( 'data-dtb-bulk-record', 'support' );
	}

	function init() {
		enhanceSupportSelectAll();
		bindSelectAll();
		bindRowCheckboxes();
		bindDelete();
		document.querySelectorAll( '[data-dtb-bulk-toolbar]' ).forEach( updateToolbar );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
