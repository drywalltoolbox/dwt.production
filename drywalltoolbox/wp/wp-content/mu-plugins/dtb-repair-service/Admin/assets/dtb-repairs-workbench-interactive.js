/* global DtbWorkbench, dtbAdminConfig */
/**
 * DTB Repairs — interactive workbench refinement.
 *
 * The base repairs modal still owns open/close, tab routing, and detail fetches.
 * This progressive layer replaces thin/static tab panels with operational tools
 * that persist through REST and refresh in-place.
 */
( function () {
	'use strict';

	var WB = window.DtbWorkbench || {};
	var CONFIG = window.dtbAdminConfig || {};
	var REST = ( CONFIG.restUrl || '/wp-json' ).replace( /\/$/, '' );
	var state = {
		repairId: 0,
		payload: null,
		technicians: null,
		isRendering: false,
		lastSignature: '',
	};

	function qs( selector, ctx ) {
		return ( ctx || document ).querySelector( selector );
	}

	function qsa( selector, ctx ) {
		return Array.prototype.slice.call( ( ctx || document ).querySelectorAll( selector ) );
	}

	function esc( value ) {
		if ( WB.escapeHtml ) {
			return WB.escapeHtml( value );
		}
		var div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( String( value == null ? '' : value ) ) );
		return div.innerHTML;
	}

	function fmtMoney( value ) {
		if ( WB.formatMoney ) {
			return WB.formatMoney( value || 0 );
		}
		return '$' + ( Number( value || 0 ).toFixed( 2 ) );
	}

	function fmtDate( value ) {
		return WB.formatDate ? WB.formatDate( value ) : ( value || '—' );
	}

	function toast( message, type ) {
		if ( WB.showToast ) {
			WB.showToast( message, type || 'success' );
		}
	}

	function getModal() {
		return qs( '#dtb-repair-modal' );
	}

	function getOpenRepairId() {
		var params = new URLSearchParams( window.location.search );
		var id = parseInt( params.get( 'open_repair' ) || '', 10 );
		if ( id > 0 ) {
			return id;
		}
		var title = qs( '#dtb-repair-modal-title' );
		var match = title && String( title.textContent || '' ).match( /#(\d+)/ );
		return match ? parseInt( match[1], 10 ) : 0;
	}

	function panel( name ) {
		var modal = getModal();
		return modal ? qs( '[data-panel="' + name + '"]', modal ) : null;
	}

	function apiFetch( url, options ) {
		if ( WB.apiFetch ) {
			return WB.apiFetch( url, options || {} );
		}
		options = options || {};
		options.headers = Object.assign( {
			'Content-Type': 'application/json',
			Accept: 'application/json',
			'X-WP-Nonce': CONFIG.nonce || '',
		}, options.headers || {} );
		options.credentials = 'same-origin';
		return fetch( url, options ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					throw new Error( data && data.message ? data.message : 'HTTP ' + response.status );
				}
				return data;
			} );
		} );
	}

	function fetchDetail( repairId ) {
		return apiFetch( REST + '/dtb/v1/admin/repairs/' + repairId + '/detail', { method: 'GET' } );
	}

	function workbenchAction( actionType, payload ) {
		payload = payload || {};
		payload.action_type = actionType;
		return apiFetch( REST + '/dtb/v1/admin/repairs/' + state.repairId + '/workbench', {
			method: 'POST',
			body: JSON.stringify( payload ),
		} ).then( updatePayload );
	}

	function legacyAction( path, payload ) {
		return apiFetch( REST + '/dtb/v1/admin/repairs/' + state.repairId + '/' + path, {
			method: 'POST',
			body: JSON.stringify( payload || {} ),
		} ).then( updatePayload );
	}

	function updatePayload( payload ) {
		state.payload = payload;
		renderInteractivePanels();
		return payload;
	}

	function ensureDetailLoaded() {
		var modal = getModal();
		if ( ! modal || ! modal.closest( '.dtb-modal-overlay--open' ) ) {
			return;
		}
		var repairId = getOpenRepairId();
		if ( ! repairId ) {
			return;
		}
		if ( repairId === state.repairId && state.payload ) {
			renderInteractivePanels();
			return;
		}
		state.repairId = repairId;
		fetchDetail( repairId ).then( updatePayload ).catch( function ( error ) {
			console.warn( 'DTB repair workbench detail load failed:', error );
		} );
	}

	function section( title, body, extraClass ) {
		return '<section class="dtb-repair-workbench-card ' + esc( extraClass || '' ) + '">' +
			( title ? '<header><h3>' + esc( title ) + '</h3></header>' : '' ) +
			'<div class="dtb-repair-workbench-card__body">' + body + '</div>' +
		'</section>';
	}

	function field( label, html ) {
		return '<label class="dtb-repair-field"><span>' + esc( label ) + '</span>' + html + '</label>';
	}

	function input( name, value, type, attrs ) {
		return '<input class="dtb-repair-input" name="' + esc( name ) + '" type="' + esc( type || 'text' ) + '" value="' + esc( value || '' ) + '" ' + ( attrs || '' ) + '>';
	}

	function textarea( name, value, rows, attrs ) {
		return '<textarea class="dtb-repair-input" name="' + esc( name ) + '" rows="' + esc( rows || 3 ) + '" ' + ( attrs || '' ) + '>' + esc( value || '' ) + '</textarea>';
	}

	function button( label, action, type ) {
		return '<button type="button" class="button ' + ( type === 'primary' ? 'button-primary' : '' ) + '" data-dtb-repair-ui-action="' + esc( action ) + '">' + esc( label ) + '</button>';
	}

	function normalizeQuote( quote ) {
		quote = quote || {};
		var totals = quote.totals || {};
		return {
			status: quote.status || 'draft',
			currency: quote.currency || 'USD',
			lines: Array.isArray( quote.lines ) ? quote.lines : [],
			expires_at: quote.expires_at || '',
			customer_note: quote.customer_note || quote.notes || '',
			internal_note: quote.internal_note || '',
			totals: totals,
		};
	}

	function quoteTotalsFromDom( root ) {
		var subtotal = 0;
		qsa( '[data-dtb-repair-quote-row]', root ).forEach( function ( row ) {
			var qty = parseFloat( ( qs( '[name="quantity"]', row ) || {} ).value || '1' ) || 1;
			var unit = parseFloat( ( qs( '[name="unit_price"]', row ) || {} ).value || '0' ) || 0;
			subtotal += Math.max( 0, qty ) * Math.max( 0, unit );
		} );
		var shipping = parseFloat( ( qs( '[name="shipping_amount"]', root ) || {} ).value || '0' ) || 0;
		return { subtotal: subtotal, total: subtotal + Math.max( 0, shipping ) };
	}

	function renderQuote( d ) {
		var target = panel( 'quote' );
		if ( ! target ) { return; }
		var q = normalizeQuote( d.quote );
		var rows = q.lines.length ? q.lines : [ { type: 'labor', label: 'Diagnostic labor', quantity: 1, unit_price: 0, description: '' } ];
		var html = '<div class="dtb-repair-workbench-grid">';
		html += section( 'Quote builder', '<div class="dtb-repair-quote-editor" data-dtb-repair-quote-editor>' +
			'<div class="dtb-repair-line-table">' + rows.map( function ( line, index ) {
				return renderQuoteLine( line, index );
			} ).join( '' ) + '</div>' +
			'<div class="dtb-repair-inline-actions">' + button( '+ Add quote line', 'quote-add-line' ) + '</div>' +
			'<div class="dtb-repair-form-grid">' +
				field( 'Shipping amount', input( 'shipping_amount', q.totals.shipping_amount || 0, 'number', 'step="0.01" min="0"' ) ) +
				field( 'Valid until', input( 'expires_at', q.expires_at ? String( q.expires_at ).slice( 0, 10 ) : '', 'date' ) ) +
			'</div>' +
			field( 'Customer note', textarea( 'customer_note', q.customer_note, 3, 'placeholder="Shown to customer with the quote"' ) ) +
			field( 'Internal quote note', textarea( 'internal_note', q.internal_note, 2, 'placeholder="Internal only"' ) ) +
		'</div>' );
		html += section( 'Quote summary', '<div class="dtb-repair-summary-metric"><span>Status</span><strong>' + esc( q.status ) + '</strong></div>' +
			'<div class="dtb-repair-summary-metric"><span>Subtotal</span><strong data-dtb-repair-quote-subtotal>' + fmtMoney( q.totals.subtotal || 0 ) + '</strong></div>' +
			'<div class="dtb-repair-summary-metric"><span>Total</span><strong data-dtb-repair-quote-total>' + fmtMoney( q.totals.total || q.totals.grand_total || 0 ) + '</strong></div>' +
			'<div class="dtb-repair-command-row">' + button( 'Save draft', 'quote-save' ) + button( 'Send quote', 'quote-send', 'primary' ) + '</div>' +
			'<p class="description">Saving or sending refreshes the repair timeline and modal state in-place.</p>' );
		html += '</div>';
		target.innerHTML = html;
		refreshQuoteTotals( target );
	}

	function renderQuoteLine( line, index ) {
		return '<div class="dtb-repair-line-row" data-dtb-repair-quote-row>' +
			'<select class="dtb-repair-input" name="type">' + [ 'labor', 'part', 'shipping', 'service', 'misc' ].map( function ( type ) {
				return '<option value="' + type + '"' + ( ( line.type || 'labor' ) === type ? ' selected' : '' ) + '>' + type + '</option>';
			} ).join( '' ) + '</select>' +
			input( 'label', line.label || '', 'text', 'placeholder="Line item"' ) +
			input( 'quantity', line.quantity || 1, 'number', 'step="0.01" min="0.01"' ) +
			input( 'unit_price', line.unit_price || 0, 'number', 'step="0.01" min="0"' ) +
			'<button type="button" class="button button-small" data-dtb-repair-ui-action="quote-remove-line" aria-label="Remove line">×</button>' +
			textarea( 'description', line.description || '', 2, 'placeholder="Optional description"' ) +
		'</div>';
	}

	function collectQuote( root, send ) {
		var lines = qsa( '[data-dtb-repair-quote-row]', root ).map( function ( row ) {
			return {
				type: ( qs( '[name="type"]', row ) || {} ).value || 'labor',
				label: ( qs( '[name="label"]', row ) || {} ).value || '',
				description: ( qs( '[name="description"]', row ) || {} ).value || '',
				quantity: parseFloat( ( qs( '[name="quantity"]', row ) || {} ).value || '1' ) || 1,
				unit_price: parseFloat( ( qs( '[name="unit_price"]', row ) || {} ).value || '0' ) || 0,
			};
		} );
		return {
			quote: {
				status: send ? 'sent' : 'draft',
				lines: lines,
				shipping_amount: parseFloat( ( qs( '[name="shipping_amount"]', root ) || {} ).value || '0' ) || 0,
				expires_at: ( qs( '[name="expires_at"]', root ) || {} ).value || '',
				customer_note: ( qs( '[name="customer_note"]', root ) || {} ).value || '',
				internal_note: ( qs( '[name="internal_note"]', root ) || {} ).value || '',
			},
		};
	}

	function refreshQuoteTotals( root ) {
		var totals = quoteTotalsFromDom( root );
		var subtotalEl = qs( '[data-dtb-repair-quote-subtotal]', root );
		var totalEl = qs( '[data-dtb-repair-quote-total]', root );
		if ( subtotalEl ) { subtotalEl.textContent = fmtMoney( totals.subtotal ); }
		if ( totalEl ) { totalEl.textContent = fmtMoney( totals.total ); }
	}

	function getAllocatedParts( d ) {
		return d.parts && Array.isArray( d.parts.allocated ) ? d.parts.allocated : [];
	}

	function getMediaItems( d ) {
		return d.media && Array.isArray( d.media.items ) ? d.media.items : [];
	}

	function shortStatus( value ) {
		return String( value || 'unknown' ).replace( /_/g, ' ' );
	}

	function metric( label, value ) {
		return '<div class="dtb-repair-summary-metric"><span>' + esc( label ) + '</span><strong>' + esc( value || '—' ) + '</strong></div>';
	}

	function renderMediaGrid( d ) {
		var media = getMediaItems( d );
		if ( ! media.length ) {
			return '<p class="dtb-wb-empty">No customer photos or media are attached to this repair yet.</p>';
		}
		return '<div class="dtb-repair-media-grid">' + media.map( function ( item ) {
			var label = item.alt || item.title || item.filename || ( 'Attachment #' + item.id );
			return '<a class="dtb-repair-media-tile" href="' + esc( item.full || item.url || '#' ) + '" target="_blank" rel="noopener">' +
				'<img src="' + esc( item.thumbnail || item.url || '' ) + '" alt="' + esc( label ) + '">' +
				'<span>' + esc( item.filename || label ) + '</span>' +
			'</a>';
		} ).join( '' ) + '</div>';
	}

	function renderOverview( d ) {
		var target = panel( 'overview' );
		if ( ! target ) { return; }
		var r = d.record || {};
		var q = normalizeQuote( d.quote );
		var intel = d.intelligence || d.intel || {};
		var blockers = Array.isArray( intel.blockers ) ? intel.blockers : [];
		var flags = [].concat( intel.intent_flags || [], intel.sentiment_flags || [] );
		var media = getMediaItems( d );
		var linked = d.linked_records || d.linked || {};
		var timeline = Array.isArray( d.timeline ) ? d.timeline : [];
		var nextAction = intel.next_best_action || deriveNextAction( d );

		target.innerHTML = '<div class="dtb-repair-overview-layout">' +
			section( 'Operational brief', '<div class="dtb-repair-overview-brief">' +
				'<p><strong>Next best action:</strong> ' + esc( nextAction ) + '</p>' +
				'<p><strong>Customer issue:</strong> ' + esc( r.issue_description || 'No issue details submitted.' ) + '</p>' +
				'<p><strong>Tool:</strong> ' + esc( [ r.tool_brand, r.tool_model, r.tool_category ].filter( Boolean ).join( ' / ' ) || 'Unspecified' ) + '</p>' +
			'</div><div class="dtb-repair-chip-row">' +
				'<span>' + esc( shortStatus( r.status ) ) + '</span>' +
				'<span>' + esc( intel.sla_state || 'SLA unknown' ) + '</span>' +
				'<span>' + esc( media.length + ' media' ) + '</span>' +
				'<span>' + esc( q.lines.length + ' quote lines' ) + '</span>' +
			'</div>' ) +
			section( 'Efficiency accelerators', renderAccelerators( d ) ) +
			section( 'Workload intelligence', metric( 'Score', intel.workload_score || '—' ) +
				metric( 'Age', intel.age_bucket || '—' ) +
				metric( 'SLA', intel.sla_state || '—' ) +
				metric( 'Quote', q.status || 'draft' ) +
				( blockers.length ? '<div class="dtb-repair-alert-list">' + blockers.map( function ( blocker ) { return '<p>' + esc( blocker ) + '</p>'; } ).join( '' ) + '</div>' : '<p class="dtb-wb-empty">No current blockers detected.</p>' ) +
				( flags.length ? '<div class="dtb-repair-chip-row">' + flags.map( function ( flag ) { return '<span>' + esc( flag ) + '</span>'; } ).join( '' ) + '</div>' : '' ) ) +
			section( 'Customer media', renderMediaGrid( d ) ) +
			section( 'Record context', metric( 'Customer', r.customer_name || '—' ) +
				metric( 'Email', r.customer_email || '—' ) +
				metric( 'Priority', r.priority || 'normal' ) +
				metric( 'Service tier', r.service_tier || '—' ) +
				metric( 'Linked records', linked && linked.records ? linked.records.length : 0 ) +
				metric( 'Timeline events', timeline.length ) ) +
		'</div>';
	}

	function deriveNextAction( d ) {
		var r = d.record || {};
		var q = normalizeQuote( d.quote );
		var media = getMediaItems( d );
		if ( ! media.length ) {
			return 'Request diagnostic photos from customer';
		}
		if ( r.status === 'submitted' ) {
			return 'Review intake and move to reviewed';
		}
		if ( q.status === 'draft' && [ 'reviewed', 'approved', 'quoted' ].indexOf( r.status ) !== -1 ) {
			return 'Finalize and send quote';
		}
		if ( ( d.parts || {} ).count > 0 && r.status === 'quote_accepted' ) {
			return 'Allocate parts and assign technician';
		}
		return 'Advance the repair through the next valid workflow step';
	}

	function renderAccelerators( d ) {
		var r = d.record || {};
		var allowed = ( d.workflow && Array.isArray( d.workflow.allowed_transitions ) ) ? d.workflow.allowed_transitions : ( r.allowed_next || [] );
		var html = '<div class="dtb-repair-accelerator-list">';
		html += '<button type="button" class="button button-primary" data-dtb-repair-ui-action="request-customer-info">Request missing info</button>';
		if ( allowed.indexOf( 'reviewed' ) !== -1 ) {
			html += '<button type="button" class="button" data-dtb-repair-ui-action="quick-transition" data-status="reviewed">Mark reviewed</button>';
		}
		if ( allowed.indexOf( 'awaiting_customer' ) !== -1 ) {
			html += '<button type="button" class="button" data-dtb-repair-ui-action="quick-transition" data-status="awaiting_customer">Awaiting customer</button>';
		}
		if ( [ 'reviewed', 'approved', 'quoted' ].indexOf( r.status ) !== -1 ) {
			html += '<button type="button" class="button" data-dtb-repair-ui-action="jump-tab" data-tab="quote">Build quote</button>';
		}
		html += '<button type="button" class="button" data-dtb-repair-ui-action="jump-tab" data-tab="conversation">Open thread</button>';
		html += '</div>';
		return html;
	}

	function renderParts( d ) {
		var target = panel( 'parts' );
		if ( ! target ) { return; }
		var parts = getAllocatedParts( d );
		var rows = parts.length ? parts : [ { sku: '', qty: 1, note: '' } ];
		var current = ( d.record || {} ).status || '';
		var canAdvance = [ 'approved', 'quote_accepted' ].indexOf( current ) !== -1;
		target.innerHTML = '<div class="dtb-repair-workbench-grid dtb-repair-workbench-grid--wide">' +
			section( 'Parts allocation', '<div class="dtb-repair-parts-editor" data-dtb-repair-parts-editor>' + rows.map( renderPartRow ).join( '' ) + '</div>' +
				'<div class="dtb-repair-inline-actions">' + button( '+ Add part', 'parts-add-row' ) + '</div>' ) +
			section( 'Allocation controls', '<p class="description">Save edits at any time. Use allocate when the repair is ready to advance from approved/quote accepted into parts allocation.</p>' +
				'<div class="dtb-repair-command-row">' + button( 'Save parts', 'parts-save' ) + ( canAdvance ? button( 'Allocate & advance', 'parts-allocate', 'primary' ) : '' ) + '</div>' +
				( canAdvance ? '' : '<p class="description">Workflow advance is unavailable from current status: ' + esc( current || 'unknown' ) + '.</p>' ) ) +
		'</div>';
	}

	function renderPartRow( part ) {
		return '<div class="dtb-repair-part-row" data-dtb-repair-part-row>' +
			input( 'sku', part.sku || '', 'text', 'placeholder="SKU"' ) +
			input( 'qty', part.qty || 1, 'number', 'min="1" step="1"' ) +
			input( 'note', part.note || '', 'text', 'placeholder="Note / bin / fitment"' ) +
			'<button type="button" class="button button-small" data-dtb-repair-ui-action="parts-remove-row" aria-label="Remove part">×</button>' +
		'</div>';
	}

	function collectParts( root ) {
		return qsa( '[data-dtb-repair-part-row]', root ).map( function ( row ) {
			return {
				sku: ( qs( '[name="sku"]', row ) || {} ).value || '',
				qty: parseInt( ( qs( '[name="qty"]', row ) || {} ).value || '1', 10 ) || 1,
				note: ( qs( '[name="note"]', row ) || {} ).value || '',
			};
		} ).filter( function ( part ) { return part.sku.trim(); } );
	}

	function renderTechnician( d ) {
		var target = panel( 'technician' );
		if ( ! target ) { return; }
		var currentId = ( d.record || {} ).technician_id || 0;
		var options = state.technicians || [];
		var selectHtml = '<select class="dtb-repair-input" name="technician_id"><option value="0">Unassigned</option>' + options.map( function ( tech ) {
			return '<option value="' + esc( tech.id ) + '"' + ( parseInt( tech.id, 10 ) === parseInt( currentId, 10 ) ? ' selected' : '' ) + '>' + esc( tech.label ) + '</option>';
		} ).join( '' ) + '</select>';
		var currentLabel = options.find( function ( tech ) { return parseInt( tech.id, 10 ) === parseInt( currentId, 10 ); } );
		target.innerHTML = '<div class="dtb-repair-workbench-grid">' +
			section( 'Current assignment', '<div class="dtb-repair-summary-metric"><span>Technician</span><strong>' + esc( currentLabel ? currentLabel.label : ( currentId ? '#' + currentId : 'Unassigned' ) ) + '</strong></div>' ) +
			section( 'Assign technician', field( 'Technician', selectHtml ) + field( 'Assignment note', textarea( 'note', '', 3, 'placeholder="Optional internal note"' ) ) + '<div class="dtb-repair-command-row">' + button( 'Save assignment', 'technician-save', 'primary' ) + '</div>' ) +
		'</div>';
		if ( ! state.technicians ) {
			loadTechnicians();
		}
	}

	function loadTechnicians() {
		apiFetch( REST + '/dtb/v1/admin/repairs/technicians', { method: 'GET' } ).then( function ( data ) {
			state.technicians = Array.isArray( data.technicians ) ? data.technicians : [];
			if ( state.payload ) {
				renderTechnician( state.payload );
			}
		} ).catch( function () {
			state.technicians = [];
		} );
	}

	function renderIntake( d ) {
		var target = panel( 'intake' );
		if ( ! target ) { return; }
		var r = d.record || {};
		var body = 'Hi ' + ( r.customer_name || 'there' ) + ',\n\nWe need a little more information to continue your repair review. Please reply with any additional photos, symptoms, or tool details that would help our technician diagnose the issue.\n\nThank you.';
		target.innerHTML = '<div class="dtb-repair-workbench-grid">' +
			section( 'Customer intake', '<div class="dtb-repair-fact-list">' +
				'<p><strong>Name:</strong> ' + esc( r.customer_name || '—' ) + '</p>' +
				'<p><strong>Email:</strong> ' + esc( r.customer_email || '—' ) + '</p>' +
				'<p><strong>Phone:</strong> ' + esc( r.customer_phone || '—' ) + '</p>' +
				'<p><strong>Tool:</strong> ' + esc( [ r.tool_brand, r.tool_model, r.tool_category ].filter( Boolean ).join( ' / ' ) || '—' ) + '</p>' +
				'<p><strong>Serial:</strong> ' + esc( r.serial_number || '—' ) + '</p>' +
				'<p><strong>Issue:</strong> ' + esc( r.issue_description || '—' ) + '</p>' +
			'</div>' ) +
			section( 'Customer photos / media', renderMediaGrid( d ) ) +
			section( 'Request more information', field( 'Message to customer', textarea( 'body', body, 7 ) ) + '<div class="dtb-repair-command-row">' + button( 'Send request', 'request-customer-info', 'primary' ) + '</div>' ) +
		'</div>';
	}

	function renderConversation( d ) {
		var target = panel( 'conversation' );
		if ( ! target ) { return; }
		var messages = d.conversation || [];
		var r = d.record || {};
		var macros = [
			{ label: 'Received / reviewing', body: 'Hi ' + ( r.customer_name || 'there' ) + ',\n\nWe received your repair request and are reviewing the details now. We will update you as soon as we complete the initial assessment.' },
			{ label: 'Need photos', body: 'Hi ' + ( r.customer_name || 'there' ) + ',\n\nCould you please send clear photos of the tool, serial label, and the problem area? This will help us diagnose the repair accurately.' },
			{ label: 'Quote update', body: 'Hi ' + ( r.customer_name || 'there' ) + ',\n\nYour repair quote has been prepared. Please review it and let us know how you would like to proceed.' },
		];
		var thread = messages.length ? messages.map( function ( msg ) {
			var type = msg.type === 'internal' ? 'internal' : ( msg.type === 'staff' ? 'staff' : 'customer' );
			return '<article class="dtb-repair-message dtb-repair-message--' + esc( type ) + '"><p>' + esc( msg.body ) + '</p><small>' + esc( msg.user_label || msg.author || 'Unknown' ) + ' · ' + esc( fmtDate( msg.created_at ) ) + '</small></article>';
		} ).join( '' ) : '<p class="dtb-wb-empty">No conversation yet.</p>';
		target.innerHTML = '<div class="dtb-repair-conversation-layout">' +
			section( 'Conversation', '<div class="dtb-repair-message-thread">' + thread + '</div>' ) +
			section( 'Reply / internal note', '<div class="dtb-repair-macros">' + macros.map( function ( macro ) { return '<button type="button" class="button button-small" data-dtb-repair-macro="' + esc( macro.body ) + '">' + esc( macro.label ) + '</button>'; } ).join( '' ) + '</div>' + field( 'Message body', textarea( 'body', '', 6, 'placeholder="Write a customer reply or internal note"' ) ) + '<div class="dtb-repair-command-row">' + button( 'Send customer reply', 'conversation-send', 'primary' ) + button( 'Add internal note', 'conversation-note' ) + button( 'Mark customer read', 'conversation-read' ) + '</div>' ) +
		'</div>';
	}

	function renderShipping( d ) {
		var target = panel( 'shipping' );
		if ( ! target ) { return; }
		var sh = d.shipping || {};
		var addr = sh.return_address || {};
		var current = ( d.record || {} ).status || '';
		target.innerHTML = '<div class="dtb-repair-workbench-grid">' +
			'<p class="description">Mailed tools are shipped at the customer’s expense; free local pickup/drop-off may be selected. DTB does not provide or pay for inbound carrier labels. Return shipping is quoted separately.</p>' +
			section( 'Return address', '<div class="dtb-repair-form-grid">' +
				field( 'Line 1', input( 'line1', addr.line1 || '', 'text' ) ) +
				field( 'City', input( 'city', addr.city || '', 'text' ) ) +
				field( 'State', input( 'state', addr.state || '', 'text' ) ) +
				field( 'Postcode', input( 'postcode', addr.postcode || '', 'text' ) ) +
				field( 'Country', input( 'country', addr.country || '', 'text' ) ) +
			'</div>' ) +
			section( 'Shipping handoff', '<div class="dtb-repair-form-grid">' +
				field( 'Carrier / service', input( 'rate_name', sh.rate_name || '', 'text' ) ) +
				field( 'Rate price', input( 'rate_price', sh.rate_price || 0, 'number', 'step="0.01" min="0"' ) ) +
				field( 'Tracking number', input( 'tracking_number', sh.tracking_number || '', 'text' ) ) +
				field( 'Veeqo order ID', input( 'veeqo_order_id', sh.veeqo_order_id || '', 'text' ) ) +
			'</div><div class="dtb-repair-command-row">' + button( 'Save shipping', 'shipping-save', 'primary' ) + ( current === 'in_progress' ? button( 'Mark ready to ship', 'shipping-ready' ) : '' ) + '</div>' ) +
		'</div>';
	}

	function renderInteractivePanels() {
		if ( state.isRendering || ! state.payload || ! getModal() ) { return; }
		state.isRendering = true;
		try {
			renderIntake( state.payload );
			renderQuote( state.payload );
			renderParts( state.payload );
			renderTechnician( state.payload );
			renderConversation( state.payload );
			renderShipping( state.payload );
		} finally {
			state.isRendering = false;
		}
	}

	function collectFormValues( root ) {
		var out = {};
		qsa( 'input[name], select[name], textarea[name]', root ).forEach( function ( el ) {
			out[ el.name ] = el.value;
		} );
		return out;
	}

	function setBusy( buttonEl, busy ) {
		if ( ! buttonEl ) { return; }
		buttonEl.disabled = !! busy;
		if ( busy ) {
			buttonEl.setAttribute( 'data-dtb-original-label', buttonEl.textContent );
			buttonEl.textContent = 'Working…';
		} else if ( buttonEl.getAttribute( 'data-dtb-original-label' ) ) {
			buttonEl.textContent = buttonEl.getAttribute( 'data-dtb-original-label' );
			buttonEl.removeAttribute( 'data-dtb-original-label' );
		}
	}

	function bindEvents() {
		document.addEventListener( 'input', function ( event ) {
			if ( event.target.closest( '#dtb-repair-modal [data-dtb-repair-quote-editor]' ) ) {
				refreshQuoteTotals( event.target.closest( '[data-panel="quote"]' ) || document );
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			var actionEl = event.target.closest( '[data-dtb-repair-ui-action]' );
			if ( ! actionEl || ! actionEl.closest( '#dtb-repair-modal' ) ) { return; }
			var action = actionEl.getAttribute( 'data-dtb-repair-ui-action' );
			var root = actionEl.closest( '.dtb-modal-tab-panel' ) || actionEl.closest( '.dtb-repair-workbench-card' ) || getModal();
			var promise;

			if ( action === 'quote-add-line' ) {
				var table = qs( '.dtb-repair-line-table', root );
				if ( table ) { table.insertAdjacentHTML( 'beforeend', renderQuoteLine( { type: 'labor', label: '', quantity: 1, unit_price: 0, description: '' }, Date.now() ) ); }
				refreshQuoteTotals( root );
				return;
			}
			if ( action === 'quote-remove-line' ) {
				var qrow = actionEl.closest( '[data-dtb-repair-quote-row]' );
				if ( qrow ) { qrow.remove(); }
				refreshQuoteTotals( root );
				return;
			}
			if ( action === 'parts-add-row' ) {
				var partsEditor = qs( '[data-dtb-repair-parts-editor]', root );
				if ( partsEditor ) { partsEditor.insertAdjacentHTML( 'beforeend', renderPartRow( { sku: '', qty: 1, note: '' } ) ); }
				return;
			}
			if ( action === 'parts-remove-row' ) {
				var prow = actionEl.closest( '[data-dtb-repair-part-row]' );
				if ( prow ) { prow.remove(); }
				return;
			}
			if ( action === 'quote-save' ) {
				promise = workbenchAction( 'quote_save', collectQuote( root, false ) );
			} else if ( action === 'quote-send' ) {
				if ( ! window.confirm( 'Send this quote to the customer?' ) ) { return; }
				promise = legacyAction( 'quote/send', collectQuote( root, true ).quote );
			} else if ( action === 'parts-save' ) {
				promise = workbenchAction( 'parts_save', { parts: collectParts( root ) } );
			} else if ( action === 'parts-allocate' ) {
				promise = workbenchAction( 'parts_allocate', { parts: collectParts( root ) } );
			} else if ( action === 'technician-save' ) {
				promise = workbenchAction( 'technician_assign', collectFormValues( root ) );
			} else if ( action === 'shipping-save' ) {
				promise = workbenchAction( 'shipping_save', collectFormValues( root ) );
			} else if ( action === 'shipping-ready' ) {
				promise = legacyAction( 'ready-to-ship', collectFormValues( root ) );
			} else if ( action === 'quick-transition' ) {
				var toStatus = actionEl.getAttribute( 'data-status' ) || '';
				if ( ! toStatus ) { return; }
				promise = legacyAction( 'transition', { to_status: toStatus } );
			} else if ( action === 'jump-tab' ) {
				var tab = actionEl.getAttribute( 'data-tab' ) || '';
				var tabButton = tab ? qs( '#dtb-repair-modal [data-tab="' + tab + '"]' ) : null;
				if ( tabButton ) { tabButton.click(); }
				return;
			} else if ( action === 'conversation-send' ) {
				var sendBody = ( qs( '[name="body"]', root ) || {} ).value || '';
				if ( ! sendBody.trim() ) { toast( 'Message body is empty.', 'warning' ); return; }
				promise = legacyAction( 'customer-message', { body: sendBody } );
			} else if ( action === 'conversation-note' ) {
				var noteBody = ( qs( '[name="body"]', root ) || {} ).value || '';
				if ( ! noteBody.trim() ) { toast( 'Note body is empty.', 'warning' ); return; }
				promise = legacyAction( 'internal-note', { body: noteBody } );
			} else if ( action === 'conversation-read' ) {
				promise = legacyAction( 'mark-customer-read', {} );
			} else if ( action === 'request-customer-info' ) {
				var infoPayload = collectFormValues( root );
				if ( ! infoPayload.body ) {
					infoPayload.body = 'Hi ' + ( ( state.payload && state.payload.record && state.payload.record.customer_name ) || 'there' ) + ',\n\nWe need a little more information to continue your repair review. Please reply with any additional photos, symptoms, or tool details that would help our technician diagnose the issue.\n\nThank you.';
				}
				promise = workbenchAction( 'request_customer_info', infoPayload );
			}

			if ( promise ) {
				setBusy( actionEl, true );
				promise.then( function () {
					toast( 'Repair updated.', 'success' );
				} ).catch( function ( error ) {
					toast( error && error.message ? error.message : 'Action failed.', 'error' );
				} ).finally( function () {
					setBusy( actionEl, false );
				} );
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			var macro = event.target.closest( '[data-dtb-repair-macro]' );
			if ( ! macro || ! macro.closest( '#dtb-repair-modal' ) ) { return; }
			var panelEl = macro.closest( '[data-panel="conversation"]' );
			var inputEl = panelEl ? qs( '[name="body"]', panelEl ) : null;
			if ( inputEl ) {
				inputEl.value = macro.getAttribute( 'data-dtb-repair-macro' ) || '';
				inputEl.focus();
			}
		} );
	}

	function boot() {
		bindEvents();
		ensureDetailLoaded();
		var observer = new MutationObserver( function () {
			window.requestAnimationFrame( ensureDetailLoaded );
		} );
		observer.observe( document.body, { childList: true, subtree: true } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
