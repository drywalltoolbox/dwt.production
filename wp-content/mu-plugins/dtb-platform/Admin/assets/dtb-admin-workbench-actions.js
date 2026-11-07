/**
 * DTB Admin Workbench Actions
 *
 * Shared progressive enhancement for the Orders, Repairs, Returns, and Support
 * workbench modals. It keeps each module's existing REST/action handlers intact
 * while upgrading the admin UX:
 *
 * - removes the dedicated Actions tab from the visible tab strip;
 * - moves the existing action controls into a single accessible Workflow dropdown;
 * - injects a synchronized modal case hero with the most useful record details.
 */
( function ( window, document ) {
	'use strict';

	var MENU_SELECTOR = '[data-dtb-workflow-menu]';
	var OPEN_CLASS = 'dtb-wb-action-menu--open';
	var scheduled = false;

	var SPECS = [
		{
			module: 'orders',
			label: 'Order',
			modalSelector: '#dtb-orders-modal',
			headerSelector: '.dtb-modal__header',
			titleSelector: '.dtb-modal__title',
			actionTabSelector: '[data-dtb-tab="actions"]',
			allTabSelector: '.dtb-modal-tab[data-dtb-tab]',
			actionPanelSelector: '.dtb-modal-tab-panel[data-dtb-tab="actions"]',
			preferredTabSelector: '.dtb-modal-tab[data-dtb-tab="overview"]',
			workflowDescription: 'Order workflow actions',
			detailLabels: [ 'Status', 'Total', 'Payment', 'Fulfillment', 'Created' ],
		},
		{
			module: 'repairs',
			label: 'Repair',
			skipGeneratedHero: true,
			modalSelector: '#dtb-repair-modal',
			headerSelector: '.dtb-modal__header',
			titleSelector: '.dtb-modal__title',
			actionTabSelector: '[data-tab="actions"]',
			allTabSelector: '.dtb-modal-tab[data-tab]',
			actionPanelSelector: '[data-panel="actions"]',
			preferredTabSelector: '.dtb-modal-tab[data-tab="overview"]',
			workflowDescription: 'Repair workflow actions',
			detailLabels: [ 'Status', 'Customer', 'Priority', 'Service tier', 'SLA' ],
		},
		{
			module: 'returns',
			label: 'Return',
			modalSelector: '#dtb-returns-modal',
			headerSelector: '.dtb-modal__header',
			titleSelector: '.dtb-modal__title',
			actionTabSelector: '[data-dtb-returns-tab="actions"]',
			allTabSelector: '.dtb-returns-modal-tab[data-dtb-returns-tab]',
			actionPanelSelector: '[data-dtb-returns-panel="actions"]',
			preferredTabSelector: '.dtb-returns-modal-tab[data-dtb-returns-tab="overview"]',
			workflowDescription: 'Return workflow actions',
			detailLabels: [ 'Status', 'Resolution', 'Order', 'Requested', 'Updated' ],
		},
		{
			module: 'support',
			label: 'Ticket',
			skipGeneratedHero: true,
			modalSelector: '#dtb-support-ticket-modal',
			headerSelector: '.dtb-modal__header',
			titleSelector: '.dtb-modal__title',
			actionTabSelector: '[data-dtb-modal-tab="actions"]',
			allTabSelector: '.dtb-support-modal-tab[data-dtb-modal-tab]',
			actionPanelSelector: '[data-dtb-modal-panel="actions"]',
			preferredTabSelector: '.dtb-support-modal-tab[data-dtb-modal-tab="thread"]',
			workflowDescription: 'Ticket workflow actions',
			detailLabels: [ 'Status', 'Priority', 'Action due', 'Delivery' ],
		},
	];

	function text( value ) {
		return String( value == null ? '' : value ).trim().replace( /\s+/g, ' ' );
	}

	function escapeHtml( value ) {
		var d = document.createElement( 'div' );
		d.appendChild( document.createTextNode( String( value == null ? '' : value ) ) );
		return d.innerHTML;
	}

	function safeId( value ) {
		return String( value || '' ).replace( /[^a-z0-9_-]/gi, '-' ).toLowerCase();
	}

	function closeMenus( except ) {
		document.querySelectorAll( MENU_SELECTOR + '.' + OPEN_CLASS ).forEach( function ( menu ) {
			if ( except && menu === except ) {
				return;
			}
			menu.classList.remove( OPEN_CLASS );
			var trigger = menu.querySelector( '[data-dtb-workflow-menu-trigger]' );
			if ( trigger ) {
				trigger.setAttribute( 'aria-expanded', 'false' );
			}
		} );
	}

	function getHeader( modal, spec ) {
		return modal.querySelector( spec.headerSelector ) || modal.querySelector( '.dtb-modal__header' ) || modal;
	}

	function getTitle( modal, spec ) {
		var titleEl = modal.querySelector( spec.titleSelector ) || modal.querySelector( '.dtb-modal__title' );
		return text( titleEl ? titleEl.textContent : spec.label );
	}

	function getFirstText( modal, selector ) {
		var el = modal.querySelector( selector );
		return el ? text( el.textContent ) : '';
	}

	function getStatus( modal ) {
		var selectors = [
			'.dtb-modal__meta .dtb-wb-badge',
			'.dtb-support-ticket-pill--status',
			'.dtb-returns-badge',
			'.dtb-wb-kv .dtb-wb-badge',
			'.dtb-wb-badge',
		];
		for ( var i = 0; i < selectors.length; i++ ) {
			var value = getFirstText( modal, selectors[ i ] );
			if ( value ) {
				return value;
			}
		}
		return '';
	}

	function findKvValue( modal, label ) {
		var rows = modal.querySelectorAll( '.dtb-wb-kv, .dtb-returns-kv, .dtb-support-ticket-kv' );
		label = text( label ).toLowerCase();
		for ( var i = 0; i < rows.length; i++ ) {
			var row = rows[ i ];
			if ( row.closest( MENU_SELECTOR ) || row.closest( '[data-dtb-modal-hero]' ) ) {
				continue;
			}
			var labelEl = row.querySelector( '.dtb-wb-kv__label, .dtb-returns-kv__label, span:first-child' );
			var rowLabel = text( labelEl ? labelEl.textContent : '' ).replace( /:$/, '' ).toLowerCase();
			if ( rowLabel !== label ) {
				continue;
			}
			var valueEl = row.querySelector( '.dtb-wb-kv__value, .dtb-returns-kv__value, strong, span:last-child' );
			var value = text( valueEl ? valueEl.textContent : row.textContent.replace( labelEl ? labelEl.textContent : '', '' ) );
			if ( value ) {
				return value;
			}
		}
		return '';
	}

	function buildDetails( modal, spec ) {
		var details = [];
		( spec.detailLabels || [] ).forEach( function ( label ) {
			var value = findKvValue( modal, label );
			if ( value ) {
				details.push( { label: label, value: value } );
			}
		} );

		if ( spec.module === 'support' ) {
			var subject = getFirstText( modal, '.dtb-support-email-subject' );
			var customer = getFirstText( modal, '.dtb-support-email-from__copy strong' );
			var due = getFirstText( modal, '.dtb-support-email-due' );
			var order = getFirstText( modal, '.dtb-support-email-meta span:first-child' );
			if ( customer ) { details.unshift( { label: 'Customer', value: customer } ); }
			if ( subject ) { details.unshift( { label: 'Subject', value: subject } ); }
			if ( due && ! details.some( function ( item ) { return item.label === 'Action due'; } ) ) {
				details.push( { label: 'Due', value: due.replace( /^Due\s+/i, '' ) } );
			}
			if ( order && /^Order\s+#?/i.test( order ) ) {
				details.push( { label: 'Order', value: order.replace( /^Order\s+/i, '' ) } );
			}
		}

		return details.slice( 0, 6 );
	}

	function issueCount( modal ) {
		var selectors = [
			'.dtb-wb-record-issues .dtb-wb-note',
			'.dtb-wb-record-issues .dtb-wb-blocker-chip',
			'.dtb-returns-issue-chip',
			'.dtb-wb-integrity-warning',
		];
		var count = 0;
		selectors.forEach( function ( selector ) {
			modal.querySelectorAll( selector ).forEach( function ( el ) {
				if ( ! el.closest( '[data-dtb-modal-hero]' ) && ! el.closest( MENU_SELECTOR ) ) {
					count++;
				}
			} );
		} );
		return count;
	}

	function renderHeroHtml( spec, data ) {
		var detailsHtml = '';
		data.details.forEach( function ( item ) {
			detailsHtml += '<div class="dtb-wb-modal-hero__fact">' +
				'<span>' + escapeHtml( item.label ) + '</span>' +
				'<strong>' + escapeHtml( item.value ) + '</strong>' +
				'</div>';
		} );

		var chips = '';
		if ( data.status ) {
			chips += '<span class="dtb-wb-modal-hero__chip dtb-wb-modal-hero__chip--status">' + escapeHtml( data.status ) + '</span>';
		}
		if ( data.issueCount > 0 ) {
			chips += '<span class="dtb-wb-modal-hero__chip dtb-wb-modal-hero__chip--warning">' + escapeHtml( data.issueCount ) + ' issue' + ( data.issueCount === 1 ? '' : 's' ) + '</span>';
		}

		return '<div class="dtb-wb-modal-hero__content">' +
			'<div class="dtb-wb-modal-hero__summary">' +
				'<span class="dtb-wb-modal-hero__eyebrow">' + escapeHtml( spec.label ) + ' workbench</span>' +
				'<h3>' + escapeHtml( data.title || spec.label ) + '</h3>' +
				( chips ? '<div class="dtb-wb-modal-hero__chips">' + chips + '</div>' : '' ) +
			'</div>' +
			'<div class="dtb-wb-modal-hero__facts">' + detailsHtml + '</div>' +
		'</div>';
	}

	function enhanceHero( modal, spec ) {
		var header = getHeader( modal, spec );
		if ( ! header || ! header.parentNode ) {
			return;
		}

		var title = getTitle( modal, spec );
		var status = getStatus( modal );
		var details = buildDetails( modal, spec );
		var issues = issueCount( modal );
		if ( ! title && ! details.length && ! status ) {
			return;
		}

		var signature = JSON.stringify( {
			module: spec.module,
			title: title,
			status: status,
			details: details,
			issues: issues,
		} );

		var hero = modal.querySelector( '[data-dtb-modal-hero="' + spec.module + '"]' );
		if ( hero && hero.getAttribute( 'data-dtb-hero-signature' ) === signature ) {
			return;
		}
		if ( ! hero ) {
			hero = document.createElement( 'section' );
			hero.className = 'dtb-wb-modal-hero dtb-wb-modal-hero--' + safeId( spec.module );
			hero.setAttribute( 'data-dtb-modal-hero', spec.module );
			hero.setAttribute( 'aria-label', spec.label + ' case summary' );
			header.parentNode.insertBefore( hero, header.nextSibling );
		}
		hero.setAttribute( 'data-dtb-hero-signature', signature );
		hero.innerHTML = renderHeroHtml( spec, {
			title: title,
			status: status,
			details: details,
			issueCount: issues,
		} );
	}

	function ensureMenuHost( modal, spec ) {
		var host = modal.querySelector( '[data-dtb-workflow-menu-host="' + spec.module + '"]' );
		if ( host ) {
			return host;
		}

		var header = getHeader( modal, spec );
		if ( ! header ) {
			return null;
		}

		var menuId = 'dtb-workflow-menu-' + safeId( spec.module );
		host = document.createElement( 'div' );
		host.className = 'dtb-wb-actions-menu-host';
		host.setAttribute( 'data-dtb-workflow-menu-host', spec.module );
		host.innerHTML = '<div class="dtb-wb-action-menu" data-dtb-workflow-menu="' + escapeHtml( spec.module ) + '">' +
			'<button type="button" class="dtb-wb-action-menu__trigger" data-dtb-workflow-menu-trigger aria-haspopup="menu" aria-expanded="false" aria-controls="' + menuId + '">' +
				'<span class="dtb-wb-action-menu__eyebrow">Workflow</span>' +
				'<span class="dtb-wb-action-menu__label">Actions</span>' +
				'<span class="dtb-wb-action-menu__chevron" aria-hidden="true">▾</span>' +
			'</button>' +
			'<div id="' + menuId + '" class="dtb-wb-action-menu__panel" role="menu" aria-label="' + escapeHtml( spec.workflowDescription ) + '">' +
				'<div class="dtb-wb-action-menu__header"><strong>' + escapeHtml( spec.workflowDescription ) + '</strong><span data-dtb-workflow-current-status></span></div>' +
				'<div class="dtb-wb-action-menu__body" data-dtb-workflow-menu-body></div>' +
			'</div>' +
		'</div>';

		var closeBtn = header.querySelector( '.dtb-modal__close' );
		if ( closeBtn && closeBtn.parentNode === header ) {
			header.insertBefore( host, closeBtn );
		} else {
			header.appendChild( host );
		}
		return host;
	}

	function ensureNonActionTabActive( modal, spec ) {
		var actionTab = modal.querySelector( spec.actionTabSelector );
		if ( ! actionTab ) {
			return;
		}
		var isActive = actionTab.classList.contains( 'is-active' ) ||
			actionTab.classList.contains( 'dtb-modal-tab--active' ) ||
			actionTab.getAttribute( 'aria-selected' ) === 'true';
		if ( ! isActive ) {
			return;
		}
		var preferred = modal.querySelector( spec.preferredTabSelector );
		var fallback = preferred || Array.prototype.slice.call( modal.querySelectorAll( spec.allTabSelector ) ).find( function ( tab ) {
			return tab !== actionTab && ! tab.hidden;
		} );
		if ( fallback && typeof fallback.click === 'function' ) {
			fallback.click();
		}
	}

	function removeActionTab( modal, spec ) {
		var tab = modal.querySelector( spec.actionTabSelector );
		if ( ! tab ) {
			return;
		}
		tab.hidden = true;
		tab.setAttribute( 'aria-hidden', 'true' );
		tab.setAttribute( 'tabindex', '-1' );
		tab.setAttribute( 'data-dtb-workflow-tab-consumed', '1' );
		if ( tab.parentNode ) {
			tab.parentNode.removeChild( tab );
		}
	}

	function movePanelActionsToMenu( modal, spec ) {
		var panel = modal.querySelector( spec.actionPanelSelector );
		if ( ! panel ) {
			return;
		}
		var host = ensureMenuHost( modal, spec );
		if ( ! host ) {
			return;
		}
		var body = host.querySelector( '[data-dtb-workflow-menu-body]' );
		var status = host.querySelector( '[data-dtb-workflow-current-status]' );
		var label = host.querySelector( '.dtb-wb-action-menu__label' );
		if ( ! body ) {
			return;
		}

		ensureNonActionTabActive( modal, spec );

		if ( panel.childNodes.length ) {
			body.innerHTML = '';
			while ( panel.firstChild ) {
				body.appendChild( panel.firstChild );
			}
		}

		if ( ! body.childNodes.length ) {
			body.innerHTML = '<div class="dtb-wb-action-menu__empty">No workflow actions are currently available.</div>';
		}

		var currentStatus = getStatus( modal );
		if ( status ) {
			status.textContent = currentStatus ? 'Current: ' + currentStatus : '';
		}
		if ( label ) {
			label.textContent = currentStatus ? currentStatus : 'Actions';
		}

		panel.hidden = true;
		panel.setAttribute( 'aria-hidden', 'true' );
		panel.classList.add( 'dtb-wb-actions-panel-consumed' );
		removeActionTab( modal, spec );
	}

	function enhanceModal( modal, spec ) {
		if ( ! modal || ! modal.ownerDocument ) {
			return;
		}
		if ( ! spec.skipGeneratedHero ) {
			enhanceHero( modal, spec );
		} else {
			var generatedHero = modal.querySelector( '[data-dtb-modal-hero="' + spec.module + '"]' );
			if ( generatedHero && generatedHero.parentNode ) {
				generatedHero.parentNode.removeChild( generatedHero );
			}
		}
		movePanelActionsToMenu( modal, spec );
	}

	function enhanceAll() {
		scheduled = false;
		SPECS.forEach( function ( spec ) {
			document.querySelectorAll( spec.modalSelector ).forEach( function ( modal ) {
				enhanceModal( modal, spec );
			} );
		} );
	}

	function scheduleEnhance() {
		if ( scheduled ) {
			return;
		}
		scheduled = true;
		window.requestAnimationFrame( enhanceAll );
	}

	document.addEventListener( 'click', function ( event ) {
		var trigger = event.target && event.target.closest ? event.target.closest( '[data-dtb-workflow-menu-trigger]' ) : null;
		if ( trigger ) {
			event.preventDefault();
			var menu = trigger.closest( MENU_SELECTOR );
			if ( ! menu ) {
				return;
			}
			var shouldOpen = ! menu.classList.contains( OPEN_CLASS );
			closeMenus( menu );
			menu.classList.toggle( OPEN_CLASS, shouldOpen );
			trigger.setAttribute( 'aria-expanded', shouldOpen ? 'true' : 'false' );
			return;
		}

		if ( ! ( event.target && event.target.closest && event.target.closest( MENU_SELECTOR ) ) ) {
			closeMenus();
			return;
		}

		var actionable = event.target.closest( 'button:not([data-dtb-workflow-menu-trigger]), a' );
		if ( actionable && ! actionable.closest( 'form' ) ) {
			setTimeout( closeMenus, 120 );
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key === 'Escape' ) {
			closeMenus();
		}
	} );

	function boot() {
		scheduleEnhance();
		var observer = new MutationObserver( scheduleEnhance );
		observer.observe( document.body, { childList: true, subtree: true, characterData: true } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}( window, document ) );
