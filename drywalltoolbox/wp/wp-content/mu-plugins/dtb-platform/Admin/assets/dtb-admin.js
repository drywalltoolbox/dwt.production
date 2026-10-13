/**
 * DTB Admin UI System — dtb-admin.js
 *
 * Shared JavaScript behaviors for all Drywall Toolbox wp-admin pages.
 * No module-specific business logic belongs here.
 *
 * Responsibilities:
 *  - Namespace: window.DtbAdmin
 *  - Dropdown behavior
 *  - Dismissible alerts
 *  - Toast notification helper
 *  - Loading button states
 *  - Confirmation helper
 *  - Drawer open/close
 *  - Modal open/close
 *  - Tab panels
 *  - Refresh helpers
 *  - Table row click → drawer
 *  - Bulk-select toggle
 *
 * @package drywall-toolbox
 */

/* global dtbAdminConfig */
( function () {
	'use strict';

	// =========================================================================
	// NAMESPACE
	// =========================================================================

	/** @type {DtbAdminConfig} dtbAdminConfig — localized via AdminAssets.php */
	const cfg = window.dtbAdminConfig || {};
	const DTB_ADMIN_AUTO_REFRESH_INTERVAL_MS = 180000;

	const DtbAdmin = {
		version: '2.0.0',
	};

	window.DtbAdmin = DtbAdmin;

	// =========================================================================
	// READY
	// =========================================================================

	document.addEventListener( 'DOMContentLoaded', function () {
		DtbAdmin.initAlerts();
		DtbAdmin.initDropdowns();
		DtbAdmin.initDrawers();
		DtbAdmin.initModals();
		DtbAdmin.initTabs();
		DtbAdmin.initLoadingButtons();
		DtbAdmin.initBulkSelect();
		DtbAdmin.initTableRowDrawer();
		DtbAdmin.initToastContainer();
		DtbAdmin.initLiveRegions();
		DtbAdmin.initSidebarNav();
		DtbAdmin.initCharts( document );
	} );

	// =========================================================================
	// ALERTS — dismissible
	// =========================================================================

	DtbAdmin.initAlerts = function () {
		document.querySelectorAll( '.dtb-alert .dtb-alert__close' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				const alert = btn.closest( '.dtb-alert' );
				if ( ! alert ) return;
				alert.style.transition = 'opacity 180ms ease, transform 180ms ease';
				alert.style.opacity    = '0';
				alert.style.transform  = 'translateY(-4px)';
				setTimeout( function () { alert.remove(); }, 200 );
			} );
		} );
	};

	// =========================================================================
	// TOASTS
	// =========================================================================

	DtbAdmin.initToastContainer = function () {
		if ( ! document.querySelector( '.dtb-toast-container' ) ) {
			const container    = document.createElement( 'div' );
			container.className = 'dtb-toast-container';
			document.body.appendChild( container );
		}
	};

	/**
	 * Show a toast notification.
	 *
	 * @param {string} message  - Main text.
	 * @param {string} [type]   - 'success' | 'danger' | 'warning' | 'info'. Default 'info'.
	 * @param {string} [title]  - Optional title line above message.
	 * @param {number} [duration] - Auto-dismiss ms. 0 = permanent. Default 4000.
	 */
	DtbAdmin.toast = function ( message, type, title, duration ) {
		type     = type     || 'info';
		duration = ( duration === undefined ) ? 4000 : duration;

		const iconMap = {
			success : 'dashicons-yes-alt',
			danger  : 'dashicons-warning',
			warning : 'dashicons-flag',
			info    : 'dashicons-info',
		};

		const iconClass = iconMap[ type ] || iconMap.info;

		const toast = document.createElement( 'div' );
		toast.className = 'dtb-toast dtb-toast--' + type;
		toast.setAttribute( 'role', 'status' );
		toast.setAttribute( 'aria-live', 'polite' );

		toast.innerHTML =
			'<span class="dtb-toast__icon dashicons ' + iconClass + '" aria-hidden="true"></span>' +
			'<div class="dtb-toast__body">' +
				( title ? '<div class="dtb-toast__title">' + escHtml( title ) + '</div>' : '' ) +
				'<p class="dtb-toast__text">' + escHtml( message ) + '</p>' +
			'</div>' +
			'<button class="dtb-toast__close" aria-label="Dismiss" type="button">&#10005;</button>';

		const container = document.querySelector( '.dtb-toast-container' );
		if ( container ) container.appendChild( toast );

		toast.querySelector( '.dtb-toast__close' ).addEventListener( 'click', function () {
			dismissToast( toast );
		} );

		if ( duration > 0 ) {
			setTimeout( function () { dismissToast( toast ); }, duration );
		}

		return toast;
	};

	function dismissToast( toast ) {
		toast.classList.add( 'dtb-toast--exit' );
		setTimeout( function () { toast.remove(); }, 200 );
	}

	// =========================================================================
	// DROPDOWNS
	// =========================================================================

	DtbAdmin.initDropdowns = function () {
		document.querySelectorAll( '[data-dtb-dropdown]' ).forEach( function ( trigger ) {
			trigger.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				const targetId = trigger.dataset.dtbDropdown;
				const menu     = document.getElementById( targetId );
				if ( ! menu ) return;

				const isOpen = menu.hasAttribute( 'data-open' );

				// Close all open dropdowns.
				document.querySelectorAll( '[data-dtb-dropdown-menu][data-open]' ).forEach( function ( m ) {
					m.removeAttribute( 'data-open' );
					m.style.display = 'none';
				} );

				if ( ! isOpen ) {
					menu.setAttribute( 'data-open', '' );
					menu.style.display = 'block';
				}
			} );
		} );

		// Close on outside click.
		document.addEventListener( 'click', function () {
			document.querySelectorAll( '[data-dtb-dropdown-menu][data-open]' ).forEach( function ( m ) {
				m.removeAttribute( 'data-open' );
				m.style.display = 'none';
			} );
		} );

		// Close on Escape.
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				document.querySelectorAll( '[data-dtb-dropdown-menu][data-open]' ).forEach( function ( m ) {
					m.removeAttribute( 'data-open' );
					m.style.display = 'none';
				} );
			}
		} );
	};

	// =========================================================================
	// DRAWERS
	// =========================================================================

	DtbAdmin.initDrawers = function () {
		// Open triggers.
		document.querySelectorAll( '[data-dtb-open-drawer]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				const drawerId = btn.dataset.dtbOpenDrawer;
				DtbAdmin.openDrawer( drawerId );
			} );
		} );

		// Close buttons inside drawers.
		document.addEventListener( 'click', function ( e ) {
			const closeBtn = e.target.closest( '.dtb-drawer__close, [data-dtb-close-drawer]' );
			if ( closeBtn ) {
				const drawer = closeBtn.closest( '.dtb-drawer' );
				if ( drawer ) DtbAdmin.closeDrawer( drawer.id );
			}

			// Overlay click closes drawer.
			if ( e.target.classList.contains( 'dtb-drawer-overlay' ) ) {
				document.querySelectorAll( '.dtb-drawer.dtb-drawer--open' ).forEach( function ( d ) {
					DtbAdmin.closeDrawer( d.id );
				} );
			}
		} );

		// Escape key.
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				document.querySelectorAll( '.dtb-drawer.dtb-drawer--open' ).forEach( function ( d ) {
					DtbAdmin.closeDrawer( d.id );
				} );
			}
		} );
	};

	/**
	 * Open a drawer by ID.
	 * @param {string} drawerId
	 */
	DtbAdmin.openDrawer = function ( drawerId ) {
		const drawer  = document.getElementById( drawerId );
		const overlay = document.querySelector( '.dtb-drawer-overlay' );
		if ( ! drawer ) return;

		drawer.classList.add( 'dtb-drawer--open' );
		drawer.setAttribute( 'aria-hidden', 'false' );

		if ( overlay ) {
			overlay.classList.add( 'dtb-drawer-overlay--open' );
			overlay.setAttribute( 'aria-hidden', 'false' );
		}

		// Focus first focusable element.
		const focusable = drawer.querySelector( 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])' );
		if ( focusable ) setTimeout( function () { focusable.focus(); }, 50 );
	};

	/**
	 * Close a drawer by ID.
	 * @param {string} drawerId
	 */
	DtbAdmin.closeDrawer = function ( drawerId ) {
		const drawer  = document.getElementById( drawerId );
		const overlay = document.querySelector( '.dtb-drawer-overlay' );
		if ( ! drawer ) return;

		drawer.classList.remove( 'dtb-drawer--open' );
		drawer.setAttribute( 'aria-hidden', 'true' );

		if ( overlay && ! document.querySelector( '.dtb-drawer.dtb-drawer--open' ) ) {
			overlay.classList.remove( 'dtb-drawer-overlay--open' );
			overlay.setAttribute( 'aria-hidden', 'true' );
		}
	};

	// =========================================================================
	// MODALS
	// =========================================================================

	DtbAdmin.initModals = function () {
		document.querySelectorAll( '[data-dtb-open-modal]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				DtbAdmin.openModal( btn.dataset.dtbOpenModal );
			} );
		} );

		document.addEventListener( 'click', function ( e ) {
			const closeBtn = e.target.closest( '.dtb-modal__close, [data-dtb-close-modal]' );
			if ( closeBtn ) {
				const modal = closeBtn.closest( '.dtb-modal-overlay' );
				if ( modal ) DtbAdmin.closeModal( modal.id );
			}

			if ( e.target.classList.contains( 'dtb-modal-overlay' ) ) {
				DtbAdmin.closeModal( e.target.id );
			}
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				document.querySelectorAll( '.dtb-modal-overlay.dtb-modal-overlay--open' ).forEach( function ( o ) {
					DtbAdmin.closeModal( o.id );
				} );
			}
		} );
	};

	DtbAdmin.openModal = function ( overlayId ) {
		const overlay = document.getElementById( overlayId );
		if ( ! overlay ) return;
		overlay.classList.add( 'dtb-modal-overlay--open' );
		overlay.setAttribute( 'aria-hidden', 'false' );
		document.body.style.overflow = 'hidden';
		const focusable = overlay.querySelector( 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])' );
		if ( focusable ) setTimeout( function () { focusable.focus(); }, 50 );
	};

	DtbAdmin.closeModal = function ( overlayId ) {
		const overlay = document.getElementById( overlayId );
		if ( ! overlay ) return;
		overlay.classList.remove( 'dtb-modal-overlay--open' );
		overlay.setAttribute( 'aria-hidden', 'true' );
		document.body.style.overflow = '';
	};

	// =========================================================================
	// TABS
	// =========================================================================

	DtbAdmin.initTabs = function () {
		document.querySelectorAll( '.dtb-section-nav' ).forEach( function ( nav ) {
			nav.querySelectorAll( '.dtb-section-nav__tab[data-dtb-tab]' ).forEach( function ( tab ) {
				tab.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					const panelId = tab.dataset.dtbTab;
					const navEl   = tab.closest( '.dtb-section-nav' );
					const wrapper = navEl ? navEl.closest( '.dtb-admin' ) || document : document;

					// Deactivate all tabs in this nav.
					navEl.querySelectorAll( '.dtb-section-nav__tab' ).forEach( function ( t ) {
						t.classList.remove( 'dtb-section-nav__tab--active' );
						t.setAttribute( 'aria-selected', 'false' );
					} );

					tab.classList.add( 'dtb-section-nav__tab--active' );
					tab.setAttribute( 'aria-selected', 'true' );

					// Hide all panels associated with this nav.
					const navGroup = navEl.dataset.dtbTabGroup;
					if ( navGroup ) {
						wrapper.querySelectorAll( '[data-dtb-tab-panel][data-dtb-tab-group="' + navGroup + '"]' ).forEach( function ( p ) {
							p.hidden = true;
						} );
					} else {
						wrapper.querySelectorAll( '[data-dtb-tab-panel]' ).forEach( function ( p ) {
							p.hidden = true;
						} );
					}

					const panel = document.getElementById( panelId );
					if ( panel ) panel.hidden = false;
				} );
			} );
		} );
	};

	// =========================================================================
	// LOADING BUTTONS
	// =========================================================================

	DtbAdmin.initLoadingButtons = function () {
		document.querySelectorAll( '[data-dtb-loading]' ).forEach( function ( form ) {
			form.addEventListener( 'submit', function () {
				const btn = form.querySelector( '[type="submit"]' );
				if ( btn ) DtbAdmin.setButtonLoading( btn, true );
			} );
		} );
	};

	DtbAdmin.setButtonLoading = function ( btn, loading ) {
		if ( loading ) {
			btn.classList.add( 'dtb-btn--loading' );
			btn.disabled = true;
			if ( btn.dataset.loadingText ) {
				btn.dataset.originalText = btn.innerHTML;
				btn.innerHTML = btn.dataset.loadingText;
			}
		} else {
			btn.classList.remove( 'dtb-btn--loading' );
			btn.disabled = false;
			if ( btn.dataset.originalText ) {
				btn.innerHTML = btn.dataset.originalText;
			}
		}
	};

	// =========================================================================
	// CONFIRM HELPER
	// =========================================================================

	/**
	 * Attach confirmation to elements with data-dtb-confirm attribute.
	 */
	document.addEventListener( 'click', function ( e ) {
		const btn = e.target.closest( '[data-dtb-confirm]' );
		if ( ! btn ) return;

		const message = btn.dataset.dtbConfirm || 'Are you sure?';
		if ( ! window.confirm( message ) ) {
			e.preventDefault();
			e.stopImmediatePropagation();
		}
	}, true );

	// =========================================================================
	// BULK SELECT
	// =========================================================================

	DtbAdmin.initBulkSelect = function () {
		document.querySelectorAll( '.dtb-table' ).forEach( function ( table ) {
			const masterCheck = table.querySelector( '.dtb-bulk-select-all' );
			const rowChecks   = table.querySelectorAll( '.dtb-bulk-select-row' );
			if ( ! masterCheck ) return;

			masterCheck.addEventListener( 'change', function () {
				rowChecks.forEach( function ( c ) {
					c.checked = masterCheck.checked;
				} );
				updateBulkActions( table );
			} );

			rowChecks.forEach( function ( c ) {
				c.addEventListener( 'change', function () {
					const total   = rowChecks.length;
					const checked = table.querySelectorAll( '.dtb-bulk-select-row:checked' ).length;
					masterCheck.checked       = checked === total;
					masterCheck.indeterminate = checked > 0 && checked < total;
					updateBulkActions( table );
				} );
			} );
		} );
	};

	function updateBulkActions( table ) {
		const checked     = table.querySelectorAll( '.dtb-bulk-select-row:checked' ).length;
		const bulkActions = document.querySelector( '[data-dtb-bulk-count]' );
		if ( bulkActions ) bulkActions.textContent = checked;

		const bulkBar = document.querySelector( '.dtb-bulk-action-bar' );
		if ( bulkBar ) {
			bulkBar.hidden = checked === 0;
		}
	}

	// =========================================================================
	// TABLE ROW → DRAWER
	// =========================================================================

	DtbAdmin.initTableRowDrawer = function () {
		document.querySelectorAll( '.dtb-table__row--clickable' ).forEach( function ( row ) {
			row.addEventListener( 'click', function ( e ) {
				// Don't fire if clicking a button/link inside the row.
				if ( e.target.closest( 'a, button, input, .dtb-btn' ) ) return;

				const drawerId = row.dataset.dtbDrawer;
				if ( drawerId ) {
					DtbAdmin.openDrawer( drawerId );
					DtbAdmin.populateDrawerFromRow( row, drawerId );
				}
			} );

			// Keyboard accessibility.
			row.setAttribute( 'tabindex', '0' );
			row.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' || e.key === ' ' ) {
					e.preventDefault();
					const drawerId = row.dataset.dtbDrawer;
					if ( drawerId ) {
						DtbAdmin.openDrawer( drawerId );
						DtbAdmin.populateDrawerFromRow( row, drawerId );
					}
				}
			} );
		} );
	};

	/**
	 * Populate drawer fields from row data attributes.
	 * Row should have data-dtb-* attributes for each drawer field.
	 */
	DtbAdmin.populateDrawerFromRow = function ( row, drawerId ) {
		const drawer = document.getElementById( drawerId );
		if ( ! drawer ) return;

		const data = row.dataset;

		// Update drawer title.
		const titleEl = drawer.querySelector( '.dtb-drawer__title' );
		if ( titleEl && data.dtbDrawerTitle ) titleEl.textContent = data.dtbDrawerTitle;

		// Fill any [data-dtb-field] targets.
		Object.keys( data ).forEach( function ( key ) {
			if ( ! key.startsWith( 'dtbField' ) ) return;
			const fieldName = key.replace( 'dtbField', '' ).toLowerCase();
			const target    = drawer.querySelector( '[data-dtb-target="' + fieldName + '"]' );
			if ( target ) target.textContent = data[ key ];
		} );

		// Trigger a custom event for page-level JS to hook into.
		drawer.dispatchEvent( new CustomEvent( 'dtb:drawer:populate', { detail: { rowData: data }, bubbles: false } ) );
	};

	// =========================================================================
	// NONCE REFRESH
	// =========================================================================

	/**
	 * Silently fetch a fresh wp_rest nonce via admin-ajax.
	 *
	 * Uses the admin-ajax transport (not REST) so the auth cookie user is
	 * preserved without needing a valid REST nonce.  Updates cfg.nonce in-place
	 * so subsequent REST requests carry the new token.
	 *
	 * @returns {Promise<boolean>} Resolves true when nonce was refreshed successfully.
	 */
	DtbAdmin._refreshNonce = function () {
		const ajaxUrl = ( cfg.ajaxUrl || window.ajaxurl || new URL( 'admin-ajax.php', window.location.href ).href ).replace( /\/$/, '' );
		return fetch( ajaxUrl + '?action=dtb_refresh_nonce', {
			method:      'GET',
			credentials: 'same-origin',
			headers:     { Accept: 'application/json' },
		} )
			.then( function ( r ) { return r.ok ? r.json() : null; } )
			.then( function ( data ) {
				if ( data && data.success && data.data && data.data.nonce ) {
					cfg.nonce = data.data.nonce;
					return true;
				}
				return false;
			} )
			.catch( function () { return false; } );
	};

	// =========================================================================
	// AJAX HELPER
	// =========================================================================

	/**
	 * Minimal REST fetch wrapper.
	 * Uses nonce from dtbAdminConfig.nonce (localized by AdminAssets.php).
	 *
	 * @param {string} endpoint  - e.g. '/dtb/v1/command-center'
	 * @param {object} [options] - fetch options (method, body, etc.)
	 * @returns {Promise<any>}
	 */
	DtbAdmin.apiFetch = function ( endpoint, options ) {
		options = options || {};
		const headers = Object.assign(
			{
				'Content-Type' : 'application/json',
				'X-WP-Nonce'   : cfg.nonce || '',
			},
			options.headers || {}
		);

		const baseUrl = ( cfg.restUrl || '/wp-json' ).replace( /\/$/, '' );
		const url     = baseUrl + '/' + endpoint.replace( /^\//, '' );

		return fetch( url, Object.assign( {}, options, { headers } ) )
			.then( function ( res ) {
				if ( ! res.ok ) {
					return res.json().then( function ( err ) {
						throw err;
					} );
				}
				return res.json();
			} );
	};

	// =========================================================================
	// REFRESH HELPER
	// =========================================================================

	/**
	 * Auto-refresh a container by calling a REST endpoint.
	 *
	 * @param {string} containerId   - DOM element ID to update.
	 * @param {string} endpoint      - REST endpoint.
	 * @param {function} renderFn    - Function(data, container) to update DOM.
	 * @param {number} intervalMs    - Polling interval in ms. 0 = no polling.
	 */
	DtbAdmin.startAutoRefresh = function ( containerId, endpoint, renderFn, intervalMs ) {
		const container = document.getElementById( containerId );
		if ( ! container ) return null;

		function refresh() {
			DtbAdmin.apiFetch( endpoint )
				.then( function ( data ) { renderFn( data, container ); } )
				.catch( function () {
					// Silently fail polling — don't disrupt the user.
				} );
		}

		refresh();

		if ( intervalMs && intervalMs > 0 ) {
			return setInterval( refresh, intervalMs );
		}

		return null;
	};

	// =========================================================================
	// UTILITIES
	// =========================================================================

	function escHtml( str ) {
		const d = document.createElement( 'div' );
		d.appendChild( document.createTextNode( String( str ) ) );
		return d.innerHTML;
	}

	DtbAdmin.escHtml = escHtml;

	/**
	 * Format a number as a compact string (e.g. 1.2k).
	 * @param {number} n
	 * @returns {string}
	 */
	DtbAdmin.formatNumber = function ( n ) {
		n = parseInt( n, 10 );
		if ( isNaN( n ) ) return '–';
		if ( n >= 1000000 ) return ( n / 1000000 ).toFixed( 1 ).replace( /\.0$/, '' ) + 'm';
		if ( n >= 1000 )    return ( n / 1000 ).toFixed( 1 ).replace( /\.0$/, '' ) + 'k';
		return String( n );
	};

	/**
	 * Format a currency amount.
	 * @param {number|string} amount
	 * @param {string} [symbol]
	 * @returns {string}
	 */
	DtbAdmin.formatCurrency = function ( amount, symbol ) {
		symbol = symbol || cfg.currencySymbol || '$';
		const num = parseFloat( amount );
		if ( isNaN( num ) ) return symbol + '0.00';
		return symbol + num.toFixed( 2 ).replace( /\B(?=(\d{3})+(?!\d))/g, ',' );
	};

	/**
	 * Relative time string (e.g. "2 hours ago").
	 * @param {string|number} dateInput
	 * @returns {string}
	 */
	DtbAdmin.relativeTime = function ( dateInput ) {
		const date   = new Date( dateInput );
		const now    = new Date();
		const diffMs = now - date;
		const diffS  = Math.floor( diffMs / 1000 );
		const diffM  = Math.floor( diffS / 60 );
		const diffH  = Math.floor( diffM / 60 );
		const diffD  = Math.floor( diffH / 24 );

		if ( diffS < 60 )  return 'Just now';
		if ( diffM < 60 )  return diffM + 'm ago';
		if ( diffH < 24 )  return diffH + 'h ago';
		if ( diffD < 7 )   return diffD + 'd ago';
		return date.toLocaleDateString();
	};

	// =========================================================================
	// REBIND — re-initialise all scoped behaviours after HTML fragment replace
	// =========================================================================

	/**
	 * Re-bind all DtbAdmin behaviours on a container that has just had its
	 * innerHTML replaced (e.g. after a live region refresh).
	 *
	 * @param {Element|Document} container
	 */
	DtbAdmin.rebind = function ( container ) {
		DtbAdmin.initAlerts( container );
		DtbAdmin.initDropdowns( container );
		DtbAdmin.initDrawers( container );
		DtbAdmin.initModals( container );
		DtbAdmin.initTabs( container );
		DtbAdmin.initLoadingButtons( container );
		DtbAdmin.initBulkSelect( container );
		DtbAdmin.initTableRowDrawer( container );
		DtbAdmin.initLiveControls( container );
		DtbAdmin.initCharts( container );
		container.dispatchEvent( new CustomEvent( 'dtb:admin:rebound', { bubbles: true } ) );
	};

	// =========================================================================
	// SIDEBAR NAV  (vanilla JS port of Modernize sidebarmenu.js)
	// =========================================================================

	/**
	 * Activate the sidebar nav item matching the current page and expand its
	 * parent submenu (.in) if it has one.  Targets #dtb-sidebarnav (if present).
	 */
	DtbAdmin.initSidebarNav = function () {
		const nav = document.getElementById( 'dtb-sidebarnav' );
		if ( ! nav ) return;

		const href = window.location.href;

		nav.querySelectorAll( 'a[href]' ).forEach( function ( link ) {
			if ( link.href && href.indexOf( link.getAttribute( 'href' ) ) !== -1 ) {
				link.classList.add( 'active' );

				// Walk up and expand parent .sidebar-item submenu
				let parent = link.parentElement;
				while ( parent && parent !== nav ) {
					if ( parent.tagName === 'LI' ) {
						parent.classList.add( 'active' );
					}
					if ( parent.tagName === 'UL' && parent.classList.contains( 'collapse' ) ) {
						parent.classList.add( 'in' );
						// Toggle caret on trigger
						const trigger = parent.previousElementSibling;
						if ( trigger ) trigger.setAttribute( 'aria-expanded', 'true' );
					}
					parent = parent.parentElement;
				}
			}
		} );

		// Submenu toggle (click on items with nested ul.collapse)
		nav.querySelectorAll( '[data-dtb-nav-toggle]' ).forEach( function ( trigger ) {
			trigger.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				const targetId = trigger.getAttribute( 'data-dtb-nav-toggle' );
				const sub = targetId ? document.getElementById( targetId ) : trigger.nextElementSibling;
				if ( ! sub ) return;

				const isOpen = sub.classList.contains( 'in' );
				// Close siblings
				const siblings = trigger.closest( 'ul' );
				if ( siblings ) {
					siblings.querySelectorAll( 'ul.collapse.in' ).forEach( function ( s ) {
						s.classList.remove( 'in' );
						const sib = s.previousElementSibling;
						if ( sib ) sib.setAttribute( 'aria-expanded', 'false' );
					} );
				}
				sub.classList.toggle( 'in', ! isOpen );
				trigger.setAttribute( 'aria-expanded', String( ! isOpen ) );
			} );
		} );
	};

	// =========================================================================
	// CHARTS — lightweight wrapper around ApexCharts
	// =========================================================================

	/**
	 * Scan container for [data-dtb-chart] elements and initialise ApexCharts.
	 * Chart config is provided via data-dtb-chart-config (JSON).
	 *
	 * @param {Element|Document} container
	 */
	DtbAdmin.initCharts = function ( container ) {
		if ( typeof ApexCharts === 'undefined' ) return;

		( container || document ).querySelectorAll( '[data-dtb-chart]:not([data-dtb-chart-ready])' ).forEach( function ( el ) {
			try {
				const raw    = el.getAttribute( 'data-dtb-chart-config' );
				const config = raw ? JSON.parse( raw ) : {};

				// Apply DTB colour defaults read from CSS variables (falls back to brand hex).
				if ( ! config.colors ) {
					const cs = getComputedStyle( document.documentElement );
					const v = function ( n, fb ) {
						return cs.getPropertyValue( n ).trim() || fb;
					};
					config.colors = [
						v( '--dtb-chart-color-1', '#1d4ed8' ),
						v( '--dtb-chart-color-2', '#22c55e' ),
						v( '--dtb-chart-color-3', '#f59e0b' ),
						v( '--dtb-chart-color-4', '#ef4444' ),
						v( '--dtb-chart-color-5', '#0ea5e9' ),
					];
				}
				if ( ! config.chart ) config.chart = {};
				config.chart.fontFamily = "Inter, 'Plus Jakarta Sans', sans-serif";
				config.chart.background = 'transparent';
				if ( ! config.grid ) config.grid = {};
				config.grid.borderColor = getComputedStyle( document.documentElement )
					.getPropertyValue( '--dtb-border-soft' ).trim() || '#e5e7eb';

				const chart = new ApexCharts( el, config );
				chart.render();
				el.setAttribute( 'data-dtb-chart-ready', '1' );
				el._dtbChart = chart;
			} catch ( err ) {
				console.warn( '[DtbAdmin] Chart init failed:', el, err );
			}
		} );
	};

	// =========================================================================
	// LIVE INTERACTION LAYER  (P0 — documented in docs/plans/mu-plugins-rebuild.md)
	// =========================================================================

	/** Tracks region IDs that have unsaved user input. */
	DtbAdmin._dirtyRegions = new Set();

	/** Mark a live region as having unsaved changes (suppress auto-refresh). */
	DtbAdmin.markDirty = function ( regionId ) {
		DtbAdmin._dirtyRegions.add( regionId );
	};

	/** Clear dirty flag (call after a successful save/submit). */
	DtbAdmin.clearDirty = function ( regionId ) {
		DtbAdmin._dirtyRegions.delete( regionId );
	};

	/** Returns true if the region is safe to auto-replace. */
	DtbAdmin.canReplace = function ( regionId ) {
		return ! DtbAdmin._dirtyRegions.has( regionId );
	};

	// ── setRegionLoading ─────────────────────────────────────────────────────

	/**
	 * Show or hide the loading overlay on a live region element.
	 *
	 * @param {Element} regionEl
	 * @param {boolean} loading
	 */
	DtbAdmin.setRegionLoading = function ( regionEl, loading ) {
		if ( ! regionEl ) return;
		regionEl.classList.toggle( 'dtb-live-region--loading', loading );
		regionEl.setAttribute( 'aria-busy', loading ? 'true' : 'false' );
	};

	// ── readLiveState / applyHistoryState ────────────────────────────────────

	/**
	 * Read URL search params into a plain state object.
	 *
	 * @returns {Object}
	 */
	DtbAdmin.readLiveState = function () {
		const params = new URLSearchParams( window.location.search );
		const state  = {};
		params.forEach( function ( val, key ) { state[ key ] = val; } );
		return state;
	};

	/**
	 * Push (or replace) a live state into the browser history.
	 *
	 * @param {Object}  state
	 * @param {boolean} [replace=false]
	 */
	DtbAdmin.applyHistoryState = function ( state, replace ) {
		const params = new URLSearchParams();
		Object.entries( state ).forEach( function ( [ k, v ] ) {
			if ( v !== '' && v !== null && v !== undefined ) {
				params.set( k, v );
			}
		} );
		const url = window.location.pathname + ( params.toString() ? '?' + params.toString() : '' );
		if ( replace ) {
			history.replaceState( state, '', url );
		} else {
			history.pushState( state, '', url );
		}
	};

	// ── liveNavigate ─────────────────────────────────────────────────────────

	/** In-flight AbortControllers keyed by region element. */
	const _liveAbort = new WeakMap();

	/**
	 * Fetch a URL and replace a live region's inner HTML, then rebind.
	 *
	 * @param {Object} opts
	 * @param {Element}          opts.target     - .dtb-live-region element to replace
	 * @param {string}           opts.endpoint   - REST URL to fetch
	 * @param {Object}           [opts.query]    - Extra query params to merge
	 * @param {boolean}          [opts.history]  - Push state to history (default true)
	 */
	DtbAdmin.liveNavigate = function ( opts ) {
		const { target, endpoint, query = {}, history: pushHistory = true, silent = false } = opts;
		if ( ! target || ! endpoint ) return;

		const regionId = target.getAttribute( 'data-dtb-live-region' );

		// Abort any pending request for this region
		if ( _liveAbort.has( target ) ) {
			_liveAbort.get( target ).abort();
		}
		const controller = new AbortController();
		_liveAbort.set( target, controller );

		if ( ! silent ) DtbAdmin.setRegionLoading( target, true );

		const url    = new URL( endpoint, window.location.origin );
		const state  = Object.assign( DtbAdmin.readLiveState(), query );
		Object.entries( state ).forEach( function ( [ k, v ] ) {
			if ( v === '' || v === null || v === undefined ) {
				url.searchParams.delete( k );
			} else {
				url.searchParams.set( k, v );
			}
		} );

		// Captures current url/signal so the retry closure can re-use the same request.
		function doFetch() {
			return fetch( url.toString(), {
				signal:  controller.signal,
				headers: {
					'X-WP-Nonce': cfg.nonce || '',
					'Accept':     'application/json',
				},
			} );
		}

		doFetch()
			.then( function ( res ) {
				// On 403: attempt a nonce refresh then retry once before giving up.
				if ( res.status === 403 ) {
					return DtbAdmin._refreshNonce().then( function ( refreshed ) {
						if ( ! refreshed ) throw new Error( 'HTTP 403' );
						return doFetch();
					} );
				}
				return res;
			} )
			.then( function ( res ) {
				if ( ! res.ok ) throw new Error( 'HTTP ' + res.status );
				const ct = res.headers.get( 'Content-Type' ) || '';
				return ct.includes( 'application/json' ) ? res.json() : res.text();
			} )
			.then( function ( payload ) {
				const html = ( payload && typeof payload === 'object' ) ? ( payload.html || '' ) : String( payload );
				const changed = target.innerHTML !== html;
				if ( changed ) target.innerHTML = html;
				if ( ! silent ) DtbAdmin.setRegionLoading( target, false );
				if ( changed ) DtbAdmin.clearDirty( regionId );

				// Hide "updates available" badge if present
				const badge = target.querySelector( '.dtb-update-available' );
				if ( badge ) badge.classList.remove( 'is-visible' );

				// Update polling interval if server suggests a different cadence.
				if ( payload && payload.meta && payload.meta.poll_after_ms ) {
					const newInterval = parseInt( payload.meta.poll_after_ms, 10 );
					if ( newInterval > 0 ) {
						target.setAttribute( 'data-dtb-refresh-interval', Math.max( newInterval, DTB_ADMIN_AUTO_REFRESH_INTERVAL_MS ) );
					}
				}

				// Update last-updated label if present on the page.
				if ( payload && payload.meta && payload.meta.updated_at ) {
					const tsEl = document.querySelector( '[data-dtb-last-updated="' + regionId + '"]' );
					if ( tsEl ) tsEl.textContent = payload.meta.updated_at;
				}

				if ( changed ) DtbAdmin.rebind( target );

				if ( pushHistory ) {
					DtbAdmin.applyHistoryState( state );
				}

				target.dispatchEvent( new CustomEvent( 'dtb:live:navigated', {
					bubbles: true,
					detail: { regionId, state, payload },
				} ) );
			} )
			.catch( function ( err ) {
				if ( err.name === 'AbortError' ) return; // superseded request
				if ( ! silent ) DtbAdmin.setRegionLoading( target, false );
				console.warn( '[DtbAdmin] liveNavigate failed:', err );
				// Suppress toast for background auto-refresh polls (silent=true) so
				// transient 403s from stale nonces don't interrupt the admin UI.
				if ( ! silent ) {
					DtbAdmin.toast( 'Failed to load content. Please try again.', 'danger', 'Load Error' );
				}
			} );
	};

	// ── liveRefresh ──────────────────────────────────────────────────────────

	/**
	 * Silently refresh a live region using its registered endpoint.
	 * Skips if the region is dirty (has unsaved user input).
	 *
	 * @param {Element} regionEl
	 */
	DtbAdmin.liveRefresh = function ( regionEl ) {
		if ( ! regionEl ) return;

		const regionId = regionEl.getAttribute( 'data-dtb-live-region' );
		if ( regionId && ! DtbAdmin.canReplace( regionId ) ) return;

		const endpoint = regionEl.getAttribute( 'data-dtb-endpoint' );
		if ( ! endpoint ) return;

		DtbAdmin.liveNavigate( { target: regionEl, endpoint, history: false, silent: true } );
	};

	// ── initLiveControls ─────────────────────────────────────────────────────

	/**
	 * Bind live-navigation controls inside a container.
	 * Looks for: data-dtb-live-tab, data-dtb-live-filter, data-dtb-live-search,
	 *             data-dtb-live-sort, data-dtb-live-page, data-dtb-live-refresh.
	 *
	 * @param {Element|Document} container
	 */
	DtbAdmin.initLiveControls = function ( container ) {
		const root = container || document;

		// Tabs, filter pills, sort controls → immediate navigate
		root.querySelectorAll( '[data-dtb-live-tab],[data-dtb-live-filter],[data-dtb-live-sort],[data-dtb-live-page]' )
			.forEach( function ( el ) {
				if ( el._dtbLiveControlBound ) return;
				el._dtbLiveControlBound = true;

				el.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					const targetId = el.getAttribute( 'data-dtb-live-target' );
					const region   = targetId
						? document.querySelector( '[data-dtb-live-region="' + targetId + '"]' )
						: el.closest( '[data-dtb-live-region]' );
					if ( ! region ) return;

					const endpoint = region.getAttribute( 'data-dtb-endpoint' );
					const query    = {};

					if ( el.hasAttribute( 'data-dtb-live-tab' ) ) {
					const tab = el.getAttribute( 'data-dtb-live-tab' );
					query.tab    = tab;
					query.status = ( tab === 'all' || tab === '' ) ? '' : tab;
					query.paged  = 1;
				}
				if ( el.hasAttribute( 'data-dtb-live-filter' ) ) {
					query.status = el.getAttribute( 'data-dtb-live-filter' );
					query.paged  = 1;
				}
				if ( el.hasAttribute( 'data-dtb-live-sort' ) )  query.sort  = el.getAttribute( 'data-dtb-live-sort' );
				if ( el.hasAttribute( 'data-dtb-live-page' ) )  query.paged = el.getAttribute( 'data-dtb-live-page' );

					DtbAdmin.liveNavigate( { target: region, endpoint, query } );
				} );
			} );

		// Search input → debounced navigate
		root.querySelectorAll( '[data-dtb-live-search]' ).forEach( function ( el ) {
			if ( el._dtbLiveSearchBound ) return;
			el._dtbLiveSearchBound = true;

			let timer;
			el.addEventListener( 'input', function () {
				clearTimeout( timer );
				timer = setTimeout( function () {
					const targetId = el.getAttribute( 'data-dtb-live-target' );
					const region   = targetId
						? document.querySelector( '[data-dtb-live-region="' + targetId + '"]' )
						: el.closest( '[data-dtb-live-region]' );
					if ( ! region ) return;
					const endpoint = region.getAttribute( 'data-dtb-endpoint' );
					DtbAdmin.liveNavigate( {
						target: region,
						endpoint,
						query: { search: el.value, paged: 1, status: '' },
					} );
				}, 320 );
			} );
		} );

		// Explicit refresh button
		root.querySelectorAll( '[data-dtb-live-refresh]' ).forEach( function ( el ) {
			if ( el._dtbLiveRefreshBound ) return;
			el._dtbLiveRefreshBound = true;

			el.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				const regionId = el.getAttribute( 'data-dtb-live-refresh' );
				const region   = regionId
					? document.querySelector( '[data-dtb-live-region="' + regionId + '"]' )
					: el.closest( '[data-dtb-live-region]' );
				DtbAdmin.liveRefresh( region );
			} );
		} );
	};

	// ── registerLiveRegion ───────────────────────────────────────────────────

	/**
	 * Register a single [data-dtb-live-region] element:
	 * - Bind its controls via initLiveControls.
	 * - Start polling if data-dtb-refresh-interval is set.
	 * - Dirty-mark when user types inside the region.
	 *
	 * @param {Element} el
	 */
	DtbAdmin.registerLiveRegion = function ( el ) {
		const regionId = el.getAttribute( 'data-dtb-live-region' );
		const configuredInterval = parseInt( el.getAttribute( 'data-dtb-refresh-interval' ) || '0', 10 );
		const interval = configuredInterval > 0 ? Math.max( configuredInterval, DTB_ADMIN_AUTO_REFRESH_INTERVAL_MS ) : 0;
		if ( interval > 0 ) {
			el.setAttribute( 'data-dtb-refresh-interval', interval );
		}

		// Mark loading overlay wrapper present
		if ( ! el.querySelector( '.dtb-region-loading-overlay' ) ) {
			const overlay = document.createElement( 'div' );
			overlay.className = 'dtb-region-loading-overlay';
			el.appendChild( overlay );
		}

		// Dirty detection — any user input in the region blocks auto-refresh
		el.addEventListener( 'input', function () {
			if ( regionId ) DtbAdmin.markDirty( regionId );
		} );
		el.addEventListener( 'change', function () {
			if ( regionId ) DtbAdmin.markDirty( regionId );
		} );
		// Re-arm dirty flag after form submit/reset
		el.addEventListener( 'submit', function () {
			if ( regionId ) DtbAdmin.clearDirty( regionId );
		} );

		// Bind inline controls
		DtbAdmin.initLiveControls( el );

		// Auto-polling
		if ( interval > 0 ) {
			setInterval( function () {
				if ( document.hidden ) return; // don't poll background tabs
				DtbAdmin.liveRefresh( el );
			}, interval );
		}
	};

	// ── initLiveRegions ──────────────────────────────────────────────────────

	/**
	 * Bootstrap the live interaction layer for the current page.
	 * Called once on DOMContentLoaded.
	 */
	DtbAdmin.initLiveRegions = function () {
		document.querySelectorAll( '[data-dtb-live-region]' ).forEach( function ( el ) {
			DtbAdmin.registerLiveRegion( el );
		} );

		// Bind live controls that live OUTSIDE any live region (e.g. tab nav in
		// the page header). The _dtbLiveControlBound guard prevents double-binding
		// for controls that were already found inside a region above.
		DtbAdmin.initLiveControls( document );

		// Handle browser back/forward — re-apply URL state to active region
		window.addEventListener( 'popstate', function ( e ) {
			if ( ! e.state ) return;
			const regions = document.querySelectorAll( '[data-dtb-live-region][data-dtb-endpoint]' );
			regions.forEach( function ( region ) {
				DtbAdmin.liveNavigate( {
					target:   region,
					endpoint: region.getAttribute( 'data-dtb-endpoint' ),
					query:    e.state,
					history:  false,
				} );
			} );
		} );
	};

} )();
