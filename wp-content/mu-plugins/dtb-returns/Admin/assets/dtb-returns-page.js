// File: dtb-returns/Admin/assets/dtb-returns-page.js
// Returns admin page — modal detail view, order sync, status/resolution/note actions
/* jshint esversion: 6 */
( function () {
	'use strict';

	// ── Constants ──────────────────────────────────────────────────────────────
	var MODAL_ID = 'dtb-returns-modal';
	var API_BASE = '/wp-json/dtb/v1';

	// ── State ──────────────────────────────────────────────────────────────────
	var state = {
		currentReturnId : null,
		activeTab       : 'overview',
		lastOrder       : null,
	};

	// ── DOM helpers ────────────────────────────────────────────────────────────
	function byId( id ) {
		return document.getElementById( id );
	}
	function qs( sel, ctx ) {
		return ( ctx || document ).querySelector( sel );
	}
	function qsa( sel, ctx ) {
		return Array.prototype.slice.call( ( ctx || document ).querySelectorAll( sel ) );
	}
	function nonce() {
		return ( window.dtbAdminConfig && window.dtbAdminConfig.nonce ) || '';
	}
	function apiUrl( path ) {
		return API_BASE + path;
	}
	function adminBaseUrl( path ) {
		var base = ( window.dtbAdminConfig && window.dtbAdminConfig.adminUrl ) || '/wp-admin/';
		return base.replace( /\/$/, '' ) + '/' + path.replace( /^\//, '' );
	}
	function esc( str ) {
		return String( str || '' )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	// ── Date helpers ───────────────────────────────────────────────────────────
	function formatDate( raw ) {
		if ( ! raw || raw === '\u2014' ) { return raw || '\u2014'; }
		var normalised = String( raw ).replace( ' ', 'T' );
		var d = new Date( normalised );
		if ( isNaN( d.getTime() ) ) { return raw; }
		var now  = new Date();
		var diff = ( now - d ) / 1000;
		if ( diff >= 0 && diff < 60 )  { return 'Just now'; }
		if ( diff >= 0 && diff < 3600 )  { return Math.floor( diff / 60 ) + 'm ago'; }
		if ( diff >= 0 && diff < 86400 ) { return Math.floor( diff / 3600 ) + 'h ago'; }
		if ( diff >= 0 && diff < 604800 ){ return Math.floor( diff / 86400 ) + 'd ago'; }
		return d.toLocaleDateString( undefined, { month: 'short', day: 'numeric', year: 'numeric' } );
	}
	function formatDateFull( raw ) {
		if ( ! raw || raw === '\u2014' ) { return raw || '\u2014'; }
		var normalised = String( raw ).replace( ' ', 'T' );
		var d = new Date( normalised );
		if ( isNaN( d.getTime() ) ) { return raw; }
		return d.toLocaleDateString( undefined, { month: 'short', day: 'numeric', year: 'numeric' } ) +
			' ' + d.toLocaleTimeString( undefined, { hour: '2-digit', minute: '2-digit' } );
	}

	// ── Utility ────────────────────────────────────────────────────────────────
	function ucFirst( s ) {
		s = String( s || '' );
		return s.charAt( 0 ).toUpperCase() + s.slice( 1 );
	}
	function statusLabel( slug ) {
		return slug ? ucFirst( slug.replace( /_/g, ' ' ) ) : '\u2014';
	}

	// Map return status slugs → badge CSS modifier
	function statusBadgeClass( slug ) {
		var map = {
			pending_review : 'warning',
			approved       : 'primary',
			awaiting_item  : 'primary',
			item_received  : 'warning',
			refund_issued  : 'success',
			exchange_sent  : 'info',
			closed         : 'muted',
			pending        : 'warning',
			processing     : 'primary',
			resolved       : 'success',
			rejected       : 'danger',
			cancelled      : 'muted',
		};
		return 'dtb-returns-badge dtb-returns-badge--' + ( map[ slug ] || 'muted' );
	}

	// Map WC order status slugs → badge CSS modifier
	function wcOrderBadgeClass( wcStatus ) {
		var map = {
			processing : '--wc-processing',
			completed  : '--wc-completed',
			refunded   : '--wc-refunded',
			cancelled  : '--wc-cancelled',
			'on-hold'  : '--wc-on-hold',
			pending    : '--wc-pending',
			failed     : '--wc-failed',
		};
		var mod = map[ wcStatus ] ? 'dtb-returns-order-badge' + map[ wcStatus ] : 'dtb-returns-order-badge--wc-default';
		return 'dtb-returns-order-badge ' + mod;
	}

	// ── Fetch helpers ──────────────────────────────────────────────────────────
	function apiFetch( method, path, body, callback ) {
		var opts = {
			method  : method,
			headers : {
				'Content-Type' : 'application/json',
				'X-WP-Nonce'   : nonce(),
			},
		};
		if ( body ) {
			opts.body = JSON.stringify( body );
		}
		fetch( apiUrl( path ), opts )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) { callback( null, data ); } )
			.catch( function ( err ) { callback( err, null ); } );
	}

	// ── Modal elements ─────────────────────────────────────────────────────────
	function getModalEls() {
		var overlay = byId( MODAL_ID );
		if ( ! overlay ) { return null; }
		var modal = qs( '.dtb-modal', overlay );
		var body  = qs( '.dtb-modal__body', overlay );
		var title = qs( '.dtb-modal__title', overlay );
		if ( ! modal || ! body ) { return null; }
		return { overlay: overlay, modal: modal, body: body, title: title };
	}

	// ── Loading / error states ─────────────────────────────────────────────────
	function showModalLoading( els ) {
		els.body.innerHTML =
			'<div class="dtb-returns-modal-loading">' +
			'<span class="dtb-returns-loading-spinner"></span> Loading return details\u2026' +
			'</div>';
	}
	function showModalError( els, msg ) {
		els.body.innerHTML =
			'<div class="dtb-returns-modal-error">' + esc( msg ) + '</div>';
	}

	// ── KV row helper ──────────────────────────────────────────────────────────
	function kv( label, valueHtml ) {
		return '<div class="dtb-returns-kv">' +
			'<span class="dtb-returns-kv__label">' + esc( label ) + '</span>' +
			'<span class="dtb-returns-kv__value">' + valueHtml + '</span>' +
			'</div>';
	}

	// ── Section card helper ────────────────────────────────────────────────────
	function sectionOpen( title ) {
		return '<div class="dtb-returns-section">' +
			( title ? '<p class="dtb-returns-section__title">' + esc( title ) + '</p>' : '' );
	}
	function sectionClose() {
		return '</div>';
	}

	// ── Tab builder: Overview ──────────────────────────────────────────────────
	function buildOverviewTab( ret, order ) {
		var html = '';

		// 2-col grid: Return Details + Customer
		html += '<div class="dtb-returns-overview-grid">';

		// Return details
		html += sectionOpen( 'Return Details' );
		html += kv( 'Return ID', '#' + esc( ret.id ) );
		html += kv( 'Status', '<span class="' + statusBadgeClass( ret.status ) + '">' + statusLabel( ret.status ) + '</span>' );
		if ( ret.resolution ) {
			html += kv( 'Resolution', statusLabel( ret.resolution ) );
		}
		var orderVal;
		if ( ret.order_number ) {
			orderVal = '<a href="' + esc( adminBaseUrl( 'post.php?post=' + ret.order_id + '&action=edit' ) ) + '" target="_blank" class="dtb-returns-order-chip">#' + esc( ret.order_number ) + ' \u2197</a>';
		} else if ( ret.order_id ) {
			orderVal = '#' + esc( ret.order_id );
		} else {
			orderVal = '\u2014';
		}
		html += kv( 'Order', orderVal );
		html += kv( 'Requested', formatDateFull( ret.created_at ) );
		if ( ret.updated_at ) {
			html += kv( 'Updated', formatDate( ret.updated_at ) );
		}
		if ( ret.rma_label ) {
			html += kv( 'RMA Label', '<a href="' + esc( ret.rma_label ) + '" target="_blank" class="dtb-returns-order-chip">Download \u2197</a>' );
		}
		html += sectionClose();

		// Customer
		html += sectionOpen( 'Customer' );
		html += kv( 'Name', esc( ret.customer_name || '\u2014' ) );
		var emailVal = ret.customer_email
			? '<a href="mailto:' + esc( ret.customer_email ) + '">' + esc( ret.customer_email ) + '</a>'
			: '\u2014';
		html += kv( 'Email', emailVal );
		html += sectionClose();

		html += '</div>'; // overview-grid

		// Live order status inline
		if ( order && order.id ) {
			html += sectionOpen( 'Live Order Status' );
			html += '<div class="dtb-returns-section__hint">';
			html += '<span class="' + wcOrderBadgeClass( order.status ) + '">' + esc( order.status_label || order.status ) + '</span>';
			html += '<span class="dtb-returns-sep">&nbsp;&middot;&nbsp;</span>';
			html += esc( String( order.items_count || 0 ) ) + ' item' + ( order.items_count === 1 ? '' : 's' );
			html += '<span class="dtb-returns-sep">&nbsp;&middot;&nbsp;</span>';
			html += esc( order.total || '' );
			if ( order.payment_method_title ) {
				html += '<span class="dtb-returns-sep">&nbsp;&middot;&nbsp;</span>' + esc( order.payment_method_title );
			}
			if ( order.admin_url ) {
				html += '&nbsp;<a href="' + esc( order.admin_url ) + '" target="_blank" class="dtb-returns-order-chip">View Order \u2197</a>';
			}
			html += '</div>';
			html += sectionClose();
		}

		// Customer notes
		if ( ret.notes ) {
			html += sectionOpen( 'Customer Notes' );
			html += '<p class="dtb-returns-customer-notes">' + esc( ret.notes ) + '</p>';
			html += sectionClose();
		}

		return html;
	}

	// ── Tab builder: Order ─────────────────────────────────────────────────────
	function buildOrderTab( order, ret ) {
		if ( ! order || ! order.id ) {
			var html = '<div class="dtb-returns-no-order">';
			html += '<div class="dtb-returns-no-order__icon">&#128230;</div>';
			html += '<p class="dtb-returns-no-order__text">No WooCommerce order is linked to this return.</p>';
			if ( ret && ret.order_id ) {
				html += '<p class="dtb-returns-no-order__text" style="font-size:12px;color:#9ca3af;">Order ID on file: #' + esc( ret.order_id ) + ' (could not be loaded)</p>';
			}
			html += '</div>';
			return html;
		}

		var html = '';

		// Header row
		html += '<div class="dtb-returns-order-header">';
		html += '<span class="' + wcOrderBadgeClass( order.status ) + '">' + esc( order.status_label || order.status ) + '</span>';
		html += '<span class="dtb-returns-order-header__id">Order #' + esc( order.id ) + '</span>';
		html += '<div class="dtb-returns-order-header__actions">';
		html += '<button type="button" class="dtb-returns-sync-btn" data-dtb-returns-sync-order="1">\u21bb Sync from WooCommerce</button>';
		html += '<span class="dtb-returns-sync-status" aria-live="polite"></span>';
		if ( order.admin_url ) {
			html += '<a href="' + esc( order.admin_url ) + '" target="_blank" class="dtb-returns-order-chip">View in WC \u2197</a>';
		}
		html += '</div>';
		html += '</div>';

		// Line items table
		if ( order.items && order.items.length ) {
			html += '<table class="dtb-returns-items-table">';
			html += '<thead><tr><th>Item</th><th class="col-qty">Qty</th><th class="col-total">Total</th></tr></thead>';
			html += '<tbody>';
			order.items.forEach( function ( item ) {
				html += '<tr><td>' + esc( item.name ) + '</td>';
				html += '<td class="col-qty">' + esc( item.quantity ) + '</td>';
				html += '<td class="col-total">' + esc( item.total ) + '</td></tr>';
			} );
			html += '</tbody></table>';
		}

		// Totals
		html += '<div class="dtb-returns-totals">';
		if ( order.subtotal ) {
			html += '<div class="dtb-returns-totals-row"><span>Subtotal</span><span>' + esc( order.subtotal ) + '</span></div>';
		}
		if ( order.discount_total && parseFloat( order.discount_total_raw ) > 0 ) {
			html += '<div class="dtb-returns-totals-row"><span>Discount</span><span>&minus;' + esc( order.discount_total ) + '</span></div>';
		}
		if ( order.shipping_total ) {
			html += '<div class="dtb-returns-totals-row"><span>Shipping</span><span>' + esc( order.shipping_total ) + '</span></div>';
		}
		if ( order.total_tax ) {
			html += '<div class="dtb-returns-totals-row"><span>Tax</span><span>' + esc( order.total_tax ) + '</span></div>';
		}
		html += '<div class="dtb-returns-totals-row is-total"><span>Total</span><span>' + esc( order.total ) + '</span></div>';
		html += '</div>';

		// Billing / Shipping
		html += '<div class="dtb-returns-order-grid">';
		if ( order.billing ) {
			html += sectionOpen( 'Billing' );
			html += '<address class="dtb-returns-address">';
			html += '<strong class="dtb-returns-address__name">' + esc( order.billing.name ) + '</strong>';
			if ( order.billing.company ) {
				html += '<span class="dtb-returns-address__company">' + esc( order.billing.company ) + '</span>';
			}
			if ( order.billing.address ) {
				html += '<span class="dtb-returns-address__lines">' +
					esc( order.billing.address ).replace( /\n/g, '<br>' ) +
					'</span>';
			}
			if ( order.billing.email ) {
				html += '<a href="mailto:' + esc( order.billing.email ) + '" class="dtb-returns-address__contact">' + esc( order.billing.email ) + '</a>';
			}
			if ( order.billing.phone ) {
				html += '<a href="tel:' + esc( order.billing.phone ) + '" class="dtb-returns-address__contact">' + esc( order.billing.phone ) + '</a>';
			}
			html += '</address>' + sectionClose();
		}
		if ( order.shipping ) {
			html += sectionOpen( 'Shipping' );
			html += '<address class="dtb-returns-address">';
			html += '<strong class="dtb-returns-address__name">' + esc( order.shipping.name ) + '</strong>';
			if ( order.shipping.company ) {
				html += '<span class="dtb-returns-address__company">' + esc( order.shipping.company ) + '</span>';
			}
			if ( order.shipping.address ) {
				html += '<span class="dtb-returns-address__lines">' +
					esc( order.shipping.address ).replace( /\n/g, '<br>' ) +
					'</span>';
			}
			html += '</address>' + sectionClose();
		}
		html += '</div>'; // order-grid

		// Payment & dates
		html += sectionOpen( 'Payment \u0026 Dates' );
		if ( order.payment_method_title ) {
			html += kv( 'Payment', esc( order.payment_method_title ) );
		}
		if ( order.date_created ) {
			html += kv( 'Ordered', formatDateFull( order.date_created ) );
		}
		html += sectionClose();

		// Customer order note
		if ( order.customer_note ) {
			html += sectionOpen( 'Customer Order Note' );
			html += '<p class="dtb-returns-customer-notes">' + esc( order.customer_note ) + '</p>';
			html += sectionClose();
		}

		return html;
	}

	// ── Tab builder: Activity ──────────────────────────────────────────────────
	function buildActivityTab( ret, events ) {
		var html  = '';
		var notes = ret.staff_notes || [];

		if ( notes.length ) {
			html += sectionOpen( 'Staff Notes' );
			html += '<div class="dtb-returns-staff-notes-list">';
			notes.forEach( function ( n ) {
				html += '<div class="dtb-returns-staff-note">';
				html += '<p class="dtb-returns-staff-note__body">' + esc( n.note ) + '</p>';
				html += '<p class="dtb-returns-staff-note__meta">' + esc( n.user_label || 'Staff' ) + ' &middot; ' + formatDateFull( n.created_at ) + '</p>';
				html += '</div>';
			} );
			html += '</div>';
			html += sectionClose();
		}

		if ( events && events.length ) {
			html += sectionOpen( 'Audit Timeline' );
			html += '<ol class="dtb-returns-timeline">';
			events.forEach( function ( ev ) {
				html += '<li class="dtb-returns-timeline-item">';
				html += '<div class="dtb-returns-timeline-dot"></div>';
				html += '<p class="dtb-returns-timeline-summary">' + esc( ev.summary || ev.action || ev.event_type || ev.event || '' ) + '</p>';
				var meta = formatDate( ev.created_at || ev.ts || ev.date );
				if ( ev.actor && ev.actor.label ) {
					meta += ' \u00b7 ' + esc( ev.actor.label );
				} else if ( ev.user_login || ev.user || ev.actor_label ) {
					meta += ' \u00b7 ' + esc( ev.user_login || ev.user || ev.actor_label );
				}
				html += '<p class="dtb-returns-timeline-meta">' + meta + '</p>';
				html += '</li>';
			} );
			html += '</ol>';
			html += sectionClose();
		} else if ( ! notes.length ) {
			html += '<p class="dtb-returns-activity-empty">No activity recorded yet.</p>';
		}

		return html;
	}

	// ── Tab builder: Customer ──────────────────────────────────────────────────
	function buildCustomerTab( customer, linked ) {
		var WB = window.DtbWorkbench || {};
		var html = '<div class="dtb-returns-overview-grid">';
		html += sectionOpen( 'Customer 360' );
		if ( WB.renderCustomerRail ) {
			html += WB.renderCustomerRail( customer || {} );
		} else {
			customer = customer || {};
			html += kv( 'Name', esc( customer.name || '\u2014' ) );
			html += kv( 'Email', customer.email ? '<a href="mailto:' + esc( customer.email ) + '">' + esc( customer.email ) + '</a>' : '\u2014' );
			html += kv( 'Lifetime spend', esc( customer.lifetime_spend || 0 ) );
		}
		html += sectionClose();
		html += sectionOpen( 'Linked Records' );
		html += WB.renderLinkedRecords ? WB.renderLinkedRecords( linked || {} ) : '<p class="dtb-returns-activity-empty">No linked records.</p>';
		html += sectionClose();
		html += '</div>';
		return html;
	}

	// ── Compact record-level issues (blockers only, no integration dashboard) ──
	function buildRecordIssues( integrations ) {
		var issues = [];
		var blockerKeys = [ 'sync_failed', 'notification_failed', 'payment_failed',
		                    'shipping_blocked', 'refund_unavailable', 'missing_linked_order' ];
		var cfg = window.dtbAdminConfig || {};
		var sysUrl = ( cfg.adminUrl ? cfg.adminUrl.replace( /admin\.php.*$/, 'admin.php' ) : '/wp-admin/admin.php' ) + '?page=dtb-system-manager';
		Object.keys( integrations || {} ).forEach( function ( key ) {
			var item = integrations[ key ] || {};
			var status = item.status || item.state || '';
			if ( status !== 'error' && status !== 'failed' && blockerKeys.indexOf( key ) === -1 ) { return; }
			issues.push( { label: item.label || key, error: item.last_error || item.error || null, url: sysUrl } );
		} );
		if ( ! issues.length ) { return ''; }
		var html = sectionOpen( 'Record Issues' );
		issues.forEach( function ( issue ) {
			html += '<div class="dtb-returns-issue-chip">';
			html += '<span class="dtb-returns-issue-chip__label">' + esc( issue.label ) + '</span>';
			if ( issue.error ) { html += ' <span class="dtb-returns-issue-chip__err">' + esc( issue.error ) + '</span>'; }
			html += ' <a href="' + esc( issue.url ) + '" class="dtb-returns-issue-chip__link">System Manager \u2197</a>';
			html += '</div>';
		} );
		html += sectionClose();
		return html;
	}

	// ── Tab builder: Decision ─────────────────────────────────────────────────
	function buildDecisionTab( ret, order, linked ) {
		linked = linked || {};
		var warnings = Array.isArray( linked.warnings ) ? linked.warnings : [];
		var mismatches = Array.isArray( linked.mismatches ) ? linked.mismatches : [];
		var allowed = Array.isArray( ret.allowed_transitions ) ? ret.allowed_transitions : [];
		var checklist = [
			{ label: 'WooCommerce order linked', done: !! ( order && order.id ) },
			{ label: 'Customer/order links verified', done: ! warnings.length && ! mismatches.length },
			{ label: 'Resolution selected', done: !! ret.resolution },
			{ label: 'Return item received', done: [ 'item_received', 'refund_issued', 'exchange_sent', 'closed' ].indexOf( ret.status ) !== -1 },
			{ label: 'Valid next transition available', done: !! allowed.length },
		];

		var html = sectionOpen( 'Decision' );
		html += kv( 'Current status', '<span class="' + statusBadgeClass( ret.status ) + '">' + statusLabel( ret.status ) + '</span>' );
		html += kv( 'Resolution', esc( ret.resolution ? statusLabel( ret.resolution ) : 'Not selected' ) );
		html += kv( 'Next statuses', allowed.length ? esc( allowed.map( statusLabel ).join( ', ' ) ) : '\u2014' );
		html += sectionClose();

		html += sectionOpen( 'Readiness Checklist' );
		html += '<ul class="dtb-returns-readiness-list">';
		checklist.forEach( function ( item ) {
			html += '<li class="dtb-returns-readiness-item ' + ( item.done ? 'is-ready' : 'is-blocked' ) + '">';
			html += '<span class="dtb-returns-readiness-mark">' + ( item.done ? '\u2713' : '!' ) + '</span>';
			html += '<span>' + esc( item.label ) + '</span>';
			html += '</li>';
		} );
		html += '</ul>';
		html += sectionClose();

		if ( warnings.length || mismatches.length ) {
			var WB = window.DtbWorkbench || {};
			html += sectionOpen( 'Link Integrity' );
			html += WB.renderLinkedRecords ? WB.renderLinkedRecords( linked ) : '';
			html += sectionClose();
		}

		return html;
	}

	// ── Tab builder: Actions ───────────────────────────────────────────────────
	function buildActionsTab( ret ) {
		var allowedTransitions = Array.isArray( ret.allowed_transitions ) ? ret.allowed_transitions : [];
		var html = '';

		// Status transitions
		html += sectionOpen( 'Update Status' );
		html += '<div class="dtb-returns-workflow-btns">';
		allowedTransitions.forEach( function ( s ) {
			html += '<button type="button" class="dtb-returns-action-btn" ' +
				'data-dtb-returns-action="status" data-dtb-returns-value="' + esc( s ) + '">' +
				statusLabel( s ) + '</button>';
		} );
		html += '</div>';
		if ( ! allowedTransitions.length ) {
			html += '<p class="dtb-returns-activity-empty">No valid status transitions are available from the current return status.</p>';
		}
		html += sectionClose();

		// Resolution
		html += sectionOpen( 'Set Resolution' );
		html += '<form data-dtb-returns-resolution-form="1">';
		html += '<select name="resolution" class="dtb-returns-select">';
		[ '', 'refund', 'exchange', 'store_credit', 'repair', 'denied' ].forEach( function ( r ) {
			var sel = r === ret.resolution ? ' selected' : '';
			html += '<option value="' + esc( r ) + '"' + sel + '>' + ( r ? statusLabel( r ) : '\u2014 Select \u2014' ) + '</option>';
		} );
		html += '</select>';
		html += '<div class="dtb-returns-form-actions">';
		html += '<button type="submit" class="dtb-btn dtb-btn--primary dtb-btn--sm">Save Resolution</button>';
		html += '<span class="dtb-returns-form-status" aria-live="polite"></span>';
		html += '</div>';
		html += '</form>';
		html += sectionClose();

		// Internal note
		html += sectionOpen( 'Add Internal Note' );
		html += '<form data-dtb-returns-note-form="1">';
		html += '<textarea name="note" class="dtb-returns-note-textarea" rows="4" placeholder="Staff note (internal only)\u2026"></textarea>';
		html += '<div class="dtb-returns-form-actions">';
		html += '<button type="submit" class="dtb-btn dtb-btn--primary dtb-btn--sm">Add Note</button>';
		html += '<span class="dtb-returns-form-status" aria-live="polite"></span>';
		html += '</div>';
		html += '</form>';
		html += sectionClose();

		return html;
	}

	// ── Render modal ───────────────────────────────────────────────────────────
	function renderModal( els, payload ) {
		var ret    = payload['return'] || payload.return_data || {};
		var events = payload.timeline || payload.events || [];
		var order  = payload.order  || null;
		var customer = payload.customer || {};
		var linked = payload.linked_records || payload.linked || {};
		var integrations = payload.integrations || {};

		state.lastOrder = order;

		if ( els.title ) {
			els.title.textContent = 'Return #' + ( ret.id || '' ) + ( ret.customer_name ? ' \u2014 ' + ret.customer_name : '' );
		}

		var orderTabLabel = 'Order';
		if ( order && order.id ) {
			orderTabLabel = 'Order <span class="dtb-returns-tab-badge">#' + esc( order.id ) + '</span>';
		}

		var html = '<div class="dtb-returns-modal">';

		// Tab navigation — CSS classes match .dtb-returns-modal-tabs / .dtb-returns-modal-tab
		html += '<nav class="dtb-returns-modal-tabs" role="tablist">';
		html += '<button class="dtb-returns-modal-tab is-active" role="tab" aria-selected="true"  data-dtb-returns-tab="overview">Overview</button>';
		html += '<button class="dtb-returns-modal-tab"           role="tab" aria-selected="false" data-dtb-returns-tab="order">'    + orderTabLabel + '</button>';
		html += '<button class="dtb-returns-modal-tab"           role="tab" aria-selected="false" data-dtb-returns-tab="decision">Decision</button>';
		html += '<button class="dtb-returns-modal-tab"           role="tab" aria-selected="false" data-dtb-returns-tab="activity">Activity</button>';
		html += '<button class="dtb-returns-modal-tab"           role="tab" aria-selected="false" data-dtb-returns-tab="customer">Customer</button>';
		html += '<button class="dtb-returns-modal-tab"           role="tab" aria-selected="false" data-dtb-returns-tab="actions">Actions</button>';
		html += '</nav>';

		// Tab panels container — CSS class .dtb-returns-modal-body manages scroll
		html += '<div class="dtb-returns-modal-body">';
		var overviewHtml = buildRecordIssues( integrations ) + buildOverviewTab( ret, order );
		html += '<div data-dtb-returns-panel="overview" class="dtb-returns-modal-panel is-active">' + overviewHtml                  + '</div>';
		html += '<div data-dtb-returns-panel="order"    class="dtb-returns-modal-panel">'           + buildOrderTab( order, ret )     + '</div>';
		html += '<div data-dtb-returns-panel="decision" class="dtb-returns-modal-panel">'           + buildDecisionTab( ret, order, linked ) + '</div>';
		html += '<div data-dtb-returns-panel="activity" class="dtb-returns-modal-panel">'           + buildActivityTab( ret, events ) + '</div>';
		html += '<div data-dtb-returns-panel="customer" class="dtb-returns-modal-panel">'           + buildCustomerTab( customer, linked ) + '</div>';
		html += '<div data-dtb-returns-panel="actions"  class="dtb-returns-modal-panel">'           + buildActionsTab( ret )          + '</div>';
		html += '</div>';

		html += '</div>'; // .dtb-returns-modal

		els.body.innerHTML = html;

		// Restore active tab if navigated away from overview during a reload
		if ( state.activeTab && state.activeTab !== 'overview' ) {
			switchTab( els, state.activeTab );
		}
	}

	// ── Tab switching ──────────────────────────────────────────────────────────
	function switchTab( els, tabName ) {
		var body = ( els && els.body ) || qs( '.dtb-modal__body', byId( MODAL_ID ) );
		if ( ! body ) { return; }
		qsa( '[data-dtb-returns-tab]', body ).forEach( function ( btn ) {
			var active = btn.getAttribute( 'data-dtb-returns-tab' ) === tabName;
			btn.classList.toggle( 'is-active', active );
			btn.setAttribute( 'aria-selected', active ? 'true' : 'false' );
		} );
		qsa( '[data-dtb-returns-panel]', body ).forEach( function ( panel ) {
			panel.classList.toggle( 'is-active', panel.getAttribute( 'data-dtb-returns-panel' ) === tabName );
		} );
		state.activeTab = tabName;
	}

	function bindTabSwitching() {
		var overlay = byId( MODAL_ID );
		if ( ! overlay ) { return; }
		overlay.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '[data-dtb-returns-tab]' );
			if ( ! btn ) { return; }
			switchTab( null, btn.getAttribute( 'data-dtb-returns-tab' ) );
		} );
	}

	// ── Fetch detail ───────────────────────────────────────────────────────────
	function fetchDetail( returnId, onDone ) {
		apiFetch( 'GET', '/admin/returns/' + returnId + '/detail', null, onDone );
	}

	function openModal( returnId ) {
		state.currentReturnId = returnId;
		state.activeTab       = 'overview';

		// Update URL without navigation
		try {
			var url = new URL( window.location.href );
			url.searchParams.set( 'return_id', returnId );
			window.history.replaceState( {}, '', url.toString() );
		} catch ( e ) {}

		var els = getModalEls();
		if ( ! els ) { return; }

		if ( window.DtbAdmin && window.DtbAdmin.openModal ) {
			window.DtbAdmin.openModal( MODAL_ID );
		} else {
			els.overlay.classList.add( 'dtb-modal-overlay--open' );
		}

		showModalLoading( els );

		fetchDetail( returnId, function ( err, data ) {
			var freshEls = getModalEls();
			if ( ! freshEls ) { return; }
			if ( err || ! data || ! data.ok ) {
				showModalError( freshEls, ( data && data.message ) || 'Failed to load return details.' );
				return;
			}
			renderModal( freshEls, data );
		} );
	}

	function reloadModal() {
		if ( ! state.currentReturnId ) { return; }
		var savedTab = state.activeTab;
		fetchDetail( state.currentReturnId, function ( err, data ) {
			var els = getModalEls();
			if ( ! els || err || ! data || ! data.ok ) { return; }
			renderModal( els, data );
			if ( savedTab ) { switchTab( null, savedTab ); }
		} );
	}

	// ── Sync order ─────────────────────────────────────────────────────────────
	function fetchOrderSync( returnId, callback ) {
		apiFetch( 'POST', '/returns/' + returnId + '/sync-order', {}, callback );
	}

	function bindSyncButton() {
		var overlay = byId( MODAL_ID );
		if ( ! overlay ) { return; }
		overlay.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '[data-dtb-returns-sync-order]' );
			if ( ! btn || ! state.currentReturnId ) { return; }

			btn.disabled    = true;
			btn.textContent = 'Syncing\u2026';

			var statusEl = btn.parentElement ? qs( '.dtb-returns-sync-status', btn.parentElement ) : null;
			if ( statusEl ) {
				statusEl.textContent = '';
				statusEl.className   = 'dtb-returns-sync-status';
			}

			fetchOrderSync( state.currentReturnId, function ( err, data ) {
				btn.disabled    = false;
				btn.textContent = '\u21bb Sync from WooCommerce';

				if ( err || ! data || ! data.success ) {
					if ( statusEl ) {
						statusEl.textContent = ( data && data.message ) || 'Sync failed.';
						statusEl.classList.add( 'is-error' );
					}
					return;
				}

				if ( statusEl ) {
					statusEl.textContent = '\u2713 Synced';
					statusEl.classList.add( 'is-success' );
				}

				state.activeTab = 'order';
				reloadModal();
			} );
		} );
	}

	// ── Row clicks — intercept ALL clicks on a return row (including the View <a> link)
	// PHP emits: data-dtb-return-id="X" on each <tr>
	// ──────────────────────────────────────────────────────────────────────────
	function bindRowClicks() {
		// Rows may be inside #dtb-returns-workspace (live region) or the page table directly.
		// Use document-level delegation so we catch both initial load and AJAX-replaced rows.
		document.addEventListener( 'click', function ( e ) {
			// Ignore clicks inside the modal itself
			if ( e.target.closest( '#' + MODAL_ID ) ) { return; }

			var row = e.target.closest( '.dtb-returns-row[data-dtb-return-id]' );
			if ( ! row ) { return; }
			if ( e.target.closest( 'a,button,input,select,textarea,label' ) ) { return; }

			e.preventDefault();
			e.stopPropagation();

			var returnId = row.getAttribute( 'data-dtb-return-id' );
			if ( returnId ) {
				openModal( returnId );
			}
		}, true ); // capture phase so we beat the platform's bubble-phase link navigation
	}

	function makeRowsFocusable() {
		qsa( '.dtb-returns-row[data-dtb-return-id]' ).forEach( function ( row ) {
			if ( ! row.getAttribute( 'tabindex' ) ) {
				row.setAttribute( 'tabindex', '0' );
				row.setAttribute( 'role', 'button' );
			}
		} );
	}

	// ── Workflow status buttons ────────────────────────────────────────────────
	function bindWorkflowButtons() {
		var overlay = byId( MODAL_ID );
		if ( ! overlay ) { return; }
		overlay.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '[data-dtb-returns-action="status"]' );
			if ( ! btn || ! state.currentReturnId ) { return; }
			var newStatus = btn.getAttribute( 'data-dtb-returns-value' );
			var sectionEl = btn.closest( '.dtb-returns-section' );
			var statusEl  = sectionEl ? qs( '.dtb-returns-form-status', sectionEl ) : null;
			btn.disabled  = true;
			// Maps target status (from data-dtb-returns-value) to canonical action_type.
			// Mirrors the inverse of the PHP transition_map in ReturnsController.php.
			// Keep both in sync when adding new status transitions.
			var actionMap = {
				approved:       'approve',
				rejected:       'reject',
				awaiting_item:  'mark_awaiting_item',
				item_received:  'mark_item_received',
				refund_issued:  'issue_refund',
				exchange_sent:  'send_exchange',
				closed:         'close',
			};
			var actionType = actionMap[ newStatus ] || newStatus;
			// Per-click nonce appended so the same transition can be retried if the first
			// attempt produced no visible response (e.g. network error). Server-side
			// allowed-transitions validation still prevents invalid state changes.
			var opNonce = Math.random().toString( 36 ).slice( 2, 8 );
			apiFetch( 'POST', '/admin/returns/' + state.currentReturnId + '/actions',
				{ action_type: actionType, idempotency_key: 'returns-' + state.currentReturnId + '-' + actionType + '-' + opNonce },
				function ( err, data ) {
					btn.disabled = false;
					if ( err || ! data || ! data.ok ) {
						var msg = ( data && data.message ) || 'Action failed.';
						if ( statusEl ) { statusEl.textContent = msg; statusEl.classList.add( 'is-error' ); }
						return;
					}
					reloadModal();
				}
			);
		} );
	}

	// ── Resolution form ────────────────────────────────────────────────────────
	function bindResolutionForm() {
		var overlay = byId( MODAL_ID );
		if ( ! overlay ) { return; }
		overlay.addEventListener( 'submit', function ( e ) {
			var form = e.target.closest( '[data-dtb-returns-resolution-form]' );
			if ( ! form ) { return; }
			e.preventDefault();
			if ( ! state.currentReturnId ) { return; }
			var select   = qs( 'select[name="resolution"]', form );
			var submit   = qs( 'button[type="submit"]', form );
			var statusEl = qs( '.dtb-returns-form-status', form );
			if ( ! select || ! select.value ) { return; }
			if ( submit ) { submit.disabled = true; }
			if ( statusEl ) { statusEl.textContent = ''; statusEl.className = 'dtb-returns-form-status'; }
			apiFetch( 'POST', '/admin/returns/' + state.currentReturnId + '/actions',
				{ action_type: 'set_resolution', resolution: select.value },
				function ( err, data ) {
					if ( submit ) { submit.disabled = false; }
					if ( err || ! data || ! data.ok ) {
						if ( statusEl ) { statusEl.textContent = 'Save failed.'; statusEl.classList.add( 'is-error' ); }
						return;
					}
					if ( statusEl ) { statusEl.textContent = '\u2713 Saved'; statusEl.classList.add( 'is-success' ); }
					reloadModal();
				}
			);
		} );
	}

	// ── Note form ──────────────────────────────────────────────────────────────
	function bindNoteForm() {
		var overlay = byId( MODAL_ID );
		if ( ! overlay ) { return; }
		overlay.addEventListener( 'submit', function ( e ) {
			var form = e.target.closest( '[data-dtb-returns-note-form]' );
			if ( ! form ) { return; }
			e.preventDefault();
			if ( ! state.currentReturnId ) { return; }
			var textarea = qs( 'textarea[name="note"]', form );
			var submit   = qs( 'button[type="submit"]', form );
			var statusEl = qs( '.dtb-returns-form-status', form );
			if ( ! textarea || ! textarea.value.trim() ) { return; }
			if ( submit ) { submit.disabled = true; }
			if ( statusEl ) { statusEl.textContent = ''; statusEl.className = 'dtb-returns-form-status'; }
			apiFetch( 'POST', '/admin/returns/' + state.currentReturnId + '/actions',
				{ action_type: 'add_note', note: textarea.value.trim() },
				function ( err, data ) {
					if ( submit ) { submit.disabled = false; }
					if ( err || ! data || ! data.ok ) {
						if ( statusEl ) { statusEl.textContent = 'Save failed.'; statusEl.classList.add( 'is-error' ); }
						return;
					}
					textarea.value = '';
					if ( statusEl ) { statusEl.textContent = '\u2713 Note added'; statusEl.classList.add( 'is-success' ); }
					reloadModal();
				}
			);
		} );
	}

	// ── Modal close ────────────────────────────────────────────────────────────
	function bindModalClose() {
		var overlay = byId( MODAL_ID );
		if ( ! overlay ) { return; }

		overlay.addEventListener( 'click', function ( e ) {
			if ( e.target === overlay ) { closeModal(); return; }
			if ( e.target.closest( '[data-dtb-modal-close]' ) ) { closeModal(); }
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && overlay.classList.contains( 'dtb-modal-overlay--open' ) ) {
				closeModal();
			}
		} );
	}

	function closeModal() {
		var overlay = byId( MODAL_ID );
		if ( overlay ) {
			overlay.classList.remove( 'dtb-modal-overlay--open' );
		}
		state.currentReturnId = null;
		state.activeTab       = 'overview';
		state.lastOrder       = null;

		try {
			var url = new URL( window.location.href );
			url.searchParams.delete( 'return_id' );
			window.history.replaceState( {}, '', url.toString() );
		} catch ( e ) {}
	}

	// ── Deep-link — open modal if return_id is already in URL (e.g. page was reloaded with ?return_id=X)
	// ──────────────────────────────────────────────────────────────────────────
	function openFromDeepLink() {
		try {
			var params   = new URLSearchParams( window.location.search );
			var returnId = params.get( 'return_id' );
			if ( returnId ) { openModal( returnId ); }
		} catch ( e ) {}
	}

	// ── Live-region (AJAX-refreshed table) ────────────────────────────────────
	function bindLiveRegion() {
		var region = byId( 'dtb-returns-workspace' );
		if ( ! region ) { return; }
		region.addEventListener( 'dtb:live:navigated', function () {
			makeRowsFocusable();
		} );
	}

	// ── Init ───────────────────────────────────────────────────────────────────
	function init() {
		if ( ! byId( MODAL_ID ) ) { return; }
		bindTabSwitching();
		bindWorkflowButtons();
		bindResolutionForm();
		bindNoteForm();
		bindSyncButton();
		bindModalClose();
		bindRowClicks();
		bindLiveRegion();
		makeRowsFocusable();
		openFromDeepLink();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init, { once: true } );
	} else {
		init();
	}
}() );
