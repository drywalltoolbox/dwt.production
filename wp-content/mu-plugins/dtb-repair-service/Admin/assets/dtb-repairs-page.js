/**
 * DTB Repairs Page — full-screen repair workbench modal
 *
 * Depends on:  window.DtbWorkbench  (dtb-admin-workbench.js)
 * Localized:   window.dtbAdminConfig  { nonce, restUrl, adminUrl, ... }
 *
 * Tabs: overview | intake | quote | parts | technician |
 *        conversation | timeline | shipping | actions
 */
/* global DtbWorkbench, dtbAdminConfig */
( function () {
	'use strict';

	var WB     = window.DtbWorkbench;
	var CONFIG = window.dtbAdminConfig || {};
	var REST   = ( CONFIG.restUrl || '' ).replace( /\/$/, '' );
	var TABS   = [ 'overview', 'quote', 'technician', 'timeline', 'conversation', 'all', 'actions' ];
	var TAB_LABELS = {
		overview: 'Order Details',
		quote: 'Quote Builder',
		technician: 'Technician',
		timeline: 'Timeline',
		conversation: 'Notes',
		all: 'All',
		actions: 'Actions',
	};

	// ── State ─────────────────────────────────────────────────────────────────

	var state = {
		repairId:    null,
		data:        null,
		activeTab:   'overview',
		loading:     false,
		refreshTimer: null,
	};

	// ── DOM ───────────────────────────────────────────────────────────────────

	var overlay, modal, tabBar, panelContainer, titleEl, metaEl, footerEl, heroEl;

	function buildDOM() {
		if ( document.getElementById( 'dtb-repair-modal-overlay' ) ) {
			return; // already built
		}

		overlay = document.createElement( 'div' );
		overlay.id        = 'dtb-repair-modal-overlay';
		overlay.className = 'dtb-modal-overlay';
		overlay.setAttribute( 'role', 'dialog' );
		overlay.setAttribute( 'aria-modal', 'true' );
		overlay.setAttribute( 'aria-labelledby', 'dtb-repair-modal-title' );

		modal = document.createElement( 'div' );
		modal.className = 'dtb-modal dtb-modal--fullscreen dtb-repair-modal--fullpage';
		modal.setAttribute( 'id', 'dtb-repair-modal' );

		// Header
		var header = document.createElement( 'div' );
		header.className = 'dtb-modal__header';

		titleEl = document.createElement( 'h2' );
		titleEl.id        = 'dtb-repair-modal-title';
		titleEl.className = 'dtb-modal__title';
		titleEl.textContent = 'Repair';

		metaEl = document.createElement( 'div' );
		metaEl.className = 'dtb-modal__meta';

		var closeBtn = document.createElement( 'button' );
		closeBtn.type      = 'button';
		closeBtn.className = 'dtb-modal__close';
		closeBtn.setAttribute( 'aria-label', 'Close' );
		closeBtn.textContent = '×';
		closeBtn.addEventListener( 'click', closeModal );

		header.appendChild( titleEl );
		header.appendChild( metaEl );
		header.appendChild( closeBtn );

		// Tab bar
		tabBar = document.createElement( 'div' );
		tabBar.className = 'dtb-modal-tabs';
		tabBar.setAttribute( 'role', 'tablist' );

		TABS.forEach( function ( tab ) {
			if ( tab === 'actions' ) {
				return;
			}
			var btn = document.createElement( 'button' );
			btn.type             = 'button';
			btn.className        = 'dtb-modal-tab';
			btn.dataset.tab      = tab;
			btn.setAttribute( 'role', 'tab' );
			btn.setAttribute( 'aria-selected', tab === 'overview' ? 'true' : 'false' );
			btn.textContent = TAB_LABELS[ tab ] || tab.charAt(0).toUpperCase() + tab.slice(1).replace( /-/g, ' ' );
			btn.addEventListener( 'click', function () {
				switchTab( tab );
			} );
			tabBar.appendChild( btn );
		} );

		// Body + panel container
		var body = document.createElement( 'div' );
		body.className = 'dtb-modal__body';

		var mainCol = document.createElement( 'div' );
		mainCol.className = 'dtb-wb-main';

		heroEl = document.createElement( 'section' );
		heroEl.className = 'dtb-repair-modal-hero';
		heroEl.setAttribute( 'aria-label', 'Repair summary' );

		panelContainer = document.createElement( 'div' );
		panelContainer.id = 'dtb-repair-panels';

		mainCol.appendChild( heroEl );
		mainCol.appendChild( tabBar );
		mainCol.appendChild( panelContainer );
		body.appendChild( mainCol );

		// Footer
		footerEl = document.createElement( 'div' );
		footerEl.className = 'dtb-modal__footer';

		var refreshBtn = document.createElement( 'button' );
		refreshBtn.type      = 'button';
		refreshBtn.className = 'button';
		refreshBtn.textContent = 'Refresh';
		refreshBtn.addEventListener( 'click', function () { loadRepair( state.repairId ); } );

		var editLink = document.createElement( 'a' );
		editLink.id        = 'dtb-repair-modal-edit-link';
		editLink.className = 'button button-small dtb-wb-fallback-link';
		editLink.textContent = 'Edit post ↗';
		editLink.target    = '_blank';
		editLink.title     = 'Fallback: open raw post editor';

		var fallbackSpan = document.createElement( 'span' );
		fallbackSpan.className = 'dtb-wb-fallback-links';
		fallbackSpan.textContent = 'Fallback: ';
		fallbackSpan.appendChild( editLink );

		footerEl.appendChild( refreshBtn );
		footerEl.appendChild( fallbackSpan );

		modal.appendChild( header );
		modal.appendChild( body );
		modal.appendChild( footerEl );
		overlay.appendChild( modal );
		document.body.appendChild( overlay );

		// Click outside to close
		overlay.addEventListener( 'click', function ( e ) {
			if ( e.target === overlay ) { closeModal(); }
		} );
	}

	// ── Open / close ──────────────────────────────────────────────────────────

	function openRepair( repairId ) {
		buildDOM();
		state.repairId  = parseInt( repairId, 10 );
		state.activeTab = WB.getUrlParam( 'repair_tab' ) || 'overview';

		overlay.classList.add( 'dtb-modal-overlay--open' );
		document.body.classList.add( 'dtb-modal-open' );

		WB.replaceUrlParam( 'open_repair', String( state.repairId ) );
		WB.replaceUrlParam( 'repair_tab', state.activeTab );

		renderLoading();
		loadRepair( state.repairId );

		// Refresh every 3 minutes while open.
		clearInterval( state.refreshTimer );
		state.refreshTimer = setInterval( function () {
			if ( state.repairId ) { loadRepair( state.repairId ); }
		}, 180000 );
	}

	function closeModal() {
		if ( overlay ) {
			overlay.classList.remove( 'dtb-modal-overlay--open' );
		}
		document.body.classList.remove( 'dtb-modal-open' );
		clearInterval( state.refreshTimer );
		WB.clearUrlParam( 'open_repair' );
		WB.clearUrlParam( 'repair_tab' );
		state.repairId = null;
		state.data     = null;
	}

	// ── Fetch ─────────────────────────────────────────────────────────────────

	function loadRepair( repairId ) {
		if ( state.loading ) { return; }
		state.loading = true;
		setHeaderLoading( true );

		WB.apiFetch( REST + '/dtb/v1/admin/repairs/' + repairId + '/detail', {
			method: 'GET',
		} )
		.then( function ( data ) {
			state.data    = data;
			state.loading = false;
			setHeaderLoading( false );
			renderAll();
		} )
		.catch( function ( err ) {
			state.loading = false;
			setHeaderLoading( false );
			renderError( err && err.message ? err.message : 'Failed to load repair.' );
		} );
	}

	// ── Tabs ──────────────────────────────────────────────────────────────────

	function switchTab( tab ) {
		if ( ! TABS.includes( tab ) ) { tab = 'overview'; }
		if ( tab === 'actions' ) { tab = 'overview'; }
		state.activeTab = tab;

		tabBar.querySelectorAll( '.dtb-modal-tab' ).forEach( function ( btn ) {
			var active = btn.dataset.tab === tab;
			btn.classList.toggle( 'dtb-modal-tab--active', active );
			btn.setAttribute( 'aria-selected', active ? 'true' : 'false' );
		} );

		panelContainer.querySelectorAll( '.dtb-modal-tab-panel' ).forEach( function ( p ) {
			if ( p.dataset.panel === 'actions' ) {
				p.setAttribute( 'hidden', '' );
				return;
			}
			if ( p.dataset.panel === tab ) {
				p.removeAttribute( 'hidden' );
			} else {
				p.setAttribute( 'hidden', '' );
			}
		} );

		WB.replaceUrlParam( 'repair_tab', tab );
	}

	function getOrCreatePanel( tab ) {
		// Validate tab name against the known-safe TABS list before using in selector.
		if ( ! TABS.includes( tab ) ) { tab = 'overview'; }
		var existing = panelContainer.querySelector( '[data-panel="' + tab + '"]' );
		if ( existing ) { return existing; }
		var panel = document.createElement( 'div' );
		panel.className = 'dtb-modal-tab-panel';
		panel.dataset.panel = tab;
		if ( tab !== state.activeTab ) {
			panel.setAttribute( 'hidden', '' );
		}
		panelContainer.appendChild( panel );
		return panel;
	}

	// ── Header helpers ────────────────────────────────────────────────────────

	function setHeaderLoading( on ) {
		titleEl.textContent = on ? 'Loading…' : ( state.data ? 'Repair #' + state.data.record.id : 'Repair' );
	}

	function renderLoading() {
		panelContainer.innerHTML = '<div class="dtb-modal-loading">Loading repair…</div>';
	}

	function renderError( msg ) {
		panelContainer.innerHTML = '<div class="dtb-modal-error">' + WB.escapeHtml( msg ) + '</div>';
	}

	// ── Render all panels ─────────────────────────────────────────────────────

	function renderAll() {
		var d = state.data;
		if ( ! d || ! d.record ) { return; }

		// Update header
		titleEl.textContent = 'Repair #' + d.record.id + ' — ' + WB.escapeHtml( d.record.customer_name || '' );
		metaEl.innerHTML    = statusBadge( d.record.status );
		if ( heroEl ) {
			heroEl.innerHTML = renderModalHero( d );
		}

		// Edit link
		var editLink = document.getElementById( 'dtb-repair-modal-edit-link' );
		if ( editLink ) {
			editLink.href = ( CONFIG.adminUrl || '' ) + 'post.php?post=' + d.record.id + '&action=edit';
		}

		// Clear all panels to re-render fresh data.
		panelContainer.innerHTML = '';

		renderOverview( d );
		renderQuote( d );
		renderTechnician( d );
		renderConversation( d );
		renderTimeline( d );
		renderAllPanel( d );
		renderActions( d );

		// Activate correct tab.
		switchTab( state.activeTab );
	}

	// ── Compact record-issues helper (integration blockers only) ─────────────

	function renderRepairRecordIssues( integrations ) {
		var issues = [];
		Object.keys( integrations || {} ).forEach( function ( key ) {
			var svc = integrations[ key ] || {};
			if ( svc.status !== 'error' && svc.status !== 'failed' ) { return; }
			issues.push( svc.label || key );
		} );
		if ( ! issues.length ) { return ''; }
		var adminUrl = ( CONFIG.adminUrl || '/wp-admin/admin.php' ).replace( /admin\.php.*$/, 'admin.php' );
		var sysUrl   = adminUrl + '?page=dtb-system-manager';
		var chips    = issues.map( function ( label ) {
			return '<span class="dtb-wb-blocker-chip">' + WB.escapeHtml( label ) + '</span>';
		} ).join( ' ' );
		return '<div class="dtb-wb-record-issues">' + chips
		     + ' <a href="' + WB.escapeHtml( sysUrl ) + '" class="dtb-wb-record-issues__link">System Manager ↗</a></div>';
	}

	// ── Panel: Overview ───────────────────────────────────────────────────────

	function renderOverview( d ) {
		var panel = getOrCreatePanel( 'overview' );
		var r     = d.record;
		var media = d.media && Array.isArray( d.media.items ) ? d.media.items : [];
		var parts = getAllocatedParts( d );
		var sh = d.shipping || {};

		var html = '<div class="dtb-repair-modal-workspace-grid">';
		var issuesHtml = renderRepairRecordIssues( d.integrations || d.integration || {} );
		if ( issuesHtml ) {
			html += issuesHtml;
		}

		html += '<section class="dtb-repair-modal-card">';
		html += '<header><h3>Customer Submitted Repair Request</h3></header>';
		html += '<div class="dtb-repair-modal-card__body">';
		html += detailRows( [
			[ 'Submitted', r.submitted_at || r.created_at ],
			[ 'Source', r.source ],
			[ 'Status', statusLabel( r.status ) ],
			[ 'Priority', r.priority ],
			[ 'Service Tier', r.service_tier ],
			[ 'Package ID', r.package_id ],
			[ 'Woo Order ID', r.wc_order_id ],
		] );
		html += '<div class="dtb-repair-modal-issue"><span>Customer Issue</span><p>' + WB.escapeHtml( r.issue_description || '—' ) + '</p></div>';
		html += '</div></section>';

		html += '<section class="dtb-repair-modal-card">';
		html += '<header><h3>Tool / Repair Details</h3></header>';
		html += '<div class="dtb-repair-modal-card__body">';
		html += detailRows( [
			[ 'Brand', r.tool_brand ],
			[ 'Model', r.tool_model ],
			[ 'Category', r.tool_category ],
			[ 'Serial Number', r.serial_number ],
			[ 'Tool Age', r.tool_age ],
			[ 'Issue Started', r.issue_start ],
			[ 'Contact Preference', r.contact_preference ],
		] );
		html += '</div></section>';

		html += '<section class="dtb-repair-modal-card">';
		html += '<header><h3>Approval / Warranty Rules</h3></header>';
		html += '<div class="dtb-repair-modal-card__body">';
		html += detailRows( [
			[ 'Approval Mode', r.approval_mode ],
			[ 'Preapproval Limit', r.preapproval_limit ? WB.formatMoney( r.preapproval_limit ) : '' ],
			[ 'Warranty Requested', r.warranty_requested ],
			[ 'Purchase Date', r.purchase_date ],
			[ 'Old Parts Return', r.old_parts_return ],
		] );
		html += '</div></section>';

		html += '<aside class="dtb-repair-modal-side-stack">';
		html += '<section class="dtb-repair-modal-card"><header><h3>Customer</h3></header><div class="dtb-repair-modal-card__body">';
		html += detailRows( [
			[ 'Name', r.customer_name ],
			[ 'Email', r.customer_email ],
			[ 'Phone', r.customer_phone ],
			[ 'Company', r.company ],
		] );
		html += '</div></section>';
		html += '<section class="dtb-repair-modal-card"><header><h3>Customer Media</h3></header><div class="dtb-repair-modal-card__body">';
		html += renderMediaTiles( media );
		html += '</div></section>';
		html += '<section class="dtb-repair-modal-card"><header><h3>Allocated Parts</h3></header><div class="dtb-repair-modal-card__body">';
		html += renderPartsSummary( parts );
		html += '</div></section>';
		html += '<section class="dtb-repair-modal-card"><header><h3>Shipping</h3></header><div class="dtb-repair-modal-card__body">';
		html += detailRows( [
			[ 'Inbound Shipping Responsibility', sh.inbound_method === 'customer_pays_carrier' ? 'Customer arranges and pays carrier' : ( sh.inbound_method === 'local_pickup_dropoff' ? 'Free local pickup / drop-off' : ( sh.inbound_method ? 'Legacy record: ' + sh.inbound_method : '' ) ) ],
			[ 'Return Preference', sh.return_preference ],
			[ 'Return Address', formatAddress( sh.return_address ) ],
			[ 'Shipping Rate ID', sh.rate_id ],
			[ 'Rate Name', sh.rate_name ],
			[ 'Rate Price', sh.rate_price ? WB.formatMoney( sh.rate_price ) : '' ],
			[ 'Tracking Number', sh.tracking_number ],
			[ 'Veeqo Order ID', sh.veeqo_order_id ],
		] );
		html += '</div></section>';
		html += '</aside>';

		html += '</div>';

		panel.innerHTML = html;
	}

	function formatAddress( address ) {
		address = address || {};
		return [ address.line1, address.city, address.state, address.postcode, address.country ].filter( Boolean ).join( ', ' );
	}

	function detailRows( rows ) {
		var html = '<dl class="dtb-repair-modal-detail-list">';
		rows.forEach( function ( row ) {
			var value = row[1];
			if ( value === null || value === undefined || value === '' ) {
				value = '—';
			}
			html += '<div><dt>' + WB.escapeHtml( row[0] ) + '</dt><dd>' + WB.escapeHtml( value ) + '</dd></div>';
		} );
		html += '</dl>';
		return html;
	}

	function renderMediaTiles( media ) {
		if ( ! media || ! media.length ) {
			return '<p class="dtb-wb-empty">No customer media has been attached yet.</p>';
		}
		return '<div class="dtb-repair-modal-media-grid">' + media.map( function ( item ) {
			var label = item.filename || item.title || ( 'Attachment #' + item.id );
			var mime = String( item.mime_type || '' );
			var src = item.thumbnail || item.url || '';
			var isImage = mime.indexOf( 'image/' ) === 0 || /\.(png|jpe?g|gif|webp|avif|bmp|svg)$/i.test( src );
			return '<a href="' + WB.escapeHtml( item.full || item.url || '#' ) + '" target="_blank" rel="noopener">' +
				( isImage
					? '<img src="' + WB.escapeHtml( src ) + '" alt="' + WB.escapeHtml( item.alt || label ) + '">'
					: '<span class="dtb-repair-modal-file-thumb" aria-hidden="true">FILE</span>' ) +
				'<span>' + WB.escapeHtml( label ) + '</span>' +
			'</a>';
		} ).join( '' ) + '</div>';
	}

	function renderPartsSummary( parts ) {
		if ( ! parts || ! parts.length ) {
			return '<p class="dtb-wb-empty">No parts are allocated yet.</p>';
		}
		return '<div class="dtb-repair-modal-parts-list">' + parts.map( function ( part ) {
			return '<div><strong>' + WB.escapeHtml( part.sku || 'Unspecified SKU' ) + '</strong><span>Qty ' + WB.escapeHtml( part.qty || 1 ) + '</span><p>' + WB.escapeHtml( part.note || '' ) + '</p></div>';
		} ).join( '' ) + '</div>';
	}

	// ── Panel: Intake ─────────────────────────────────────────────────────────

	function renderIntake( d ) {
		var panel = getOrCreatePanel( 'intake' );
		var r     = d.record;

		panel.innerHTML = kvSection( 'Customer', [
			[ 'Name',    r.customer_name ],
			[ 'Email',   r.customer_email ],
			[ 'Phone',   r.customer_phone ],
			[ 'Company', r.company ],
		] ) + kvSection( 'Tool', [
			[ 'Brand',       r.tool_brand ],
			[ 'Category',    r.tool_category ],
			[ 'Model',       r.tool_model ],
			[ 'Serial',      r.serial_number ],
			[ 'Tool age',    r.tool_age ],
		] ) + kvSection( 'Issue', [
			[ 'Issue start',   r.issue_start ],
			[ 'Description',   r.issue_description ],
			[ 'Contact pref',  r.contact_preference ],
		] );
	}

	// ── Panel: Quote ──────────────────────────────────────────────────────────

	function renderQuote( d ) {
		var panel = getOrCreatePanel( 'quote' );
		var q     = d.quote || {};
		var perms = d.permissions || {};
		var html  = '';

		if ( ! Object.keys( q ).length ) {
			html = '<p style="color:#64748b;padding:1rem">No quote on file.</p>';
		} else {
			html += kvSection( 'Quote', [
				[ 'Status',     q.status ],
				[ 'Labour',     WB.formatMoney( q.labour_total || 0 ) ],
				[ 'Parts',      WB.formatMoney( q.parts_total  || 0 ) ],
				[ 'Shipping',   WB.formatMoney( q.shipping_total || 0 ) ],
				[ 'Total',      WB.formatMoney( q.grand_total  || 0 ) ],
				[ 'Valid until', WB.formatDate( q.valid_until ) ],
				[ 'Notes',      q.notes ],
			] );
		}

		if ( perms.can_edit_quote ) {
			html += '<div class="dtb-wb-command-bar">';
			html += actionButton( 'Save draft', 'dtb-repair-quote-save', { repairId: d.record.id } );
			html += actionButton( 'Send to customer', 'dtb-repair-quote-send', { repairId: d.record.id }, 'button-primary' );
			html += '</div>';
		}

		panel.innerHTML = html;

		if ( perms.can_edit_quote ) {
			bindActionButton( panel, 'dtb-repair-quote-save', function () {
				doAction( d.record.id, 'quote/save', {} );
			} );
			bindActionButton( panel, 'dtb-repair-quote-send', function () {
				WB.confirmDanger( 'Send this quote to the customer?', function () {
					doAction( d.record.id, 'quote/send', {} );
				} );
			} );
		}
	}

	// ── Panel: Parts ──────────────────────────────────────────────────────────

	function getAllocatedParts( d ) {
		var partsState = d.parts || {};
		if ( Array.isArray( partsState.allocated ) ) {
			return partsState.allocated;
		}
		if ( Array.isArray( d.parts ) ) {
			return d.parts;
		}
		return [];
	}

	function partsToInput( parts ) {
		return parts.map( function ( part ) {
			return [
				part.sku || '',
				part.qty || 1,
				part.note || '',
			].join( ', ' );
		} ).join( '\n' );
	}

	function parsePartsInput( value ) {
		return String( value || '' ).split( /\n+/ ).map( function ( line ) {
			var cols = line.split( ',' );
			return {
				sku:  ( cols[0] || '' ).trim(),
				qty:  parseInt( ( cols[1] || '1' ).trim(), 10 ) || 1,
				note: ( cols.slice( 2 ).join( ',' ) || '' ).trim(),
			};
		} ).filter( function ( part ) {
			return !! part.sku;
		} );
	}

	function renderParts( d ) {
		var panel = getOrCreatePanel( 'parts' );
		var perms = d.permissions || {};
		var parts = getAllocatedParts( d );
		var html = '<div class="dtb-wb-section" style="padding:1rem">';
		html += '<h3 class="dtb-wb-section__title">Allocated parts</h3>';
		if ( parts.length ) {
			html += '<table class="widefat striped dtb-repair-parts-table"><thead><tr><th>SKU</th><th>Qty</th><th>Note</th></tr></thead><tbody>';
			parts.forEach( function ( part ) {
				html += '<tr><td>' + WB.escapeHtml( part.sku || '' ) + '</td><td>' + WB.escapeHtml( part.qty || 1 ) + '</td><td>' + WB.escapeHtml( part.note || '' ) + '</td></tr>';
			} );
			html += '</tbody></table>';
		} else {
			html += '<p class="dtb-wb-empty">No parts are allocated yet.</p>';
		}
		html += '</div>';
		if ( perms.can_allocate_parts ) {
			html += '<div class="dtb-wb-section dtb-repair-parts-editor" style="padding:1rem">';
			html += '<h3 class="dtb-wb-section__title">Allocation editor</h3>';
			html += '<label class="screen-reader-text" for="dtb-repair-parts-input">Parts list</label>';
			html += '<textarea id="dtb-repair-parts-input" class="widefat" rows="5" placeholder="SKU, qty, note">' + WB.escapeHtml( partsToInput( parts ) ) + '</textarea>';
			html += '<p class="description">One part per line: SKU, quantity, note.</p>';
			html += '<div class="dtb-wb-command-bar">';
			html += actionButton( 'Allocate parts', 'dtb-repair-parts-allocate', { repairId: d.record.id }, 'button-primary' );
			html += '</div>';
			html += '</div>';
		}
		panel.innerHTML = html;

		if ( perms.can_allocate_parts ) {
			bindActionButton( panel, 'dtb-repair-parts-allocate', function () {
				var input = panel.querySelector( '#dtb-repair-parts-input' );
				doAction( d.record.id, 'parts/allocate', { parts: parsePartsInput( input ? input.value : '' ) } );
			} );
		}
	}

	// ── Panel: Technician ─────────────────────────────────────────────────────

	function renderTechnician( d ) {
		var panel = getOrCreatePanel( 'technician' );
		var r     = d.record;
		var perms = d.permissions || {};
		var html = kvSection( 'Assignment', [
			[ 'Technician ID', r.technician_id ? String( r.technician_id ) : 'Unassigned' ],
		] );

		if ( perms.can_assign_technician ) {
			html += '<div class="dtb-wb-section" style="padding:1rem">';
			html += '<h3 class="dtb-wb-section__title">Assign technician</h3>';
			html += '<div class="dtb-wb-inline-form">';
			html += '<input id="dtb-repair-technician-id" type="number" min="0" class="regular-text" value="' + WB.escapeHtml( r.technician_id || '' ) + '" placeholder="User ID">';
			html += actionButton( 'Save assignment', 'dtb-repair-technician-save', {}, 'button-primary' );
			html += '</div>';
			html += '</div>';
		}

		panel.innerHTML = html;

		if ( perms.can_assign_technician ) {
			bindActionButton( panel, 'dtb-repair-technician-save', function () {
				var input = panel.querySelector( '#dtb-repair-technician-id' );
				doAction( d.record.id, 'technician/assign', {
					technician_id: input ? input.value : 0,
				} );
			} );
		}
	}

	// ── Panel: Conversation ───────────────────────────────────────────────────

	function renderConversation( d ) {
		var panel     = getOrCreatePanel( 'conversation' );
		var msgs      = d.conversation || [];
		var perms     = d.permissions  || {};
		var html      = '';

		if ( ! msgs.length ) {
			html = '<p style="color:#64748b;padding:1rem">No messages yet.</p>';
		} else {
			html += '<div class="dtb-wb-thread" style="padding:1rem">';
			msgs.forEach( function ( m ) {
				var cls = 'dtb-wb-message--' + ( m.type === 'internal' ? 'internal' : ( m.type === 'staff' ? 'staff' : 'customer' ) );
				html += '<div class="dtb-wb-message ' + cls + '">';
				html += '<p>' + WB.escapeHtml( m.body ) + '</p>';
				html += '<p class="dtb-wb-message__meta">' + WB.escapeHtml( m.user_label ) + ' &middot; ' + WB.formatDate( m.created_at ) + '</p>';
				html += '</div>';
			} );
			html += '</div>';
		}

		if ( perms.can_message ) {
			html += '<div style="padding:1rem">';
			html += '<textarea id="dtb-repair-msg-body" class="widefat" rows="3" placeholder="Reply to customer…"></textarea>';
			html += '<div class="dtb-wb-command-bar" style="padding:.5rem 0 0">';
			html += actionButton( 'Send', 'dtb-repair-msg-send', {}, 'button-primary' );
			html += actionButton( 'Add internal note', 'dtb-repair-note-add', {} );
			html += '</div>';
			html += '</div>';
		}

		panel.innerHTML = html;

		if ( perms.can_message ) {
			bindActionButton( panel, 'dtb-repair-msg-send', function () {
				var ta  = panel.querySelector( '#dtb-repair-msg-body' );
				var txt = ta ? ta.value.trim() : '';
				if ( ! txt ) { WB.showToast( 'Message body is empty.', 'warning' ); return; }
				doAction( d.record.id, 'customer-message', { body: txt } )
					.then( function () { if ( ta ) { ta.value = ''; } } );
			} );
			bindActionButton( panel, 'dtb-repair-note-add', function () {
				var ta  = panel.querySelector( '#dtb-repair-msg-body' );
				var txt = ta ? ta.value.trim() : '';
				if ( ! txt ) { WB.showToast( 'Note body is empty.', 'warning' ); return; }
				doAction( d.record.id, 'internal-note', { body: txt } )
					.then( function () { if ( ta ) { ta.value = ''; } } );
			} );
		}
	}

	// ── Panel: Timeline ───────────────────────────────────────────────────────

	function renderTimeline( d ) {
		var panel  = getOrCreatePanel( 'timeline' );
		var events = d.timeline || d.audit || [];
		panel.innerHTML = WB.renderTimeline ? WB.renderTimeline( events ) : '';
	}

	// ── Panel: Shipping ───────────────────────────────────────────────────────

	function hasIntegrationWarnings( integrations ) {
		var text = JSON.stringify( integrations || {} ).toLowerCase();
		return /failed|error|blocked|degraded|stale/.test( text );
	}

	function renderChecklist( items ) {
		var html = '<ul class="dtb-wb-checklist">';
		items.forEach( function ( item ) {
			html += '<li class="dtb-wb-checklist__item ' + ( item.done ? 'is-done' : 'is-open' ) + '">';
			html += '<span class="dtb-wb-checklist__mark">' + ( item.done ? '✓' : '!' ) + '</span>';
			html += '<span>' + WB.escapeHtml( item.label ) + '</span>';
			html += '</li>';
		} );
		html += '</ul>';
		return html;
	}

	function repairCloseoutChecklist( d ) {
		var r = d.record || {};
		var sh = d.shipping || {};
		var quote = d.quote || {};
		var statusesPastParts = [ 'parts_allocated', 'in_progress', 'ready_to_ship', 'closed', 'resolved' ];
		var statusesPastWork = [ 'ready_to_ship', 'closed', 'resolved' ];
		var parts = getAllocatedParts( d );
		var messages = d.conversation || [];
		var integrations = d.integrations || d.integration || {};

		return [
			{
				label: 'Customer has been messaged from the repair thread',
				done: messages.some( function ( msg ) { return msg.type === 'staff' || msg.type === 'customer'; } ),
			},
			{
				label: 'Quote is not waiting on a draft/send step',
				done: !! quote.sent_at || !! quote.accepted_at || [ 'quote_accepted', 'parts_allocated', 'in_progress', 'ready_to_ship', 'closed', 'resolved' ].indexOf( r.status ) !== -1,
			},
			{
				label: 'Parts are allocated or the repair status is past parts allocation',
				done: parts.length > 0 || statusesPastParts.indexOf( r.status ) !== -1,
			},
			{
				label: 'Repair work is complete or ready to ship',
				done: statusesPastWork.indexOf( r.status ) !== -1,
			},
			{
				label: 'Tracking or Veeqo order reference is present',
				done: !! ( sh.tracking_number || sh.veeqo_order_id ),
			},
			{
				label: 'No integration warnings are currently visible',
				done: ! hasIntegrationWarnings( integrations ),
			},
		];
	}

	function renderShipping( d ) {
		var panel = getOrCreatePanel( 'shipping' );
		var sh    = d.shipping || {};
		var addr  = sh.return_address || {};
		var perms = d.permissions || {};
		var r     = d.record;

		var html = kvSection( 'Return address', [
			[ 'Line 1',   addr.line1 ],
			[ 'City',     addr.city ],
			[ 'State',    addr.state ],
			[ 'Postcode', addr.postcode ],
			[ 'Country',  addr.country ],
		] ) + kvSection( 'Shipping', [
			[ 'Rate name',      sh.rate_name ],
			[ 'Rate price',     WB.formatMoney( sh.rate_price || 0 ) ],
			[ 'Tracking #',     sh.tracking_number || '—' ],
			[ 'Veeqo order ID', sh.veeqo_order_id || '—' ],
		] );
		html += '<div class="dtb-wb-section" style="padding:1rem">';
		html += '<h3 class="dtb-wb-section__title">Shipping readiness</h3>';
		html += renderChecklist( repairCloseoutChecklist( d ) );
		html += '</div>';

		var canShip = perms.can_transition && r.status === 'in_progress';
		if ( canShip ) {
			html += '<div style="padding:1rem">';
			html += '<label style="display:block;font-size:.8125rem;margin-bottom:.25rem">Tracking number</label>';
			html += '<input id="dtb-repair-tracking" type="text" class="regular-text" value="' + WB.escapeHtml( sh.tracking_number || '' ) + '">';
			html += '<label style="display:block;font-size:.8125rem;margin:.75rem 0 .25rem">Veeqo order ID</label>';
			html += '<input id="dtb-repair-veeqo-order-id" type="text" class="regular-text" value="' + WB.escapeHtml( sh.veeqo_order_id || '' ) + '">';
			html += '</div>';
			html += '<div class="dtb-wb-command-bar">';
			html += actionButton( 'Mark ready to ship', 'dtb-repair-ready-ship', {}, 'button-primary' );
			html += '</div>';
		}

		panel.innerHTML = html;

		if ( canShip ) {
			bindActionButton( panel, 'dtb-repair-ready-ship', function () {
				var tin = panel.querySelector( '#dtb-repair-tracking' );
				var vin = panel.querySelector( '#dtb-repair-veeqo-order-id' );
				doAction( d.record.id, 'ready-to-ship', {
					tracking_number: tin ? tin.value.trim() : '',
					veeqo_order_id: vin ? vin.value.trim() : '',
				} );
			} );
		}
	}

	function statusLabel( status ) {
		return String( status || '' ).replace( /_/g, ' ' );
	}

	function formatIntegrationState( value ) {
		var state = value && typeof value === 'object' ? ( value.state || value.status || 'pending' ) : ( value || 'pending' );
		return String( state ).replace( /_/g, ' ' );
	}

	function integrationPill( label, state, href, hrefText ) {
		var stateLabel = formatIntegrationState( state );
		var cls = stateLabel.replace( /\s+/g, '-' ).toLowerCase();
		return '<span class="dtb-repair-modal-int-pill dtb-repair-modal-int-pill--' + WB.escapeHtml( cls ) + '">' +
			'<strong>' + WB.escapeHtml( label ) + '</strong><i aria-hidden="true"></i>' +
			( href ? '<a href="' + WB.escapeHtml( href ) + '" target="_blank" rel="noopener">' + WB.escapeHtml( hrefText || stateLabel ) + '</a>' : '<span>' + WB.escapeHtml( stateLabel ) + '</span>' ) +
		'</span>';
	}

	function renderModalHero( d ) {
		var r = d.record || {};
		var integrations = d.integrations || d.integration || {};
		var tool = [ r.tool_brand, r.tool_model || r.tool_category ].filter( Boolean ).join( ' — ' );
		var wcHref = r.wc_order_id ? ( CONFIG.adminUrl || '' ) + 'post.php?post=' + r.wc_order_id + '&action=edit' : '';
		var allowed = ( d.workflow && Array.isArray( d.workflow.allowed_transitions ) ) ? d.workflow.allowed_transitions : ( r.allowed_next || [] );
		var progress = repairProgress( r.status );

		return '<div class="dtb-repair-modal-hero__left">' +
			'<div class="dtb-repair-modal-hero__id">Repair #' + WB.escapeHtml( r.id || '' ) + '</div>' +
			'<h3>' + WB.escapeHtml( r.customer_name || '(No customer name)' ) + '</h3>' +
			'<div class="dtb-repair-modal-hero__status">' + statusBadge( r.status ) + '</div>' +
			'<div class="dtb-repair-modal-int-row">' +
				integrationPill( 'WooCommerce', integrations.woocommerce || ( r.wc_order_id ? 'synced' : 'pending' ), wcHref, r.wc_order_id ? '#' + r.wc_order_id + ' →' : '' ) +
				integrationPill( 'Veeqo', integrations.veeqo || 'pending' ) +
				integrationPill( 'QuickBooks', integrations.quickbooks || 'pending' ) +
			'</div>' +
			'<div class="dtb-repair-modal-hero__meta">' +
				( r.customer_email ? '<span><b>✉</b>' + WB.escapeHtml( r.customer_email ) + '</span>' : '' ) +
				( r.customer_phone ? '<span><b>☎</b>' + WB.escapeHtml( r.customer_phone ) + '</span>' : '' ) +
				( tool ? '<span><b>↗</b>' + WB.escapeHtml( tool ) + '</span>' : '' ) +
				( r.service_tier ? '<span><b>★</b>' + WB.escapeHtml( r.service_tier ) + '</span>' : '' ) +
				( r.created_at ? '<span><b>▣</b>Submitted ' + WB.escapeHtml( WB.formatDate ? WB.formatDate( r.created_at ) : r.created_at ) + '</span>' : '' ) +
			'</div>' +
		'</div>' +
		'<aside class="dtb-repair-modal-hero__workflow">' +
			'<div class="dtb-repair-modal-workflow-card">' +
				'<div class="dtb-repair-modal-workflow-card__top"><span>Current Status</span>' + statusBadge( r.status ) + '</div>' +
				'<div class="dtb-repair-modal-track"><i style="width:' + WB.escapeHtml( progress ) + '%"></i></div>' +
				'<div class="dtb-repair-modal-milestones">' +
					renderMilestone( 'Submitted', progress >= 8, progress < 28 ) +
					renderMilestone( 'In Progress', progress >= 28, progress >= 28 && progress < 88 ) +
					renderMilestone( 'Ready to Ship', progress >= 88, progress >= 88 && progress < 100 ) +
					renderMilestone( 'Completed', progress >= 100, progress >= 100 ) +
				'</div>' +
				'<p>' + ( allowed.length ? WB.escapeHtml( allowed.length + ' workflow transition' + ( allowed.length === 1 ? '' : 's' ) + ' available from ' + statusLabel( r.status ) + '.' ) : 'No transitions available from this status.' ) + '</p>' +
			'</div>' +
		'</aside>';
	}

	function renderMilestone( label, done, active ) {
		return '<span class="' + ( active ? 'is-active' : ( done ? 'is-done' : '' ) ) + '"><i></i>' + WB.escapeHtml( label ) + '</span>';
	}

	function repairProgress( status ) {
		var map = {
			submitted: 8,
			reviewed: 16,
			awaiting_customer: 20,
			approved: 28,
			quoted: 35,
			quote_accepted: 42,
			parts_allocated: 55,
			in_progress: 70,
			ready_to_ship: 88,
			completed: 100,
			closed: 100,
			cancelled: 100,
			quote_declined: 100,
		};
		return map[ status ] || 8;
	}

	function renderAllPanel( d ) {
		var panel = getOrCreatePanel( 'all' );
		var r = d.record || {};
		var q = d.quote || {};
		var parts = getAllocatedParts( d );
		var events = d.timeline || d.audit || [];
		var messages = d.conversation || [];
		var shipping = d.shipping || {};

		var html = '<div class="dtb-repair-modal-all-grid">';
		html += '<section class="dtb-repair-modal-card"><header><h3>Repair Order Details</h3></header><div class="dtb-repair-modal-card__body">' +
			detailRows( [
				[ 'Brand', r.tool_brand ],
				[ 'Model', r.tool_model ],
				[ 'Serial Number', r.serial_number ],
				[ 'Service Tier', r.service_tier ],
				[ 'Priority', r.priority ],
				[ 'Status', statusLabel( r.status ) ],
			] ) +
			'<div class="dtb-repair-modal-issue"><span>Customer Issue</span><p>' + WB.escapeHtml( r.issue_description || '—' ) + '</p></div>' +
		'</div></section>';
		html += '<section class="dtb-repair-modal-card"><header><h3>Quote Summary</h3></header><div class="dtb-repair-modal-card__body">' +
			detailRows( [
				[ 'Status', q.status || 'draft' ],
				[ 'Lines', Array.isArray( q.lines ) ? q.lines.length : 0 ],
				[ 'Subtotal', q.totals && q.totals.subtotal ? WB.formatMoney( q.totals.subtotal ) : '$0.00' ],
				[ 'Total', q.totals && ( q.totals.total || q.totals.grand_total ) ? WB.formatMoney( q.totals.total || q.totals.grand_total ) : '$0.00' ],
			] ) +
		'</div></section>';
		html += '<section class="dtb-repair-modal-card"><header><h3>Parts / Shipping</h3></header><div class="dtb-repair-modal-card__body">' +
			detailRows( [
				[ 'Allocated Parts', parts.length ],
				[ 'Tracking Number', shipping.tracking_number ],
				[ 'Veeqo Order ID', shipping.veeqo_order_id ],
				[ 'Return Preference', shipping.return_preference ],
			] ) +
		'</div></section>';
		html += '<section class="dtb-repair-modal-card"><header><h3>Recent Timeline</h3></header><div class="dtb-repair-modal-card__body">' +
			( events.length && WB.renderTimeline ? WB.renderTimeline( events.slice( -8 ) ) : '<p class="dtb-wb-empty">No timeline events yet.</p>' ) +
		'</div></section>';
		html += '<section class="dtb-repair-modal-card"><header><h3>Notes / Conversation</h3></header><div class="dtb-repair-modal-card__body">' +
			( messages.length ? messages.slice( -5 ).map( function ( msg ) {
				return '<article class="dtb-repair-modal-note"><p>' + WB.escapeHtml( msg.body || '' ) + '</p><small>' + WB.escapeHtml( msg.user_label || msg.author || 'Unknown' ) + ' · ' + WB.escapeHtml( WB.formatDate ? WB.formatDate( msg.created_at ) : msg.created_at || '' ) + '</small></article>';
			} ).join( '' ) : '<p class="dtb-wb-empty">No notes or customer conversation yet.</p>' ) +
		'</div></section>';
		html += '</div>';
		panel.innerHTML = html;
	}

	function repairNextActionFallback( d ) {
		var r = d.record || {};
		var media = d.media || {};
		var quote = d.quote || {};
		var parts = d.parts || {};
		if ( ! media.count ) {
			return 'Request customer photos before diagnosis.';
		}
		if ( r.status === 'submitted' ) {
			return 'Review intake details and mark reviewed.';
		}
		if ( quote.status === 'draft' && [ 'reviewed', 'approved', 'quoted' ].indexOf( r.status ) !== -1 ) {
			return 'Finalize and send the repair quote.';
		}
		if ( r.status === 'quote_accepted' && ! parts.count ) {
			return 'Allocate parts before technician work starts.';
		}
		if ( r.status === 'in_progress' ) {
			return 'Record work outcome and prepare shipping handoff.';
		}
		return 'Use the next valid workflow transition.';
	}

	function repairTransitionHint( status ) {
		var hints = {
			reviewed: 'Intake has enough detail for diagnosis or quoting.',
			awaiting_customer: 'Pause the queue while customer info/photos are pending.',
			approved: 'Repair is accepted for quote/work preparation.',
			quoted: 'Quote is prepared and ready for customer decision.',
			quote_accepted: 'Customer approved the quote and work can proceed.',
			parts_allocated: 'Required parts are reserved for the technician.',
			in_progress: 'Technician work is actively underway.',
			ready_to_ship: 'Repair work is complete and shipping needs handoff.',
			cancelled: 'Stop the repair and remove it from active workload.',
			closed: 'Repair is fully complete with no remaining staff action.',
		};
		return hints[ status ] || 'Move this repair to ' + String( status || 'the selected status' ).replace( /_/g, ' ' ) + '.';
	}

	// ── Panel: Actions ────────────────────────────────────────────────────────

	function renderActions( d ) {
		var panel  = getOrCreatePanel( 'actions' );
		var r      = d.record;
		var perms  = d.permissions || {};
		var intel  = d.intelligence || d.intel || {};
		var quote  = d.quote || {};
		var parts  = d.parts || {};
		var media  = d.media || {};
		var blockers = Array.isArray( intel.blockers ) ? intel.blockers : [];
		var html   = '';

		html += '<div class="dtb-wb-section" style="padding:1rem">';
		html += '<h3 class="dtb-wb-section__title">Action intelligence</h3>';
		html += '<div class="dtb-repair-action-brief">';
		html += '<p><strong>Recommended:</strong> ' + WB.escapeHtml( intel.next_best_action || repairNextActionFallback( d ) ) + '</p>';
		html += '<p><strong>Signals:</strong> ' + WB.escapeHtml( [
			'SLA ' + ( intel.sla_state || 'unknown' ),
			'media ' + ( media.count || 0 ),
			'quote ' + ( quote.status || 'draft' ),
			'parts ' + ( parts.count || 0 ),
		].join( ' · ' ) ) + '</p>';
		html += '</div>';
		if ( blockers.length ) {
			html += '<div class="dtb-repair-action-blockers">';
			blockers.forEach( function ( blocker ) {
				html += '<p>' + WB.escapeHtml( blocker ) + '</p>';
			} );
			html += '</div>';
		}
		html += '</div>';

		// Status transitions
		var allowed = ( d.workflow && Array.isArray( d.workflow.allowed_transitions ) ) ? d.workflow.allowed_transitions : ( r.allowed_next || [] );
		if ( perms.can_transition && allowed.length ) {
			html += '<div class="dtb-wb-section" style="padding:1rem">';
			html += '<h3 class="dtb-wb-section__title">Transition status</h3>';
			html += '<div class="dtb-repair-transition-grid">';
			allowed.forEach( function ( nextStatus ) {
				html += '<button type="button" class="button dtb-repair-transition-btn" data-status="' + WB.escapeHtml( nextStatus ) + '">';
				html += '<strong>' + WB.escapeHtml( nextStatus.replace( /_/g, ' ' ) ) + '</strong>';
				html += '<span>' + WB.escapeHtml( repairTransitionHint( nextStatus ) ) + '</span>';
				html += '</button>';
			} );
			html += '</div>';
			html += '</div>';
		}

		// Close
		if ( perms.can_close && ! r.is_terminal ) {
			html += '<div class="dtb-wb-section" style="padding:0 1rem 1rem">';
			html += '<h3 class="dtb-wb-section__title">Close repair</h3>';
			html += renderChecklist( repairCloseoutChecklist( d ) );
			html += '<textarea id="dtb-repair-close-note" class="widefat" rows="2" placeholder="Closing note (optional)"></textarea>';
			html += '<div class="dtb-wb-command-bar" style="padding:.5rem 0 0">';
			html += actionButton( 'Close repair', 'dtb-repair-close', {}, 'button button-link-delete' );
			html += '</div>';
			html += '</div>';
		}

		panel.innerHTML = html;

		// Bind transition buttons
		panel.querySelectorAll( '.dtb-repair-transition-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var toStatus = btn.dataset.status;
				WB.confirmDanger( 'Transition to "' + toStatus + '"?', function () {
					doAction( d.record.id, 'transition', { to_status: toStatus } );
				} );
			} );
		} );

		if ( perms.can_close && ! r.is_terminal ) {
			bindActionButton( panel, 'dtb-repair-close', function () {
				var noteEl = panel.querySelector( '#dtb-repair-close-note' );
				WB.confirmDanger( 'Close this repair?', function () {
					doAction( d.record.id, 'close', { note: noteEl ? noteEl.value.trim() : '' } );
				} );
			} );
		}
	}

	// ── Action helper ─────────────────────────────────────────────────────────

	function doAction( repairId, actionPath, payload ) {
		var url = REST + '/dtb/v1/admin/repairs/' + repairId + '/' + actionPath;
		setHeaderLoading( true );

		return WB.apiFetch( url, {
			method: 'POST',
			body:   JSON.stringify( payload ),
		} )
		.then( function ( data ) {
			state.data = data;
			setHeaderLoading( false );
			renderAll();
			WB.showToast( 'Updated.', 'success' );
			return data;
		} )
		.catch( function ( err ) {
			setHeaderLoading( false );
			WB.showToast( ( err && err.message ) ? err.message : 'Action failed.', 'error' );
			return Promise.reject( err );
		} );
	}

	// ── Utilities ─────────────────────────────────────────────────────────────

	function kv( label, value ) {
		return '<div class="dtb-wb-kv"><span class="dtb-wb-kv__label">' + WB.escapeHtml( label ) + '</span><span class="dtb-wb-kv__value">' + ( value || '—' ) + '</span></div>';
	}

	function kvSection( title, pairs ) {
		var html = '<div class="dtb-wb-section" style="padding:1rem">';
		html += '<h3 class="dtb-wb-section__title">' + WB.escapeHtml( title ) + '</h3>';
		pairs.forEach( function ( p ) {
			html += kv( p[0], WB.escapeHtml( p[1] || '' ) );
		} );
		html += '</div>';
		return html;
	}

	function statusBadge( status ) {
		var cls = statusBadgeClass( status );
		return '<span class="dtb-wb-badge dtb-wb-badge--' + cls + '">' + WB.escapeHtml( ( status || '' ).replace( /_/g, ' ' ) ) + '</span>';
	}

	function statusBadgeClass( status ) {
		var map = {
			submitted: 'info', reviewed: 'info', awaiting_customer: 'warning',
			approved: 'ok', quoted: 'warning', quote_accepted: 'ok',
			parts_allocated: 'ok', in_progress: 'ok', ready_to_ship: 'warning',
			completed: 'muted', closed: 'muted', cancelled: 'muted', quote_declined: 'breach',
		};
		return map[ status ] || 'muted';
	}

	function slaBadgeClass( state ) {
		var map = { ok: 'ok', warning: 'warning', breach: 'breach' };
		return map[ state ] || 'muted';
	}

	function linkedRecordChip( rec ) {
		var label = WB.escapeHtml( rec.module + ' #' + rec.id );
		if ( rec.url ) {
			return '<a href="' + WB.escapeHtml( rec.url ) + '" target="_blank">' + label + '</a>'
				 + ( rec.confidence === 'orphaned' ? ' <em style="color:#dc2626">(orphaned)</em>' : '' );
		}
		return label;
	}

	function actionButton( label, id, dataset, extraClass ) {
		var cls = 'button ' + ( extraClass || '' );
		return '<button type="button" class="' + WB.escapeHtml( cls.trim() ) + '" id="' + WB.escapeHtml( id ) + '">' + WB.escapeHtml( label ) + '</button>';
	}

	function bindActionButton( panel, id, fn ) {
		var btn = panel.querySelector( '#' + id );
		if ( btn ) { btn.addEventListener( 'click', fn ); }
	}

	// ── Queue click delegation ────────────────────────────────────────────────

	function initQueueDelegation() {
		document.addEventListener( 'click', function ( e ) {
			var row = e.target.closest( '[data-dtb-open-repair]' );
			if ( ! row ) { return; }
			if ( e.target.closest( 'a,button,input,select,textarea,label' ) ) { return; }
			e.preventDefault();
			openRepair( row.dataset.dtbOpenRepair );
		} );
	}

	// ── Keyboard ─────────────────────────────────────────────────────────────

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && overlay && overlay.classList.contains( 'dtb-modal-overlay--open' ) ) {
			closeModal();
		}
	} );

	// ── Deep-link init ────────────────────────────────────────────────────────

	function initDeepLink() {
		var params    = new URLSearchParams( window.location.search );
		var openId    = params.get( 'open_repair' );
		var activeTab = params.get( 'repair_tab' );
		if ( openId && parseInt( openId, 10 ) > 0 ) {
			if ( activeTab && TABS.includes( activeTab ) ) {
				state.activeTab = activeTab;
			}
			openRepair( openId );
		}
	}

	// ── Boot ──────────────────────────────────────────────────────────────────

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( ! window.DtbWorkbench ) {
			console.warn( 'DTB Repairs: DtbWorkbench not loaded.' );
			return;
		}
		initQueueDelegation();
		initDeepLink();
	} );

	// Expose for external triggers (e.g. other modules linking to a repair).
	window.DtbRepairs = { open: openRepair, close: closeModal };
} )();
