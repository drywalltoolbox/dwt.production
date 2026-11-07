/**
 * DTB Admin Workbench — shared primitives (window.DtbWorkbench)
 *
 * Provides a unified modal shell, tab switcher, fetch wrapper, toast system,
 * action-locking, URL param helpers, and escaping utilities consumed by the
 * dtb-support, dtb-returns, and dtb-repairs admin pages.
 *
 * Loads before any module-specific script.
 */
/* global dtbAdminConfig */
( function ( window ) {
	'use strict';

	// ── Internal state ─────────────────────────────────────────────────────────
	var _modals    = {};   // { modalId: { el, titleEl, bodyEl, footerEl } }
	var _toastTimer = null;

	// ── Utilities ──────────────────────────────────────────────────────────────

	/**
	 * Escape a string for safe HTML insertion.
	 *
	 * @param {*} value
	 * @return {string}
	 */
	function escapeHtml( value ) {
		var d = document.createElement( 'div' );
		d.appendChild( document.createTextNode( String( value == null ? '' : value ) ) );
		return d.innerHTML;
	}

	/**
	 * Format a raw datetime/ISO string as a relative or absolute human label.
	 *
	 * @param {string} raw
	 * @return {string}
	 */
	function formatDate( raw ) {
		if ( ! raw || raw === '\u2014' ) { return raw || '\u2014'; }
		var normalised = String( raw ).replace( ' ', 'T' );
		var d = new Date( normalised );
		if ( isNaN( d.getTime() ) ) { return raw; }
		var now  = new Date();
		var diff = ( now - d ) / 1000;
		if ( diff >= 0 && diff < 60 )    { return 'Just now'; }
		if ( diff >= 0 && diff < 3600 )  { return Math.floor( diff / 60 ) + 'm ago'; }
		if ( diff >= 0 && diff < 86400 ) { return Math.floor( diff / 3600 ) + 'h ago'; }
		if ( diff >= 0 && diff < 604800 ){ return Math.floor( diff / 86400 ) + 'd ago'; }
		return d.toLocaleDateString( undefined, { month: 'short', day: 'numeric', year: 'numeric' } );
	}

	/**
	 * Format a full date+time string.
	 *
	 * @param {string} raw
	 * @return {string}
	 */
	function formatDateFull( raw ) {
		if ( ! raw || raw === '\u2014' ) { return raw || '\u2014'; }
		var normalised = String( raw ).replace( ' ', 'T' );
		var d = new Date( normalised );
		if ( isNaN( d.getTime() ) ) { return raw; }
		return d.toLocaleDateString( undefined, { month: 'short', day: 'numeric', year: 'numeric' } ) +
			' ' + d.toLocaleTimeString( undefined, { hour: '2-digit', minute: '2-digit' } );
	}

	/**
	 * Format a monetary value.
	 *
	 * @param {number}  value
	 * @param {string=} currency  ISO currency code, e.g. 'USD'.
	 * @return {string}
	 */
	function formatMoney( value, currency ) {
		currency = currency || ( window.dtbAdminConfig && window.dtbAdminConfig.currencySymbol ) || 'USD';
		var num = parseFloat( value );
		if ( isNaN( num ) ) { return '\u2014'; }
		try {
			return new Intl.NumberFormat( undefined, {
				style:    'currency',
				currency: currency.length === 3 ? currency : 'USD',
			} ).format( num );
		} catch ( e ) {
			return currency + num.toFixed( 2 );
		}
	}

	/**
	 * Render a key/value row used inside modal info panels.
	 *
	 * @param {string} label
	 * @param {string} valueHtml  Already-escaped HTML.
	 * @return {string}
	 */
	function renderKeyValue( label, valueHtml ) {
		return '<div class="dtb-wb-kv">' +
			'<span class="dtb-wb-kv__label">' + escapeHtml( label ) + '</span>' +
			'<span class="dtb-wb-kv__value">' + ( valueHtml || '\u2014' ) + '</span>' +
			'</div>';
	}

	/**
	 * Render a status badge.
	 *
	 * @param {string} status
	 * @param {string=} label
	 * @return {string}
	 */
	function renderStatusBadge( status, label ) {
		status = String( status || 'unknown' ).replace( /[^a-z0-9_-]/gi, '' ).toLowerCase();
		label = label || status.replace( /[_-]/g, ' ' );
		return '<span class="dtb-wb-badge dtb-wb-badge--' + escapeHtml( status || 'unknown' ) + '">' +
			escapeHtml( label ) + '</span>';
	}

	/**
	 * Render a Customer 360 rail card.
	 *
	 * @param {Object} customer
	 * @return {string}
	 */
	function renderCustomerRail( customer ) {
		customer = customer || {};
		var html = '<div class="dtb-wb-card dtb-wb-card--customer">';
		html += '<div class="dtb-wb-card__title">Customer</div>';
		html += '<div class="dtb-wb-card__body">';
		html += renderKeyValue( 'Name', escapeHtml( customer.name || 'Unknown Customer' ) );
		html += renderKeyValue( 'Email', customer.email ? '<a href="mailto:' + escapeHtml( customer.email ) + '">' + escapeHtml( customer.email ) + '</a>' : '\u2014' );
		html += renderKeyValue( 'Phone', customer.phone ? '<a href="tel:' + escapeHtml( customer.phone ) + '">' + escapeHtml( customer.phone ) + '</a>' : '\u2014' );
		html += renderKeyValue( 'Orders', escapeHtml( customer.order_count || 0 ) );
		html += renderKeyValue( 'Open tickets', escapeHtml( customer.open_tickets || 0 ) );
		html += renderKeyValue( 'Open returns', escapeHtml( customer.open_returns || 0 ) );
		html += renderKeyValue( 'Open repairs', escapeHtml( customer.open_repairs || 0 ) );
		html += renderKeyValue( 'Lifetime spend', formatMoney( customer.lifetime_spend || customer.lifetime_value || 0 ) );
		if ( customer.is_high_value ) {
			html += '<div class="dtb-wb-note dtb-wb-note--success">High-value customer</div>';
		}
		if ( Array.isArray( customer.risk_notes ) && customer.risk_notes.length ) {
			html += '<div class="dtb-wb-note dtb-wb-note--warning">' +
				customer.risk_notes.map( escapeHtml ).join( ', ' ) + '</div>';
		}
		html += '</div></div>';
		return html;
	}

	/**
	 * Render normalized linked records.
	 *
	 * @param {Object} linked
	 * @return {string}
	 */
	function renderLinkedRecords( linked ) {
		linked = linked || {};
		var records = Array.isArray( linked.records ) ? linked.records : [];
		var warnings = Array.isArray( linked.warnings ) ? linked.warnings : [];
		var mismatches = Array.isArray( linked.mismatches ) ? linked.mismatches : [];
		var html = '';
		if ( warnings.length || mismatches.length ) {
			html += '<div class="dtb-wb-link-warnings">';
			warnings.forEach( function ( warning ) {
				html += '<div class="dtb-wb-note dtb-wb-note--' + escapeHtml( warning.severity || 'warning' ) + '">' +
					escapeHtml( warning.label || warning.code || 'Linked record warning' ) +
					( warning.message ? '<span>' + escapeHtml( warning.message ) + '</span>' : '' ) +
					'</div>';
			} );
			mismatches.forEach( function ( mismatch ) {
				html += '<div class="dtb-wb-note dtb-wb-note--' + escapeHtml( mismatch.severity || 'warning' ) + '">' +
					escapeHtml( mismatch.label || mismatch.code || 'Mismatch' ) +
					'<span>' + escapeHtml( mismatch.expected || 'unknown' ) + ' / ' + escapeHtml( mismatch.actual || 'unknown' ) + '</span>' +
					'</div>';
			} );
			html += '</div>';
		}
		if ( ! records.length ) {
			return html + '<div class="dtb-wb-empty">No linked records.</div>';
		}
		html += '<ul class="dtb-wb-links">';
		records.forEach( function ( rec ) {
			var confidence = rec.confidence ? ' <span class="dtb-wb-link-confidence">' + escapeHtml( rec.confidence ) + '</span>' : '';
			var label = escapeHtml( rec.label || ( rec.module + ' #' + rec.id ) );
			html += '<li class="dtb-wb-link dtb-wb-link--' + escapeHtml( rec.module || 'record' ) + '">';
			if ( rec.url ) {
				html += '<a href="' + escapeHtml( rec.url ) + '">' + label + '</a>';
			} else {
				html += '<span>' + label + '</span>';
			}
			html += confidence + '</li>';
		} );
		html += '</ul>';
		return html;
	}

	/**
	 * Render compact record-level issue chips for errors/failures only.
	 *
	 * Shows only integration keys with status 'error', 'failed', or 'sync_failed',
	 * or keys matching a canonical blocker name regardless of status. Each chip
	 * links to System Manager for diagnostics. Use this in module modals instead of
	 * renderIntegrationHealth — system-level diagnostics belong in System Manager.
	 *
	 * @param {Object} integrations  integrations/intel block from the canonical payload.
	 * @param {string} [sysUrl]      Override System Manager URL (auto-derived if absent).
	 * @return {string}
	 */
	function renderRecordIssueChips( integrations, sysUrl ) {
		integrations = integrations || {};
		var blockerKeys = [
			'sync_failed', 'notification_failed', 'payment_failed', 'fulfillment_blocked',
			'shipping_blocked', 'refund_unavailable', 'quote_notification_failed',
			'parts_allocation_unavailable', 'missing_linked_order',
		];
		var adminBase = ( window.dtbAdminConfig && window.dtbAdminConfig.adminUrl )
			? window.dtbAdminConfig.adminUrl.replace( /admin\.php.*$/, 'admin.php' )
			: new URL( 'admin.php', window.location.href ).href;
		var resolvedSysUrl = sysUrl || ( adminBase + '?page=dtb-system-manager' );
		var chips = [];
		Object.keys( integrations ).forEach( function ( key ) {
			var item = integrations[ key ] || {};
			var status = item.status || item.state || '';
			var isError = status === 'error' || status === 'failed' || status === 'sync_failed';
			var isBlocker = blockerKeys.indexOf( key ) !== -1;
			if ( ! isError && ! isBlocker ) { return; }
			chips.push( {
				label: item.label || key.replace( /_/g, ' ' ),
				error: item.last_error || item.error || null,
				url:   resolvedSysUrl,
			} );
		} );
		if ( ! chips.length ) { return ''; }
		var html = '<div class="dtb-wb-record-issues">';
		html += '<div class="dtb-wb-record-issues__title">Record Issues</div>';
		chips.forEach( function ( chip ) {
			html += '<div class="dtb-wb-note dtb-wb-note--error">';
			html += escapeHtml( chip.label );
			if ( chip.error ) { html += ' — ' + escapeHtml( chip.error ); }
			html += ' <a href="' + escapeHtml( chip.url ) + '" target="_blank" rel="noopener">System Manager ↗</a>';
			html += '</div>';
		} );
		html += '</div>';
		return html;
	}

	/**
	 * Render canonical integration health.
	 *
	 * @param {Object} integrations
	 * @return {string}
	 */
	function renderIntegrationHealth( integrations ) {
		integrations = integrations || {};
		var keys = Object.keys( integrations );
		if ( ! keys.length ) {
			return '<div class="dtb-wb-empty">No integration state available.</div>';
		}
		var html = '<div class="dtb-wb-integrations">';
		keys.forEach( function ( key ) {
			var item = integrations[ key ] || {};
			var status = item.status || item.state || 'unknown';
			html += '<div class="dtb-wb-integration dtb-wb-integration--' + escapeHtml( status ) + '">';
			html += '<span class="dtb-wb-integration__name">' + escapeHtml( item.label || key ) + '</span>';
			html += renderStatusBadge( status );
			if ( item.last_error ) {
				html += '<span class="dtb-wb-integration__error">' + escapeHtml( item.last_error ) + '</span>';
			}
			html += '</div>';
		} );
		html += '</div>';
		return html;
	}

	/**
	 * Render a normalized timeline.
	 *
	 * @param {Array} events
	 * @return {string}
	 */
	function renderTimeline( events ) {
		events = Array.isArray( events ) ? events : [];
		if ( ! events.length ) {
			return '<div class="dtb-wb-empty">No timeline events yet.</div>';
		}
		var html = '<ol class="dtb-wb-timeline">';
		events.forEach( function ( ev ) {
			var actor = ev.actor && ev.actor.label ? ev.actor.label : ( ev.actor_label || 'System' );
			html += '<li class="dtb-wb-timeline__item">';
			html += '<div class="dtb-wb-timeline__summary">' + escapeHtml( ev.summary || ev.event_type || ev.action || 'Event' ) + '</div>';
			html += '<div class="dtb-wb-timeline__meta">' + escapeHtml( formatDateFull( ev.created_at || ev.ts || ev.date ) ) +
				' · ' + escapeHtml( actor ) +
				' · ' + escapeHtml( ev.visibility || 'internal' ) + '</div>';
			html += '</li>';
		} );
		html += '</ol>';
		return html;
	}

	// ── URL helpers ────────────────────────────────────────────────────────────

	/**
	 * Set a URL search param without page reload.
	 *
	 * @param {string} key
	 * @param {string} value
	 */
	function replaceUrlParam( key, value ) {
		try {
			var url = new URL( window.location.href );
			url.searchParams.set( key, value );
			window.history.replaceState( null, '', url.toString() );
		} catch ( e ) { /* noop in older browsers */ }
	}

	/**
	 * Remove a URL search param without page reload.
	 *
	 * @param {string} key
	 */
	function clearUrlParam( key ) {
		try {
			var url = new URL( window.location.href );
			url.searchParams.delete( key );
			window.history.replaceState( null, '', url.toString() );
		} catch ( e ) { /* noop */ }
	}

	/**
	 * Read a URL search param.
	 *
	 * @param {string} key
	 * @return {?string}
	 */
	function getUrlParam( key ) {
		try {
			return new URL( window.location.href ).searchParams.get( String( key || '' ) );
		} catch ( e ) {
			return null;
		}
	}

	// ── Toast ──────────────────────────────────────────────────────────────────

	/**
	 * Show a brief toast notification.
	 *
	 * @param {string} message
	 * @param {string=} type  'success'|'error'|'info'|'warning'
	 */
	function showToast( message, type ) {
		type = type || 'info';
		var existing = document.getElementById( 'dtb-wb-toast' );
		if ( existing ) { existing.remove(); }

		var el = document.createElement( 'div' );
		el.id        = 'dtb-wb-toast';
		el.className = 'dtb-wb-toast dtb-wb-toast--' + escapeHtml( type );
		el.setAttribute( 'role', 'status' );
		el.setAttribute( 'aria-live', 'polite' );
		el.textContent = message;
		document.body.appendChild( el );

		// Trigger animation.
		requestAnimationFrame( function () {
			el.classList.add( 'dtb-wb-toast--visible' );
		} );

		clearTimeout( _toastTimer );
		_toastTimer = setTimeout( function () {
			el.classList.remove( 'dtb-wb-toast--visible' );
			setTimeout( function () { el.remove(); }, 300 );
		}, 3500 );
	}

	// ── Action locking ─────────────────────────────────────────────────────────

	/**
	 * Disable a button and replace its label with a loading indicator.
	 *
	 * @param {HTMLElement} button
	 * @param {string=}     loadingLabel
	 */
	function lockAction( button, loadingLabel ) {
		if ( ! button ) { return; }
		button.disabled = true;
		button.setAttribute( 'data-dtb-original-label', button.textContent );
		button.textContent = loadingLabel || 'Processing\u2026';
	}

	/**
	 * Re-enable a button and restore its original label.
	 *
	 * @param {HTMLElement} button
	 * @param {string=}     originalLabel  Falls back to data-dtb-original-label.
	 */
	function unlockAction( button, originalLabel ) {
		if ( ! button ) { return; }
		button.disabled = false;
		button.textContent = originalLabel || button.getAttribute( 'data-dtb-original-label' ) || button.textContent;
	}

	/**
	 * Show a danger-confirmation dialog.
	 *
	 * @param {string} message
	 * Show a native confirmation dialog and invoke callback on accept.
	 *
	 * @param {string}   message   Confirmation prompt text.
	 * @param {Function} callback  Called if the user confirms (no arguments).
	 */
	function confirmDanger( message, callback ) {
		// eslint-disable-next-line no-alert
		if ( window.confirm( message ) && typeof callback === 'function' ) {
			callback();
		}
	}

	// ── API fetch ──────────────────────────────────────────────────────────────

	/**
	 * Shared authenticated fetch.
	 *
	 * @param {string}  url      Full URL or a path relative to the WP REST base.
	 * @param {Object=} options  Fetch init overrides: { method, body, timeout }.
	 *                           `body` may be a pre-serialised string or an object.
	 * @return {Promise<Object>}  Resolves with parsed JSON or rejects with Error.
	 */
	function apiFetch( url, options ) {
		options = options || {};
		var cfg   = window.dtbAdminConfig || {};
		var nonce = cfg.nonce || '';

		// If url is a relative path (no protocol) build the absolute URL.
		if ( url.indexOf( 'http' ) !== 0 ) {
			var base = ( cfg.restUrl || '/wp-json/' ).replace( /\/$/, '' );
			url = base + '/' + url.replace( /^\//, '' );
		}

		var method = ( options.method || 'GET' ).toUpperCase();
		var init = {
			method:  method,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   nonce,
			},
			credentials: 'same-origin',
		};

		if ( options.body && method !== 'GET' && method !== 'HEAD' ) {
			init.body = typeof options.body === 'string' ? options.body : JSON.stringify( options.body );
		}

		var timeoutMs = options.timeout || 20000;
		var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
		if ( controller ) {
			init.signal = controller.signal;
		}
		var timeoutHandle = controller
			? setTimeout( function () { controller.abort(); }, timeoutMs )
			: null;

		return fetch( url, init )
			.then( function ( res ) {
				if ( timeoutHandle ) { clearTimeout( timeoutHandle ); }
				var ct = res.headers.get( 'Content-Type' ) || '';
				if ( ! ct.includes( 'application/json' ) ) {
					return Promise.reject( new Error( 'Non-JSON response (' + res.status + ')' ) );
				}
				return res.json().then( function ( data ) {
					if ( ! res.ok ) {
						var msg = ( data && ( data.message || data.error ) ) || 'Request failed (' + res.status + ')';
						return Promise.reject( new Error( msg ) );
					}
					return data;
				} );
			} )
			.catch( function ( err ) {
				if ( timeoutHandle ) { clearTimeout( timeoutHandle ); }
				return Promise.reject( err );
			} );
	}

	// ── Modal helpers ──────────────────────────────────────────────────────────

	/**
	 * Register a DOM modal element for management.
	 *
	 * Expected HTML structure:
	 *   <div id="{modalId}" class="dtb-modal-overlay" role="dialog" aria-modal="true">
	 *     <div class="dtb-modal">
	 *       <header class="dtb-modal__header">
	 *         <h2 class="dtb-modal__title">…</h2>
	 *         <button class="dtb-modal__close" aria-label="Close">×</button>
	 *       </header>
	 *       <div class="dtb-modal__body">…</div>
	 *       <footer class="dtb-modal__footer">…</footer>
	 *     </div>
	 *   </div>
	 *
	 * @param {string} modalId
	 */
	function _registerModal( modalId ) {
		if ( _modals[ modalId ] ) { return; }
		var overlay = document.getElementById( modalId );
		if ( ! overlay ) { return; }

		var modal   = overlay.querySelector( '.dtb-modal' ) || overlay;
		var titleEl = overlay.querySelector( '.dtb-modal__title' );
		var bodyEl  = overlay.querySelector( '.dtb-modal__body' );
		var footerEl = overlay.querySelector( '.dtb-modal__footer' );

		_modals[ modalId ] = { el: overlay, modal: modal, titleEl: titleEl, bodyEl: bodyEl, footerEl: footerEl };

		// Close button.
		var closeBtn = overlay.querySelector( '.dtb-modal__close' );
		if ( closeBtn ) {
			closeBtn.addEventListener( 'click', function () { closeRecordModal( modalId ); } );
		}

		// Click-outside close.
		overlay.addEventListener( 'click', function ( e ) {
			if ( e.target === overlay ) { closeRecordModal( modalId ); }
		} );
	}

	/**
	 * Open a record modal.
	 *
	 * @param {Object} config {
	 *   modalId: string,
	 *   title: string,
	 *   loadingLabel: string,
	 *   urlParam: string,
	 *   urlParamValue: string,
	 *   focusReturnEl: HTMLElement,
	 * }
	 */
	function openRecordModal( config ) {
		var modalId = config.modalId;
		_registerModal( modalId );
		var m = _modals[ modalId ];
		if ( ! m ) { return; }

		m.el.classList.add( 'dtb-modal-overlay--open' );
		m.el.removeAttribute( 'hidden' );
		m.el.setAttribute( 'aria-hidden', 'false' );

		if ( config.focusReturnEl ) {
			m._focusReturnEl = config.focusReturnEl;
		}

		if ( config.title && m.titleEl ) {
			m.titleEl.textContent = config.title;
		}

		if ( config.loadingLabel && m.bodyEl ) {
			m.bodyEl.innerHTML = '<div class="dtb-modal-loading" aria-live="polite">' +
				escapeHtml( config.loadingLabel ) + '</div>';
		}

		if ( config.urlParam && config.urlParamValue ) {
			replaceUrlParam( config.urlParam, config.urlParamValue );
		}

		// Focus first focusable element in modal body.
		setTimeout( function () {
			var focusable = m.el.querySelector(
				'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
			);
			if ( focusable ) { focusable.focus(); }
		}, 80 );

		document.body.classList.add( 'dtb-modal-open' );
	}

	/**
	 * Close a modal by ID.
	 *
	 * @param {string}  modalId
	 * @param {string=} urlParam  If set, the URL param is cleared on close.
	 */
	function closeRecordModal( modalId, urlParam ) {
		var m = _modals[ modalId ];
		if ( ! m ) {
			var overlay = document.getElementById( modalId );
			if ( overlay ) {
				overlay.classList.remove( 'dtb-modal-overlay--open' );
				overlay.setAttribute( 'hidden', '' );
				overlay.setAttribute( 'aria-hidden', 'true' );
			}
		} else {
			m.el.classList.remove( 'dtb-modal-overlay--open' );
			m.el.setAttribute( 'hidden', '' );
			m.el.setAttribute( 'aria-hidden', 'true' );
			if ( m._focusReturnEl ) {
				m._focusReturnEl.focus();
				m._focusReturnEl = null;
			}
		}

		if ( urlParam ) { clearUrlParam( urlParam ); }
		document.body.classList.remove( 'dtb-modal-open' );
	}

	/**
	 * Set a modal into a loading state.
	 *
	 * @param {string}  modalId
	 * @param {string=} label
	 */
	function setModalLoading( modalId, label ) {
		_registerModal( modalId );
		var m = _modals[ modalId ];
		if ( m && m.bodyEl ) {
			m.bodyEl.innerHTML = '<div class="dtb-modal-loading" aria-live="polite">' +
				escapeHtml( label || 'Loading\u2026' ) + '</div>';
		}
	}

	/**
	 * Set a modal into an error state.
	 *
	 * @param {string} modalId
	 * @param {string} message
	 */
	function setModalError( modalId, message ) {
		_registerModal( modalId );
		var m = _modals[ modalId ];
		if ( m && m.bodyEl ) {
			m.bodyEl.innerHTML = '<div class="dtb-modal-error" role="alert">' +
				escapeHtml( message || 'An error occurred.' ) + '</div>';
		}
	}

	// ── Tab switching ──────────────────────────────────────────────────────────

	/**
	 * Switch active tab within a container element.
	 *
	 * Expected HTML:
	 *   <nav class="dtb-modal-tabs">
	 *     <button class="dtb-modal-tab" data-dtb-tab="overview" aria-selected="true">…</button>
	 *   </nav>
	 *   <div class="dtb-modal-tab-panel" data-dtb-tab="overview">…</div>
	 *
	 * @param {HTMLElement} root     The modal or container element.
	 * @param {string}      tabKey   The value of data-dtb-tab to activate.
	 */
	function switchTabs( root, tabKey ) {
		if ( ! root ) { return; }

		// Guard: only allow safe alphanumeric/dash/underscore tab keys.
		if ( typeof tabKey !== 'string' || ! /^[a-z0-9_-]+$/i.test( tabKey ) ) {
			return;
		}

		root.querySelectorAll( '.dtb-modal-tab' ).forEach( function ( btn ) {
			var isActive = btn.getAttribute( 'data-dtb-tab' ) === tabKey;
			btn.classList.toggle( 'dtb-modal-tab--active', isActive );
			btn.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
		} );

		root.querySelectorAll( '.dtb-modal-tab-panel' ).forEach( function ( panel ) {
			var isActive = panel.getAttribute( 'data-dtb-tab' ) === tabKey;
			panel.hidden = ! isActive;
			panel.classList.toggle( 'dtb-modal-tab-panel--active', isActive );
		} );
	}

	// ── Global keyboard handler ────────────────────────────────────────────────

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) {
			// Close the topmost open modal.
			var openModals = document.querySelectorAll( '.dtb-modal-overlay--open' );
			if ( openModals.length ) {
				var last = openModals[ openModals.length - 1 ];
				closeRecordModal( last.id );
			}
		}
	} );

	// ── Deep-link on page load ─────────────────────────────────────────────────

	/**
	 * Check the URL for an open-record param and fire a custom event so
	 * module scripts can open their modal automatically.
	 *
	 * e.g.  ?open_ticket=123   fires  CustomEvent('dtb:deeplink', { detail: { module:'ticket', id: 123 } })
	 */
	( function initDeepLink() {
		var params   = new URLSearchParams( window.location.search );
		var paramMap = {
			open_ticket: 'ticket',
			open_return: 'return',
			open_repair: 'repair',
			open_order: 'order',
		};
		Object.keys( paramMap ).forEach( function ( param ) {
			var val = params.get( param );
			if ( val ) {
				document.dispatchEvent( new CustomEvent( 'dtb:deeplink', {
					bubbles: true,
					detail:  { module: paramMap[ param ], id: parseInt( val, 10 ) || val },
				} ) );
			}
		} );
	}() );

	// ── Public API ─────────────────────────────────────────────────────────────

	window.DtbWorkbench = {
		apiFetch:         apiFetch,
		openRecordModal:  openRecordModal,
		closeRecordModal: closeRecordModal,
		setModalLoading:  setModalLoading,
		setModalError:    setModalError,
		switchTabs:       switchTabs,
		replaceUrlParam:  replaceUrlParam,
		clearUrlParam:    clearUrlParam,
		getUrlParam:      getUrlParam,
		showToast:        showToast,
		lockAction:       lockAction,
		unlockAction:     unlockAction,
		confirmDanger:    confirmDanger,
		formatDate:       formatDate,
		formatDateFull:   formatDateFull,
		formatMoney:      formatMoney,
		escapeHtml:       escapeHtml,
		renderKeyValue:   renderKeyValue,
		renderStatusBadge: renderStatusBadge,
		renderCustomerRail: renderCustomerRail,
		renderLinkedRecords: renderLinkedRecords,
		renderIntegrationHealth: renderIntegrationHealth,
		renderRecordIssueChips: renderRecordIssueChips,
		renderTimeline:    renderTimeline,
	};

}( window ) );
